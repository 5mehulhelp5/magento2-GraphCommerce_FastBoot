<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Model\Query;

use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\ValidationRule;

/** Skip a proven structural rule only for this exact document and schema. */
class CachedRule extends ValidationRule
{
    public function __construct(private ValidationRule $original, private ValidationProof $proof)
    {
        $this->name = $original->getName();
    }
    public function getVisitor(QueryValidationContext $context): array
    {
        return $this->proof->matches($context)
            ? [] : $this->original->getVisitor($context);
    }
}
