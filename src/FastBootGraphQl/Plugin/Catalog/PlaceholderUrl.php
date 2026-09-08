<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Plugin\Catalog;

use GraphCommerce\FastBootCache\Model\Feature;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Image\Placeholder;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The placeholder image URL of a store and image type from the cache: core
 * resolves the theme, the design configuration and the static asset context
 * for it on every listing. The entry follows the config and theme tags.
 */
class PlaceholderUrl
{
    private const SWITCH = 'placeholder_url';

    private const KEY = 'FASTBOOT_PLACEHOLDER_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly StoreManagerInterface $storeManager,
        private readonly Feature $feature,
    ) {
    }

    public function aroundGetPlaceholder(Placeholder $subject, callable $proceed, string $imageType): string
    {
        if (!$this->feature->on(self::SWITCH)) {
            return $proceed($imageType);
        }
        $key = self::KEY . $this->storeManager->getStore()->getId() . '_' . $imageType;
        $url = $this->cache->load($key);
        if ($url === false) {
            $url = $proceed($imageType);
            $this->cache->save($url, $key, [ConfigCache::CACHE_TAG, 'THEME']);
        }

        return (string)$url;
    }
}
