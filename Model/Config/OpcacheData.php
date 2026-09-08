<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\Config;

use GraphCommerce\FastBoot\Model\Cache\PhpFiles;
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Config\Data;
use Magento\Framework\Config\ReaderInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * A config data set that a request takes from an opcache PHP file instead
 * of the cache entry. A di.xml virtual type of Magento\Framework\Config\Data
 * becomes one of these by its type attribute alone; the reader and the
 * cache id stay. The file is written from the cache entry or the reader on
 * the first request of a version.
 */
class OpcacheData extends Data
{
    private readonly string $fileId;

    public function __construct(
        ReaderInterface $reader,
        CacheInterface $cache,
        $cacheId,
        private readonly PhpFiles $files,
        ?SerializerInterface $serializer = null,
        ?array $cacheTags = null,
    ) {
        $this->fileId = (string)$cacheId;
        parent::__construct($reader, $cache, $cacheId, $serializer, $cacheTags);
    }

    protected function initData()
    {
        $data = $this->files->read('data', $this->fileId);
        if (is_array($data)) {
            $this->merge($data);

            return;
        }
        parent::initData();
        $this->files->write('data', $this->fileId, $this->_data);
    }

    public function reset()
    {
        $this->files->remove('data', $this->fileId);
        parent::reset();
    }
}
