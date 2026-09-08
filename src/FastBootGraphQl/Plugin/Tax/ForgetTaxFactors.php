<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Plugin\Tax;

use GraphCommerce\FastBootGraphQl\Plugin\CacheId\GuestTaxFactor;
use Magento\Framework\App\CacheInterface;

/**
 * Drops the cached tax factors after a save or delete on the resource models
 * the factors derive from (di.xml: tax rates, rules, classes, customer groups).
 */
class ForgetTaxFactors
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function afterSave($subject, $result)
    {
        $this->cache->clean([GuestTaxFactor::TAG]);

        return $result;
    }

    public function afterDelete($subject, $result)
    {
        $this->cache->clean([GuestTaxFactor::TAG]);

        return $result;
    }
}
