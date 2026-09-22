#!/usr/bin/env python3
"""Interleave process-scoped counters for previously measured PHP workloads."""

import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import random
import re
import resource
import shutil
import subprocess

from perf_support import EVENTS, metrics, parse_stat


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('benchmark', type=Path)
    parser.add_argument('--kind', choices=['micro', 'phpstan', 'phpbench'], required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--runs', type=int, default=3)
    parser.add_argument('--seed', type=int, default=8680)
    args = parser.parse_args()
    data = json.loads(args.benchmark.read_text())
    variants = data['arguments']['variants']
    source_work = args.benchmark.resolve().with_suffix('.work')
    work = args.output.resolve().with_suffix('.work')
    work.mkdir(parents=True, exist_ok=False)
    cpu = int(data['arguments']['cpu'])
    rng = random.Random(args.seed)
    result = {'started_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'benchmark': str(args.benchmark.resolve()), 'kind': args.kind,
              'input_sha256': hashlib.sha256(args.benchmark.read_bytes()).hexdigest(),
              'runs': args.runs, 'events': EVENTS, 'seed': args.seed,
              'perf_version': subprocess.check_output(['perf', '--version'], text=True),
              'scope': 'Whole application process and inherited children, including startup; excludes PTS controller.',
              'measurements': []}
    guard = work / 'require-opcache.php'
    guard.write_text('''<?php
$s = opcache_get_status(false);
if (!$s || !$s['opcache_enabled'] || ($s['jit']['on'] ?? false)) {
    throw new RuntimeException('OPcache must be enabled and JIT disabled');
}
''')
    for entry in data['benchmarks']:
        if entry['mode'] != 'on':
            raise RuntimeError('Only enabled OPcache is supported')
        workload = entry.get('workload', args.kind)
        order = variants[:]
        rng.shuffle(order)
        for block in range(-1, args.runs):
            if block >= 0:
                order = order[1:] + order[:1]
            for variant in order:
                build = data['builds']['builds'][variant]
                binary = build['binary' if data['arguments'].get('sapi', 'cli') == 'cli' else 'libphp_runner']
                env = dict(os.environ, PHP_INI_SCAN_DIR='', XDEBUG_MODE='off', LC_ALL='C')
                runtime_log = work / f'{workload}-{block}-{variant}.runtime.jsonl'
                if args.kind == 'micro':
                    command = [binary, *entry['options']]
                    if workload.endswith('.php'):
                        command += ['-d', f'auto_prepend_file={guard}',
                                    str(Path(data['arguments']['source']) / 'Zend' / workload)]
                    else:
                        command += [str(Path(__file__).with_name('workloads.php')), workload,
                                    str(entry['iterations']), str(source_work / 'functions.php'),
                                    data['arguments']['autoload']]
                    env['VM_BENCH_CACHE'] = str(source_work / 'twig-cache')
                    cwd = work
                elif args.kind == 'phpstan':
                    cache = source_work / 'phpstan-cache'
                    if cache.exists():
                        shutil.rmtree(cache)
                    command = entry['samples'][variant][0]['command']
                    env['VM_BENCH_RUNTIME_LOG'] = str(runtime_log)
                    cwd = Path(data['arguments']['demo'])
                else:
                    cwd = Path(data['arguments']['pts']) / 'installed-tests/pts/phpbench-1.1.6/phpbench-0.8.1-patched2'
                    command = [binary, *entry['options'], '-d', f'auto_prepend_file={guard}',
                               str(cwd / 'phpbench.php'), '-i', '2000000']
                stat = work / f'{workload}-{block}-{variant}.stat'
                perf = ['perf', 'stat', '-x', ';', '-o', str(stat), '-e', EVENTS,
                        '--', 'taskset', '-c', str(cpu), *command]
                before = resource.getrusage(resource.RUSAGE_CHILDREN)
                run = subprocess.run(perf, cwd=cwd, env=env, capture_output=True, text=True, timeout=600)
                after = resource.getrusage(resource.RUSAGE_CHILDREN)
                raw = stat.read_text() if stat.exists() else ''
                sample = {'variant': variant, 'workload': workload, 'block': block,
                          'warmup': block < 0, 'command': perf, 'stdout': run.stdout,
                          'stderr': run.stderr, 'exit_code': run.returncode, 'raw': raw,
                          'process_tree_usage_including_perf': {
                              k: getattr(after, k) - getattr(before, k)
                              for k in ['ru_utime', 'ru_stime', 'ru_nvcsw', 'ru_nivcsw']}}
                if run.returncode:
                    (work / 'failure.json').write_text(json.dumps(sample, indent=2))
                    raise RuntimeError(f'Counter workload failed: {work / "failure.json"}')
                counters = parse_stat(raw)
                sample.update(counters=counters, metrics=metrics(counters))
                if args.kind == 'micro':
                    if workload.endswith('.php'):
                        assert 'Total' in run.stdout
                    else:
                        output = json.loads(run.stdout)
                        assert output['cached'] and output['checksum'] == entry['samples'][variant][0]['checksum']
                        sample['workload_result'] = output
                elif args.kind == 'phpstan':
                    analysis = json.loads(run.stdout)
                    assert analysis['totals']['errors'] == analysis['totals']['file_errors'] == 0
                    runtime = [json.loads(line) for line in runtime_log.read_text().splitlines()]
                    assert len(runtime) == 2 and all(x['opcache_enabled'] and not x['jit_on'] for x in runtime)
                    sample.update(analysis=analysis, runtime=runtime)
                else:
                    tests = re.findall(r'^\s*(test_\w+)\s+([\d.]+) seconds\.', run.stdout, re.M)
                    assert len(tests) == 56 and '* REGRESSION *' not in run.stdout and 'Score      :' in run.stdout
                    sample['per_test_seconds'] = dict(tests)
                result['measurements'].append(sample)
                args.output.write_text(json.dumps(result, indent=2) + '\n')
            print(args.kind, workload, 'block', block, 'complete', flush=True)
    result['finished_utc'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
    args.output.write_text(json.dumps(result, indent=2) + '\n')


if __name__ == '__main__':
    main()
