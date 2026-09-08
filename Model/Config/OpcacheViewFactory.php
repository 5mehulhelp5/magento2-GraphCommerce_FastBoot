<?php
declare(strict_types=1);

namespace GraphCommerce\FastBoot\Model\Config;

use Magento\Framework\Config\FileResolver;
use Magento\Framework\Config\View;
use Magento\Framework\Config\ViewFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Theme\Model\View\Design;

/**
 * Core's factory with one addition: the view config gets the area and the
 * theme path as its cache key, so its opcache file is one per theme.
 */
class OpcacheViewFactory extends ViewFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {
        parent::__construct($objectManager);
    }

    public function create(array $arguments = [])
    {
        $viewConfigArguments = [];
        if (isset($arguments['themeModel']) && isset($arguments['area'])) {
            if (!($arguments['themeModel'] instanceof ThemeInterface)) {
                throw new LocalizedException(
                    new Phrase('%1 doesn\'t implement ThemeInterface', [$arguments['themeModel']])
                );
            }
            $design = $this->objectManager->create(Design::class);
            $design->setDesignTheme($arguments['themeModel'], $arguments['area']);
            $viewConfigArguments['fileResolver'] = $this->objectManager->create(
                FileResolver::class,
                ['designInterface' => $design]
            );
            $viewConfigArguments['cacheKey'] = $arguments['area'] . '/' . $arguments['themeModel']->getFullPath();
        }

        return $this->objectManager->create(View::class, $viewConfigArguments);
    }
}
