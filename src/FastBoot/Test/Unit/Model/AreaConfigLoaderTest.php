<?php

declare(strict_types=1);

namespace GraphCommerce\FastBoot\Test\Unit\Model;

use GraphCommerce\FastBoot\Model\ObjectManager\AreaConfigLoader;
use GraphCommerce\FastBootCache\Model\Feature;
use GraphCommerce\FastBootCache\Model\Release;
use Magento\Framework\App\Filesystem\DirectoryList;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class AreaConfigLoaderTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCorruptArtifactsRepairAndPreparationRebuildsChangedMetadata(): void
    {
        $root = sys_get_temp_dir().'/fastboot-area-'.bin2hex(random_bytes(8));
        define('BP', $root);
        mkdir($root.'/generated/metadata', 0700, true);
        $global = ['arguments' => ['same' => ['x' => 1], 'changed' => ['x' => 1]]];
        $area = ['arguments' => ['same' => ['x' => 1], 'changed' => ['x' => 2]]];
        file_put_contents($root.'/generated/metadata/global.php', '<?php return '.var_export($global, true).';');
        file_put_contents($root.'/generated/metadata/graphql.php', '<?php return '.var_export($area, true).';');
        $directory = $this->createStub(DirectoryList::class);
        $directory->method('getPath')->willReturnCallback(static function ($code) use ($root) {
            self::assertSame(DirectoryList::CACHE, $code);
            return $root.'/custom-cache';
        });
        $feature = $this->createStub(Feature::class);
        $feature->method('on')->willReturn(true);
        $release = $this->createStub(Release::class);
        $release->method('id')->willReturn('deployed-build');
        $loader = fn () => new AreaConfigLoader($directory, $feature, $release);
        $expected = ['arguments' => ['changed' => ['x' => 2]]];
        try {
            self::assertSame($expected, $loader()->load('graphql'));
            $file = glob($root.'/custom-cache/fastboot/metadata/*.php')[0];
            file_put_contents($file, '<?php invalid syntax');
            self::assertSame($expected, $loader()->load('graphql'));
            file_put_contents($file, '<?php return false;');
            self::assertSame($expected, $loader()->load('graphql'));
            $instance = $loader();
            self::assertSame($expected, $instance->load('graphql'));
            $area['arguments']['changed']['x'] = 3;
            file_put_contents($root.'/generated/metadata/graphql.php', '<?php return '.var_export($area, true).';');
            self::assertSame(['arguments' => ['changed' => ['x' => 3]]], $instance->load('graphql', true));
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
