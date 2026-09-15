<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootGraphQl\Plugin\Schema;

use GraphCommerce\FastBootCache\Model\Version;

/** Direct schema resets must also retire derived parse/validation/config records. */
class Reset
{
    public function __construct(private Version $version)
    {
    }
    public function aroundReset($subject, callable $proceed)
    {
        $this->version->bump();
        try {
            return $proceed();
        } finally {
            $this->version->bump();
        }
    }
}
