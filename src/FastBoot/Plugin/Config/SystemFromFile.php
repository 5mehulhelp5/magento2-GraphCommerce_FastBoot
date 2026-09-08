<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin\Config;

use GraphCommerce\FastBootCache\Model\PhpFiles;
use GraphCommerce\FastBootCache\Model\Feature;
use Magento\Config\App\Config\Type\System;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The system configuration of every scope as one opcache PHP array, so a
 * request neither decrypts nor unserialises a scope's cache entry. The
 * first request of a version takes the whole tree from the type and writes
 * the file; the config cache clean of a config save bumps the version. The
 * values on disk are the values the database holds, the same exposure as a
 * dumped config.php, so a deployment that must not have them on disk turns
 * this plugin off in its di.xml.
 */
class SystemFromFile implements ResetAfterRequestInterface
{
    private const SWITCH = 'system_config_array';

    private const KEY = 'system';

    private ?array $data = null;

    public function __construct(
        private readonly PhpFiles $files,
        private readonly Feature $feature,
    ) {
    }

    public function aroundGet(System $subject, callable $proceed, $path = '')
    {
        if (!$this->feature->on(self::SWITCH)) {
            return $proceed($path);
        }
        if ($this->data === null) {
            $data = $this->files->read('SYSTEM', self::KEY);
            if (!is_array($data)) {
                $data = (array)$proceed('');
                $this->files->write('SYSTEM', self::KEY, $data);
            }
            $this->data = $data;
        }
        if ($path === '') {
            return $this->data;
        }
        $value = $this->data;
        foreach (explode('/', (string)$path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function afterClean(System $subject, $result)
    {
        $this->data = null;

        return $result;
    }

    public function _resetState(): void
    {
        $this->data = null;
    }
}
