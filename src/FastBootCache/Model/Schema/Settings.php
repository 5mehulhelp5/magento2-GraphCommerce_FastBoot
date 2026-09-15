<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model\Schema;

use GraphCommerce\FastBootCache\Model\Feature;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;

/** Schema-only configuration; an explicit release namespace is required for shared Redis. */
class Settings
{
    private const SWITCH = 'schema_array';
    private array $options;
    private ?Remote $remote = null;

    public function __construct(DeploymentConfig $deploymentConfig, private StateInterface $cacheState, private Feature $feature, private ?DirectoryList $directories = null)
    {
        $options = (array)$deploymentConfig->get('fastboot/schema_l1', []);
        $frontend = (array)$deploymentConfig->get('cache/frontend/default', []);
        $backend = (array)($frontend['backend_options'] ?? []);
        $this->options = $options + [
            'host' => $backend['server'] ?? null,
            'port' => $backend['port'] ?? 6379,
            'database' => $backend['database'] ?? 0,
            'password' => $backend['password'] ?? null,
            'username' => $backend['username'] ?? null,
            'installation' => $frontend['id_prefix'] ?? null,
        ];
    }

    public function configuredMode(): string
    {
        return ($this->options['enabled'] ?? false) === true ? 'opcache' : 'native';
    }

    public function mode(): string
    {
        return $this->cacheState->isEnabled('config') && $this->feature->on(self::SWITCH) ? $this->configuredMode() : 'native';
    }

    public function remote(): Remote
    {
        foreach (['host', 'installation', 'release'] as $required) {
            if (!is_string($this->options[$required] ?? null) || $this->options[$required] === '') {
                throw new \InvalidArgumentException('FastBoot schema_l1 requires '.$required.'; configure it before enabling.');
            }
        }
        $prefix = 'fastboot:schema:v1:{'.hash('sha256', $this->options['installation']).'}:';
        return $this->remote ??= new Remote(array_replace($this->options, [
            'key' => $prefix.hash('sha256', $this->options['release']),
            'epoch_key' => $prefix.'epoch',
        ]));
    }

    public function local(): Local
    {
        $identity = hash('sha256', json_encode([$this->options['host'], $this->options['port'], $this->options['database'], $this->options['installation'], $this->options['release']], JSON_THROW_ON_ERROR));
        $var = $this->directories?->getPath(DirectoryList::VAR_DIR) ?? BP.'/var';
        return new Local($this->remote(), rtrim($var, '/').'/fastboot-schema/'.$identity, (float)($this->options['grace'] ?? 0), (int)($this->options['max_files'] ?? 16), (int)($this->options['max_bytes'] ?? 33554432));
    }

    public function frontend(?\Magento\Framework\Cache\FrontendInterface $native = null): \Magento\Framework\Cache\FrontendInterface
    {
        return $this->remote();
    }
}
