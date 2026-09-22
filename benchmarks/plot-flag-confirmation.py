#!/usr/bin/env python3
"""Plot application throughput changes and paired intervals against hybrid."""

import argparse
import importlib.util
import json
from pathlib import Path

import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('results', type=Path)
parser.add_argument('output', type=Path, help='output basename for SVG and PNG')
args = parser.parse_args()
spec = importlib.util.spec_from_file_location('matrix', Path(__file__).with_name('benchmark-matrix.py'))
matrix = importlib.util.module_from_spec(spec)
spec.loader.exec_module(matrix)
variants = ['tailcall-tuned', 'tailcall-omitfp', 'tailcall-medium-omitfp']
labels, rows = [], []
for name in ['final-phpstan', 'final-phpbench', 'final-http-1core', 'final-http-4core']:
    data = json.loads((args.results / f'{name}.json').read_text())
    if not data.get('finished_utc'):
        raise RuntimeError(f'{name} is incomplete')
    for entry in data['benchmarks']:
        if name.startswith('final-http'):
            label = f"Symfony {entry['mode']}: {entry['workload']} ({'1 core' if '1core' in name else '4 cores'})"
            metric = lambda sample: sample['rps']
        elif name == 'final-phpbench':
            label = 'Phoronix PHPBench score'
            metric = lambda sample: sample['score']
        else:
            label = 'PHPStan: analyses per second'
            metric = lambda sample: 1 / sample['seconds']
        samples = {v: [dict(s, seconds=metric(s)) for s in runs]
                   for v, runs in entry['samples'].items()}
        labels.append(label)
        rows.append([matrix.compare(samples, 'hybrid-tuned', v) for v in variants])

fig, ax = plt.subplots(figsize=(12, 8))
colors = ['#2872a3', '#bc4a26', '#387e56']
for column, (label, color) in enumerate(zip(
        ['Current tailcall', 'Tailcall + omit frame pointers', 'Medium inlining + omit frame pointers'], colors)):
    points = [row[column]['elapsed_change_percent'] for row in rows]
    intervals = [row[column]['paired_bootstrap_95ci_percent'] for row in rows]
    positions = [index + (column - 1) * .21 for index in range(len(rows))]
    # Draw the interval separately: a bootstrap percentile interval need not contain the point estimate.
    for y, (low, high) in zip(positions, intervals):
        ax.plot([low, high], [y, y], color=color, linewidth=1.5)
        ax.plot([low, high], [y, y], '|', color=color, markersize=5)
    ax.plot(points, positions, 'o', color=color, markersize=5, label=label)
ax.set_yticks(range(len(labels)), labels)
ax.invert_yaxis()
ax.axvline(0, color='#333333', linewidth=1)
for y in [1.5, 5.5]:
    ax.axhline(y, color='#dddddd', linewidth=.8)
ax.set_xlabel('Throughput change versus hybrid (%) — positive is faster')
ax.grid(axis='x', color='#e5e5e5')
ax.set_axisbelow(True)
ax.spines[['top', 'right', 'left']].set_visible(False)
ax.tick_params(axis='y', length=0)
ax.set_title('PHP 8.6 GCC: application confirmation', fontsize=16, loc='left', pad=52)
ax.legend(loc='lower left', bbox_to_anchor=(0, 1.01), frameon=False, fontsize=9)
fig.text(.025, .025, 'All builds: LTO + OPcache enabled, JIT disabled. Ryzen 9 5950X / GCC 16.2.0.\n'
         'Dots: median-ratio estimates. Lines: 95% paired bootstrap intervals; no correction for multiple comparisons.', fontsize=9)
fig.subplots_adjust(left=.35, right=.97, top=.80, bottom=.13)
args.output.parent.mkdir(parents=True, exist_ok=True)
for extension in ['svg', 'png']:
    fig.savefig(args.output.with_suffix('.' + extension), dpi=160, facecolor='white')
print(args.output)
