<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Test\Unit\Model;

use GraphCommerce\FastBootGraphQl\Model\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    public function testABuiltInScalarLeavesTheTypeListAlone(): void
    {
        $query = new ObjectType(['name' => 'Query', 'fields' => ['a' => Type::string()]]);
        $schema = new Schema([
            'query' => $query,
            'typeLoader' => fn(string $name) => $name === 'Query' ? $query : null,
            'types' => function (): array {
                self::fail('The type list was materialized');
            },
        ]);

        self::assertSame(Type::string(), $schema->getType('String'));
        self::assertSame(Type::id(), $schema->getType('ID'));
        self::assertSame($query, $schema->getType('Query'));
    }
}
