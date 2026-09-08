<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin\Query;

use GraphCommerce\FastBoot\Model\Cache\PhpFiles;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;
use Magento\Framework\GraphQl\Exception\ExceptionFormatter;
use Magento\Framework\GraphQl\Query\ErrorHandlerInterface;
use Magento\Framework\GraphQl\Query\QueryDataFormatter;
use Magento\Framework\GraphQl\Query\QueryParser;
use Magento\Framework\GraphQl\Query\QueryProcessor;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * A query that ran without errors under the current opcache version is
 * valid against this schema, and runs without the validation rules from
 * then on: the rules walk the document against the schema on every request
 * under php-fpm, 7 ms for a product listing. A config cache clean, which
 * every schema change makes, drops the record with the version.
 */
class ValidatedQueries
{
    private const GROUP = 'VALIDATED';

    public function __construct(
        private readonly PhpFiles $files,
        private readonly QueryParser $queryParser,
        private readonly ExceptionFormatter $exceptionFormatter,
        private readonly ErrorHandlerInterface $errorHandler,
        private readonly QueryDataFormatter $formatter,
    ) {
    }

    public function aroundProcess(
        QueryProcessor $subject,
        \Closure $proceed,
        Schema $schema,
        DocumentNode|string $source,
        ?ContextInterface $contextValue = null,
        ?array $variableValues = null,
        ?string $operationName = null
    ): array {
        $body = $source instanceof DocumentNode ? $source->loc?->source?->body : $source;
        if ($body === null) {
            return $proceed($schema, $source, $contextValue, $variableValues, $operationName);
        }
        $id = sha1($body);
        if ($this->files->read(self::GROUP, $id) !== true) {
            $result = $proceed($schema, $source, $contextValue, $variableValues, $operationName);
            if (!isset($result['errors'])) {
                $this->files->write(self::GROUP, $id, true);
            }

            return $result;
        }

        $executionResult = GraphQL::executeQuery(
            $schema,
            is_string($source) ? $this->queryParser->parse($source) : $source,
            null,
            $contextValue,
            $variableValues,
            $operationName,
            null,
            []
        )->setErrorsHandler(
            [$this->errorHandler, 'handle']
        )->toArray(
            (int)($this->exceptionFormatter->shouldShowDetail() ? DebugFlag::INCLUDE_DEBUG_MESSAGE : false)
        );

        return $this->formatter->formatResponse($executionResult);
    }
}
