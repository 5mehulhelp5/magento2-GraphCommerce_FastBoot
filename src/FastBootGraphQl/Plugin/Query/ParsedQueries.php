<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Plugin\Query;

use GraphCommerce\FastBootCache\Model\Feature;
use GraphCommerce\FastBootCache\Model\PhpFiles;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\Source;
use GraphQL\Language\Visitor;
use GraphQL\Utils\AST;
use Magento\Framework\GraphQl\Query\QueryParser;

/**
 * A query's document from an opcache PHP file instead of the lexer and the
 * parser on every request: the array form webonyx exports, rebuilt with the
 * query as the source of every node's location, so an error still names its
 * line. The record lives under the opcache version.
 */
class ParsedQueries implements \Magento\Framework\ObjectManager\ResetAfterRequestInterface
{
    private const SWITCH = 'parsed_queries';

    private const GROUP = 'PARSED';
    private array $documents = [];

    public function __construct(
        private readonly PhpFiles $files,
        private readonly Feature $feature,
    ) {
    }

    public function aroundParse(QueryParser $subject, \Closure $proceed, string $query): DocumentNode
    {
        if (!$this->feature->on(self::SWITCH) || strlen($query) > 65536) {
            return $proceed($query);
        }
        // A document accepted by a permissive parser must not bypass a stricter instance.
        // Older supported Magento parsers have no nesting policy; newer ones keep it private.
        $parser = new \ReflectionClass(QueryParser::class);
        $depth = $parser->hasProperty('maxNestingDepth')
            ? $parser->getProperty('maxNestingDepth')->getValue($subject)
            : null;
        $id = hash('sha256', json_encode([get_class($subject), $depth, $query], JSON_THROW_ON_ERROR));
        if (isset($this->documents[$id])) {
            return $this->documents[$id];
        }
        $generation = $this->files->generation();
        $array = $this->files->read(self::GROUP, $id);
        if (is_array($array)) {
            $source = new Source($query, 'GraphQL');
            $document = AST::fromArray($array);
            Visitor::visit($document, [
                'enter' => static function (Node $node) use ($source): void {
                    if ($node->loc !== null) {
                        $node->loc->source = $source;
                    }
                },
            ]);

            return $this->documents[$id] = $document;
        }
        $document = $proceed($query);
        $this->files->write(self::GROUP, $id, AST::toArray($document), null, $generation);

        return $this->documents[$id] = $document;
    }
    public function afterReloadState(QueryParser $subject, $result)
    {
        $this->_resetState();
        return $result;
    }
    public function _resetState(): void
    {
        $this->documents = [];
    }
}
