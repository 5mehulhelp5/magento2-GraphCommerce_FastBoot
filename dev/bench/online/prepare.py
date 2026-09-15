#!/usr/bin/env python3
"""Create two private roots from an installed image; compile native and FastBoot DI."""
import argparse
import hashlib
from pathlib import Path
import shutil
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--source', default='/var/www/html')
parser.add_argument('--output', required=True)
args = parser.parse_args()
source, output = Path(args.source).resolve(), Path(args.output).resolve()
if output.exists():
    parser.error('Output must not exist')
if source == output or source in output.parents:
    parser.error('Use an output directory outside the serving application')
output.mkdir(parents=True)
runtime = source / 'var/fastboot-benchmark' / hashlib.sha256(str(output).encode()).hexdigest()[:16]
runtime.mkdir(parents=True)
print(f'Runtime storage: {runtime}', flush=True)
quote = lambda value: "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"
for mode in ['native', 'fastboot']:
    root = output / mode
    root.mkdir()
    for name in ['app', 'bin', 'setup', 'lib', 'vendor']:
        shutil.copytree(source / name, root / name, symlinks=True)
    for name in ['composer.json', 'composer.lock']:
        shutil.copy2(source / name, root / name)
    (runtime / mode).mkdir()
    (root / 'var').symlink_to(runtime / mode, target_is_directory=True)
    (root / 'pub').mkdir()
    for item in (source / 'pub').iterdir():
        target = root / 'pub' / item.name
        if item.is_file():
            shutil.copy2(item, target)
        else:
            target.symlink_to(item, target_is_directory=True)
    for name in ['var/log', 'generated/code', 'generated/metadata']:
        (root / name).mkdir(parents=True, exist_ok=True)
    env = root / 'app/etc/env.php'
    env.unlink()
    env.write_text('''<?php
$config = require ''' + quote(source / 'app/etc/env.php') + ''';
$config['cache_types']['full_page'] = 0;
foreach ($config['cache']['frontend'] as &$frontend) {
    $frontend['id_prefix'] = ''' + quote('fbonline_' + mode + '_') + ''';
}
unset($frontend);
$config['fastboot']['schema_l1']['installation'] .= ''' + quote(':benchmark:' + mode) + ''';
return $config;
''')
    if mode == 'native':
        subprocess.run(['php', '-r', '''$f='app/etc/config.php'; $c=require $f;
foreach (['GraphCommerce_FastBoot','GraphCommerce_FastBootCache','GraphCommerce_FastBootGraphQl','GraphCommerce_FastBootPreload'] as $m) $c['modules'][$m]=0;
file_put_contents($f,"<?php\\nreturn ".var_export($c,true).";\\n");'''], cwd=root, check=True)
    with (output / (mode + '-compile.log')).open('w') as log:
        subprocess.run(['php', '-d', 'memory_limit=2G', 'bin/magento', 'setup:di:compile'],
                       cwd=root, stdout=log, stderr=subprocess.STDOUT, check=True)
    if mode == 'fastboot':
        subprocess.run(['php', 'bin/magento', 'fastboot:prepare'], cwd=root, check=True)
    print(f'{mode}: {root}', flush=True)
