<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Test\Unit\Plugin\Query;

use GraphCommerce\FastBootCache\Model\{Feature, PhpFiles, Version, Release};
use GraphCommerce\FastBootGraphQl\Plugin\Query\ValidatedQueries;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\{ObjectType, Type};
use GraphQL\Type\Schema;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\GraphQl\Query\{QueryParser, QueryProcessor};
use PHPUnit\Framework\TestCase;

class ValidationGenerationTest extends TestCase
{
    public function testInvalidationDuringExecutionCannotValidateTheNextSchemaGeneration(): void
    {
        $root = sys_get_temp_dir().'/fastboot-validation-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $directory = $this->createStub(ReadInterface::class);
        $directory->method('getAbsolutePath')->willReturn($root.'/');
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($directory);
        $generation = 'before';
        $version = $this->createStub(Version::class);
        $version->method('current')->willReturnCallback(function () use (&$generation) {
            return $generation;
        });
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn ($key, $default = null) => $default);
        $release = $this->createStub(Release::class);
        $release->method('id')->willReturn('test-release');
        $files = new PhpFiles($filesystem, $version, $deployment, $release);
        $feature = $this->createStub(Feature::class);
        $feature->method('on')->willReturn(true);
        $plugin = new ValidatedQueries($files, $feature, new QueryParser());
        $subject = $this->createStub(QueryProcessor::class);
        $old = new Schema(['query' => new ObjectType(['name' => 'Query','fields' => ['items' => ['type' => Type::int(),'resolve' => fn () => 1]]])]);
        $new = new Schema(['query' => new ObjectType(['name' => 'Query','fields' => ['other' => Type::int()]])]);
        try {
            $first = $plugin->aroundProcess($subject, function ($schema, $source) use (&$generation) {
                $generation = 'after'; // A resolver or downstream plugin invalidates configuration.
                return GraphQL::executeQuery($schema, $source)->toArray();
            }, $old, '{items}');
            self::assertSame(1, $first['data']['items']);
            $second = $plugin->aroundProcess($subject, fn ($schema, $source) => GraphQL::executeQuery($schema, $source)->toArray(), $new, '{items}');
            self::assertArrayHasKey('errors', $second, 'An old schema must not publish validation proof into the new generation');
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
