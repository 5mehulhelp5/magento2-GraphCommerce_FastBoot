<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\GraphQl;

/**
 * Hands the generator a Schema that answers built-in scalars itself; the
 * framework's factory constructs its own class and takes no preference.
 */
class SchemaFactory extends \Magento\Framework\GraphQl\SchemaFactory
{
    public function create(array $config): Schema
    {
        return new Schema($config);
    }
}
