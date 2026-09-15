<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Plugin;

use GraphCommerce\FastBootCache\Model\PhpFiles;
use GraphCommerce\FastBootCache\Model\Version;
use GraphCommerce\FastBootCache\Model\Feature;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Opcache in front of the cache types that hold configuration: every load
 * of a covered type (di.xml `types`, by tag) answers from a PHP file after
 * the first request of a version, with the entry's string as it is, so the
 * encrypted system config stays encrypted on disk. A clean or a remove on a
 * covered type bumps the version, on every server, since the token lives in
 * the shared cache. An entry with a lifetime lands in a file with its expiry.
 */
class OpcacheLayer
{
    private const SWITCH = 'cache_files';

    /**
     * @param string[] $types cache tags of the types to cover
     */
    public function __construct(
        private readonly PhpFiles $files,
        private readonly Version $version,
        private readonly Feature $feature,
        private readonly \GraphCommerce\FastBootCache\Model\EntryLifetime $lifetime,
        private readonly \Magento\Framework\App\Cache\StateInterface $cacheState,
        private readonly array $types = [],
    ) {
    }

    public function aroundLoad(TagScope $subject, callable $proceed, $identifier)
    {
        $tag = $subject->getTag();
        $type = array_search($tag, $this->types, true);
        if (!is_string($type) || !$this->cacheState->isEnabled($type) || !$this->feature->on(self::SWITCH) || !$this->cacheState->isEnabled('config')) {
            return $proceed($identifier);
        }
        $value = $this->files->read($tag, (string)$identifier);
        if ($value !== null) {
            return $value;
        }
        $generation = $this->version->current();
        $value = $proceed($identifier);
        $ttl = $value === false ? false : $this->lifetime->remaining($subject, (string)$identifier, $value);
        if ($ttl !== false) {
            $this->files->write($tag, (string)$identifier, $value, $ttl, $generation);
        }

        return $value;
    }

    public function afterSave(TagScope $subject, $result, $data, $identifier, array $tags = [], $lifeTime = null)
    {
        $tag = $subject->getTag();
        if ($result && in_array($tag, $this->types, true)) {
            $this->version->bump();
            if ($this->feature->on(self::SWITCH) && $this->cacheState->isEnabled('config')) {
                $ttl = $this->lifetime->remaining($subject, (string)$identifier, $data);
                if ($ttl !== false) {
                    $this->files->write($tag, (string)$identifier, $data, $ttl);
                }
            }
        }

        return $result;
    }

    public function afterRemove(TagScope $subject, $result, $identifier)
    {
        if (in_array($subject->getTag(), $this->types, true)) {
            $this->version->bump();
        }

        return $result;
    }

    public function afterClean(TagScope $subject, $result)
    {
        if (in_array($subject->getTag(), $this->types, true)) {
            $this->version->bump();
        }

        return $result;
    }
}
