#!/usr/bin/env python3
"""Check whether a workload's variance depends on per-process address randomization."""

import argparse
import json
import os
from pathlib import Path
import random
import statistics
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('benchmark', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--workload', default='ary3')
    parser.add_argument('--variants', nargs='+', default=['hybrid-default', 'hybrid-tuned'])
    parser.add_argument('--runs', type=int, default=12)
    parser.add_argument('--scale', type=int, default=3)
    args = parser.parse_args()
    if args.runs < 2 or args.scale < 1:
        parser.error('need at least two runs and a positive scale')
    system_aslr = int(Path('/proc/sys/kernel/randomize_va_space').read_text())
    if system_aslr == 0:
        parser.error('the enabled-ASLR control requires system ASLR to be enabled')
    base = json.loads(args.benchmark.read_text())
    entry = next(e for e in base['benchmarks'] if e['workload'] == args.workload and e['mode'] == 'on')
    scratch = args.benchmark.resolve().with_suffix('.work')
    os.sched_setaffinity(0, {base['arguments']['cpu']})
    cases = [(name, enabled) for name in args.variants for enabled in (True, False)]
    samples = {f'{name}/aslr-{int(enabled)}': [] for name, enabled in cases}
    result = {'benchmark': str(args.benchmark.resolve()), 'workload': args.workload,
              'iterations': entry['iterations'] * args.scale, 'runs': args.runs,
              'cpu': base['arguments']['cpu'], 'system_randomize_va_space': system_aslr,
              'samples': samples, 'commands': {}, 'orders': []}
    rng = random.Random(86)
    for iteration in range(-3, args.runs):
        order = list(cases)
        rng.shuffle(order)
        if iteration >= 0:
            result['orders'].append(order)
        for name, enabled in order:
            key = f'{name}/aslr-{int(enabled)}'
            command = [] if enabled else ['setarch', os.uname().machine, '-R']
            command += [base['builds']['builds'][name]['binary'], *entry['options'],
                        str(Path(__file__).resolve().with_name('workloads.php')), args.workload,
                        str(result['iterations']), str(scratch / 'functions.php'), base['arguments']['autoload']]
            result['commands'][key] = command
            run = subprocess.run(command, capture_output=True, text=True, check=True,
                                 env=dict(os.environ, VM_BENCH_CACHE=str(scratch / 'twig-cache')))
            if run.stderr:
                raise RuntimeError(run.stderr)
            sample = json.loads(run.stdout)
            if sample['checksum'] != entry['samples'][name][0]['checksum']:
                raise RuntimeError('ASLR probe output mismatch')
            if iteration >= 0:
                samples[key].append(sample)
        if iteration >= 0:
            args.output.write_text(json.dumps(result, indent=2) + '\n')
    result['summary'] = {}
    for key, values in samples.items():
        seconds = [v['seconds'] for v in values]
        median = statistics.median(seconds)
        result['summary'][key] = {'median_seconds': median, 'min_seconds': min(seconds),
            'max_seconds': max(seconds),
            'mad_percent': 100 * statistics.median(abs(v - median) for v in seconds) / median}
        print(key, result['summary'][key], flush=True)
    args.output.write_text(json.dumps(result, indent=2) + '\n')


if __name__ == '__main__':
    main()
