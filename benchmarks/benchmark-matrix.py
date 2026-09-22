#!/usr/bin/env python3
"""Interleave a GCC PHP VM/inlining matrix; save every sample and paired bootstrap CIs."""

import argparse
import datetime
import hashlib
import json
import math
import os
from pathlib import Path
import random
import re
import resource
import statistics
import subprocess
import time

HERE = Path(__file__).resolve().parent
WORKLOADS = ['bench.php', 'micro_bench.php', 'mandel', 'mandel2', 'ary3', 'nestedloop',
             'fibo', 'hash2', 'objects', 'arrays', 'strings-json', 'exceptions',
             'generators', 'fibers', 'twig', 'symfony-yaml']


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def quantile(xs, fraction):
    xs = sorted(xs)
    i = (len(xs) - 1) * fraction
    return xs[math.floor(i)] + (xs[math.ceil(i)] - xs[math.floor(i)]) * (i % 1)


def summarize(samples):
    wall = [s['seconds'] for s in samples]
    median = statistics.median(wall)
    return {'median_seconds': median, 'min_seconds': min(wall), 'max_seconds': max(wall),
            'p95_seconds': quantile(wall, .95),
            'mad_percent': 100 * statistics.median(abs(x - median) for x in wall) / median,
            'median_cpu_seconds': statistics.median(s['cpu_seconds'] for s in samples)}


def compare(samples, baseline, candidate):
    before, after = ([s['seconds'] for s in samples[name]] for name in (baseline, candidate))
    rng = random.Random(619)
    effects = []
    for _ in range(5000):
        indexes = rng.choices(range(len(before)), k=len(before))
        effects.append(100 * (statistics.median(after[i] for i in indexes) /
                              statistics.median(before[i] for i in indexes) - 1))
    return {'baseline': baseline, 'candidate': candidate,
            'elapsed_change_percent': 100 * (statistics.median(after) / statistics.median(before) - 1),
            'paired_bootstrap_95ci_percent': [quantile(effects, .025), quantile(effects, .975)]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('builds', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--cpu', default=4, type=int)
    parser.add_argument('--runs', default=20, type=int)
    parser.add_argument('--warmups', default=3, type=int)
    parser.add_argument('--target-seconds', default=.2, type=float)
    parser.add_argument('--seed', default=8616, type=int)
    parser.add_argument('--modes', nargs='+', choices=['on', 'noopt'], default=['on'])
    parser.add_argument('--workloads', nargs='+', choices=WORKLOADS, default=WORKLOADS)
    parser.add_argument('--variants', nargs='+', default=['hybrid-default', 'tailcall-default', 'hybrid-tuned', 'tailcall-tuned'])
    parser.add_argument('--sapi', choices=['cli', 'libphp'], default='cli')
    parser.add_argument('--autoload', type=Path, default=HERE.parent / 'vendor/autoload.php')
    args = parser.parse_args()
    if args.runs < 4 or args.warmups < 0 or args.target_seconds <= 0:
        parser.error('need >=4 runs, nonnegative warmups and a positive duration')
    os.sched_setaffinity(0, {args.cpu})
    source = args.source.resolve()
    builds = json.loads(args.builds.read_text())
    binaries = {name: builds['builds'][name]['binary' if args.sapi == 'cli' else 'libphp_runner']
                for name in args.variants}
    scratch = args.output.resolve().with_suffix('.work')
    scratch.mkdir(parents=True, exist_ok=False)
    text = (source / 'Zend/bench.php').read_text()
    marker = '$t0 = $t = start_test();'
    if text.count(marker) != 1:
        raise RuntimeError('Upstream benchmark has changed; inspect extraction marker')
    functions = scratch / 'functions.php'
    functions.write_text(text.split(marker)[0])
    env = dict(os.environ, VM_BENCH_CACHE=str(scratch / 'twig-cache'))
    result = {
        'started_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'arguments': {k: str(v) if isinstance(v, Path) else v for k, v in vars(args).items()},
        'uname': list(os.uname()), 'lscpu': subprocess.check_output(['lscpu'], text=True),
        'load_start': list(os.getloadavg()), 'builds': builds, 'runtime': {}, 'benchmarks': [],
        'sha256': {str(p): digest(p) for p in [HERE / 'workloads.php', Path(__file__),
                     source / 'Zend/bench.php', source / 'Zend/micro_bench.php',
                     args.autoload.parent.parent / 'composer.lock']},
    }
    rng = random.Random(args.seed)
    expected_checksums = {}
    identity = None
    for mode in args.modes:
        options = ['-n', '-d', 'opcache.enable=1', '-d', f'opcache.enable_cli={int(mode != "off")}',
                   '-d', 'opcache.file_update_protection=0', '-d', 'opcache.jit=disable',
                   '-d', 'opcache.jit_buffer_size=0', '-d', 'memory_limit=512M']
        if mode == 'noopt':
            options += ['-d', 'opcache.optimization_level=0']
        for name, binary in binaries.items():
            probe = subprocess.run([binary, *options, '-r',
                'echo json_encode(["version"=>PHP_VERSION,"zts"=>PHP_ZTS,"vm"=>ZEND_VM_KIND,'
                '"extensions"=>get_loaded_extensions(),"opcache"=>opcache_get_status(false),'
                '"optimization_level"=>ini_get("opcache.optimization_level")]);'],
                capture_output=True, text=True, check=True, env=env)
            if probe.stderr:
                raise RuntimeError(probe.stderr)
            info = json.loads(probe.stdout)
            expected = 'ZEND_VM_KIND_' + name.split('-')[0].upper()
            if info['vm'] != expected or not info['zts'] or bool(info['opcache']) != (mode != 'off'):
                raise RuntimeError(f'{name}: unexpected runtime: {info}')
            if info['opcache'] and info['opcache'].get('jit', {}).get('on'):
                raise RuntimeError('JIT unexpectedly enabled')
            if mode == 'noopt' and int(info['optimization_level'], 0) != 0:
                raise RuntimeError('OPcache optimizer unexpectedly enabled')
            current = (info['version'], info['zts'], sorted(info['extensions']))
            identity = current if identity is None else identity
            if identity != current:
                raise RuntimeError('Build identities/extensions differ')
            result['runtime'][f'{mode}/{name}'] = info

        for workload in args.workloads:
            upstream = workload.endswith('.php')

            def execute(name, iterations):
                command = [binaries[name], *options]
                command += [str(source / 'Zend' / workload)] if upstream else [
                    str(HERE / 'workloads.php'), workload, str(iterations), str(functions), str(args.autoload.resolve())]
                usage0 = resource.getrusage(resource.RUSAGE_CHILDREN)
                start = time.perf_counter()
                run = subprocess.run(command, capture_output=True, text=True, check=True, env=env)
                wall = time.perf_counter() - start
                usage1 = resource.getrusage(resource.RUSAGE_CHILDREN)
                if run.stderr:
                    raise RuntimeError(f'{name} {workload}: {run.stderr}')
                cpu = usage1.ru_utime + usage1.ru_stime - usage0.ru_utime - usage0.ru_stime
                if upstream:
                    if 'Total' not in run.stdout:
                        raise RuntimeError(run.stdout)
                    operations = {k.strip(): float(v) for k, v in re.findall(
                        r'^(.+?)\s+(-?\d+\.\d+)(?:\s+-?\d+\.\d+)?\s*$', run.stdout, re.M)}
                    return {'seconds': wall, 'cpu_seconds': cpu, 'operations': operations, 'stdout': run.stdout}
                sample = json.loads(run.stdout)
                if sample['cached'] != (mode != 'off'):
                    raise RuntimeError(f'Workload not cached as expected: {sample}')
                expected_checksums.setdefault(workload, sample['checksum'])
                if sample['checksum'] != expected_checksums[workload]:
                    raise RuntimeError(f'Output mismatch for {name}/{workload}')
                sample['process_seconds'] = wall
                sample['process_cpu_seconds'] = cpu
                return sample

            iterations = 1
            if not upstream:
                calibration = execute(args.variants[0], 1)
                iterations = max(1, math.ceil(args.target_seconds / calibration['seconds']))
            samples = {name: [] for name in binaries}
            orders = []
            for iteration in range(-args.warmups, args.runs):
                # Balanced positions in groups of four, with a new random permutation per group.
                if iteration == -args.warmups or iteration == 0 or iteration % len(binaries) == 0:
                    order = list(binaries)
                    rng.shuffle(order)
                else:
                    order = order[1:] + order[:1]
                if iteration >= 0:
                    orders.append(list(order))
                for name in order:
                    sample = execute(name, iterations)
                    if iteration >= 0:
                        samples[name].append(sample)
            pairs = [('hybrid-default', 'tailcall-default'), ('hybrid-tuned', 'tailcall-tuned'),
                     ('hybrid-default', 'hybrid-tuned'), ('tailcall-default', 'tailcall-tuned')]
            pairs += [(args.variants[0], candidate) for candidate in args.variants[1:]]
            pairs = list(dict.fromkeys(pairs))
            comparisons = [compare(samples, a, b) for a, b in pairs if a in samples and b in samples]
            entry = {'workload': workload, 'mode': mode, 'options': options, 'iterations': iterations,
                     'orders': orders, 'samples': samples, 'summary': {n: summarize(s) for n, s in samples.items()},
                     'comparisons': comparisons, 'load': list(os.getloadavg())}
            result['benchmarks'].append(entry)
            result['finished_utc'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
            args.output.write_text(json.dumps(result, indent=2) + '\n')
            print(f'{mode:5} {workload:16} ' + ' '.join(
                f'{n}={s["median_seconds"]:.4f}s' for n, s in entry['summary'].items()), flush=True)


if __name__ == '__main__':
    main()
