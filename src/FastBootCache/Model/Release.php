<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model;

use Magento\Framework\App\Filesystem\DirectoryList;

/** A request-local snapshot of Magento's deployed static-content version. */
class Release
{
    private bool $resolved = false;
    private ?string $value = null;

    public function __construct(private DirectoryList $directories)
    {
    }

    public function id(): ?string
    {
        if (!$this->resolved) {
            $path = $this->directories->getPath(DirectoryList::STATIC_VIEW).'/deployed_version.txt';
            $value = is_file($path) ? @file_get_contents($path) : null;
            $this->value = is_string($value) && trim($value) !== '' ? trim($value) : null;
            $this->resolved = true;
        }
        return $this->value;
    }
}
