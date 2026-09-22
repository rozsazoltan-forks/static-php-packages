#!/usr/bin/env python3
"""Compare matching PHP VMs on Symfony Demo/PHPStan or the official PTS PHPBench profile."""

import argparse
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import random
import re
import resource
import shlex
import shutil
import statistics
import subprocess
import time
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('matrix', HERE / 'benchmark-matrix.py')
matrix = importlib.util.module_from_spec(spec)
spec.loader.exec_module(matrix)
VARIANTS = ['hybrid-tuned', 'tailcall-tuned']


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def measured(command, cwd, env):
    before = resource.getrusage(resource.RUSAGE_CHILDREN)
    start = time.perf_counter()
    run = subprocess.run(command, cwd=cwd, env=env, capture_output=True, text=True, timeout=600)
    seconds = time.perf_counter() - start
    after = resource.getrusage(resource.RUSAGE_CHILDREN)
    return {'command': command, 'seconds': seconds,
            'cpu_seconds': after.ru_utime + after.ru_stime - before.ru_utime - before.ru_stime,
            'exit_code': run.returncode, 'stdout': run.stdout, 'stderr': run.stderr}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('builds', type=Path)
    parser.add_argument('workload', choices=['phpstan', 'phpbench'])
    parser.add_argument('--demo', type=Path)
    parser.add_argument('--pts', type=Path, help='isolated PTS_USER_PATH_OVERRIDE with installed PHPBench')
    parser.add_argument('--pts-entry', type=Path,
                        default=Path('/usr/share/phoronix-test-suite/pts-core/phoronix-test-suite.php'))
    parser.add_argument('--host-php', default='/usr/bin/php', help='PHP used only for the PTS harness')
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--runs', type=int, default=12)
    parser.add_argument('--warmups', type=int, default=2)
    parser.add_argument('--cpu', type=int, default=4)
    parser.add_argument('--modes', nargs='+', choices=['on'], default=['on'])
    parser.add_argument('--variants', nargs='+', default=VARIANTS)
    parser.add_argument('--resume', action='store_true', help='continue an interrupted matrix without repeating saved samples')
    parser.add_argument('--seed', type=int, default=8622)
    args = parser.parse_args()
    variants = args.variants
    if args.runs < 4 or args.runs % 2 or args.warmups < 0:
        parser.error('use an even number of >=4 runs and nonnegative warmups')
    if args.workload == 'phpstan' and not args.demo:
        parser.error('--demo is required for PHPStan')
    if args.workload == 'phpbench' and not args.pts:
        parser.error('--pts is required for PHPBench')
    os.sched_setaffinity(0, {args.cpu})
    work = args.output.resolve().with_suffix('.work')
    work.mkdir(parents=True, exist_ok=args.resume)
    builds = json.loads(args.builds.read_text())
    result = {'started_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'arguments': {k: str(v) if isinstance(v, Path) else v for k, v in vars(args).items()},
              'builds': builds, 'uname': list(os.uname()), 'load_start': list(os.getloadavg()),
              'lscpu': subprocess.check_output(['lscpu'], text=True),
              'runner_sha256': digest(__file__), 'runtime': {}, 'benchmarks': []}
    if args.resume:
        result = json.loads(args.output.read_text())
        for key in ['workload', 'runs', 'warmups', 'cpu', 'seed']:
            if result['arguments'][key] != getattr(args, key):
                raise RuntimeError(f'Resume would change {key}')
        if result['builds'] != builds:
            raise RuntimeError('Resume would change the builds')
        if any(set(e['samples']) != set(variants) for e in result['benchmarks']):
            raise RuntimeError('Resume would change the variants')
        if any(e['mode'] not in args.modes for e in result['benchmarks']):
            raise RuntimeError('Existing matrix contains an unrequested mode')
        result.setdefault('continuations', []).append({
            'utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'runner_sha256': digest(__file__), 'modes': args.modes})
    env = dict(os.environ, XDEBUG_MODE='off')
    expected_output = None
    expected_tests = None
    if args.workload == 'phpstan':
        demo = args.demo.resolve()
        result['demo'] = {
            'commit': subprocess.check_output(['git', '-C', str(demo), 'rev-parse', 'HEAD'], text=True).strip(),
            'status': subprocess.check_output(['git', '-C', str(demo), 'status', '--porcelain'], text=True),
            'composer_lock_sha256': digest(demo / 'composer.lock'),
            'phpstan_phar_sha256': digest(demo / 'vendor/phpstan/phpstan/phpstan.phar'),
            'upstream_config': (demo / 'phpstan.dist.neon').read_text(),
            'packages': {p['name']: p['version'] for p in json.loads((demo / 'composer.lock').read_text())['packages-dev']
                         if p['name'].startswith('phpstan/')},
        }
        cache = work / 'phpstan-cache'
        config = work / 'phpstan.neon'
        bootstrap = work / 'require-opcache.php'
        bootstrap.write_text('''<?php
$status = opcache_get_status(false);
if (!$status || !$status['opcache_enabled'] || ($status['jit']['on'] ?? false)) {
    throw new RuntimeException('Benchmark requires enabled OPcache and disabled JIT in every PHPStan process');
}
file_put_contents(getenv('VM_BENCH_RUNTIME_LOG'), json_encode([
    'pid' => getmypid(), 'binary' => PHP_BINARY, 'vm' => ZEND_VM_KIND,
    'ini' => php_ini_loaded_file(), 'opcache_enabled' => $status['opcache_enabled'],
    'jit_on' => $status['jit']['on'] ?? false
])."\\n", FILE_APPEND | LOCK_EX);
''')
        config.write_text('includes:\n    - ' + json.dumps(str(demo / 'phpstan.dist.neon')) +
                          '\nparameters:\n    tmpDir: ' + json.dumps(str(cache)) +
                          '\n    bootstrapFiles:\n        - ' + json.dumps(str(bootstrap)) +
                          '\n    parallel:\n        maximumNumberOfProcesses: 1\n')
        result['config'] = config.read_text()
    else:
        pts = args.pts.resolve()
        config_xml = ET.parse(pts / 'user-config.xml')
        for key, value in [('OpenBenchmarking/AnonymousUsageReporting', 'FALSE'),
                           ('OpenBenchmarking/AllowResultUploadsToOpenBenchmarking', 'FALSE'),
                           ('BatchMode/UploadResults', 'FALSE'), ('BatchMode/OpenBrowser', 'FALSE'),
                           ('Networking/NoInternetCommunication', 'TRUE'),
                           ('Networking/NoNetworkCommunication', 'TRUE')]:
            if config_xml.findtext('./Options/' + key) != value:
                raise RuntimeError(f'PTS must have {key}={value}')
        result['pts_config'] = (pts / 'user-config.xml').read_text()
        profile = pts / 'test-profiles/pts/phpbench-1.1.6'
        result['profile'] = {p.name: p.read_text() for p in profile.glob('*.xml')}
        result['phpbench_archive_sha256'] = digest(pts / 'installed-tests/pts/phpbench-1.1.6/phpbench-081-patched2.zip')

    def persist():
        args.output.write_text(json.dumps(result, indent=2) + '\n')

    rng = random.Random(args.seed)
    for mode in args.modes:
        options = ['-n', '-d', 'opcache.enable=1', '-d', f'opcache.enable_cli={int(mode == "on")}',
                   '-d', 'opcache.file_update_protection=0', '-d', 'opcache.memory_consumption=256',
                   '-d', 'opcache.max_accelerated_files=30000', '-d', 'opcache.jit=disable',
                   '-d', 'opcache.jit_buffer_size=0', '-d', 'memory_limit=1G']
        mode_env = env
        ini_text = None
        if args.workload == 'phpstan':
            # PHPStan spawns PHP_BINARY and forwards php_ini_loaded_file(), but
            # does not forward arbitrary command-line -d settings to workers.
            ini_text = '\n'.join(options[2::2]) + '\n'
            ini = work / f'php-{mode}.ini'
            ini.write_text(ini_text)
            options = ['-c', str(ini)]
            mode_env = dict(env, PHP_INI_SCAN_DIR='')
        entry = next((e for e in result['benchmarks'] if e['mode'] == mode), None)
        if entry is None:
            entry = {'mode': mode, 'options': options, 'samples': {v: [] for v in variants},
                     'ini': ini_text, 'warmups': [], 'order': []}
            result['benchmarks'].append(entry)
        elif entry['options'] != options or entry['ini'] != ini_text:
            raise RuntimeError('Resume would change runtime options')
        wrappers = {}
        identity = None
        for variant in variants:
            build = builds['builds'][variant]
            if digest(build['binary']) != build['sha256']:
                raise RuntimeError('Binary differs from build metadata')
            probe = subprocess.run([build['binary'], *options, '-r',
                'echo json_encode(["version"=>PHP_VERSION,"vm"=>ZEND_VM_KIND,"zts"=>PHP_ZTS,'
                '"extensions"=>get_loaded_extensions(),"opcache"=>opcache_get_status(false)]);'],
                env=mode_env, capture_output=True, text=True, check=True)
            info = json.loads(probe.stdout)
            if (info['vm'] != 'ZEND_VM_KIND_' + variant.split('-')[0].upper() or not info['zts'] or
                    bool(info['opcache']) != (mode == 'on') or probe.stderr):
                raise RuntimeError(f'Unexpected runtime identity: {probe}')
            current = [info['version'], info['zts'], sorted(info['extensions'])]
            identity = current if identity is None else identity
            if identity != current:
                raise RuntimeError('Runtime versions/extensions differ')
            result['runtime'][f'{variant}/{mode}'] = info
            wrapper = work / f'{variant}-{mode}'
            wrapper.write_text('#!/bin/sh\nexec ' + shlex.join([build['binary'], *options]) + ' "$@"\n')
            wrapper.chmod(0o755)
            wrappers[variant] = wrapper

        orders = []
        for _ in range(args.runs // 2):
            order = variants[:]
            rng.shuffle(order)
            orders.extend([order, order[::-1]])
        rng.shuffle(orders)
        schedule = [(True, i, v) for i in range(args.warmups) for v in variants]
        schedule += [(False, i, v) for i, order in enumerate(orders) for v in order]
        for warmup, block, variant in schedule:
            saved = entry['warmups'] if warmup else entry['samples'][variant]
            if any(s['block'] == block and s['variant'] == variant for s in saved):
                continue
            if args.workload == 'phpstan':
                if cache.exists():
                    shutil.rmtree(cache)
                command = [str(wrappers[variant]), str(demo / 'vendor/phpstan/phpstan/phpstan'),
                           'analyse', '--configuration', str(config), '--no-progress', '--error-format=json']
                runtime_log = work / f'{variant}-{mode}-{warmup}-{block}-runtime.jsonl'
                sample = measured(command, demo, dict(mode_env, VM_BENCH_RUNTIME_LOG=str(runtime_log)))
                sample['process_runtime'] = [json.loads(line) for line in runtime_log.read_text().splitlines()]
                if len({r['pid'] for r in sample['process_runtime']}) < 2 or any(
                        r['vm'] != 'ZEND_VM_KIND_' + variant.split('-')[0].upper()
                        or not r['opcache_enabled'] or r['jit_on'] for r in sample['process_runtime']):
                    raise RuntimeError('PHPStan worker runtime differs from expected settings')
                try:
                    output = json.loads(sample['stdout'])
                except ValueError:
                    output = None
                if sample['exit_code'] or output is None or output.get('totals', {}).get('errors') or output.get('totals', {}).get('file_errors'):
                    (work / 'failed-run.json').write_text(json.dumps(sample, indent=2))
                    raise RuntimeError(f'PHPStan failed; see {work / "failed-run.json"}')
                expected_output = output if expected_output is None else expected_output
                if output != expected_output:
                    raise RuntimeError('PHPStan analysis output changed between runs')
                sample['analysis'] = output
            else:
                run_name = f'{args.output.stem}-{mode}-{variant}-{"warmup" if warmup else "run"}-{block:02d}'
                if args.resume:
                    run_name += f'-continuation-{len(result["continuations"])}'
                run_env = dict(env, PTS_MODE='CLIENT', PTS_USER_PATH_OVERRIDE=str(pts) + '/',
                               PHP_BIN=str(wrappers[variant]), FORCE_TIMES_TO_RUN='1',
                               TEST_RESULTS_NAME=run_name, TEST_RESULTS_IDENTIFIER=f'{variant}/{mode}',
                               TEST_RESULTS_DESCRIPTION='Local PHP 8.6 GCC VM comparison; JIT disabled')
                command = [args.host_php, '-d', 'xdebug.mode=off', '-d', 'opcache.enable=1',
                           '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=disable',
                           '-d', 'opcache.jit_buffer_size=0', str(args.pts_entry),
                           'batch-run', 'pts/phpbench-1.1.6']
                sample = measured(command, work, run_env)
                folder = pts / 'test-results' / run_name
                composite = folder / 'composite.xml'
                if sample['exit_code'] or not composite.exists():
                    (work / 'failed-run.json').write_text(json.dumps(sample, indent=2))
                    raise RuntimeError(f'PTS failed; see {work / "failed-run.json"}')
                tree = ET.parse(composite)
                scores = [float(e.text) for e in tree.findall('./Result/Data/Entry/Value')]
                if len(scores) != 1 or scores[0] <= 0:
                    raise RuntimeError(f'Unexpected PTS scores: {scores}')
                logs = {str(p.relative_to(folder)): p.read_text(errors='replace') for p in folder.rglob('*')
                        if p.is_file() and ('test-logs' in p.parts or p.suffix == '.log')}
                benchmark_logs = [s for s in logs.values() if 'Score      :' in s]
                if not benchmark_logs:
                    raise RuntimeError('PTS did not save the underlying PHPBench output')
                raw = benchmark_logs[-1]
                tests = {m[0]: float(m[1]) for m in re.findall(r'^\s*(test_\w+)\s+([\d.]+) seconds\.', raw, re.M)}
                expected_tests = sorted(tests) if expected_tests is None else expected_tests
                if not tests or sorted(tests) != expected_tests or '* REGRESSION *' in raw or 'Fatal error' in raw:
                    raise RuntimeError('PHPBench test set changed or a test failed')
                sample.update({'score': scores[0], 'harness_seconds': sample['seconds'],
                               'seconds': 20000000 / scores[0], 'per_test_seconds': tests,
                               'composite_xml': composite.read_text(), 'test_logs': logs,
                               'environment': {k: run_env[k] for k in ['PHP_BIN', 'PTS_USER_PATH_OVERRIDE',
                                    'FORCE_TIMES_TO_RUN', 'TEST_RESULTS_NAME', 'TEST_RESULTS_IDENTIFIER']}})
            sample.update({'variant': variant, 'block': block, 'warmup': warmup})
            if warmup:
                entry['warmups'].append(sample)
            else:
                entry['samples'][variant].append(sample)
                entry['order'].append({'block': block, 'variant': variant})
            persist()
            print(f'{args.workload} {mode} {variant} {"warmup" if warmup else "run"} {block}: '
                  f'{sample["seconds"]:.4f}s' + (f' score={sample["score"]:g}' if 'score' in sample else ''), flush=True)
        entry['summary'] = {v: matrix.summarize(entry['samples'][v]) for v in variants}
        entry['comparisons'] = [matrix.compare(entry['samples'], variants[0], v) for v in variants[1:]]
        if len(variants) == 2:
            entry['comparison'] = entry['comparisons'][0]
        if args.workload == 'phpbench':
            medians = {v: statistics.median(s['score'] for s in entry['samples'][v]) for v in variants}
            entry['median_scores'] = medians
            entry['score_changes_percent'] = {v: 100 * (medians[v] / medians[variants[0]] - 1)
                                              for v in variants[1:]}
            if len(variants) == 2:
                entry['score_change_percent'] = entry['score_changes_percent'][variants[1]]
        persist()
        print(json.dumps(entry['comparisons']), flush=True)
    result['finished_utc'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
    result['load_end'] = list(os.getloadavg())
    persist()


if __name__ == '__main__':
    main()
