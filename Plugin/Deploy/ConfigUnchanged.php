<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin\Deploy;

use Magento\Deploy\Model\DeploymentConfig\ChangeDetector;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;

/**
 * Remembers in the config cache that the deployment configuration matches
 * the hash its import stored, so the flag table is not read on every
 * request: that read is the only reason a request that serves everything
 * else from the cache connects to MySQL. A detected change is not kept, and
 * the config cache clean of a deployment drops the entry.
 */
class ConfigUnchanged
{
    private const KEY = 'FASTBOOT_CONFIG_UNCHANGED_';

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function aroundHasChanges(ChangeDetector $subject, callable $proceed, $sectionName = null)
    {
        $key = self::KEY . (string)$sectionName;
        if ($this->cache->load($key)) {
            return false;
        }
        $changed = (bool)$proceed($sectionName);
        if (!$changed) {
            $this->cache->save('1', $key, [ConfigCache::CACHE_TAG]);
        }

        return $changed;
    }
}
