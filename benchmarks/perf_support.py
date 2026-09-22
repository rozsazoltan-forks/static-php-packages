"""Process-scoped perf counters and synchronized HTTP measurement windows."""

import os
from pathlib import Path
import select
import signal
import subprocess

EVENTS = ('cycles:u,instructions:u,branches:u,branch-misses:u,cache-references:u,'
          'cache-misses:u,task-clock,context-switches,cpu-migrations,page-faults')


def parse_stat(text):
    counters = {}
    for line in text.splitlines():
        fields = line.split(';')
        if len(fields) < 5 or not fields[2]:
            continue
        name = fields[2].split(':')[0]
        counters[name] = {
            'value': None if fields[0].startswith('<') else float(fields[0]),
            'unit': fields[1], 'event': fields[2],
            'event_runtime_ns': float(fields[3]), 'running_percent': float(fields[4]),
        }
    if any(counters.get(n, {}).get('value') is None for n in ['cycles', 'instructions']):
        raise RuntimeError(f'Essential perf counters unavailable: {text}')
    return counters


def metrics(counters, operations=1):
    c = {k: v['value'] for k, v in counters.items()}
    result = {k + '_per_operation': v / operations for k, v in c.items() if v is not None}
    result['instructions_per_cycle'] = c['instructions'] / c['cycles']
    if c.get('branch-misses') is not None and c.get('branches'):
        result['branch_miss_percent'] = 100 * c['branch-misses'] / c['branches']
    if c.get('cache-misses') is not None:
        result['cache_misses_per_1000_instructions'] = 1000 * c['cache-misses'] / c['instructions']
    return result


class AttachedPerf:
    def __init__(self, pid, output):
        self.pid = pid
        self.output = Path(output)
        self.threads = sorted(int(p.name) for p in Path(f'/proc/{pid}/task').iterdir())
        control_read, self.control = os.pipe()
        self.ack, ack_write = os.pipe()
        self.command = ['perf', 'stat', '-x', ';', '-o', str(output), '-e', EVENTS,
                        '-p', str(pid), '-D', '-1',
                        '--control', f'fd:{control_read},{ack_write}']
        self.process = subprocess.Popen(self.command, pass_fds=(control_read, ack_write),
                                        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
        os.close(control_read)
        os.close(ack_write)

    def control_command(self, message):
        os.write(self.control, (message + '\n').encode())
        answer = os.read(self.ack, 4096) if select.select([self.ack], [], [], 10)[0] else b''
        if answer.rstrip(b'\0\r\n') != b'ack':
            raise RuntimeError(f'perf did not acknowledge {message}: {answer!r}')

    def start(self):
        self.context_before = self.context_switches()
        self.control_command('enable')

    def context_switches(self):
        result = {}
        for task in Path(f'/proc/{self.pid}/task').iterdir():
            try:
                values = {}
                for line in (task / 'status').read_text().splitlines():
                    if line.startswith(('voluntary_ctxt_switches:', 'nonvoluntary_ctxt_switches:')):
                        key, value = line.split(':', 1)
                        values[key] = int(value)
                result[task.name] = values
            except FileNotFoundError:
                continue
        return result

    def stop(self):
        try:
            self.control_command('disable')
        finally:
            self.process.send_signal(signal.SIGINT)
            try:
                _, stderr = self.process.communicate(timeout=10)
            except subprocess.TimeoutExpired:
                self.process.kill()
                _, stderr = self.process.communicate()
            os.close(self.control)
            os.close(self.ack)
        if self.process.returncode not in (0, -signal.SIGINT, 128 + signal.SIGINT):
            raise RuntimeError(f'perf failed: {stderr}')
        raw = self.output.read_text()
        counters = parse_stat(raw)
        context_after = self.context_switches()
        return {'command': self.command, 'initial_threads': self.threads,
                'counters': counters, 'raw': raw, 'stderr': stderr,
                'thread_context_switches_before': self.context_before,
                'thread_context_switches_after': context_after,
                'surviving_thread_context_switch_deltas': {
                    key: sum(values[key] - self.context_before.get(tid, {}).get(key, 0)
                             for tid, values in context_after.items())
                    for key in ['voluntary_ctxt_switches', 'nonvoluntary_ctxt_switches']}}
