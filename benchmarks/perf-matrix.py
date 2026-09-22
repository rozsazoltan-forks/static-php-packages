#!/usr/bin/env python3
"""Collect interpreter hardware counters using a completed benchmark's calibration."""

import argparse
import json
import os
from pathlib import Path
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('benchmark', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--workloads', nargs='+', default=['mandel', 'ary3', 'objects', 'twig'])
    parser.add_argument('--repeats', type=int, default=3)
    parser.add_argument('--scale', type=int, default=5)
    parser.add_argument('--variants', nargs='+', help='subset of variants in the recorded benchmark')
    args = parser.parse_args()
    base = json.loads(args.benchmark.read_text())
    scratch = args.benchmark.resolve().with_suffix('.work')
    result = {'benchmark': str(args.benchmark.resolve()), 'repeats': args.repeats,
              'note': 'Counters cover the whole process, including setup and four warmup calls.',
              'measurements': []}
    for entry in base['benchmarks']:
        if entry['mode'] != 'on' or entry['workload'] not in args.workloads:
            continue
        for variant in args.variants or base['arguments']['variants']:
            build = base['builds']['builds'][variant]
            binary = build['binary' if base['arguments']['sapi'] == 'cli' else 'libphp_runner']
            statfile = scratch / f'perf-{variant}-{entry["workload"]}.csv'
            command = ['perf', 'stat', '-x,', '-r', str(args.repeats), '-o', str(statfile),
                       '-e', 'cycles:u,instructions:u,branches:u,branch-misses:u,cache-misses:u',
                       '--', 'taskset', '-c', str(base['arguments']['cpu']), binary, *entry['options'],
                       str(Path(__file__).resolve().with_name('workloads.php')), entry['workload'],
                       str(entry['iterations'] * args.scale), str(scratch / 'functions.php'),
                       base['arguments']['autoload']]
            run = subprocess.run(command, capture_output=True, text=True, check=True,
                                 env=dict(os.environ, VM_BENCH_CACHE=str(scratch / 'twig-cache')))
            outputs = [json.loads(line) for line in run.stdout.splitlines()]
            expected = entry['samples'][variant][0]['checksum']
            if len(outputs) != args.repeats or any(o['checksum'] != expected for o in outputs):
                raise RuntimeError('Unexpected perf workload output')
            text = statfile.read_text()
            counters = {}
            for line in text.splitlines():
                fields = line.split(',')
                if len(fields) >= 3 and fields[2].endswith(':u'):
                    if fields[0].startswith('<'):
                        raise RuntimeError(f'Counter unavailable: {line}')
                    counters[fields[2]] = float(fields[0])
            result['measurements'].append({'variant': variant, 'workload': entry['workload'],
                'iterations': entry['iterations'] * args.scale, 'command': command,
                'counters': counters, 'stat': text, 'stdout': outputs, 'stderr': run.stderr})
            args.output.write_text(json.dumps(result, indent=2) + '\n')
            print(variant, entry['workload'], counters, flush=True)


if __name__ == '__main__':
    main()
