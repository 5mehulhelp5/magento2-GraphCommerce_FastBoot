<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Test\Unit\Model;

use GraphCommerce\FastBootCache\Model\Feature;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class FeatureTest extends TestCase
{
    public function testAMechanismIsOnUnlessSetToFalse(): void
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->expects(self::once())->method('get')->with('fastboot', [])->willReturn([
            'schema_scalars' => false,
            'cache_files' => true,
        ]);
        $feature = new Feature($config);

        self::assertFalse($feature->on('schema_scalars'));
        self::assertTrue($feature->on('cache_files'));
        self::assertTrue($feature->on('validated_queries'));
    }
    public function testDisablingConfigCacheBypassesDerivedCachesButKeepsPureOptimizations(): void
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn([]);
        $state = $this->createStub(\Magento\Framework\App\Cache\StateInterface::class);
        $state->method('isEnabled')->willReturn(false);
        $feature = new Feature($config, $state);
        foreach (['cache_files','system_config_array','scopes_cache','validated_queries'] as $name) {
            self::assertFalse($feature->on($name));
        }
        self::assertTrue($feature->on('schema_scalars'));
        self::assertTrue($feature->on('quote_without_connection'));
    }
}
