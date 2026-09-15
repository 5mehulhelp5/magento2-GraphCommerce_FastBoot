#!/usr/bin/env python3
"""Compare prepared Magento roots through private FPM masters on the deployed host."""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import re
import signal
import socket
import statistics
import struct
import subprocess
import time
from urllib.parse import urlsplit

QUERIES = {
    'storeConfig': {'query': '{storeConfig{store_code store_name locale base_currency_code}}'},
    'products': {'query': '{products(filter:{price:{from:"0"}},pageSize:24,currentPage:1,sort:{name:ASC}){total_count items{uid sku name url_key price_range{minimum_price{regular_price{value currency} final_price{value currency}}}} page_info{current_page page_size total_pages}}}'},
    'luma': None,
}


def fcgi(port, params, body=b''):
    def record(kind, data):
        return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data

    def length(n):
        return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)

    encoded = b''
    for key, value in params.items():
        key, value = key.encode(), str(value).encode()
        encoded += length(len(key)) + length(len(value)) + key + value
    with socket.create_connection(('127.0.0.1', port), timeout=120) as sock:
        sock.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded)
                     + record(4, b'') + (record(5, body) if body else b'') + record(5, b''))

        def read(n):
            data = b''
            while len(data) < n:
                chunk = sock.recv(n - len(data))
                if not chunk:
                    raise RuntimeError('Unexpected FPM EOF')
                data += chunk
            return data

        output, errors = b'', b''
        while True:
            _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', read(8))
            data = read(size)
            read(padding)
            if kind == 6:
                output += data
            elif kind == 7:
                errors += data
            elif kind == 3:
                break
    return output.decode(errors='replace'), errors.decode(errors='replace')


def fingerprint(response, graphql):
    body = response.split('\r\n\r\n', 1)[1]
    if graphql:
        value = json.loads(body)
        if value.get('errors') or not value.get('data'):
            raise RuntimeError(body[:1500])
        if 'products' in value['data'] and not value['data']['products']['items']:
            raise RuntimeError('Product listing is empty')
        canonical = json.dumps(value, sort_keys=True, separators=(',', ':'))
    else:
        main = re.search(r'<main\b.*?</main>', body, re.S)
        if not main or 'catalog-category-view' not in body or 'product-items' not in main[0]:
            raise RuntimeError('Expected a Luma category product grid')
        canonical = re.sub(r'(name="form_key"[^>]*value=")[^"]+', r'\1FORM_KEY', main[0])
        canonical = re.sub(r'("formKey"\s*:\s*")[^"]+', r'\1FORM_KEY', canonical)
        canonical = re.sub(r'\s+', ' ', canonical).strip()
    return hashlib.sha256(canonical.encode()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', action='append', required=True, help='label=/absolute/root; repeat per mode')
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--category', default='/gear/bags.html')
    parser.add_argument('--store', default='default')
    parser.add_argument('--php-fpm', default='/usr/sbin/php-fpm8.4')
    parser.add_argument('--output', required=True)
    parser.add_argument('--blocks', type=int, default=3)
    parser.add_argument('--warmups', type=int, default=10)
    parser.add_argument('--runs', type=int, default=30)
    parser.add_argument('--workload', action='append', choices=['storeConfig', 'products', 'luma'])
    parser.add_argument('--profile', action='store_true', help='Capture one Magento CSV profile after measured traffic')
    parser.add_argument('--preload', action='append', default=[], help='Enable class preload for this root label; repeatable')
    args = parser.parse_args()
    roots = {label: Path(root).resolve() for label, root in (s.split('=', 1) for s in args.root)}
    if set(args.preload) - roots.keys():
        parser.error('Preload labels must match a root')
    for label in args.preload:
        if not (roots[label] / 'var/cache/preload/classes.txt').is_file():
            parser.error(f'{label}: record or seed the preload class list before running')
    if any(not re.fullmatch(r'[a-z][a-z0-9_-]*', label) for label in roots):
        parser.error('Mode labels must contain lowercase letters, digits, underscores or hyphens')
    if any(not (root / 'pub/index.php').is_file() for root in roots.values()):
        parser.error('Every root must contain pub/index.php')
    if args.blocks < 1 or args.warmups < 2 or args.runs < 2:
        parser.error('Use at least one block, two warmups and two measured requests')
    out = Path(args.output).resolve()
    if out.exists() and any(out.iterdir()):
        parser.error('Output directory must be empty')
    if any(root / 'pub' == out or root / 'pub' in out.parents for root in roots.values()):
        parser.error('Output must be outside the public document root')
    out.mkdir(parents=True, exist_ok=True)
    url = urlsplit(args.base_url)
    if url.scheme not in ['http', 'https'] or not url.hostname or url.username:
        parser.error('Base URL must be an HTTP(S) store URL without credentials')
    queries = dict(QUERIES)
    if args.workload:
        queries = {key: value for key, value in queries.items() if key in args.workload}
    processes, workers, rows, hashes = [], {}, [], {}
    profile_requests = {}
    quote = lambda value: "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"
    try:
        for label, root in roots.items():
            directory = out / label
            directory.mkdir()
            metrics = directory / 'metrics.jsonl'
            wrapper = directory / 'request.php'
            wrapper.write_text('''<?php
$start = hrtime(true);
register_shutdown_function(function () use ($start) {
    $ms = (hrtime(true) - $start) / 1e6;
    $peak = memory_get_peak_usage(true) / 1048576;
    $opcache = opcache_get_status(false);
    $om = \\Magento\\Framework\\App\\ObjectManager::getInstance();
    $fpc = $om->get(\\Magento\\Framework\\App\\Cache\\StateInterface::class)->isEnabled('full_page');
    file_put_contents(''' + quote(metrics) + ''', json_encode([
        'ms' => $ms, 'peak_mib' => $peak, 'status' => http_response_code(),
        'pid' => getmypid(), 'full_page_cache' => $fpc,
        'opcache' => $opcache['memory_usage'] ?? null,
        'opcache_statistics' => $opcache['opcache_statistics'] ?? null,
        'opcache_full' => $opcache['cache_full'] ?? null,
        'preload' => [
            'classes' => count($opcache['preload_statistics']['classes'] ?? []),
            'scripts' => count($opcache['preload_statistics']['scripts'] ?? []),
            'memory_consumption' => $opcache['preload_statistics']['memory_consumption'] ?? 0,
        ],
        'opcache_ini' => array_combine(
            ['memory_consumption','max_accelerated_files','validate_timestamps','revalidate_freq','preload'],
            array_map(fn($key) => ini_get('opcache.'.$key),
                ['memory_consumption','max_accelerated_files','validate_timestamps','revalidate_freq','preload'])
        ),
    ])."\\n", FILE_APPEND);
});
require ''' + quote(root / 'pub/index.php') + ';\n')
            with socket.socket() as probe:
                probe.bind(('127.0.0.1', 0))
                port = probe.getsockname()[1]
            config = directory / 'fpm.conf'
            config.write_text(f'''[global]
error_log = {directory}/fpm.log
daemonize = no
[benchmark]
listen = 127.0.0.1:{port}
pm = static
pm.max_children = 1
pm.max_requests = 0
clear_env = no
catch_workers_output = yes
php_admin_value[memory_limit] = 2G
''')
            preload = root / 'vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php'
            command = [args.php_fpm, '-F', '-y', str(config), '-d',
                       'opcache.preload=' + (str(preload) if label in args.preload else '')]
            environment = dict(os.environ, FASTBOOT_MAGENTO_ROOT=str(root), FASTBOOT_CACHE_DIR=str(root / 'var/cache'))
            with (directory / 'fpm-output.log').open('w') as log:
                process = subprocess.Popen(command, cwd=root, env=environment, stdout=log, stderr=log)
            processes.append(process)
            for _ in range(150):
                if process.poll() is not None:
                    raise RuntimeError(f'{label}: FPM startup failed')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.1)
            else:
                raise RuntimeError(f'{label}: FPM startup timed out')
            workers[label] = (port, root, wrapper, metrics)
        for block in range(args.blocks):
            labels = list(roots)
            offset = block % len(labels)
            labels = labels[offset:] + labels[:offset]
            for workload, query in queries.items():
                for label in labels:
                    port, root, wrapper, metrics = workers[label]
                    body = json.dumps(query).encode() if query else b''
                    path = '/graphql' if query else args.category
                    params = {'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.1',
                              'REQUEST_METHOD': 'POST' if query else 'GET', 'SCRIPT_FILENAME': wrapper,
                              'SCRIPT_NAME': '/index.php', 'REQUEST_URI': path, 'QUERY_STRING': path.partition('?')[2],
                              'DOCUMENT_ROOT': root / 'pub', 'SERVER_NAME': url.hostname, 'HTTP_HOST': url.netloc,
                              'SERVER_PORT': url.port or (443 if url.scheme == 'https' else 80),
                              'HTTPS': 'on' if url.scheme == 'https' else '', 'REMOTE_ADDR': '127.0.0.1',
                              'HTTP_ACCEPT': 'application/json' if query else 'text/html', 'HTTP_STORE': args.store,
                              'MAGE_RUN_CODE': args.store, 'MAGE_RUN_TYPE': 'store',
                              'HTTP_COOKIE': 'PHPSESSID=fastbootonlinebenchmark' + label,
                              'CONTENT_TYPE': 'application/json' if query else '', 'CONTENT_LENGTH': len(body)}
                    profile_requests[label, workload] = (port, root, params, body)
                    block_rows = []
                    for iteration in range(args.warmups + args.runs):
                        if iteration == args.warmups - 1:
                            # Allow the serving pool's file_update_protection window before the final warmup.
                            time.sleep(3)
                        response, errors = fcgi(port, params, body)
                        row = json.loads(metrics.read_text().splitlines()[-1])
                        if row['status'] != 200 or row['full_page_cache'] is not False:
                            (out / 'failed-response.txt').write_text(response + errors)
                            raise RuntimeError(f'Expected status 200 and FPC disabled: {row}')
                        if iteration >= args.warmups and row['opcache_full']:
                            raise RuntimeError(f'{label}: OPcache is full; size the serving pool before comparing')
                        if (row['preload']['classes'] > 0) != (label in args.preload):
                            raise RuntimeError(f'{label}: unexpected FPM preload state: {row["preload"]}')
                        digest = fingerprint(response, query is not None)
                        if workload in hashes and digest != hashes[workload]:
                            (out / 'failed-response.txt').write_text(response)
                            raise RuntimeError(f'Content mismatch: {label}/{workload}/{block}/{iteration}')
                        hashes[workload] = digest
                        row.update(mode=label, workload=workload, block=block, iteration=iteration,
                                   measured=iteration >= args.warmups, content_sha256=digest)
                        rows.append(row)
                        if row['measured']:
                            block_rows.append(row)
                    proc = Path('/proc') / str(row['pid']) / 'smaps_rollup'
                    if proc.exists():
                        (out / f'{label}-{workload}-{block}-smaps.txt').write_text(proc.read_text())
                    print(json.dumps({'mode': label, 'workload': workload, 'block': block,
                                      'median_ms': statistics.median(r['ms'] for r in block_rows),
                                      'peak_mib': max(r['peak_mib'] for r in block_rows)}), flush=True)
                    (out / 'samples.json').write_text(json.dumps(rows, indent=2) + '\n')
        summary = {}
        for workload in queries:
            summary[workload] = {}
            for label in roots:
                samples = [r for r in rows if r['measured'] and r['mode'] == label and r['workload'] == workload]
                summary[workload][label] = {'n': len(samples), 'median_ms': statistics.median(r['ms'] for r in samples),
                    'p95_ms': sorted(r['ms'] for r in samples)[math.ceil(.95 * len(samples)) - 1],
                    'peak_mib': max(r['peak_mib'] for r in samples), 'opcache': samples[-1]['opcache'],
                    'preload': samples[-1]['preload'],
                    'content_sha256': hashes[workload]}
        (out / 'results.json').write_text(json.dumps({'arguments': vars(args), 'summary': summary}, indent=2) + '\n')
        print(json.dumps(summary, indent=2))
        if args.profile:
            for (label, workload), (port, root, params, body) in profile_requests.items():
                fcgi(port, dict(params, MAGE_PROFILER='csvfile', HTTP_ACCEPT='text/html'), body)
                profile = root / 'var/log/profiler.csv'
                if not profile.is_file():
                    raise RuntimeError(f'{label}/{workload}: Magento did not write a profiler CSV')
                (out / f'{label}-{workload}-profile.csv').write_bytes(profile.read_bytes())
    finally:
        for process in processes:
            if process.poll() is None:
                process.send_signal(signal.SIGQUIT)
        for process in processes:
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()


if __name__ == '__main__':
    main()
