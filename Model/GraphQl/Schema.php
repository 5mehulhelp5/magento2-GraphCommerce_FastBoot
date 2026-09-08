<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\GraphQl;

use GraphQL\Type\Definition\Type;

/**
 * A built-in scalar is answered as such. webonyx looks for a scalar override
 * in the schema's type list the first time a scalar value is completed, and
 * Magento's type list is a closure that builds every declared type: hundreds
 * of config elements per request under php-fpm. Magento maps the built-in
 * scalar names to webonyx's own instances, so there is no override to find.
 */
class Schema extends \Magento\Framework\GraphQl\Schema
{
    /** @var array<string, Type> */
    private readonly array $scalars;

    public function __construct($config)
    {
        parent::__construct($config);
        $this->scalars = Type::builtInScalars();
    }

    public function getType(string $name): ?Type
    {
        return $this->scalars[$name] ?? parent::getType($name);
    }
}
