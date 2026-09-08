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
        $config->method('get')->with('fastboot', [])->willReturn([
            'schema_scalars' => false,
            'cache_files' => true,
        ]);
        $feature = new Feature($config);

        self::assertFalse($feature->on('schema_scalars'));
        self::assertTrue($feature->on('cache_files'));
        self::assertTrue($feature->on('validated_queries'));
    }
}
