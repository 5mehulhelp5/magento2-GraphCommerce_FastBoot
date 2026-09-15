<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Plugin\Schema;

class Flush
{
    public function __construct(private \GraphCommerce\FastBootCache\Model\Schema\Settings $settings)
    {
    }
    public function afterClean($subject, $result, $tags = [])
    {
        if ($this->settings->configuredMode() !== 'native') {
            $this->settings->remote()->clean($tags ? 'matchingAnyTag' : 'all', (array)$tags);
        }return $result;
    }
    public function afterFlush($subject, $result, $types)
    {
        if ($types && $this->settings->configuredMode() !== 'native') {
            $this->settings->remote()->clean();
        }return $result;
    }
}
