<?php

declare(strict_types=1);

namespace GraphCommerce\FastBoot\Test\Unit\Plugin;

use GraphCommerce\FastBoot\Plugin\Config\SystemFromFile;
use GraphCommerce\FastBootCache\Model\{PhpFiles,Feature,Version};
use Magento\Config\App\Config\Type\System;
use Magento\Framework\App\Cache\StateInterface;
use PHPUnit\Framework\TestCase;

class SystemFromFileTest extends TestCase
{
    public function testScopeLoadingIsLazyReentrantAndCleanDoesNotServeOldMemory(): void
    {
        $files = $this->createStub(PhpFiles::class);
        $files->method('read')->willReturn(null);
        $feature = $this->createStub(Feature::class);
        $feature->method('on')->willReturn(true);
        $version = $this->createStub(Version::class);
        $version->method('current')->willReturn('v');
        $state = $this->createStub(StateInterface::class);
        $state->method('isEnabled')->willReturn(true);
        $plugin = new SystemFromFile($files, $feature, $version, $state);
        $subject = $this->createStub(System::class);
        $calls = [];
        $proceed = function ($path) use (&$calls, $plugin, $subject) {
            $calls[] = $path;
            self::assertNotSame('', $path, 'A scoped read must not load every store');
            if ($path === 'stores/one') {
                self::assertSame('nested', $plugin->aroundGet($subject, fn ($p) => 'nested', 'stores/one/leaf'));
                return ['leaf' => 'old'];
            }return ['leaf' => 'new'];
        };
        self::assertSame('old', $plugin->aroundGet($subject, $proceed, 'stores/one/leaf'));
        self::assertSame('old', $plugin->aroundGet($subject, $proceed, 'stores/one/leaf'));
        self::assertSame(['stores/one'], $calls);
        $plugin->aroundClean($subject, function () use ($plugin, $subject) {
            self::assertSame('clean-value', $plugin->aroundGet($subject, fn ($p) => 'clean-value', 'stores/one/leaf'));
        });
        self::assertSame('new', $plugin->aroundGet($subject, fn ($p) => ['leaf' => 'new'], 'stores/one/leaf'));
    }
}
