# FastBoot

## Installation

```sh
composer require graphcommerce/magento-fast-boot
bin/magento module:enable GraphCommerce_FastBootCache GraphCommerce_FastBoot GraphCommerce_FastBootGraphQl
```

## Configuration

`app/etc/env.php`:

```php
'fastboot' => [
    'schema_l1' => [
        'enabled' => true,
        'installation' => 'my-shop-production',
    ],
],
```

`installation` identifies the environment across web, admin and CLI nodes. Redis connection settings default to Magento's default cache frontend.

Cache namespaces use `pub/static/deployed_version.txt`. Each PHP/DI/config build needs a distinct static-content version, shared across its nodes. `setup:static-content:deploy --content-version="$BUILD_ID"` supplies that identity.

## Deployment

After DI compilation and static deployment, run on each node:

```sh
bin/magento fastboot:status
bin/magento fastboot:prepare
```

`status` checks the deployment identity, cache directory and schema Redis connection. `prepare` writes area DI differences, schema data and store configuration to the local cache.

All optimizations are enabled by default. No database patches are installed.
