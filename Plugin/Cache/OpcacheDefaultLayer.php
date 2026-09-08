<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin\Cache;

use GraphCommerce\FastBoot\Model\Cache\PhpFiles;
use GraphCommerce\FastBoot\Model\Cache\Version;
use Magento\Framework\App\Cache;

/**
 * Opcache in front of the default cache frontend for the entries that hold
 * configuration but go through no cache type: the EAV entity types and
 * attributes, the resolved stores, the DDL of a table. Covered by id prefix
 * (di.xml `ids`); a remove of a covered id, or a clean whose tags meet the
 * covered tags (di.xml `tags`), bumps the version on every server.
 */
class OpcacheDefaultLayer
{
    private const GROUP = 'DEFAULT';

    /**
     * @param string[] $ids the id prefixes to cover
     * @param string[] $tags the tags whose clean drops the files
     */
    public function __construct(
        private readonly PhpFiles $files,
        private readonly Version $version,
        private readonly array $ids = [],
        private readonly array $tags = [],
    ) {
    }

    public function aroundLoad(Cache $subject, callable $proceed, $identifier)
    {
        if (!$this->covered((string)$identifier)) {
            return $proceed($identifier);
        }
        $value = $this->files->read(self::GROUP, (string)$identifier);
        if ($value !== null) {
            return $value;
        }
        $value = $proceed($identifier);
        if ($value !== false) {
            $this->files->write(self::GROUP, (string)$identifier, $value);
        }

        return $value;
    }

    public function afterSave(Cache $subject, $result, $data, $identifier, $tags = [], $lifeTime = null)
    {
        if ($this->covered((string)$identifier)) {
            $this->files->write(self::GROUP, (string)$identifier, $data, PhpFiles::lifetime($lifeTime));
        }

        return $result;
    }

    public function afterRemove(Cache $subject, $result, $identifier)
    {
        if ($this->covered((string)$identifier)) {
            $this->version->bump();
        }

        return $result;
    }

    public function afterClean(Cache $subject, $result, $tags = [])
    {
        if (!$tags || array_intersect((array)$tags, $this->tags)) {
            $this->version->bump();
        }

        return $result;
    }

    /**
     * The frontend stores an id upper-cased with dashes as underscores; the
     * prefixes are matched on that form.
     */
    private function covered(string $identifier): bool
    {
        $identifier = strtoupper(str_replace('-', '_', $identifier));
        foreach ($this->ids as $prefix) {
            if (str_starts_with($identifier, strtoupper($prefix))) {
                return true;
            }
        }

        return false;
    }
}
