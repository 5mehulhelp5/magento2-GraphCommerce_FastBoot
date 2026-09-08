<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * The classes recorded requests declared, one per line under var/fastboot,
 * the input of the preload script. Recording appends what a request adds
 * to what earlier requests wrote, so a handful of typical requests give
 * the set a php-fpm process starts with.
 */
class ClassList
{
    private const FILE = 'fastboot/classes.txt';

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /** @return string[] */
    public function read(): array
    {
        $directory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        if (!$directory->isFile(self::FILE)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $directory->readFile(self::FILE)))));
    }

    /** @param string[] $classes */
    public function append(array $classes): int
    {
        $new = array_diff($classes, $this->read());
        if ($new) {
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)
                ->writeFile(self::FILE, implode("\n", $new) . "\n", 'a');
        }

        return count($new);
    }
}
