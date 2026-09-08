<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin\Cache;

use GraphCommerce\FastBoot\Model\Cache\PhpFiles;
use GraphCommerce\FastBoot\Model\Cache\Version;
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
    /**
     * @param string[] $types cache tags of the types to cover
     */
    public function __construct(
        private readonly PhpFiles $files,
        private readonly Version $version,
        private readonly array $types = [],
    ) {
    }

    public function aroundLoad(TagScope $subject, callable $proceed, $identifier)
    {
        $tag = $subject->getTag();
        if (!in_array($tag, $this->types, true)) {
            return $proceed($identifier);
        }
        $value = $this->files->read($tag, (string)$identifier);
        if ($value !== null) {
            return $value;
        }
        $value = $proceed($identifier);
        if ($value !== false) {
            $this->files->write($tag, (string)$identifier, $value);
        }

        return $value;
    }

    public function afterSave(TagScope $subject, $result, $data, $identifier, array $tags = [], $lifeTime = null)
    {
        $tag = $subject->getTag();
        if (in_array($tag, $this->types, true)) {
            $this->files->write($tag, (string)$identifier, $data, PhpFiles::lifetime($lifeTime));
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
