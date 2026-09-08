<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootPreload\Plugin;

use GraphCommerce\FastBootPreload\Model\ClassList;
use Magento\Framework\AppInterface;

/**
 * Appends the classes a request declared to the list while the list
 * records itself, and always with FASTBOOT_RECORD in the environment.
 */
class RecordClasses
{
    public function __construct(
        private readonly ClassList $classList,
    ) {
    }

    public function afterLaunch(AppInterface $subject, $result)
    {
        if (getenv('FASTBOOT_RECORD') || $this->classList->recording()) {
            $this->classList->append(array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()));
        }

        return $result;
    }
}
