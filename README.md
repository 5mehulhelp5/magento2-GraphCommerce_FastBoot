# GraphCommerce FastBoot

FastBoot reduces the work Magento repeats at the start of each PHP-FPM request. It caches reusable configuration and GraphQL data as local PHP arrays, so OPcache can reuse the compiled data. Shared cache invalidation keeps the servers in sync. Optional class preloading reduces startup work further.

FastBoot runs with ordinary PHP-FPM. Each server keeps its own local cache; the GraphQL schema uses Redis as its authoritative shared cache. Application objects and customer sessions keep their normal request lifecycle.

**Status:** release candidate for customer staging. The package has been installed and tested on the targets below; customer-specific extensions and infrastructure still need staging validation. See [test results and memory measurements](docs/VALIDATION.md) and the [changelog](CHANGELOG.md).

## What it improves

| Area | Optimization |
|---|---|
| Magento startup | Load system configuration by requested scope and reuse compiled area configuration differences. |
| Configuration caches | Reuse local PHP values while preserving shared invalidation and backend expiry. |
| GraphQL | Reuse schema arrays, parsed queries and successful structural validation. Magento's processor, request-specific checks and custom validation rules still run. |
| Storefront helpers | Avoid repeated store lookups, view XML parsing and selected tax, placeholder and database-quoting work. |
| PHP startup | Optionally preload class definitions recorded from representative requests. |

Every optimization can be disabled separately. See the [configuration reference](docs/CONFIGURATION.md) for all switches and their defaults.

## Requirements

- Magento 2.4.8-era module APIs or compatible Mage-OS packages. Composer checks the exact dependency ranges.
- PHP 8.2–8.5 within the range supported by your Magento installation, with OPcache enabled for PHP-FPM.
- The phpredis extension and a writable Redis primary when schema L1 is enabled.
- A private, writable local `var` directory on each server.

Composer installs the required GraphQL and PHP parser dependencies. The PHP parser is used when preparing preload dependencies.

| Tested application | Coverage |
|---|---|
| Mage-OS 3.5 / PHP 8.4 | Project GraphQL queries, configuration saves, invalidation, memory and performance. |
| Magento 2.4.8 / PHP 8.3 and 8.4 | Core GraphQL, units, fresh DI compilation and preload using the installed ZIP. |
| PHP 8.5 | Unit tests only. |
| PHP 8.2 | Syntax checks only. |

The [validation report](docs/VALIDATION.md) describes the compatibility fixture and the limits of these checks. Schema L1 currently uses a direct Redis primary connection; Sentinel discovery and Redis Cluster routing are not implemented.

## Install and configure

Run these steps in the new Magento release directory as part of your normal deployment process.

### 1. Install the Composer package

Place the release ZIP in a Composer artifact directory, then run:

```sh
composer config repositories.fastboot artifact /absolute/path/to/artifacts
composer require graphcommerce/magento-fast-boot:0.2.0-rc3
bin/magento module:enable GraphCommerce_FastBootCache GraphCommerce_FastBoot GraphCommerce_FastBootGraphQl GraphCommerce_FastBootPreload
```

The ZIP is a Composer package. Composer extracts it under `vendor/graphcommerce/magento-fast-boot`. You can also distribute the package through your own Composer/VCS repository.

### 2. Add the deployment configuration

Merge this entry into the array returned by `app/etc/env.php`:

```php
'fastboot' => [
    'release' => 'shop-build-2026-09-15-001',
    'schema_l1' => [
        'enabled' => true,
        'installation' => 'my-shop-production',
        'release' => 'shop-build-2026-09-15-001',
        'grace' => 0,
    ],
],
```

Replace the example identities:

- **Installation:** a stable, unique ID for this shop/environment. All its web, admin and CLI nodes use the same value. Staging uses a different value from production.
- **Release:** an immutable application build ID. Both release fields use the same value. Nodes serving the same build agree on it; a new code, DI or deployment-config build gets a new value. During a rolling deployment, old and new builds retain their own release IDs and share the installation ID.

The schema connection inherits the default Magento cache frontend's Redis endpoint, database and credentials. If that frontend is not a directly usable Redis endpoint, configure an explicit connection. See [connection options and cache limits](docs/CONFIGURATION.md).

The 15 feature switches default to enabled. Schema L1 additionally requires `schema_l1.enabled=true` and the connection/identity settings above. Class preloading requires the separate PHP startup setting below.

### 3. Compile and prepare

Start with fresh generated code and DI metadata for the new release; do not carry compiled metadata over from an older package version. Then run:

```sh
bin/magento cache:clean config compiled_config
bin/magento setup:di:compile
bin/magento fastboot:status
bin/magento fastboot:prepare
```

Continue your project's normal Magento deployment steps. FastBoot itself ships no database schema or data patches.

`fastboot:status` checks release configuration, the generic local-cache directory and schema Redis connectivity. It reports feature switches. Also check directory access as the FPM user; a successful CLI check does not establish FPM permissions or OPcache health.

`fastboot:prepare` prepares area metadata, the GraphQL schema and default/store configuration scopes. It prepares files for the new release; it cannot warm a separate FPM process's OPcache. Run it on each node that needs local artifacts.

### 4. Warm and verify

Start the new FPM service and send representative requests to that release before routing customer traffic to it. Exercise your stores, customer/authentication flows, GraphQL queries, admin configuration saves and cache invalidation. Check the serving FPM process's OPcache usage and logs.

## Optional class preload

Start by warming representative requests without preload. The preload module records the classes those requests use, normally during a 15-minute recording window after compilation. Then add this setting to PHP's FPM startup configuration:

```ini
opcache.preload=/absolute/magento/root/vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php
; Set opcache.preload_user to the application user if the FPM master runs as root.
```

Use a dedicated FPM master/service for each preloaded Magento application/release. **Restart that master/service after recording the classes.** Recycling individual workers does not reload preloaded definitions. Warm the restarted service and verify it before directing traffic to it.

For a symlink/path installation, set `FASTBOOT_MAGENTO_ROOT=/absolute/magento/root` in the master/service environment before startup. A pool-only environment setting is too late. An invalid explicit root prevents startup.

Only class definitions are preloaded. Runtime cache values, TTLs and invalidation metadata remain changeable. See the [preload module guide](src/FastBootPreload/README.md) for recording details and path requirements.

## Freshness and memory

With the default `schema_l1.grace=0`, a warm schema load checks Redis metadata before using the local array. This is a check when loading the schema, **not a Redis call for every GraphQL field or array access**. Generic caches separately read a shared generation token once per request. An in-flight request can finish using data it already loaded when another node invalidates it.

A missing local value is fetched or rebuilt from its authoritative source. Redis remains authoritative for schema data; a strict schema read fails if Redis validation fails. It does not serve a stale local schema. See [how schema L1/L2 works](SCHEMA-L1.md).

Local file limits bound the amount of data admitted to the cache. They do not set OPcache's memory budget. Monitor OPcache in the serving FPM service and allow headroom for cold startup. Preload reduces worker costs but increases the master footprint; warm PHP allocation figures alone do not describe total server memory.

Keep cache directories private to each server and outside the public document root. Configuration files can contain decrypted settings. Retire old local caches with their release and FPM lifecycle: deleting files alone does not reclaim their compiled OPcache memory.

## Disable or roll back

To disable one optimization, set its switch to the PHP boolean `false` in the `fastboot` configuration and deploy the change with a new release ID and FPM restart. For example:

```php
'validated_queries' => false,
```

To bypass schema L1 while keeping shared invalidation active, set `schema_array=false` and **retain `schema_l1.enabled=true`**. Keep the module's invalidation hooks active on web, admin and CLI nodes while any node still uses schema L1.

Disabling Magento's config cache also bypasses the derived data caches. The [switch reference](docs/CONFIGURATION.md#feature-switches) identifies the remaining independent shortcuts.

To remove FastBoot entirely, remove the `opcache.preload` setting before removing its files. Disable the four modules, rebuild generated code/metadata and clean caches through your normal deployment process, then restart the FPM master/service. Restore the previous application release if that is your deployment's rollback mechanism.

## Further reading

- [Configuration reference](docs/CONFIGURATION.md): switches, Redis settings and local-cache limits.
- [Schema L1/L2 design](SCHEMA-L1.md): reads, writes, invalidation, TTL and failures.
- [Validation report](docs/VALIDATION.md): measured performance, memory and tested scope.
- [Luma category-page findings](docs/LUMA.md): regular HTML listings and Magento attribute caching.
- [Changelog](CHANGELOG.md): release changes.

GitHub Actions checks PHP 8.2–8.5 syntax and runs Magento 2.4.8 unit, Redis, DI and package-installation checks on PHP 8.3/8.4. It does not provision a customer storefront database or replace application staging.

For source contributors, the repository also contains `dev/tests/README.md` and `dev/bench/README.md`. Development tools and tests are excluded from the runtime ZIP.
