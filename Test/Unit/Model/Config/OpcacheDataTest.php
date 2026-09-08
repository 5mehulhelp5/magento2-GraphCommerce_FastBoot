<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Test\Unit\Model\Config;

use GraphCommerce\FastBoot\Model\Cache\PhpFiles;
use GraphCommerce\FastBoot\Model\Cache\Version;
use GraphCommerce\FastBoot\Model\Config\OpcacheData;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Config\ReaderInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class OpcacheDataTest extends TestCase
{
    private string $var;
    private string $tree;

    protected function setUp(): void
    {
        $this->var = sys_get_temp_dir() . '/fastboot-test-' . bin2hex(random_bytes(4));
        mkdir($this->var);
        $this->tree = substr(hash('sha256', json_encode([[], '', '', ''])), 0, 8);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->var . '/fastboot/*/*/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['/fastboot/*/*/*', '/fastboot/*/*', '/fastboot/*'] as $level) {
            foreach (glob($this->var . $level) ?: [] as $dir) {
                rmdir($dir);
            }
        }
        @rmdir($this->var . '/fastboot');
        rmdir($this->var);
    }

    public function testTheSecondRequestOfAVersionReadsTheFileAndNotTheCache(): void
    {
        $files = $this->files('v1');
        $reader = $this->createMock(ReaderInterface::class);
        $reader->method('read')->willReturn(['types' => ['Query' => ['fields' => 1]]]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects(self::once())->method('save');

        $first = new OpcacheData($reader, $cache, 'Schema_Data', $files, new Json());
        self::assertSame(1, $first->get('types/Query/fields'));
        self::assertFileExists($this->var . '/fastboot/' . $this->tree . '/v1/data/Schema_Data.php');

        $untouched = $this->createMock(CacheInterface::class);
        $untouched->expects(self::never())->method('load');
        $second = new OpcacheData($this->createMock(ReaderInterface::class), $untouched, 'Schema_Data', $files, new Json());
        self::assertSame(['Query' => ['fields' => 1]], $second->get('types'));

        $second->reset();
        self::assertFileDoesNotExist($this->var . '/fastboot/' . $this->tree . '/v1/data/Schema_Data.php');
    }

    public function testVersionsLiveApartUntilASweep(): void
    {
        $this->files('v1')->write('CONFIG', 'A', ['old' => true]);
        $files = $this->files('v2');
        $files->write('CONFIG', 'A', 'a string too');
        self::assertFileExists($this->var . '/fastboot/' . $this->tree . '/v1/CONFIG/A.php');
        self::assertSame('a string too', $files->read('CONFIG', 'A'));
        self::assertNull($files->read('CONFIG', 'B'));

        $files->sweep();
        self::assertFileDoesNotExist($this->var . '/fastboot/' . $this->tree . '/v1/CONFIG/A.php');
        self::assertNull($files->read('CONFIG', 'A'));
    }

    private function files(string $version): PhpFiles
    {
        $directory = $this->createMock(ReadInterface::class);
        $directory->method('getAbsolutePath')->willReturn($this->var . '/');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($directory);
        $versions = $this->createMock(Version::class);
        $versions->method('current')->willReturn($version);
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturn([]);

        return new PhpFiles($filesystem, $versions, $deployment);
    }
}
