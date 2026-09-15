#!/usr/bin/env python3
"""Measure the public FastBoot endpoint with curl, including connection and TLS time."""
import argparse
import hashlib
import json
import math
import re
from pathlib import Path
import statistics
import subprocess
import tempfile
import time
import uuid
from urllib.parse import urlsplit

from compare import QUERIES


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--store', required=True)
    parser.add_argument('--category', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--warmups', type=int, default=5)
    parser.add_argument('--runs', type=int, default=20)
    args = parser.parse_args()
    base = args.base_url.rstrip('/')
    if urlsplit(base).scheme != 'https' or not args.category.startswith('/'):
        parser.error('Use an HTTPS base URL and a category path starting with /')
    output = Path(args.output)
    if output.exists():
        parser.error('Output file must not exist')
    if args.runs < 2 or args.warmups < 1:
        parser.error('Use at least two measured requests and one warmup')
    report = {'arguments': vars(args), 'connection': 'new curl process and TLS connection per request',
              'compression': 'negotiated with curl --compressed', 'workloads': {}}
    with tempfile.TemporaryDirectory(prefix='fastboot-http-') as temp:
        directory = Path(temp)
        body, headers, payload, cookies = [directory / name for name in ['body', 'headers', 'query.json', 'cookies']]
        for workload, query in QUERIES.items():
            rows, expected = [], None
            if query:
                payload.write_text(json.dumps(query))
            for index in range(args.warmups + args.runs):
                if index == args.warmups - 1:
                    time.sleep(3)
                url = base + ('/graphql' if query else args.category + ('&' if '?' in args.category else '?')
                              + 'fastboot_benchmark=' + uuid.uuid4().hex)
                command = ['curl', '-sS', '--compressed', '--max-time', '120', '-o', str(body), '-D', str(headers),
                           '-b', str(cookies), '-c', str(cookies), '-H', 'Store: ' + args.store,
                           '-w', '{"status":%{http_code},"ttfb_s":%{time_starttransfer},"total_s":%{time_total},"connect_s":%{time_connect},"tls_s":%{time_appconnect}}']
                if query:
                    command += ['-H', 'Content-Type: application/json', '--data-binary', '@' + str(payload)]
                row = json.loads(subprocess.check_output(command + [url], text=True))
                if row['status'] != 200:
                    raise RuntimeError(f'{workload}: HTTP {row["status"]}')
                content = body.read_text()
                if query:
                    data = json.loads(content)
                    if data.get('errors') or not data.get('data'):
                        raise RuntimeError(f'{workload}: GraphQL error')
                    if workload == 'products' and len(data['data']['products']['items']) != 24:
                        raise RuntimeError('Expected 24 products')
                    digest = hashlib.sha256(json.dumps(data, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
                    if expected and expected != digest:
                        raise RuntimeError(f'{workload}: response changed')
                    expected = digest
                elif ('catalog-category-view' not in content or 'product-items' not in content
                      or len(re.findall(r'<html\b', content, re.I)) != 1):
                    raise RuntimeError('Expected a Luma category product grid')
                cache = [line.partition(':')[2].strip() for line in headers.read_text().splitlines()
                         if line.lower().startswith('x-magento-cache-debug:')]
                row['cache'] = cache[-1] if cache else None
                if row['cache'] not in ['MISS', 'UNCACHEABLE']:
                    raise RuntimeError(f'{workload}: expected uncached response, got {row["cache"]}')
                row['measured'] = index >= args.warmups
                rows.append(row)
            samples = [r for r in rows if r['measured']]
            summary = {'n': len(samples), 'median_ttfb_ms': statistics.median(r['ttfb_s'] for r in samples) * 1000,
                       'p95_ttfb_ms': sorted(r['ttfb_s'] for r in samples)[math.ceil(.95 * len(samples)) - 1] * 1000,
                       'median_total_ms': statistics.median(r['total_s'] for r in samples) * 1000,
                       'content_sha256': expected, 'samples': rows}
            report['workloads'][workload] = summary
            print(workload, json.dumps({k: v for k, v in summary.items() if k != 'samples'}), flush=True)
            output.write_text(json.dumps(report, indent=2) + '\n')


if __name__ == '__main__':
    main()
