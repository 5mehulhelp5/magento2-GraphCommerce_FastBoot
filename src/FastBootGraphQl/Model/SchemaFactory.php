<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Model;

use GraphCommerce\FastBootCache\Model\Feature;

/**
 * Hands the generator a Schema that answers built-in scalars itself; the
 * framework's factory constructs its own class and takes no preference.
 */
class SchemaFactory extends \Magento\Framework\GraphQl\SchemaFactory
{
    private const SWITCH = 'schema_scalars';

    public function __construct(
        private readonly Feature $feature,
    ) {
    }

    public function create(array $config): \Magento\Framework\GraphQl\Schema
    {
        return $this->feature->on(self::SWITCH) ? new Schema($config) : parent::create($config);
    }
}
