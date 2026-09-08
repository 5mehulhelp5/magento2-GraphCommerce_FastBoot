<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model;

use Magento\Framework\App\DeploymentConfig;

/**
 * The switches of the FastBoot modules, from the `fastboot` array of
 * app/etc/env.php (or config.php): every mechanism is on unless its name is
 * set to false there, so a deployment turns one off without a code change
 * and a bench measures one alone. Each mechanism names its own switch.
 */
class Feature
{
    private ?array $switches = null;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
    ) {
    }

    public function on(string $name): bool
    {
        $this->switches ??= (array)$this->deploymentConfig->get('fastboot', []);

        return ($this->switches[$name] ?? true) !== false;
    }
}
