<?php

declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\ObjectManager;

use GraphCommerce\FastBootCache\Model\Feature;
use GraphCommerce\FastBootCache\Model\Release;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager\ConfigLoader\Compiled;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;

/**
 * The compiled metadata of an area holds the whole configuration, global
 * entries included, and the object manager merges it entry by entry into the
 * global one it already holds: 15 000 arguments replaced by themselves on
 * every request. This loader hands the object manager only the entries the
 * area changes, from a file under var/cache/fastboot/metadata that follows
 * an immutable release identity (or the source hashes without one).
 */
class AreaConfigLoader implements ConfigLoaderInterface
{
    private const SWITCH = 'area_config_diff';

    /** @var array<string, array> */
    private array $loaded = [];

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly Feature $feature,
        private readonly Release $release,
    ) {
    }

    public function load($area, bool $rebuild = false)
    {
        if (!$rebuild && isset($this->loaded[$area])) {
            return $this->loaded[$area];
        }
        $areaFile = Compiled::getFilePath($area);
        $globalFile = Compiled::getFilePath(Area::AREA_GLOBAL);
        if ($area === Area::AREA_GLOBAL || !is_file($areaFile) || !is_file($globalFile)
            || !$this->feature->on(self::SWITCH)
        ) {
            return $this->loaded[$area] = include $areaFile;
        }
        $release = $this->release->id();
        // Immutable releases avoid hashing large compiled files on every request. Without a release use content hashes.
        $stamp = hash('sha256', serialize([$release, realpath($areaFile), realpath($globalFile),
            $release ? null : hash_file('sha256', $areaFile), $release ? null : hash_file('sha256', $globalFile)]));
        $diffFile = $this->directoryList->getPath(DirectoryList::CACHE) . '/fastboot/metadata/' . $stamp . '.php';
        if (!$rebuild && is_file($diffFile)) {
            try {
                $diff = @include $diffFile;
            } catch (\ParseError) {
                $diff = null;
            }
            if (is_array($diff) && ($diff['stamp'] ?? null) === $stamp && is_array($diff['config'] ?? null)) {
                return $this->loaded[$area] = $diff['config'];
            }
        }

        $global = include $globalFile;
        $config = [];
        foreach (include $areaFile as $key => $entries) {
            if (!is_array($entries) || !is_array($global[$key] ?? null)) {
                $config[$key] = $entries;
                continue;
            }
            $config[$key] = [];
            foreach ($entries as $name => $value) {
                if (!array_key_exists($name, $global[$key]) || $global[$key][$name] !== $value) {
                    $config[$key][$name] = $value;
                }
            }
        }

        @mkdir(dirname($diffFile), 0700, true);
        $temporary = $diffFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $content = "<?php\nreturn " . var_export(['stamp' => $stamp, 'config' => $config], true) . ";\n";
        if (@file_put_contents($temporary, $content) === strlen($content)) {
            @chmod($temporary, 0600);
            @rename($temporary, $diffFile);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($diffFile, true);
            }
        }

        @unlink($temporary);
        return $this->loaded[$area] = $config;
    }
}
