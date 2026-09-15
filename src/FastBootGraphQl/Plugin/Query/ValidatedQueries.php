<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Plugin\Query;

use GraphCommerce\FastBootCache\Model\{Feature, PhpFiles};
use GraphCommerce\FastBootGraphQl\Model\Query\CachedRule;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\ValuesOfCorrectType;
use Magento\Framework\GraphQl\Query\QueryProcessor;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;

/** Cache structural proof, retaining Magento's processor, security rules and custom validation. */
class ValidatedQueries
{
    private const SWITCH = 'validated_queries';
    public function __construct(private PhpFiles $files, private Feature $feature, private \Magento\Framework\GraphQl\Query\QueryParser $parser)
    {
    }
    public function aroundProcess(QueryProcessor $subject, \Closure $proceed, Schema $schema, DocumentNode|string $source, ?ContextInterface $contextValue = null, ?array $variableValues = null, ?string $operationName = null): array
    {
        $body = $source instanceof DocumentNode ? $source->loc?->source?->body : $source;
        if (!$this->feature->on(self::SWITCH) || $body === null || strlen($body) > 65536) {
            return $proceed($schema, $source, $contextValue, $variableValues, $operationName);
        }
        $generation = $this->files->generation();
        $document = $source instanceof DocumentNode ? $source : $this->parser->parse($source);
        $id = hash('sha256', \GraphQL\Language\Printer::doPrint($document));
        $proof = new \GraphCommerce\FastBootGraphQl\Model\Query\ValidationProof($schema, $id);
        $restore = [];
        $cached = $this->files->read('VALIDATED', $id) === true;
        if ($cached) {
            $defaults = DocumentValidator::defaultRules();
            foreach (DocumentValidator::allRules() as $rule) {
                $class = get_class($rule);
                // Exact built-ins only. Custom rules, security rules and scalar parseLiteral stay live.
                if (isset($defaults[$class]) && $class !== ValuesOfCorrectType::class) {
                    $restore[$rule->getName()] = $rule;
                    DocumentValidator::addRule(new CachedRule($rule, $proof));
                }
            }
        }
        try {
            $result = $proceed($schema, $source, $contextValue, $variableValues, $operationName);
            if (!$cached && !isset($result['errors'])) {
                $this->files->write('VALIDATED', $id, true, null, $generation);
            }
            return $result;
        } finally {
            // Never leak temporary rules to another operation or a long-running process.
            foreach ($restore as $rule) {
                $current = DocumentValidator::allRules()[$rule->getName()] ?? null;
                if ($current instanceof CachedRule) {
                    DocumentValidator::addRule($rule);
                }
            }
        }
    }
}
