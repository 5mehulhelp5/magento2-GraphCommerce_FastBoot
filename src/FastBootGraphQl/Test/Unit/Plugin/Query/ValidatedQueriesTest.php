<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Test\Unit\Plugin\Query;

use GraphCommerce\FastBootCache\Model\{Feature,PhpFiles};
use GraphCommerce\FastBootGraphQl\Plugin\Query\ValidatedQueries;
use GraphCommerce\FastBootGraphQl\Model\Query\CachedRule;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\{ObjectType,Type};
use GraphQL\Type\Schema;
use GraphQL\Validator\{DocumentValidator,QueryValidationContext};
use GraphQL\Validator\Rules\{QueryComplexity,ValidationRule,DisableIntrospection};
use Magento\Framework\GraphQl\Query\QueryProcessor;
use PHPUnit\Framework\TestCase;

class ValidatedQueriesTest extends TestCase
{
    public function testWarmQueryRetainsVariableComplexityCustomRulesAndProcessorChain(): void
    {
        $cache = [];
        $files = $this->createStub(PhpFiles::class);
        // Capture by reference for stateful cache stubs.
        $files->method('read')->willReturnCallback(function ($g, $id) use (&$cache) {
            return $cache[$id] ?? null;
        });
        $files->method('write')->willReturnCallback(function ($g, $id, $v) use (&$cache) {
            $cache[$id] = $v;
        });
        $feature = $this->createStub(Feature::class);
        $feature->method('on')->willReturn(true);
        $plugin = new ValidatedQueries($files, $feature, new \Magento\Framework\GraphQl\Query\QueryParser());
        $schema = new Schema(['query' => new ObjectType(['name' => 'Query','fields' => ['items' => ['type' => Type::int(),'args' => ['n' => Type::int()],'complexity' => fn ($children, $args) => $args['n'],'resolve' => fn ($root, $args) => $args['n']]]])]);
        $custom = new class () extends ValidationRule {
            public int $calls = 0;
            public function getVisitor(QueryValidationContext $context): array
            {
                $this->calls++;
                return [];
            }
        };
        DocumentValidator::addRule($custom);
        DocumentValidator::addRule(new QueryComplexity(10));
        $calls = 0;
        $proceed = function ($schema, $source, $context, $variables, $operation) use (&$calls) {
            $calls++;
            return GraphQL::executeQuery($schema, $source, null, $context, $variables, $operation)->toArray();
        };
        $subject = $this->createStub(QueryProcessor::class);
        $query = 'query($n:Int!){items(n:$n)}';
        try {
            self::assertSame(1, $plugin->aroundProcess($subject, $proceed, $schema, $query, null, ['n' => 1])['data']['items']);
            self::assertArrayHasKey('errors', $plugin->aroundProcess($subject, $proceed, $schema, $query, null, ['n' => 100]));
            self::assertSame(2, $calls);
            self::assertSame(2, $custom->calls);
            self::assertNotEmpty($cache);
            foreach (DocumentValidator::allRules() as $rule) {
                self::assertNotInstanceOf(CachedRule::class, $rule);
            }
            $mutate = function ($schema, $source, $context, $variables, $operation) {
                $document = \GraphQL\Language\Parser::parse($source);
                $document->definitions[0]->selectionSet->selections[0]->name->value = 'missingField';
                return GraphQL::executeQuery($schema, $document, null, $context, $variables, $operation)->toArray();
            };
            self::assertArrayHasKey('errors', $plugin->aroundProcess($subject, $mutate, $schema, $query, null, ['n' => 1]), 'Downstream AST mutations must receive full structural validation');
            $this->expectException(\RuntimeException::class);
            $plugin->aroundProcess($subject, function () {
                throw new \RuntimeException('downstream');
            }, $schema, $query, null, ['n' => 1]);
        } finally {
            DocumentValidator::removeRule($custom);
            DocumentValidator::addRule(new QueryComplexity(QueryComplexity::DISABLED));
            foreach (DocumentValidator::allRules() as $rule) {
                self::assertNotInstanceOf(CachedRule::class, $rule);
            }
        }
    }
}
