<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\ObjectManager;

use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager\ConfigLoader\Compiled;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;

/**
 * The compiled metadata of an area holds the whole configuration, global
 * entries included, and the object manager merges it entry by entry into the
 * global one it already holds: 15 000 arguments replaced by themselves on
 * every request. This loader hands the object manager only the entries the
 * area changes, from a file under var/fastboot/metadata that follows the
 * metadata files' modification times.
 */
class AreaConfigLoader implements ConfigLoaderInterface
{
    /** @var array<string, array> */
    private array $loaded = [];

    public function __construct(
        private readonly DirectoryList $directoryList,
    ) {
    }

    public function load($area)
    {
        if (isset($this->loaded[$area])) {
            return $this->loaded[$area];
        }
        $areaFile = Compiled::getFilePath($area);
        $globalFile = Compiled::getFilePath(Area::AREA_GLOBAL);
        if ($area === Area::AREA_GLOBAL || !is_file($areaFile) || !is_file($globalFile)) {
            return $this->loaded[$area] = include $areaFile;
        }
        $stamp = filemtime($areaFile) . ':' . filemtime($globalFile);
        $diffFile = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/fastboot/metadata/' . $area . '.php';
        if (is_file($diffFile)) {
            $diff = include $diffFile;
            if (($diff['stamp'] ?? null) === $stamp) {
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

        @mkdir(dirname($diffFile), 0775, true);
        $temporary = $diffFile . '.' . getmypid() . '.tmp';
        $content = "<?php\nreturn " . var_export(['stamp' => $stamp, 'config' => $config], true) . ";\n";
        if (file_put_contents($temporary, $content) !== false) {
            rename($temporary, $diffFile);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($diffFile, true);
            }
        }

        return $this->loaded[$area] = $config;
    }
}
