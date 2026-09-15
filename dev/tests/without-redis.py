#!/usr/bin/env python3
"""Run a command and its PHP children without ext-redis; leave system PHP settings untouched."""
import json
import os
import pathlib
import re
import shlex
import shutil
import subprocess
import sys
import tempfile

command = sys.argv[1:]
if command[:1] == ['--']:
    command = command[1:]
if not command:
    sys.exit('Usage: python3 dev/tests/without-redis.py -- COMMAND [ARG ...]')
php = shutil.which('php')
files = json.loads(subprocess.check_output([
    php, '-r', 'echo json_encode([php_ini_loaded_file(), php_ini_scanned_files()]);'
], text=True))
sources = ([files[0]] if files[0] else []) + ([p.strip() for p in files[1].split(',') if p.strip()] if files[1] else [])
with tempfile.TemporaryDirectory(prefix='fastboot-no-redis-') as temporary:
    root = pathlib.Path(temporary)
    ini = root / 'php.ini'
    # Match extension=redis, redis.so, or an absolute module path, with optional quotes/comments.
    extension = re.compile(r'^\s*extension\s*=\s*["\']?(?:[^\n;"\']*/)?(?:php_)?redis(?:\.(?:so|dll))?["\']?\s*(?:;.*)?$', re.I)
    lines = []
    for source in sources:
        lines.extend(line for line in pathlib.Path(source).read_text().splitlines() if not extension.match(line))
    ini.write_text('\n'.join(lines) + '\n')
    wrapper = root / 'php'
    wrapper.write_text('#!/bin/sh\nexec ' + shlex.quote(php) + ' -c ' + shlex.quote(str(ini)) + ' "$@"\n')
    wrapper.chmod(0o700)
    env = dict(os.environ, PHP_INI_SCAN_DIR='', PATH=str(root) + os.pathsep + os.environ.get('PATH', ''))
    subprocess.run(['php', '-r', 'if (extension_loaded("redis") || class_exists("Redis", false)) exit(1); echo "PASS ext-redis is absent\\n";'], env=env, check=True)
    sys.exit(subprocess.run(command, env=env).returncode)
