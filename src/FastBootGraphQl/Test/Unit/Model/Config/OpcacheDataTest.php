<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Test\Unit\Model\Config;

use GraphCommerce\FastBootCache\Model\Schema\{Settings, Remote, Local};
use GraphCommerce\FastBootGraphQl\Model\Config\OpcacheData;
use Magento\Framework\Config\{CacheInterface, ReaderInterface};
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class OpcacheDataTest extends TestCase
{
    public function testNativeModeUsesMagentoCache(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('mode')->willReturn('native');
        $settings->expects(self::never())->method('remote');
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('{"types":{"Query":[]}}');
        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects(self::never())->method('read');
        $data = new OpcacheData($reader, $cache, 'schema', $settings, new Json());
        self::assertSame(['types' => ['Query' => []]], $data->get(null));
    }
    public function testWarmArrayDoesNotReadSourceOrPublish(): void
    {
        $remote = $this->createMock(Remote::class);
        $remote->expects(self::never())->method('publish');
        $local = $this->createStub(Local::class);
        $local->method('load')->willReturn(['types' => ['Query' => []]]);
        $settings = $this->settings($remote, $local);
        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects(self::never())->method('read');
        $data = new OpcacheData($reader, $this->createStub(CacheInterface::class), 'schema', $settings, new Json());
        self::assertSame(['types' => ['Query' => []]], $data->get(null));
    }
    public function testBuildCapturesGenerationBeforeSourceReadAndRejectsPublicationWithoutRetrying(): void
    {
        $captured = false;
        $remote = $this->createMock(Remote::class);
        $remote->expects(self::once())->method('generation')->willReturnCallback(function () use (&$captured) {
            $captured = true;
            return 'before-clean';
        });
        $remote->expects(self::once())->method('publish')->with('{"types":[]}', self::anything(), 7200, 'before-clean')->willReturn(false);
        $local = $this->createStub(Local::class);
        $local->method('load')->willReturn(null);
        $reader = $this->createMock(ReaderInterface::class);
        $reader->expects(self::once())->method('read')->willReturnCallback(function () use (&$captured) {
            self::assertTrue($captured);
            return ['types' => []];
        });
        $data = new OpcacheData($reader, $this->createStub(CacheInterface::class), 'schema', $this->settings($remote, $local), new Json());
        self::assertSame(['types' => []], $data->get(null));
    }
    private function settings(Remote $remote, Local $local): Settings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('mode')->willReturn('opcache');
        $settings->method('frontend')->willReturn($remote);
        $settings->method('remote')->willReturn($remote);
        $settings->method('local')->willReturn($local);
        return $settings;
    }
}
