<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Test\Unit\Plugin\Query;

use GraphCommerce\FastBootCache\Model\Feature;
use GraphCommerce\FastBootCache\Model\PhpFiles;
use GraphCommerce\FastBootGraphQl\Plugin\Query\ParsedQueries;
use Magento\Framework\GraphQl\Query\QueryParser;
use PHPUnit\Framework\TestCase;

class ParsedQueriesTest extends TestCase
{
    public function testCachedDocumentCannotBypassStricterParserPolicy(): void
    {
        if (!(new \ReflectionClass(QueryParser::class))->hasProperty('maxNestingDepth')) {
            self::markTestSkipped('This Magento version has no configurable parser nesting guard.');
        }
        $cache = [];
        $files = $this->createStub(PhpFiles::class);
        $files->method('read')->willReturnCallback(function ($group, $id) use (&$cache) {
            return $cache[$id] ?? null;
        });
        $files->method('write')->willReturnCallback(function ($group, $id, $value) use (&$cache) {
            $cache[$id] = $value;
        });
        $feature = $this->createStub(Feature::class);
        $feature->method('on')->willReturn(true);
        $plugin = new ParsedQueries($files, $feature);
        $query = '{a{b{c}}}';
        $permissive = new QueryParser(10);
        $strict = new QueryParser(2);
        $calls = 0;
        $parse = function ($query) use ($permissive, &$calls) {
            $calls++;
            return $permissive->parse($query);
        };
        $first = $plugin->aroundParse($permissive, $parse, $query);
        $plugin->_resetState();
        self::assertSame(\GraphQL\Utils\AST::toArray($first), \GraphQL\Utils\AST::toArray($plugin->aroundParse($permissive, $parse, $query)));
        self::assertSame(1, $calls, 'Warm local document avoids parsing');
        // Verify the stricter native parser is reached without depending on Magento translation setup.
        $this->expectException(\DomainException::class);
        $plugin->aroundParse($strict, function () {
            throw new \DomainException('Stricter native parser reached');
        }, $query);
    }
}
