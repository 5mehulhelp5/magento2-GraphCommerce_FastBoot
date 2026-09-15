<?php

require (getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 5)).'/vendor/autoload.php';
use GraphCommerce\FastBootCache\Model\Schema\{Remote,Local};

[$script,$port,$dir,$mode] = $argv;
$options = ['host' => '127.0.0.1','port' => (int)$port,'database' => 0,'key' => 'failure-fixture','password' => 'audit-fixture-only','timeout' => 0.15,'read_timeout' => 0.15];
if ($mode === 'persistent-acl') {
    $options['username'] = 'fastboot-fixture';
    $options['password'] = 'acl-fixture-only';
    $options['database'] = 3;
}
$r = new Remote($options);
$l = new Local($r, $dir, 0);
if (in_array($mode, ['persistent', 'persistent-acl'], true)) {
    $r->save('{"ok":1}', 'schema', [], 60);
    $l->load();
    echo "READY\n";
    flush();
    while (($action = trim((string)fgets(STDIN))) !== 'quit') {
        if ($action === 'timeout') {
            try {
                $l->load();
                throw new RuntimeException('Expected timeout');
            } catch (RedisException|CredisException) {
            }
        } elseif ($action === 'recovered') {
            if ($l->load() !== ['ok' => 1]) {
                throw new RuntimeException('Same-client recovery failed');
            }
        } else {
            throw new RuntimeException('Unknown action');
        }
        echo "PASS $action\n";
        flush();
    }
    exit;
}

switch ($mode) {
    case 'acl':
        $r->command('acl', ['SETUSER', 'fastboot-fixture', 'on', '>acl-fixture-only', '~*', '+@all']);
        $options['username'] = 'fastboot-fixture';
        $options['password'] = 'acl-fixture-only';
        $options['database'] = 3;
        $acl = new Remote($options);
        $acl->save('{"acl":true}', 'schema', [], 60);
        if ($acl->load('schema') !== '{"acl":true}' || $r->load('schema') !== '{"ok":1}') {
            throw new RuntimeException('ACL authentication or database isolation failed');
        }
        break;
    case 'seed':$r->save('{"ok":1}', 'schema', [], 60);
        if ($l->load() !== ['ok' => 1]) {
            throw new RuntimeException('Seed failure');
        }break;
    case 'bad-auth':$options['password'] = 'wrong';
        try {
            (new Remote($options))->metadata();
            throw new RuntimeException('Wrong credentials accepted');
        } catch (RedisException|CredisException) {
        }break;
    case 'failed':$start = microtime(true);
        try {
            $l->load();
            throw new RuntimeException('Stale value accepted');
        } catch (RedisException|CredisException) {
        }if (microtime(true) - $start > 2) {
            throw new RuntimeException('Unbounded timeout');
        }break;
    case 'grace':if ((new Local($r, $dir, 60))->load() !== ['ok' => 1]) {
        throw new RuntimeException('Expected bounded grace');
    }break;
    case 'empty':if ($l->load() !== null) {
        throw new RuntimeException('Restart resurrected L1');
    }break;
    default:throw new RuntimeException('Bad mode');
}
echo "PASS $mode\n";
