# FastBoot

FastBoot caches reusable configuration and GraphQL data on each server. All optimizations are enabled by default. Class preloading is optional and has its own [guide](PRELOAD.md).

## Install

Install the published Composer package using your artifact repository:

```sh
composer config repositories.fastboot artifact /absolute/path/to/artifacts
composer require graphcommerce/magento-fast-boot:0.2.0-rc5
bin/magento module:enable GraphCommerce_FastBootCache GraphCommerce_FastBoot GraphCommerce_FastBootGraphQl
```

Place the release ZIP in that artifact directory first. Source installations can use a Composer VCS repository instead. The preload module can remain disabled unless you use class preload.

## Configure

Merge into `app/etc/env.php`:

```php
'fastboot' => [
    'schema_l1' => [
        'enabled' => true,
        'installation' => 'my-shop-production',
    ],
],
```

Use a stable installation ID shared by this environment's web, admin and CLI nodes. Staging and production use different IDs. Schema L1 requires a Redis server; the PHP Redis extension is optional. The schema connection inherits the default Magento cache frontend's Redis endpoint and credentials; configure an [explicit endpoint](CONFIGURATION.md#redis-connection) when needed.

FastBoot uses Magento's `pub/static/deployed_version.txt` as its build identity. **Deploy a new static-content version for every code, DI or deployment-configuration release**, and distribute the same version to all nodes serving that build. Magento's version is normally timestamp-based; a CI build ID supplied through static deployment's `--content-version` option avoids independent node timestamps and identifies PHP-only deployments too.

No additional FastBoot release value is needed. During rolling deployment, each immutable application release keeps its own static-content version while sharing the installation ID. If the version is absent, generic local caches fall back to Magento; schema L1 and the status command report the missing deployment step.

## Deploy

Use the project's normal Magento deployment pipeline: production mode, dependency installation, fresh generated code/DI and deployed static assets. In the new release:

```sh
bin/magento cache:clean config compiled_config
bin/magento setup:di:compile
bin/magento setup:static-content:deploy --content-version="$BUILD_ID" en_US
bin/magento fastboot:status
bin/magento fastboot:prepare
```

Supply your pipeline's build ID and required locales/themes. FastBoot has no database patches. Finish the application's normal deployment steps, restart the serving PHP-FPM service, and warm representative requests before routing customer traffic to the release. Preparation must follow static deployment so it uses the final deployment version. Run it on every node that needs local cache files.

`fastboot:status` checks configuration, cache-directory access and schema Redis connectivity. Check the same directory permissions as the FPM user. `fastboot:prepare` prepares area configuration, schema and store configuration; CLI preparation does not warm the web service's OPcache.

## Operate

Keep Magento's normal caches enabled. Store FastBoot files under each node's private `var/cache/fastboot/` directory, outside the public document root. Files may contain decrypted configuration values. [Cache limits](CONFIGURATION.md#local-cache-limits) bound admitted files, not OPcache memory.

Strict freshness is the default: schema loads check Redis metadata before using local data; generic caches check a shared generation once per request. An in-flight request can finish with data it already loaded. Missing local files are rebuilt or fetched from the authoritative cache. A strict schema Redis failure is reported rather than serving stale local data. See [cache behavior](CACHE.md).

Monitor PHP errors, Redis connectivity, OPcache capacity and real request latency. Exercise normal configuration/attribute saves and cross-node invalidation in staging. Keep invalidation hooks enabled on all nodes while any node uses FastBoot.

## Disable or roll back

For local development, class preload can stay disabled while FastBoot remains active. Diagnostic switches are documented for [developers](https://github.com/graphcommerce-org/magento2-GraphCommerce_FastBoot/blob/main/dev/README.md#feature-switches).

To remove FastBoot, first disable any configured class preload. Disable the FastBoot modules, rebuild generated code/DI, deploy a new static-content version and clean Magento caches through the normal deployment pipeline. Restart PHP-FPM. For release rollback, route to the previous application release and its corresponding FPM service and static-content version.
