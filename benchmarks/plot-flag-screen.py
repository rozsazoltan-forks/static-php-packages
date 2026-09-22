#!/usr/bin/env python3
"""Plot the per-workload screening effects relative to the current tailcall flags."""

import argparse
import json
from pathlib import Path

import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
from matplotlib.colors import TwoSlopeNorm
import numpy as np

p = argparse.ArgumentParser(description=__doc__)
p.add_argument('results', type=Path)
p.add_argument('output', type=Path, help='output basename for SVG and PNG')
args = p.parse_args()
variants = ['tailcall-default', 'tailcall-medium', 'tailcall-extreme', 'tailcall-omitfp',
            'tailcall-align32', 'tailcall-align128', 'tailcall-znver3']
labels, rows = [], []
for file in ['screen-cli.json', 'screen-phpstan.json', 'screen-http.json']:
    data = json.loads((args.results / file).read_text())
    for entry in data['benchmarks']:
        label = entry.get('workload', 'PHPStan')
        if file == 'screen-http.json':
            label = f'Symfony worker: {label}'
        labels.append(label)
        baseline = entry['summary']['tailcall-tuned']['median_seconds']
        rows.append([100 * (entry['summary'][v]['median_seconds'] / baseline - 1) for v in variants])
values = np.asarray(rows)
fig, ax = plt.subplots(figsize=(12, 10))
im = ax.imshow(values, cmap='RdBu_r', norm=TwoSlopeNorm(vmin=-20, vcenter=0, vmax=20), aspect='auto')
ax.set_xticks(range(len(variants)), ['Default\ninlining', 'Medium\ninlining', 'Extreme\ninlining',
                                  'Omit frame\npointers', 'Align\n32 bytes', 'Align\n128 bytes', 'Tune for\nZen 3'])
ax.set_yticks(range(len(labels)), labels)
ax.tick_params(length=0, pad=9)
for row in range(len(labels)):
    for col in range(len(variants)):
        value = values[row, col]
        ax.text(col, row, f'{value:+.1f}%', ha='center', va='center', fontsize=9,
                color='white' if abs(value) > 13 else '#17202a')
ax.axhline(15.5, color='#17202a', linewidth=1.4)
ax.set_title('PHP 8.6 GCC tailcall: compiler flag screening', fontsize=16, loc='left', pad=40)
fig.text(.02, .03, 'Relative to current tailcall flags. Blue = faster; red = slower. '
         'HTTP uses inverse throughput.\nOPcache + LTO enabled; JIT disabled. '
         'Ryzen 9 5950X / GCC 16.2.0. Screening estimates; finalists require confirmation.', fontsize=10)
bar = fig.colorbar(im, ax=ax, pad=.025, shrink=.65, extend='both')
bar.set_label('Time change (%) — color capped at ±20%')
fig.subplots_adjust(left=.24, right=.92, top=.86, bottom=.12)
args.output.parent.mkdir(parents=True, exist_ok=True)
for ext in ['svg', 'png']:
    fig.savefig(args.output.with_suffix('.' + ext), dpi=160, facecolor='white')
print(args.output)
