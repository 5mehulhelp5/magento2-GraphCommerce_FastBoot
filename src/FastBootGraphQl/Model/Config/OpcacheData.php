<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Model\Config;

use GraphCommerce\FastBootCache\Model\Schema\{Settings, Metrics};

class OpcacheData extends \Magento\Framework\Config\Data
{
    private \Magento\Framework\Serialize\SerializerInterface $schemaSerializer;
    private \Magento\Framework\Cache\FrontendInterface $schemaCache;
    public function __construct(
        \Magento\Framework\Config\ReaderInterface $reader,
        \Magento\Framework\Config\CacheInterface $cache,
        $cacheId,
        private Settings $settings,
        ?\Magento\Framework\Serialize\SerializerInterface $serializer = null,
        ?array $cacheTags = null,
    ) {
        $this->schemaSerializer = $serializer ?? new \Magento\Framework\Serialize\Serializer\Json();
        $this->schemaCache = $settings->mode() === 'native' ? $cache : $settings->frontend($cache);
        // Parent requires Config CacheInterface. Override initData/reset to use our chosen frontend.
        $this->_reader = $reader;
        $this->_cacheId = $cacheId;
        parent::__construct($reader, $cache, $cacheId, $this->schemaSerializer, $cacheTags);
    }
    protected function initData()
    {
        $start = hrtime(true);
        try {
            if ($this->settings->mode() === 'native') {
                parent::initData();
                return;
            }
            $data = $this->settings->local()->load();
            if ($data === null || $data === false) {
                $generation = $this->settings->remote()->generation();
                $data = $this->_reader->read();
                if (!$this->settings->remote()->publish(json_encode($data, JSON_THROW_ON_ERROR), $this->cacheTags, 7200, $generation)) {
                    Metrics::add('rejected_publications');
                }

            }
            $this->merge($data);
        } finally {
            Metrics::add('schema_ms', (hrtime(true) - $start) / 1e6);
        }
    }
    public function reset()
    {
        if ($this->settings->mode() === 'native') {
            parent::reset();
            return;
        }
        $this->schemaCache->remove($this->_cacheId);
        $this->_data = [];
        $data = $this->_reader->read();
        if ($data) {
            $this->merge($data);
        }
    }
}
