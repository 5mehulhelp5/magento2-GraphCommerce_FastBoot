<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootPreload\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager\ConfigLoader\Compiled;
use Magento\Framework\Filesystem;

/**
 * The classes real requests declare, in var/fastboot/classes.txt, which
 * preload.php loads at php-fpm start. The list records itself: a list that
 * is missing or older than the compiled metadata starts a recording window
 * (a marker file, `WINDOW` seconds) with an empty list, and every request
 * in the window appends what it declared. A php-fpm master that starts
 * inside a window preloads the part so far; the next one has it all.
 */
class ClassList
{
    private const FILE = 'fastboot/classes.txt';

    private const MARK = 'fastboot/classes.recording';

    private const WINDOW = 900;

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    public function recording(): bool
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
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
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $known = $var->isFile(self::FILE)
            ? array_filter(array_map('trim', explode("\n", $var->readFile(self::FILE))))
            : [];
        $new = array_diff($classes, $known);
        if ($new) {
            $var->writeFile(self::FILE, implode("\n", $new) . "\n", 'a');
        }

        return count($new);
    }
}
