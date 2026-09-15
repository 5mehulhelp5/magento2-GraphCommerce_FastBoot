<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Plugin\Schema;

class Invalidate
{
    public function __construct(private \GraphCommerce\FastBootCache\Model\Schema\Settings $settings)
    {
    }
    public function afterClean($subject, $result)
    {
        if ($this->settings->configuredMode() !== 'native') {
            $this->settings->remote()->clean();
        }return $result;
    }
    public function afterSave($subject, $result, $data, $identifier, array $tags = [], $lifeTime = null)
    {
        if ($result) {
            $this->afterRemove($subject, $result, $identifier);
        }
        return $result;
    }
    public function afterRemove($subject, $result, $identifier)
    {
        if ($identifier === 'Magento_Framework_GraphQlSchemaStitching_Config_Data' && $this->settings->configuredMode() !== 'native') {
            $this->settings->remote()->remove($identifier);
        }return $result;
    }
}
