<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin\Store;

use GraphCommerce\FastBootCache\Model\Feature;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Group;

/**
 * The default store of a group from the store repository, which holds the
 * resolved stores of the request, instead of a load of the group's store
 * collection: the Store header of every GraphQL request asks for it.
 */
class DefaultStore
{
    private const SWITCH = 'default_store';

    public function __construct(
        private readonly StoreRepositoryInterface $stores,
        private readonly Feature $feature,
    ) {
    }

    public function aroundGetDefaultStore(Group $group, callable $proceed)
    {
        $id = (int)$group->getDefaultStoreId();
        if (!$id || !$this->feature->on(self::SWITCH)) {
            return $proceed();
        }
        try {
            $store = $this->stores->getById($id);
        } catch (NoSuchEntityException) {
            return $proceed();
        }

        return (int)$store->getGroupId() === (int)$group->getId() ? $store : $proceed();
    }
}
