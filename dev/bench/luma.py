#!/usr/bin/env python3
"""Measure uncached Luma HTML in a dedicated local FPM master; never alter application configuration."""
import argparse
import hashlib
import json
import math
import os
import pathlib
import re
import signal
import socket
import statistics
import struct
import subprocess
import time
import urllib.parse
import uuid


def record(kind, body):
    return struct.pack('!BBHHBB', 1, kind, 1, len(body), 0, 0) + body


def encoded_length(value):
    return bytes([value]) if value < 128 else struct.pack('!I', value | 0x80000000)


def request(port, params):
    payload = b''
    for key, value in params.items():
        key, value = key.encode(), value.encode()
        payload += encoded_length(len(key)) + encoded_length(len(value)) + key + value
    with socket.create_connection(('127.0.0.1', port), timeout=90) as connection:
        connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, payload) + record(4, b'') + record(5, b''))
        output, errors = b'', b''

        def read(size):
            data = b''
            while len(data) < size:
                chunk = connection.recv(size - len(data))
                if not chunk:
                    raise RuntimeError('Unexpected FPM EOF')
                data += chunk
            return data

        while True:
            _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', read(8))
            body = read(size)
            read(padding)
            if kind == 6:
                output += body
            elif kind == 7:
                errors += body
            elif kind == 3:
                break
    return output.decode(errors='replace'), errors.decode(errors='replace')


def fingerprint(response):
    match = re.search(r'<main\b.*?</main>', response, re.S)
    if not match or 'catalog-category-view' not in response or 'products list items product-items' not in match[0]:
        raise RuntimeError('Expected a Luma category product grid, not a redirect, error or static landing page')
    content = re.sub(r'(name="form_key"[^>]*value=")[^"]+', r'\1FORM_KEY', match[0])
    content = re.sub(r'("formKey"\s*:\s*")[^"]+', r'\1FORM_KEY', content)
    content = re.sub(r'\s+', ' ', content).strip()
    return hashlib.sha256(content.encode()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--magento-root', required=True)
    parser.add_argument('--php-fpm', required=True, help='FPM binary matching the installed PHP extensions')
    parser.add_argument('--base-url', required=True, help='Configured store URL, including scheme')
    parser.add_argument('--path', action='append', required=True, help='Category path, optionally with query string; repeatable')
    parser.add_argument('--output', required=True, help='Empty directory outside the public document root')
    parser.add_argument('--label', required=True)
    parser.add_argument('--runs', type=int, default=20)
    parser.add_argument('--warmups', type=int, default=10)
    parser.add_argument('--preload', action='store_true')
    parser.add_argument('--compare', help='Prior results.json whose rendered main content must match')
    args = parser.parse_args()
    root, output = pathlib.Path(args.magento_root).resolve(), pathlib.Path(args.output).resolve()
    if not (root / 'pub/index.php').is_file():
        parser.error('Magento root must contain pub/index.php')
    if output == root / 'pub' or root / 'pub' in output.parents:
        parser.error('Keep benchmark output outside the public document root')
    if output.exists() and any(output.iterdir()):
        parser.error('Output directory must be empty')
    if args.runs < 2 or args.warmups < 1:
        parser.error('Use at least two measured requests and one warmup')
    base = urllib.parse.urlsplit(args.base_url)
    if base.scheme not in ['http', 'https'] or not base.hostname or base.username:
        parser.error('Use a configured HTTP(S) store URL without credentials')
    for path in args.path:
        if not path.startswith('/') or '\n' in path or '\r' in path:
            parser.error('Category paths must start with / and contain no newlines')
    output.mkdir(parents=True, exist_ok=True)
    metrics = output / 'requests.jsonl'
    # Keep PHP literals literal even if local paths contain apostrophes.
    quote = lambda value: "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"
    wrapper = output / 'request.php'
    wrapper.write_text('''<?php
$start = hrtime(true);
register_shutdown_function(function () use ($start) {
    $elapsed = (hrtime(true) - $start) / 1e6;
    $peak = memory_get_peak_usage(true) / 1048576;
    $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    $fpc = null;
    if (class_exists('Magento\\\\Framework\\\\App\\\\ObjectManager', false)) {
        $fpc = \\Magento\\Framework\\App\\ObjectManager::getInstance()
            ->get(\\Magento\\Framework\\App\\Cache\\StateInterface::class)->isEnabled('full_page');
    }
    file_put_contents(''' + quote(metrics) + ''', json_encode([
        'ms' => $elapsed, 'peak_mib' => $peak, 'pid' => getmypid(),
        'status' => http_response_code(), 'full_page_cache' => $fpc,
        'opcache' => $opcache ? $opcache['memory_usage'] : null,
    ])."\\n", FILE_APPEND);
});
require ''' + quote(root / 'pub/index.php') + ';\n')
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    config = output / 'fpm.conf'
    # FPM INI accepts quoted paths; line breaks or quotes cannot be embedded.
    if any(ch in str(output) for ch in ['\n', '\r', '"']):
        parser.error('Output path cannot contain quotes or newlines')
    config.write_text(f'''[global]
error_log = "{output}/fpm.log"
daemonize = no
[benchmark]
listen = 127.0.0.1:{port}
pm = static
pm.max_children = 1
pm.max_requests = 0
clear_env = no
catch_workers_output = yes
php_admin_value[memory_limit] = 512M
''')
    command = [args.php_fpm, '-F', '-y', str(config), '-d', 'opcache.enable=1', '-d', 'opcache.memory_consumption=256',
               '-d', 'opcache.max_accelerated_files=65407', '-d', 'opcache.file_update_protection=0',
               '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.preload=' +
               (str(root / 'vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php') if args.preload else '')]
    env = dict(os.environ, FASTBOOT_MAGENTO_ROOT=str(root))
    expected = json.loads(pathlib.Path(args.compare).read_text())['pages'] if args.compare else {}
    report = {'label': args.label, 'warmups': args.warmups, 'runs': args.runs, 'preload': args.preload, 'pages': {}}
    cookie = 'PHPSESSID=' + uuid.uuid4().hex
    with (output / 'fpm-output.log').open('w') as log:
        process = subprocess.Popen(command, cwd=root, env=env, stdout=log, stderr=log)
        try:
            for attempt in range(150):
                if process.poll() is not None:
                    raise RuntimeError('FPM startup failed; inspect fpm-output.log')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.1)
            else:
                raise RuntimeError('FPM startup timed out')
            for index, path in enumerate(args.path):
                params = {'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.1', 'REQUEST_METHOD': 'GET',
                          'SCRIPT_FILENAME': str(wrapper), 'SCRIPT_NAME': '/index.php', 'REQUEST_URI': path,
                          'QUERY_STRING': path.partition('?')[2], 'DOCUMENT_ROOT': str(root / 'pub'),
                          'SERVER_NAME': base.hostname, 'HTTP_HOST': base.netloc,
                          'SERVER_PORT': str(base.port or (443 if base.scheme == 'https' else 80)),
                          'REMOTE_ADDR': '127.0.0.1', 'HTTP_ACCEPT': 'text/html', 'HTTP_COOKIE': cookie,
                          'HTTP_USER_AGENT': 'FastBoot Luma benchmark'}
                if base.scheme == 'https':
                    params['HTTPS'] = 'on'
                samples, digest = [], None
                for iteration in range(args.warmups + args.runs):
                    response, error = request(port, params)
                    (output / f'page-{index}.html').write_text(response)
                    row = json.loads(metrics.read_text().splitlines()[-1])
                    if row['status'] != 200 or row['full_page_cache'] is not False:
                        raise RuntimeError(f'Need HTTP 200 and disabled full-page cache: {row}; {error[:300]}')
                    current = fingerprint(response)
                    if digest is not None and current != digest:
                        raise RuntimeError(f'Rendered main content changed during {path}')
                    digest = current
                    samples.append(row)
                if args.compare and (path not in expected or expected[path]['content_sha256'] != digest):
                    raise RuntimeError(f'Rendered content differs from comparison for {path}')
                warm = samples[args.warmups:]
                report['pages'][path] = {'median_ms': statistics.median(r['ms'] for r in warm),
                    'p95_ms': sorted(r['ms'] for r in warm)[math.ceil(.95 * len(warm)) - 1],
                    'peak_mib': statistics.median(r['peak_mib'] for r in warm),
                    'cold_ms': samples[0]['ms'], 'cold_peak_mib': samples[0]['peak_mib'],
                    'content_sha256': digest, 'opcache': samples[-1]['opcache'], 'samples': samples}
                request(port, dict(params, MAGE_PROFILER='csvfile'))
                profile = root / 'var/log/profiler.csv'
                if not profile.is_file():
                    raise RuntimeError('Magento did not produce a profiler CSV')
                (output / f'page-{index}-profile.csv').write_bytes(profile.read_bytes())
                print(path, json.dumps({k: v for k, v in report['pages'][path].items() if k != 'samples'}), flush=True)
        finally:
            if process.poll() is None:
                process.send_signal(signal.SIGQUIT)
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
    (output / 'results.json').write_text(json.dumps(report, indent=2) + '\n')


if __name__ == '__main__':
    main()
