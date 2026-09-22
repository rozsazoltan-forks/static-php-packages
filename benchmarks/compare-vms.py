#!/usr/bin/env python3
"""Compare two PHP 8.6 CLI builds using upstream Zend benchmarks (JIT off)."""

import argparse
import json
import os
from pathlib import Path
import re
import resource
import statistics
import subprocess
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('tailcall', type=Path)
    parser.add_argument('hybrid', type=Path)
    parser.add_argument('--runs', type=int, default=16)
    parser.add_argument('--warmups', type=int, default=3)
    parser.add_argument('--cpu', type=int, default=min(os.sched_getaffinity(0)))
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if args.runs < 2 or args.warmups < 0:
        parser.error('need at least two runs and a nonnegative warmup count')
    os.sched_setaffinity(0, {args.cpu})

    binaries = {vm: str(getattr(args, vm).resolve()) for vm in ('tailcall', 'hybrid')}
    workloads = [args.source.resolve() / 'Zend' / name for name in ('bench.php', 'micro_bench.php')]
    for workload in workloads:
        if not workload.is_file():
            parser.error(f'missing benchmark: {workload}')
    result = {'cpu': args.cpu, 'runs': args.runs, 'warmups': args.warmups, 'binaries': binaries, 'runtime': {}, 'benchmarks': []}
    for opcache in (True,):
        options = ['-n', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
                   '-d', 'opcache.file_update_protection=0', '-d', 'opcache.jit=disable',
                   '-d', 'opcache.jit_buffer_size=0']
        runtime = {}
        for vm, binary in binaries.items():
            probe = subprocess.run([binary, *options, '-r',
                'echo json_encode(["version" => PHP_VERSION, "zts" => PHP_ZTS, '
                '"vm" => defined("ZEND_VM_KIND") ? ZEND_VM_KIND : null, '
                '"opcache" => function_exists("opcache_get_status") ? opcache_get_status(false) : false]);'],
                capture_output=True, text=True, check=True)
            if probe.stderr:
                raise RuntimeError(probe.stderr)
            runtime[vm] = json.loads(probe.stdout)
            info = runtime[vm]
            if info['vm'] != f'ZEND_VM_KIND_{vm.upper()}':
                raise RuntimeError(f'{vm}: unexpected VM: {info["vm"]}')
            if bool(info['opcache']) != opcache or (info['opcache'] and info['opcache'].get('jit', {}).get('on')):
                raise RuntimeError(f'{vm}: unexpected OPcache/JIT settings')
        for key in ('version', 'zts'):
            if runtime['tailcall'][key] != runtime['hybrid'][key]:
                raise RuntimeError(f'builds differ in {key}')
        result['runtime'][str(opcache)] = runtime

        for workload in workloads:
            samples = {vm: [] for vm in binaries}
            cpu_samples = {vm: [] for vm in binaries}
            outputs = {vm: [] for vm in binaries}
            for iteration in range(-args.warmups, args.runs):
                order = list(binaries) if iteration % 2 == 0 else list(reversed(binaries))
                for vm in order:
                    cpu_start = resource.getrusage(resource.RUSAGE_CHILDREN)
                    start = time.perf_counter()
                    run = subprocess.run([binaries[vm], *options, str(workload)], capture_output=True, text=True, check=True)
                    elapsed = time.perf_counter() - start
                    cpu_end = resource.getrusage(resource.RUSAGE_CHILDREN)
                    if run.stderr or 'Total' not in run.stdout:
                        raise RuntimeError(f'{vm} {workload.name}: {run.stderr or run.stdout}')
                    if iteration >= 0:
                        samples[vm].append(elapsed)
                        cpu_samples[vm].append(cpu_end.ru_utime + cpu_end.ru_stime - cpu_start.ru_utime - cpu_start.ru_stime)
                        outputs[vm].append(run.stdout)
            medians = {vm: statistics.median(times) for vm, times in samples.items()}
            operations = {}
            for vm, runs in outputs.items():
                timings = {}
                for text in runs:
                    for name, seconds in re.findall(r'^(.+?)\s+(\d+\.\d+)(?:\s+\d+\.\d+)?\s*$', text, re.M):
                        timings.setdefault(name.strip(), []).append(float(seconds))
                operations[vm] = {name: statistics.median(times) for name, times in timings.items()}
            speedup = medians['hybrid'] / medians['tailcall']
            entry = {
                'workload': workload.name, 'opcache': opcache, 'options': options,
                'wall_seconds': samples, 'median_seconds': medians,
                'cpu_seconds': cpu_samples,
                'median_cpu_seconds': {vm: statistics.median(times) for vm, times in cpu_samples.items()},
                'median_operations': operations,
                'tailcall_speedup': speedup, 'stdout': outputs,
            }
            result['benchmarks'].append(entry)
            args.output.write_text(json.dumps(result, indent=2) + '\n')
            print(f'{workload.name:16} OPcache={int(opcache)} '
                  f'hybrid={medians["hybrid"]:.6f}s tailcall={medians["tailcall"]:.6f}s '
                  f'speedup={speedup:.3f}x', flush=True)


if __name__ == '__main__':
    main()
