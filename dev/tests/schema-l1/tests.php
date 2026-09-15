<?php

declare(strict_types=1);
require (getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 5)).'/vendor/autoload.php';
use GraphCommerce\FastBootCache\Model\Schema\{Remote,Local,Metrics};

function check($value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }echo "PASS $message\n";
}
$root = (getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 5)).'/var/schema-l1-tests/'.bin2hex(random_bytes(6));
$options = ['key' => 'fba:schema-lab:test:'.bin2hex(random_bytes(8)),'database' => 12];
$r = new Remote($options);
$a = new Local($r, $root.'/a', 0);
$b = new Local($r, $root.'/b', 0);
try {
    check(extension_loaded('redis') ? $r->getBackend() instanceof \Redis : $r->getBackend() instanceof \Credis_Client, 'available Redis client selected automatically');
    $r->save('{"v":1}', 'schema', ['CONFIG'], 20);
    check($a->load() === ['v' => 1] && $b->load() === ['v' => 1], 'two local roots promote from shared Redis');
    $r->save('{"v":2}', 'schema', ['CONFIG'], 20);
    check($b->load() === ['v' => 2] && $a->load() === ['v' => 2], 'strict readers observe completed remote writes');
    $r->clean('matchingAnyTag', ['unrelated']);
    check($a->load() === ['v' => 2], 'unrelated tag keeps schema');
    $r->clean('matchingTag', ['CONFIG','missing']);
    check($a->load() === ['v' => 2], 'all-tag clean requires every tag');
    $r->clean('matchingAnyTag', ['CONFIG']);
    check($a->load() === null && $b->load() === null, 'matching tag invalidates both nodes');
    $r->save('{"v":3}', 'schema', [], 20);
    $g = new Local($r, $root.'/grace', 0.15);
    check($g->load() === ['v' => 3], 'grace node seeded');
    $r->save('{"v":4}', 'schema', [], 20);
    Metrics::$values = [];
    check($g->load() === ['v' => 3] && (Metrics::$values['redis_commands'] ?? 0) === 0, 'within grace local hit makes zero Redis commands');
    usleep(180000);
    check($g->load() === ['v' => 4], 'after grace node observes remote change');
    $r->save('{"v":5}', 'schema', [], 1);
    $ttl = new Local($r, $root.'/ttl', 60);
    check($ttl->load() === ['v' => 5], 'TTL node seeded');
    usleep(1050000);
    check($ttl->load() === null, 'entry TTL overrides longer validation grace');
    $r->save('{"v":6}', 'schema', [], 20);
    $a->load();
    $r->remove('schema');
    check($a->load() === null, 'explicit delete does not serve old local data');
    $r->save('{"v":7}', 'schema', [], 20);
    $a->load();
    $failed = new class ($options) extends Remote {
        public function metadata(): ?array
        {
            throw new \CredisException('injected outage');
        }public function entry(): ?array
        {
            throw new \CredisException('injected outage');
        }
    };
    try {
        (new Local($failed, $root.'/a', 0))->load();
        check(false, 'outage must not return stale data');
    } catch (\CredisException $e) {
        check(true, 'strict Redis failure propagates without serving stale data');
    }
    check($a->load() === ['v' => 7], 'reader recovers after Redis returns');
    file_put_contents($root.'/a/stamp.json', 'broken');
    check($a->load() === ['v' => 7], 'malformed stamp recovers from Redis');
    $before = count(glob($root.'/a/*.php'));
    for ($i = 0;$i < 10;$i++) {
        $r->remove('schema');
        $r->save('{"v":7}', 'schema', [], 20);
        check($a->load() === ['v' => 7], 'identical rebuild '.$i);
    }
    check(count(glob($root.'/a/*.php')) === $before, 'identical schema invalidations reuse the PHP file');
    file_put_contents($root.'/block', 'file');
    check((new Local($r, $root.'/block/nested', 0))->load() === ['v' => 7], 'unwritable local directory falls back to remote');
    // Stash only private test connection settings for concurrent processes.
    file_put_contents($root.'/options.json',json_encode($options));
    echo 'CONCURRENCY_ROOT='.$root."\n";
} finally {
    $r->command('del',[$options['key'],$options['key'].':epoch']);
}
