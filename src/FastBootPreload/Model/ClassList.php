<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootPreload\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager\ConfigLoader\Compiled;
use Magento\Framework\Filesystem;

/**
 * The classes real requests declare, in var/cache/preload/classes.txt, which
 * preload.php loads at php-fpm start. The list records itself: a list that
 * is missing or older than the compiled metadata starts a recording window
 * (a marker file, `WINDOW` seconds) with an empty list, and every request
 * in the window appends what it declared. A php-fpm master that starts
 * inside a window preloads the part so far; the next one has it all.
 */
class ClassList
{
    private const FILE = 'preload/classes.txt';

    private const MARK = 'preload/classes.recording';

    private const WINDOW = 900;

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    public function recording(): bool
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::CACHE);
        if ($var->isExist(self::MARK)) {
            if (time() - (int)$var->stat(self::MARK)['mtime'] < self::WINDOW) {
                return true;
            }
            $var->delete(self::MARK);

            return false;
        }
        $metadata = Compiled::getFilePath(Area::AREA_GLOBAL);
        $compiled = is_file($metadata) ? filemtime($metadata) : 0;
        if (!$var->isFile(self::FILE) || (int)$var->stat(self::FILE)['mtime'] < $compiled) {
            $var->writeFile(self::FILE, '');
            $var->touch(self::MARK);

            return true;
        }

        return false;
    }

    public function append(array $classes): int
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::CACHE);
        $path = $var->getAbsolutePath(self::FILE);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            return 0;
        }
        $classes = array_values(array_filter($classes, static function (string $class): bool {
            if (str_contains($class, '@') || str_contains($class, "\0") || str_starts_with($class, 'PhpParser\\')) {
                return false;
            }
            $reflection = new \ReflectionClass($class);
            return !$reflection->isInternal() && strcasecmp($reflection->getName(), $class) === 0;
        }));
        $previous = is_file($path) ? array_filter(explode("\n", (string)file_get_contents($path))) : [];
        if (!array_diff($classes, $previous)) {
            return 0;
        }
        // Discover anonymous-class dependencies while recording, never run the parser in the preload master.
        Dependencies::load();
        $classes = array_unique(array_merge($classes, get_declared_classes(), get_declared_interfaces(), get_declared_traits()));
        $handle = @fopen($path, 'c+');
        if (!$handle) {
            return 0;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return 0;
            }
            $known = array_fill_keys(array_filter(explode("\n", stream_get_contents($handle))), true);
            $added = 0;
            foreach ($classes as $class) {
                if (count($known) >= 20000) {
                    break;
                }
                if (isset($known[$class]) || str_starts_with($class, 'PhpParser\\') || str_contains($class, '@') || str_contains($class, "\0") || (new \ReflectionClass($class))->isInternal() || strcasecmp((new \ReflectionClass($class))->getName(), $class) !== 0) {
                    continue;
                }
                if (fwrite($handle, $class."\n") === false) {
                    break;
                }
                $known[$class] = true;
                $added++;
            }
            @chmod($path, 0600);
            return $added;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
