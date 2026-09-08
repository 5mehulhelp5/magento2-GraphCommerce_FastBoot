<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Console\Command;

use GraphCommerce\FastBoot\Model\ClassList;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes var/fastboot/preload.php: a script php-fpm runs once at start
 * (opcache.preload) that loads and links every recorded class into the
 * shared opcache, so a request finds them without autoloading, including
 * or linking. Classes the autoloader cannot find are skipped, so a stale
 * record after a deploy costs nothing.
 */
class Preload extends Command
{
    private const FILE = 'fastboot/preload.php';

    public function __construct(
        private readonly ClassList $classList,
        private readonly Filesystem $filesystem,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastboot:preload')
            ->setDescription('Writes the opcache preload script from the recorded classes (FASTBOOT_RECORD=1 on a few requests first)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $classes = $this->classList->read();
        if (!$classes) {
            $output->writeln('<error>No recorded classes. Run a few requests with FASTBOOT_RECORD=1 in the php-fpm environment first.</error>');

            return Command::FAILURE;
        }
        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath();
        $script = "<?php\n"
            . "// Written by bin/magento fastboot:preload from " . count($classes) . " recorded classes.\n"
            . "require " . var_export(rtrim($root, '/') . '/vendor/autoload.php', true) . ";\n"
            . "foreach (" . var_export($classes, true) . " as \$class) {\n"
            . "    class_exists(\$class) || interface_exists(\$class) || trait_exists(\$class);\n"
            . "}\n";
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $var->writeFile(self::FILE, $script);
        $output->writeln(sprintf('Wrote %s with %d classes.', $var->getAbsolutePath(self::FILE), count($classes)));
        $output->writeln(sprintf('php-fpm ini: opcache.preload=%s', $var->getAbsolutePath(self::FILE)));

        return Command::SUCCESS;
    }
}
