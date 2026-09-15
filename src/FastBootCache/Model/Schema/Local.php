<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model\Schema;

/** One schema per namespace. Local stamp is shared across FPM processes, not servers. */
class Local
{
    public function __construct(private Remote $remote, private string $directory, private float $grace, private int $maxFiles = 16, private int $maxBytes = 33554432)
    {
        if (!is_finite($grace) || $grace < 0) {
            throw new \InvalidArgumentException('Grace must be finite and nonnegative');
        }
    }
    private function stamp(): ?array
    {
        $file = $this->directory.'/stamp.json';
        if (!is_file($file)) {
            return null;
        }
        $v = json_decode((string)@file_get_contents($file), true);
        return is_array($v) && isset($v['version'],$v['hash'],$v['checked']) && is_string($v['version']) && is_string($v['hash']) && is_numeric($v['checked']) && array_key_exists('expires', $v) && ($v['expires'] === null || is_numeric($v['expires'])) && preg_match('/^[a-f0-9]{64}$/D', $v['hash']) ? $v : null;
    }
    private function alive(array $s): bool
    {
        return $s['expires'] === null || $s['expires'] > microtime(true);
    }
    private function value(array $s): ?array
    {
        $file = $this->directory.'/'.$s['hash'].'.php';
        if (!is_file($file)) {
            return null;
        }
        try {
            $v = @include $file;
        } catch (\ParseError $error) {
            $v = null;
        }
        if (!is_array($v)) {
            // Broken cache files must be removed so the next promotion can repair them.
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            @unlink($file);
        }
        return is_array($v) ? $v : null;
    }
    private function atomic(string $path, string $bytes): bool
    {
        $tmp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
    public function load(): ?array
    {
        $s = $this->stamp();
        $now = microtime(true);
        if ($s && $this->alive($s) && $now >= $s['checked'] && $now - $s['checked'] < $this->grace && ($v = $this->value($s)) !== null) {
            Metrics::add('local_hits');
            return $v;
        }
        // Strict warm hits validate independently; do not serialize all FPM readers on a file lock.
        if ($this->grace <= 0 && $s) {
            $meta = $this->remote->metadata();
            if ($meta === null) {
                return null;
            }
            if ($s['version'] === $meta['version'] && $this->alive($meta) && ($v = $this->value($s)) !== null) {
                Metrics::add('validated_hits');
                return $v;
            }
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return $this->fallback();
        }
        $lock = @fopen($this->directory.'/validate.lock', 'c');
        if (!$lock) {
            return $this->fallback();
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                return $this->fallback();
            }
            // Recheck after acquiring the process-shared lock to coalesce grace refreshes.
            $s = $this->stamp();
            $checked = microtime(true);
            if ($s && $this->alive($s) && $checked >= $s['checked'] && $checked - $s['checked'] < $this->grace && ($v = $this->value($s)) !== null) {
                Metrics::add('local_hits');
                return $v;
            }
            $meta = $this->remote->metadata();
            if ($meta === null) {
                @unlink($this->directory.'/stamp.json');
                return null;
            }
            if ($s && $s['version'] === $meta['version'] && $this->alive($meta) && ($v = $this->value($s)) !== null) {
                if ($this->grace > 0) {
                    $s['checked'] = $checked;
                    $s['expires'] = $meta['expires'];
                    $this->atomic($this->directory.'/stamp.json', json_encode($s, JSON_THROW_ON_ERROR));
                }
                Metrics::add('validated_hits');
                return $v;
            }
            $e = $this->remote->entry();
            if ($e === null || !$this->alive($e)) {
                @unlink($this->directory.'/stamp.json');
                return null;
            }
            $v = json_decode($e['data'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($v) || !hash_equals($e['hash'], hash('sha256', $e['data']))) {
                throw new \RuntimeException('Schema record mismatch');
            }
            $file = $this->directory.'/'.$e['hash'].'.php';
            if (!is_file($file)) {
                $files = glob($this->directory.'/*.php') ?: [];
                $content = "<?php\nreturn ".var_export($v, true).";\n";
                if (count($files) >= $this->maxFiles || array_sum(array_map('filesize', $files)) + strlen($content) > $this->maxBytes) {
                    Metrics::add('admission_rejections');
                    return $v;
                }
            }
            // Content-addressed files are never overwritten; identical rebuilds reuse OPcache.
            if (!is_file($file) && !$this->atomic($file, "<?php\nreturn ".var_export($v, true).";\n")) {
                return $v;
            }
            $this->atomic($this->directory.'/stamp.json', json_encode(['version' => $e['version'],'hash' => $e['hash'],'checked' => $checked,'expires' => $e['expires']], JSON_THROW_ON_ERROR));
            Metrics::add('promotions');
            return $v;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    private function fallback(): ?array
    {
        $e = $this->remote->entry();
        if ($e === null || !$this->alive($e)) {
            return null;
        }
        $v = json_decode($e['data'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($v) || !hash_equals($e['hash'], hash('sha256', $e['data']))) {
            throw new \RuntimeException('Schema record mismatch');
        }
        return $v;
    }
}
