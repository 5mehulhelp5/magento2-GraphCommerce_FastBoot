<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The version of the opcache files: a random token in the cache under the
 * config cache tag. A config cache clean or a flush on any server, and any
 * clean or remove on a covered cache type, gives every server a new
 * version, so the files of the old one are never read again. A server reads
 * the token from the cache once per grace period (di.xml `grace`, seconds)
 * and keeps it in a stamp file in between, so a request within the period
 * opens no cache connection at all; another server's clean reaches it at
 * the end of the period. A grace of 0 reads the token on every request. A
 * flush of the cache removes the token without a bump: the request that
 * finds it missing starts a new version and sweeps the old ones.
 */
class Version implements ResetAfterRequestInterface
{
    private const KEY = 'FASTBOOT_VERSION';

    private ?string $current = null;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly PhpFilesFactory $filesFactory,
        private readonly Filesystem $filesystem,
        private readonly int $grace = 2,
    ) {
    }

    public function current(): string
    {
        if ($this->current === null) {
            $stamp = $this->stamp();
            if ($this->grace > 0 && is_file($stamp) && filemtime($stamp) > time() - $this->grace) {
                $this->current = trim((string)file_get_contents($stamp));
            }
            if (!$this->current) {
                $token = $this->cache->load(self::KEY);
                if (!$token) {
                    $token = bin2hex(random_bytes(6));
                    $this->cache->save($token, self::KEY, [ConfigCache::CACHE_TAG]);
                    $this->filesFactory->create()->sweep();
                }
                $this->current = (string)$token;
                if ($this->grace > 0) {
                    @mkdir(dirname($stamp), 0775, true);
                    @file_put_contents($stamp, $this->current);
                }
            }
        }

        return $this->current;
    }

    public function bump(): void
    {
        $this->cache->remove(self::KEY);
        $this->current = null;
        @unlink($this->stamp());
        $this->filesFactory->create()->sweep();
    }

    /**
     * A worker process keeps this object between requests; the next request
     * reads the token again, within the grace from the stamp.
     */
    public function _resetState(): void
    {
        $this->current = null;
    }

    private function stamp(): string
    {
        $var = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath();

        return rtrim($var, '/') . '/fastboot/.version';
    }
}
