#!/usr/bin/env python3
"""Interleave local Symfony Demo HTTP measurements with verified libphp and OPcache."""

import argparse
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import random
import re
import signal
import statistics
import subprocess
import time
import urllib.request

from perf_support import AttachedPerf, metrics

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('matrix', HERE / 'benchmark-matrix.py')
matrix = importlib.util.module_from_spec(spec)
spec.loader.exec_module(matrix)
PATHS = {'blog': '/en/blog/',
         'post': '/en/blog/posts/lorem-ipsum-dolor-sit-amet-consectetur-adipiscing-elit'}
INI = '''opcache.enable=1
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.file_update_protection=0
opcache.memory_consumption=256
opcache.max_accelerated_files=30000
opcache.jit=disable
opcache.jit_buffer_size=0
memory_limit=512M
realpath_cache_size=4M
date.timezone=UTC
display_errors=0
log_errors=1
error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED
'''
PROBE = '''<?php
header('Content-Type: application/json');
$data = ['version' => PHP_VERSION, 'vm' => ZEND_VM_KIND, 'zts' => PHP_ZTS,
    'sapi' => PHP_SAPI, 'ini' => php_ini_loaded_file(), 'scanned' => php_ini_scanned_files(),
    'extensions' => get_loaded_extensions(), 'opcache' => opcache_get_status(false),
    'configuration' => opcache_get_configuration()];
array_walk_recursive($data, static function (&$value): void {
    if (is_float($value) && !is_finite($value)) $value = is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity');
});
echo json_encode($data, JSON_THROW_ON_ERROR);
'''
PREPEND = '''<?php
if (!empty($_SERVER['FRANKENPHP_WORKER'])) {
    $status = opcache_get_status(false);
    array_walk_recursive($status, static function (&$value): void {
        if (is_float($value) && !is_finite($value)) $value = is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity');
    });
    file_put_contents(getenv('VM_BENCH_WORKER_LOG'), json_encode([
        'vm' => ZEND_VM_KIND, 'opcache' => $status,
        'worker' => $_SERVER['FRANKENPHP_WORKER'], 'ini' => php_ini_loaded_file()
    ])."\\n", FILE_APPEND | LOCK_EX);
}
'''
LUA = '''threads = {}
function setup(thread) table.insert(threads, thread) end
function init(args) invalid = 0 end
function response(status, headers, body)
    if status ~= 200 or #body < 5000 then invalid = invalid + 1 end
end
function done(summary, latency, requests)
    local errors = 0
    for _, thread in ipairs(threads) do errors = errors + thread:get("invalid") end
    io.write(string.format("BENCH_INVALID %d\\n", errors))
end
'''


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def cpu_seconds(pid):
    fields = Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()
    return (int(fields[11]) + int(fields[12])) / os.sysconf('SC_CLK_TCK')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('builds', type=Path)
    p.add_argument('--frankenphp', type=Path, required=True)
    p.add_argument('--demo', type=Path, required=True)
    p.add_argument('--wrk', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--variants', nargs='+', default=['hybrid-tuned', 'tailcall-tuned'])
    p.add_argument('--modes', nargs='+', choices=['classic', 'worker'], default=['classic', 'worker'])
    p.add_argument('--routes', nargs='+', choices=PATHS, default=list(PATHS))
    p.add_argument('--runs', type=int, default=8)
    p.add_argument('--duration', type=int, default=5)
    p.add_argument('--warmup', type=int, default=2)
    p.add_argument('--server-cpus', default='4')
    p.add_argument('--client-cpus', default='16,18')
    p.add_argument('--php-threads', type=int, default=1)
    p.add_argument('--connections', type=int, default=2)
    p.add_argument('--client-threads', type=int, default=1)
    p.add_argument('--port', type=int, default=18686)
    p.add_argument('--seed', type=int, default=8623)
    p.add_argument('--perf', action='store_true', help='collect server counters around each measured wrk run')
    args = p.parse_args()
    if args.runs < (3 if args.perf else 4) or min(args.duration, args.warmup, args.php_threads) < 1:
        p.error('need >=4 timing runs (>=3 with --perf) and positive durations/thread counts')
    work = args.output.resolve().with_suffix('.work')
    work.mkdir(parents=True, exist_ok=False)
    demo = args.demo.resolve()
    (work / '__vm_probe.php').write_text(PROBE)
    (work / 'prepend.php').write_text(PREPEND)
    (work / 'check.lua').write_text(LUA)
    ini = work / 'php.ini'
    ini.write_text(INI + f'auto_prepend_file={work / "prepend.php"}\n')
    builds = json.loads(args.builds.read_text())
    result = {'started_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'arguments': {k: str(v) if isinstance(v, Path) else v for k, v in vars(args).items()},
              'builds': builds, 'runner_sha256': digest(__file__), 'ini': ini.read_text(),
              'frankenphp_sha256': digest(args.frankenphp), 'wrk_sha256': digest(args.wrk),
              'demo': {'commit': subprocess.check_output(['git', '-C', str(demo), 'rev-parse', 'HEAD'], text=True).strip(),
                       'lock_sha256': digest(demo / 'composer.lock'),
                       'database_sha256': digest(demo / 'data/database.sqlite'),
                       'production_override': (demo / 'config/packages/prod/benchmark.yaml').read_text()
                            if (demo / 'config/packages/prod/benchmark.yaml').exists() else None,
                       'asset_manifest_sha256': digest(demo / 'public/assets/manifest.json')},
              'lscpu': subprocess.check_output(['lscpu'], text=True), 'uname': list(os.uname()),
              'load_start': list(os.getloadavg()), 'benchmarks': []}
    base = f'http://127.0.0.1:{args.port}'
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    expected_bodies = {}
    rng = random.Random(args.seed)
    for mode in args.modes:
        entries = {}
        for route in args.routes:
            entry = {'mode': mode, 'workload': route, 'path': PATHS[route],
                     'samples': {v: [] for v in args.variants}, 'order': []}
            entries[route] = entry
            result['benchmarks'].append(entry)
        orders = []
        for _ in range((args.runs + len(args.variants) - 1) // len(args.variants)):
            order = args.variants[:]
            rng.shuffle(order)
            orders.extend(order[i:] + order[:i] for i in range(len(order)))
        orders = orders[:args.runs]
        rng.shuffle(orders)
        for block, order in enumerate(orders):
            for variant in order:
                build = builds['builds'][variant]
                lib = Path(build['libphp']).resolve()
                if digest(lib) != build['libphp_sha256']:
                    raise RuntimeError('Library changed since build')
                label = f'{mode}-{block:02d}-{variant}'
                worker_log = work / f'{label}-workers.jsonl'
                cfg = work / f'{label}.Caddyfile'
                count = args.php_threads + (mode == 'worker')
                worker = (f'worker {{\nfile index.php\nnum {args.php_threads}\n}}' if mode == 'worker' else '')
                cfg.write_text(f'''{{
auto_https off
admin off
persist_config off
frankenphp {{
num_threads {count}
max_threads {count}
}}
}}
{base} {{
bind 127.0.0.1
route /__vm_probe.php {{
rewrite * /__vm_probe.php
php {{
root {work}
}}
}}
route {{
rewrite * /index.php
php {{
root {demo / 'public'}
{worker}
}}
}}
}}
''')
                env = dict(os.environ, LD_LIBRARY_PATH=str(lib.parent), PHPRC=str(ini), PHP_INI_SCAN_DIR='',
                           APP_ENV='prod', APP_DEBUG='0', APP_SECRET='local-vm-benchmark',
                           DATABASE_URL=f'sqlite:///{demo}/data/database.sqlite', FRANKENPHP_LOOP_MAX='0',
                           XDG_CONFIG_HOME=str(work / 'config'), XDG_DATA_HOME=str(work / 'data'),
                           VM_BENCH_WORKER_LOG=str(worker_log), GOMAXPROCS=str(args.php_threads),
                           XDEBUG_MODE='off')
                command = ['taskset', '-c', args.server_cpus, str(args.frankenphp),
                           'run', '--config', str(cfg), '--adapter', 'caddyfile']
                log_path = work / f'{label}.log'
                with log_path.open('w') as log:
                    process = subprocess.Popen(command, cwd=demo, env=env, stdout=log, stderr=log)
                    try:
                        deadline = time.monotonic() + 45
                        while True:
                            if process.poll() is not None:
                                raise RuntimeError(f'Server exited: {log_path.read_text()}')
                            try:
                                with opener.open(base + '/__vm_probe.php', timeout=2) as response:
                                    identity = json.load(response)
                                break
                            except Exception:
                                if time.monotonic() > deadline:
                                    raise RuntimeError(f'Server did not start: {log_path.read_text()}')
                                time.sleep(.1)
                        mappings = [s for s in Path(f'/proc/{process.pid}/maps').read_text().splitlines() if 'libphp' in s]
                        if not mappings or any(str(lib) not in s for s in mappings):
                            raise RuntimeError(f'Wrong libphp loaded: {mappings}')
                        vm = 'ZEND_VM_KIND_' + variant.split('-')[0].upper()
                        if (identity['vm'] != vm or not identity['zts'] or identity['sapi'] != 'frankenphp'
                                or not identity['opcache']['opcache_enabled'] or identity['opcache'].get('jit', {}).get('on')
                                or identity['scanned'] or identity['ini'] != str(ini)):
                            raise RuntimeError(f'Unexpected server runtime: {identity}')
                        directives = identity['configuration']['directives']
                        if directives['opcache.optimization_level'] != 0x7ffebfff or directives['opcache.validate_timestamps']:
                            raise RuntimeError(f'Unexpected OPcache configuration: {directives}')
                        for route in args.routes:
                            url = base + PATHS[route]
                            with opener.open(url, timeout=20) as response:
                                body = response.read()
                                if response.status != 200 or len(body) < 5000 or b'Symfony Demo' not in body:
                                    raise RuntimeError('Invalid Symfony page')
                            normalized = re.sub(rb'<!-- (Page|Fragment) rendered on .*? -->',
                                                rb'<!-- \1 rendered on TIMESTAMP -->', body)
                            body_hash = hashlib.sha256(normalized).hexdigest()
                            expected_bodies.setdefault(route, body_hash)
                            if body_hash != expected_bodies[route]:
                                raise RuntimeError(f'Symfony response body changed: {mode}/{variant}/{route}')
                            wrk = ['taskset', '-c', args.client_cpus, str(args.wrk),
                                   '-t', str(args.client_threads), '-c', str(args.connections),
                                   '--timeout', '5s', '--latency', '-s', str(work / 'check.lua')]
                            outputs = []
                            for phase, seconds in enumerate([args.warmup, args.duration]):
                                before = cpu_seconds(process.pid)
                                cmd = wrk + ['-d', f'{seconds}s', url]
                                counters = None
                                collector = AttachedPerf(process.pid, work / f'{label}-{route}.perf') if args.perf and phase else None
                                try:
                                    if collector:
                                        collector.start()
                                    run = subprocess.run(cmd, capture_output=True, text=True, timeout=seconds + 20)
                                finally:
                                    if collector:
                                        counters = collector.stop()
                                cpu = cpu_seconds(process.pid) - before
                                if (run.returncode or 'BENCH_INVALID 0' not in run.stdout
                                        or 'Non-2xx' in run.stdout or 'Socket errors:' in run.stdout):
                                    raise RuntimeError(f'HTTP benchmark failed: {run.stdout}\n{run.stderr}')
                                outputs.append({'command': cmd, 'stdout': run.stdout, 'stderr': run.stderr,
                                                'server_cpu_seconds': cpu})
                                if counters:
                                    outputs[-1]['perf'] = counters
                            sample = outputs[-1]
                            rps = float(re.search(r'Requests/sec:\s+([\d.]+)', sample['stdout'])[1])
                            requests = int(re.search(r'(\d+) requests in', sample['stdout'])[1])
                            if 'perf' in sample:
                                sample['perf']['per_request'] = metrics(sample['perf']['counters'], requests)
                            sample.update({'variant': variant, 'block': block, 'rps': rps, 'requests': requests,
                                           'seconds': 1 / rps, 'cpu_seconds': sample['server_cpu_seconds'] / requests,
                                           'warmup': outputs[0], 'body_sha256': body_hash, 'body_bytes': len(body),
                                           'raw_body_sha256': hashlib.sha256(body).hexdigest(),
                                           'server_command': command, 'configuration': cfg.read_text(),
                                           'libphp_mappings': mappings})
                            for percentile in [50, 90, 99]:
                                m = re.search(rf'^\s+{percentile}%\s+([\d.]+)(us|ms|s)', sample['stdout'], re.M)
                                sample[f'p{percentile}_ms'] = float(m[1]) * {'us': .001, 'ms': 1, 's': 1000}[m[2]]
                            with opener.open(base + '/__vm_probe.php', timeout=5) as response:
                                sample['runtime'] = json.load(response)
                            if sample['runtime']['opcache']['opcache_statistics']['num_cached_scripts'] < 100:
                                raise RuntimeError('Symfony scripts were not cached')
                            if mode == 'worker':
                                workers = [json.loads(s) for s in worker_log.read_text().splitlines()]
                                if len(workers) != args.php_threads or any(
                                        w['vm'] != vm or not w['opcache']['opcache_enabled']
                                        or w['opcache'].get('jit', {}).get('on') for w in workers):
                                    raise RuntimeError('Unexpected worker initialization')
                                sample['workers'] = workers
                            entries[route]['samples'][variant].append(sample)
                            entries[route]['order'].append({'block': block, 'variant': variant})
                            args.output.write_text(json.dumps(result, indent=2) + '\n')
                            print(f'{mode} {route} {variant} block={block}: {rps:.2f} req/s', flush=True)
                    finally:
                        if process.poll() is None:
                            process.send_signal(signal.SIGINT)
                            try:
                                process.wait(timeout=15)
                            except subprocess.TimeoutExpired:
                                process.kill()
                                process.wait()
                if re.search(r'PHP (Fatal|Warning)|panic:|segmentation fault', log_path.read_text(), re.I):
                    raise RuntimeError(f'Server emitted errors: {log_path}')
        for entry in entries.values():
            entry['summary'] = {v: matrix.summarize(s) for v, s in entry['samples'].items()}
            entry['median_rps'] = {v: statistics.median(s['rps'] for s in samples)
                                   for v, samples in entry['samples'].items()}
            entry['comparisons'] = [matrix.compare(entry['samples'], args.variants[0], v) for v in args.variants[1:]]
            throughput = {v: [dict(s, seconds=s['rps']) for s in samples]
                          for v, samples in entry['samples'].items()}
            entry['rps_comparisons'] = []
            for v in args.variants[1:]:
                comparison = matrix.compare(throughput, args.variants[0], v)
                comparison['throughput_change_percent'] = comparison.pop('elapsed_change_percent')
                entry['rps_comparisons'].append(comparison)
    result['load_end'] = list(os.getloadavg())
    result['finished_utc'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
    args.output.write_text(json.dumps(result, indent=2) + '\n')


if __name__ == '__main__':
    main()
