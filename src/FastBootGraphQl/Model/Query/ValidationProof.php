<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Model\Query;

use GraphQL\Language\Printer;
use GraphQL\Validator\QueryValidationContext;

/** Share one canonical-document check across all structural rules in this validation pass. */
class ValidationProof
{
    private \WeakMap $contexts;
    public function __construct(private object $schema, private string $fingerprint)
    {
        $this->contexts = new \WeakMap();
    }
    public function matches(QueryValidationContext $context): bool
    {
        return $this->contexts[$context] ??= $context->getSchema() === $this->schema
            && hash('sha256', Printer::doPrint($context->getDocument())) === $this->fingerprint;
    }
}
