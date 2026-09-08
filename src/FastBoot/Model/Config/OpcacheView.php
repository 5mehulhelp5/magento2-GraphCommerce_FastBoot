<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\Config;

use GraphCommerce\FastBootCache\Model\PhpFiles;
use GraphCommerce\FastBootCache\Model\Feature;
use Magento\Framework\Config\ConverterInterface;
use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Config\View;

/**
 * The view configuration (view.xml of the theme chain: image sizes, swatch
 * sizes, media entities) from an opcache file instead of the XML files.
 * Core parses and validates the files on every request that asks for an
 * image size, since the view config has no cache of its own. The key names
 * the area and theme the factory built the file resolver for.
 */
class OpcacheView extends View
{
    private const SWITCH = 'view_config';

    public function __construct(
        FileResolverInterface $fileResolver,
        ConverterInterface $converter,
        SchemaLocatorInterface $schemaLocator,
        ValidationStateInterface $validationState,
        private readonly PhpFiles $files,
        private readonly Feature $feature,
        $fileName,
        $idAttributes = [],
        $domDocumentClass = \Magento\Framework\Config\Dom::class,
        $defaultScope = 'global',
        $xpath = [],
        private readonly string $cacheKey = '',
    ) {
        parent::__construct(
            $fileResolver,
            $converter,
            $schemaLocator,
            $validationState,
            $fileName,
            $idAttributes,
            $domDocumentClass,
            $defaultScope,
            $xpath
        );
    }

    public function read($scope = null)
    {
        if (!$this->feature->on(self::SWITCH)) {
            return parent::read($scope);
        }
        $key = ($this->cacheKey ?: 'global') . '|' . ($scope ?: $this->_defaultScope) . '|' . $this->_fileName;
        $data = $this->files->read('VIEW', $key);
        if (is_array($data)) {
            return $data;
        }
        $data = parent::read($scope);
        $this->files->write('VIEW', $key, $data);

        return $data;
    }
}
