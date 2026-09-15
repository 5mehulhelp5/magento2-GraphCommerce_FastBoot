<?php

declare(strict_types=1);
require (getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 5)).'/vendor/autoload.php';
use GraphCommerce\FastBootCache\Model\Schema\{Remote,Local,Metrics};

function check($v, string $m): void
{
    if (!$v) {
        throw new RuntimeException($m);
    }echo "PASS $m\n";
}
$options = ['key' => 'fba:schema-edge:'.bin2hex(random_bytes(8)),'database' => 12];
$r = new Remote($options);
$root = (getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 5)).'/var/schema-edge/'.bin2hex(random_bytes(8));
$a = new Local($r, $root, 0);
try {
    $generation = $r->generation();
    $r->clean('matchingAnyTag', ['CONFIG']);
    check(!$r->publish('{"old":1}', [], 20, $generation) && $r->metadata() === null, 'config clean fences an in-flight first build');
    $generation = $r->generation();
    $r->remove('schema');
    check(!$r->publish('{"old":1}', [], 20, $generation), 'remove fences a cold build');
    $generation = $r->generation();
    check($r->publish('{"v":1}', [], 20, $generation), 'unchanged generation publishes');
    check(!$r->publish('{"v":2}', [], 20, $generation) && $a->load() === ['v' => 1], 'a competing rebuild cannot overwrite the winner');
    $generation = $r->generation();
    $r->command('del', [$options['key']]);
    check(!$r->publish('{"old":1}', [], 20, $generation), 'physical Redis eviction also fences publication');
    $r->save('{"v":3}', 'schema', [], 20);
    $a->load();
    $file = $root.'/'.hash('sha256', '{"v":3}').'.php';
    unlink($file);
    check($a->load() === ['v' => 3], 'missing PHP file repromotes');
    file_put_contents($file, '<?php this is invalid syntax');
    check($a->load() === ['v' => 3], 'PHP syntax corruption repairs from Redis');
    file_put_contents($file, '<?php return false;');
    check($a->load() === ['v' => 3], 'non-array local file repairs from Redis');
    $stamp = json_decode(file_get_contents($root.'/stamp.json'), true);
    $stamp['checked'] = microtime(true) + 3600;
    file_put_contents($root.'/stamp.json', json_encode($stamp));
    $r->save('{"v":4}', 'schema', [], 20);
    check((new Local($r, $root, 60))->load() === ['v' => 4], 'future validation stamp does not extend grace');
    $r->save('{"v":5}', 'schema', [], 20);
    $a->load();
    $r->clean('notMatchingTag', ['CONFIG']);
    check($a->load() === ['v' => 5], 'inverse clean retains matching schema');
    $r->clean('notMatchingTag', ['OTHER']);
    check($a->load() === null, 'inverse clean invalidates nonmatching schema');
    $r->save('{"v":6}', 'schema', [], 20);
    $race = new class ($options) extends Remote {
        public function entry(): ?array
        {
            $this->remove('schema');
            return parent::entry();
        }
    };
    check((new Local($race, $root.'/race', 0))->load() === null, 'invalidation between metadata and payload read returns miss');
    $r->save('{"v":7}', 'schema', [], 20);
    $r->command('hSet', [$options['key'],'hash',str_repeat('0', 64)]);
    foreach ([$root.'/fresh',$root.'/blocked/subdir'] as $dir) {
        if (str_contains($dir, 'blocked')) {
            file_put_contents($root.'/blocked', 'file');
        }try {
            (new Local($r, $dir, 0))->load();
            check(false, 'damaged remote hash should fail');
        } catch (RuntimeException $e) {
            check($e->getMessage() === 'Schema record mismatch', 'remote hash mismatch rejected including disk-failure fallback');
        }
    }
    $expired = new class ($options) extends Remote {
        public function entry(): ?array
        {
            return ['version' => 'v','data' => '{"v":8}','hash' => hash('sha256', '{"v":8}'),'expires' => microtime(true) - 1];
        }
    };
    check((new Local($expired, $root.'/blocked/subdir', 0))->load() === null, 'disk-failure fallback does not revive expired data');
    $r->save('{"v":9}', 'schema', [], 20);
    $a->load();
    $otherOptions = $options;
    $otherOptions['key'] .= ':other';
    $other = new Remote($otherOptions);
    $other->save('{"v":10}', 'schema', [], 20);
    check((new Local($other, $root, 0))->load() === ['v' => 10] && $a->load() === ['v' => 9], 'even accidentally shared local root validates remote identity via unique revision');
    $other->command('del', [$otherOptions['key'],$otherOptions['key'].':epoch']);
    $rolling = $options;
    $rolling['key'] .= ':release-b';
    $rolling['epoch_key'] = $options['key'].':epoch';
    $releaseB = new Remote($rolling);
    $r->save('{"release":"a"}', 'schema', [], 20);
    $releaseB->save('{"release":"b"}', 'schema', [], 20);
    $localB = new Local($releaseB, $root.'/release-b', 0);
    check($a->load() === ['release' => 'a'] && $localB->load() === ['release' => 'b'], 'rolling releases keep distinct schemas');
    $pending = $releaseB->generation();
    $r->clean('matchingAnyTag', ['CONFIG']);
    check($a->load() === null && $localB->load() === null && !$releaseB->publish('{"stale":1}', [], 20, $pending), 'one release config clean invalidates both releases and fences both builds');
    $r->command('del', [$options['key'].':epoch']);
    check($releaseB->metadata() === null, 'evicted installation epoch never revives old data');
    $releaseB->command('del', [$rolling['key']]);
    $cap = new Local($r, $root.'/bounded', 0, 1, 1000000);
    $r->save('{"v":11}', 'schema', [], 20);
    $cap->load();
    $r->save('{"v":12}','schema',[],20);
    check($cap->load() === ['v' => 12] && count(glob($root.'/bounded/*.php')) === 1,'schema admission cap keeps correct Redis fallback without growing compiled file count');
    try {
        new Local($r,$root,-1);
        check(false,'negative grace');
    } catch (InvalidArgumentException) {
        check(true,'negative grace rejected');
    }
} finally {
    $r->command('del',[$options['key'],$options['key'].':epoch']);
}
