<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin;

use GraphCommerce\FastBoot\Model\ClassList;
use Magento\Framework\AppInterface;

/**
 * Records the classes, interfaces and traits a request declared, when the
 * FASTBOOT_RECORD environment variable is set. Generated interceptors,
 * factories and proxies are recorded as they are: the preload script
 * resolves them through the autoloader like any other class.
 */
class RecordClasses
{
    public function __construct(
        private readonly ClassList $classList,
    ) {
    }

    public function afterLaunch(AppInterface $subject, $result)
    {
        if (getenv('FASTBOOT_RECORD')) {
            $this->classList->append(array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()));
        }

        return $result;
    }
}
