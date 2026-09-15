<?php

declare(strict_types=1);

namespace GraphCommerce\FastBoot\Plugin\Config;

use GraphCommerce\FastBootCache\Model\{PhpFiles, Feature, Version};
use Magento\Config\App\Config\Type\System;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/** Cache only requested scopes; never turn an ordinary get into a full-store tree load. */
class SystemFromFile implements ResetAfterRequestInterface
{
    private const SWITCH = 'system_config_array';
    private array $data = [];
    private array $loading = [];
    private bool $cleaning = false;
    public function __construct(private PhpFiles $files, private Feature $feature, private Version $version, private StateInterface $cacheState)
    {
    }
    public function aroundGet(System $subject, callable $proceed, $path = '')
    {
        if (!$this->feature->on(self::SWITCH) || !$this->cacheState->isEnabled('config') || $this->cleaning || $path === '') {
            return $proceed($path);
        }
        $parts = explode('/', (string)$path);
        $scope = array_shift($parts);
        if ($scope !== 'default') {
            if (!$parts) {
                return $proceed($path);
            }
            $scope .= '/'.array_shift($parts);
        }
        if (isset($this->loading[$scope])) {
            return $proceed($path);
        }
        if (!array_key_exists($scope, $this->data)) {
            $generation = $this->version->current();
            $data = $this->files->read('SYSTEM', $scope);
            if (!is_array($data)) {
                $this->loading[$scope] = true;
                try {
                    $data = $proceed($scope);
                } finally {
                    unset($this->loading[$scope]);
                }
                if (!is_array($data)) {
                    return $proceed($path);
                }
                $this->files->write('SYSTEM', $scope, $data, null, $generation);
            }
            $this->data[$scope] = $data;
        }
        $value = $this->data[$scope];
        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } elseif ($value instanceof \Magento\Framework\DataObject) {
                $value = $value->getDataByKey($part);
            } else {
                return null;
            }
        }
        return $value;
    }
    public function aroundClean(System $subject, callable $proceed)
    {
        return $this->clean($proceed);
    }
    public function aroundCleanAndWarmDefaultScopeData(System $subject, callable $proceed, callable $cleaner)
    {
        return $this->clean(static fn () => $proceed($cleaner));
    }
    private function clean(callable $proceed)
    {
        $this->data = [];
        $wasCleaning = $this->cleaning;
        $this->cleaning = true;
        $this->version->bump();
        try {
            return $proceed();
        } finally {
            $this->data = [];
            $this->cleaning = $wasCleaning;
            $this->version->bump();
        }
    }
    public function _resetState(): void
    {
        $this->data = [];
        $this->loading = [];
        $this->cleaning = false;
    }
}
