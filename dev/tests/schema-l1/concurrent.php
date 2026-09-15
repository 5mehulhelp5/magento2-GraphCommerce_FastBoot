<?php

require (getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 5)).'/vendor/autoload.php';
use GraphCommerce\FastBootCache\Model\Schema\{Remote,Local,Metrics};

$root = $argv[1];
$mode = $argv[2];
$r = new Remote(json_decode(file_get_contents($root.'/options.json'), true));
while (!is_file($root.'/go')) {
    usleep(1000);
}
if ($mode === 'writer') {
    for ($i = 1;$i <= 30;$i++) {
        $r->save(json_encode(['v' => $i,'body' => str_repeat((string)$i, 500)]), 'schema', [], 30);
    }
    echo json_encode(['writer' => true]);
    exit;
}
$l = new Local($r, $root.'/node-'.($mode === 'cold' ? 'cold' : $argv[3]), $mode === 'cold' ? 2 : 0);
$iterations = $mode === 'cold' ? 1 : 50;
for ($i = 0;$i < $iterations;$i++) {
    $v = $l->load();
    if ($v === null || $v['body'] !== str_repeat((string)$v['v'], 500)) {
        throw new RuntimeException('Torn record');
    }
}
echo json_encode(['metrics' => Metrics::$values,'iterations' => $iterations]);
