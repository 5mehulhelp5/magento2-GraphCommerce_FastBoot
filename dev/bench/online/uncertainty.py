#!/usr/bin/env python3
"""Estimate uncertainty in differences of medians by resampling paired blocks."""
import argparse
import csv
import json
from pathlib import Path
import random
import statistics


def interval(values):
    values = sorted(values)

    def percentile(fraction):
        position = (len(values) - 1) * fraction
        lower = int(position)
        upper = min(lower + 1, len(values) - 1)
        return values[lower] + (values[upper] - values[lower]) * (position - lower)

    return [percentile(.025), percentile(.975)]


def summarize(rows, resamples, seed):
    rows = [row for row in rows if row['measured'] == '1']
    if not rows:
        raise ValueError('No measured requests')
    report = {'method': 'Paired block bootstrap of differences of pooled medians',
              'resamples': resamples, 'seed': seed, 'confidence': .95, 'workloads': {}}
    rng = random.Random(seed)
    for workload in dict.fromkeys(row['workload'] for row in rows):
        selected = [row for row in rows if row['workload'] == workload]
        blocks = sorted({int(row['block']) for row in selected})
        modes = list(dict.fromkeys(row['mode'] for row in selected))
        groups = {(mode, block): [float(row['php_ms']) for row in selected
                                 if row['mode'] == mode and int(row['block']) == block]
                  for mode in modes for block in blocks}
        counts = {len(values) for values in groups.values()}
        if len(blocks) < 2 or len(counts) != 1 or 0 in counts:
            raise ValueError('Expected at least two complete, equally sized paired blocks')
        medians = {mode: statistics.median(value for block in blocks for value in groups[mode, block])
                   for mode in modes}
        pairs = [('native', 'fastboot'), ('native', 'preload'), ('fastboot', 'preload')]
        if any(mode not in modes for pair in pairs for mode in pair):
            raise ValueError('Expected native, fastboot and preload modes')
        bootstraps = {pair: [] for pair in pairs}
        for _ in range(resamples):
            draw = rng.choices(blocks, k=len(blocks))
            estimates = {mode: statistics.median(value for block in draw for value in groups[mode, block])
                         for mode in modes}
            for a, b in pairs:
                bootstraps[a, b].append(estimates[a] - estimates[b])
        comparisons = {}
        for a, b in pairs:
            differences = [statistics.median(groups[a, block]) - statistics.median(groups[b, block])
                           for block in blocks]
            comparisons[f'{a}_minus_{b}'] = {
                'saving_ms': medians[a] - medians[b], 'ci95_ms': interval(bootstraps[a, b]),
                'block_saving_range_ms': [min(differences), max(differences)],
                'positive_blocks': sum(value > 0 for value in differences),
            }
        report['workloads'][workload] = {'blocks': len(blocks),
                                       'measured_per_mode': len(blocks) * next(iter(counts)),
                                       'comparisons': comparisons}
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('samples', type=Path, help='Published samples.csv')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--resamples', type=int, default=10000)
    parser.add_argument('--seed', type=int, default=20260915)
    args = parser.parse_args()
    if args.resamples < 100:
        parser.error('Use at least 100 bootstrap resamples')
    if args.output.exists():
        parser.error('Output must not exist')
    with args.samples.open(newline='') as file:
        result = summarize(list(csv.DictReader(file)), args.resamples, args.seed)
    args.output.write_text(json.dumps(result, indent=2) + '\n')


if __name__ == '__main__':
    main()
