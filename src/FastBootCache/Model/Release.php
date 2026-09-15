<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;

/** A request-local snapshot of Magento's deployed static-content version. */
class Release
{
    private bool $resolved = false;
    private ?string $value = null;

    public function __construct(private DeploymentConfig $config, private DirectoryList $directories)
    {
    }

    public function id(): ?string
    {
        if (!$this->resolved) {
            // Retain explicit IDs for installations upgrading from an existing configuration.
            $value = $this->config->get('fastboot/release', $this->config->get('fastboot/schema_l1/release'));
            if ($value === null) {
                $path = $this->directories->getPath(DirectoryList::STATIC_VIEW).'/deployed_version.txt';
                $value = is_file($path) ? @file_get_contents($path) : null;
            }
            $this->value = is_string($value) && trim($value) !== '' ? trim($value) : null;
            $this->resolved = true;
        }
        return $this->value;
    }
}
