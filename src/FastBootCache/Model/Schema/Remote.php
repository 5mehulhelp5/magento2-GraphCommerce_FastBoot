<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model\Schema;

/** Atomic single-schema-family transport. Not a general Magento cache backend. */
class Remote implements \Magento\Framework\Config\CacheInterface
{
    private ?\Redis $redis = null;
    public function __construct(private array $options)
    {
        foreach (['timeout', 'read_timeout'] as $name) {
            $value = (float)($options[$name] ?? 0.3);
            if (!is_finite($value) || $value <= 0) {
                throw new \InvalidArgumentException('FastBoot Redis '.$name.' must be finite and positive.');
            }
        }
    }
    private function connection(): \Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        $start = hrtime(true);
        $r = new \Redis();
        // Persistent sockets are scoped by database and authentication identity, never shared across installations.
        $persistentId = 'fastboot-schema-'.hash('sha256', json_encode($this->options, JSON_THROW_ON_ERROR));
        $r->pconnect($this->options['host'] ?? '127.0.0.1', (int)($this->options['port'] ?? 6379), (float)($this->options['timeout'] ?? 0.3), $persistentId, 0, (float)($this->options['read_timeout'] ?? 0.3), $this->options['context'] ?? []);
        if (isset($this->options['password']) && $this->options['password'] !== '') {
            $auth = empty($this->options['username']) ? $this->options['password'] : [$this->options['username'], $this->options['password']];
            if (!$r->auth($auth)) {
                throw new \RedisException('FastBoot schema Redis authentication failed');
            }
            Metrics::add('redis_commands');
        }
        $r->setOption(\Redis::OPT_READ_TIMEOUT, (float)($this->options['read_timeout'] ?? 0.3));
        $r->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        if (!$r->select((int)($this->options['database'] ?? 0))) {
            throw new \RedisException('FastBoot schema Redis SELECT failed');
        }
        Metrics::add('redis_commands');
        Metrics::add('connect_ms', (hrtime(true) - $start) / 1e6);
        return $this->redis = $r;
    }
    private function key(): string
    {
        return $this->options['key'];
    }
    private function epochKey(): string
    {
        return $this->options['epoch_key'] ?? $this->key().':epoch';
    }
    public function command(string $method, array $args): mixed
    {
        $r = $this->connection();
        $start = hrtime(true);
        Metrics::add('redis_commands');
        try {
            return $r->$method(...$args);
        } catch (\RedisException $error) {
            // Discard a timed-out socket; do not retry a write whose outcome is unknown.
            $this->redis = null;
            try {
                $r->close();
            } catch (\RedisException) {
            }
            throw $error;
        } finally {
            Metrics::add('redis_ms', (hrtime(true) - $start) / 1e6);
        }
    }
    public function metadata(): ?array
    {
        $start = microtime(true);
        $r = $this->command('eval', ["local v=redis.call('HGET',KEYS[1],'version'); if not v or redis.call('HGET',KEYS[1],'epoch')~=redis.call('GET',KEYS[2]) or redis.call('HEXISTS',KEYS[1],'data')==0 then return {} end; return {v,redis.call('PTTL',KEYS[1])}",[$this->key(),$this->epochKey()],2]);
        if (!$r) {
            return null;
        }
        return ['version' => $r[0],'expires' => $r[1] < 0 ? null : $start + max(0, $r[1]) / 1000];
    }
    public function entry(): ?array
    {
        $start = microtime(true);
        $r = $this->command('eval', ["local v=redis.call('HMGET',KEYS[1],'version','data','hash'); if not v[1] or not v[2] or redis.call('HGET',KEYS[1],'epoch')~=redis.call('GET',KEYS[2]) then return {} end; return {v[1],v[2],v[3],redis.call('PTTL',KEYS[1])}",[$this->key(),$this->epochKey()],2]);
        if (!$r) {
            return null;
        }
        Metrics::add('payload_bytes', strlen($r[1]));
        return ['version' => $r[0],'data' => $r[1],'hash' => $r[2],'expires' => $r[3] < 0 ? null : $start + max(0, $r[3]) / 1000];
    }
    public function load($identifier)
    {
        $entry = $this->entry();
        return $entry === null ? false : $entry[str_ends_with((string)$identifier, ':hash') ? 'hash' : 'data'];
    }
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        // SymfonyL2 writes a separate :hash; our payload save already writes it atomically.
        if (str_ends_with((string)$identifier, ':hash')) {
            return $this->load($identifier) === $data;
        }
        return $this->publish($data, $tags, $lifeTime);
    }
    /** Capture before reading source configuration. A concurrent clean/write/expiry fences publication. */
    public function generation(): string
    {
        return $this->command('eval', ["redis.call('SETNX',KEYS[2],ARGV[1]); redis.call('HSETNX',KEYS[1],'version',ARGV[1]); return redis.call('GET',KEYS[2])..':'..redis.call('HGET',KEYS[1],'version')", [$this->key(), $this->epochKey(), bin2hex(random_bytes(16))], 2]);
    }
    public function publish(string $data, array $tags = [], $lifeTime = null, ?string $expected = null): bool
    {
        $ttl = $lifeTime === false ? 7200 : ($lifeTime === null ? 0 : (int)$lifeTime);
        if ($lifeTime !== null && $lifeTime !== false && $ttl <= 0) {
            return $this->remove('schema');
        }
        $tags = array_values(array_unique(array_merge(['CONFIG'], $tags)));
        $lua = "local epoch=redis.call('GET',KEYS[2]); local v=redis.call('HGET',KEYS[1],'version'); if ARGV[6]~='' and (not epoch or not v or epoch..':'..v~=ARGV[6]) then return 0 end; if not epoch then epoch=ARGV[3]; redis.call('SET',KEYS[2],epoch) end; redis.call('HSET',KEYS[1],'epoch',epoch,'data',ARGV[1],'hash',ARGV[2],'version',ARGV[3],'tags',ARGV[4]); if tonumber(ARGV[5])>0 then redis.call('PEXPIRE',KEYS[1],ARGV[5]) else redis.call('PERSIST',KEYS[1]) end; return 1";
        return (bool)$this->command('eval', [$lua, [$this->key(), $this->epochKey(), $data, hash('sha256', $data), bin2hex(random_bytes(16)), json_encode($tags, JSON_THROW_ON_ERROR), $ttl * 1000, $expected ?? ''], 2]);
    }
    public function remove($identifier)
    {
        return $this->clean();
    }
    public function clean($mode = 'all', array $tags = [])
    {
        $lua = <<<'LUA'
local raw=redis.call('HGET',KEYS[1],'tags')
local have=raw and cjson.decode(raw) or {'CONFIG'}; local want=cjson.decode(ARGV[2]); local matched=0
for _,w in ipairs(want) do for _,h in ipairs(have) do if w==h then matched=matched+1;break end end end
local m=ARGV[1]
if m=='all' or (m=='matchingTag' and matched==#want) or (m=='matchingAnyTag' and matched>0) or (m=='notMatchingTag' and matched==0) then
 redis.call('SET',KEYS[2],ARGV[3]); redis.call('DEL',KEYS[1]); redis.call('HSET',KEYS[1],'version',ARGV[3])
end
return 1
LUA;
        if (!in_array($mode, ['all','old','matchingTag','matchingAnyTag','notMatchingTag'], true)) {
            throw new \InvalidArgumentException('Unsupported clean mode');
        }
        return (bool)$this->command('eval', [$lua, [$this->key(), $this->epochKey(), $mode, json_encode(array_values(array_unique($tags)), JSON_THROW_ON_ERROR), bin2hex(random_bytes(16))], 2]);
    }
    public function test($identifier)
    {
        return $this->metadata() === null ? false : time();
    }
    public function getBackend()
    {
        return $this->connection();
    }
    public function getLowLevelFrontend()
    {
        return $this;
    }
}
