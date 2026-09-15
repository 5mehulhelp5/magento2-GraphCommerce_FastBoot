<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Test\Unit\Model;

use GraphCommerce\FastBootCache\Model\Feature;
use GraphCommerce\FastBootCache\Model\Release;
use Magento\Framework\App\Filesystem\DirectoryList;
use GraphCommerce\FastBootCache\Model\Schema\Settings;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class SchemaSettingsTest extends TestCase
{
    public function testUnconfiguredInstallationStaysNative(): void
    {
        self::assertSame('native', $this->settings([])->mode());
    }
    public function testCacheDisableBypassesReadsButKeepsInvalidationEnabled(): void
    {
        $settings = $this->settings(['enabled' => true], false);
        self::assertSame('native', $settings->mode());
        self::assertSame('opcache', $settings->configuredMode());
    }
    public function testFeatureSwitchBypassesReadsButKeepsInvalidationEnabled(): void
    {
        $settings = $this->settings(['enabled' => true], true, false);
        self::assertSame('native', $settings->mode());
        self::assertSame('opcache', $settings->configuredMode());
    }
    public function testEnablingWithoutReleaseFailsBeforeOpeningRedis(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('static content deployment version');
        $this->settings(['enabled' => true,'installation' => 'test'])->remote();
    }
    private function settings(array $options, bool $cacheEnabled = true, bool $featureEnabled = true): Settings
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn ($key, $default = null) => match($key) {
            'fastboot/schema_l1' => $options,
            'cache/frontend/default' => ['backend_options' => ['server' => '127.0.0.1','database' => 12]],
            default => $default,
        });
        $state = $this->createStub(StateInterface::class);
        $state->method('isEnabled')->willReturn($cacheEnabled);
        $feature = $this->createStub(Feature::class);
        $feature->method('on')->willReturn($featureEnabled);
        $directories = $this->createStub(DirectoryList::class);
        $directories->method('getPath')->willReturn('/missing-fastboot-test-static');
        return new Settings($config, $state, $feature, $directories, new Release($directories));
    }
}
