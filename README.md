# GraphCommerce FastBoot

FastBoot reduces the configuration and GraphQL work Magento repeats in each PHP request. It stores reusable data in private local PHP files, loads them through OPcache, and preserves shared-cache invalidation.

FastBoot and PHP class preload are independent. FastBoot works with ordinary PHP-FPM and OPcache. Optional preload loads class definitions at PHP startup and requires a master restart after code changes; leave it disabled during everyday local development.

| User guide | Purpose |
|---|---|
| [FastBoot](src/FastBoot/README.md) | Install, configure, deploy and operate the package. |
| [Cache configuration](src/FastBootCache/README.md) | Redis connections, local paths, limits and freshness. |
| [GraphQL](src/FastBootGraphQl/README.md) | GraphQL behavior and extension compatibility. |
| [Class preload](src/FastBootPreload/README.md) | Record, enable and disable optional class preload. |

## Requirements

- Magento 2.4.8-era APIs or compatible Mage-OS packages; Composer checks exact module ranges.
- PHP 8.2–8.5 within the range supported by your Magento installation, with OPcache enabled.
- A writable Redis primary when using schema L1. The PHP Redis extension is optional.
- Private, writable, node-local cache storage.

Release candidate for customer staging. Validate your extensions and infrastructure before production rollout. See the [changelog](CHANGELOG.md) for release changes.

## Magento performance tips

These settings apply to Magento independently of FastBoot. Validate them with the customer's extensions and infrastructure.

### Attribute metadata caching

Magento can cache user-defined EAV attribute definitions, including labels, backend types and source-model settings:

```sh
bin/magento config:set dev/caching/cache_user_defined_attributes 1
bin/magento cache:clean config eav
```

If deployment configuration owns this setting, update and import it through that process. This caches metadata, not each product's attribute values, prices or stock. Confirm that extensions which update attribute tables perform normal cache invalidation. See [Adobe's attribute documentation](https://developer.adobe.com/commerce/php/development/components/attributes).

### Application and page caches

Use production mode, fresh compiled DI and deployed static assets. Keep configuration, EAV, layout, block and full-page caches enabled. If Magento uses Varnish, verify that traffic passes through Varnish and that both hits and invalidation work. An enabled Magento cache flag does not make a direct origin request a Varnish hit.

### PHP Redis extension

Enable `ext-redis` (phpredis) in the serving PHP and CLI environments to reduce PHP-side Redis client work. FastBoot uses it when available and otherwise uses Magento's Credis PHP client. The extension is a performance recommendation, not an installation or correctness requirement.

Schema L1 still needs a writable Redis server: atomic scripts coordinate publication, expiry and invalidation. Both clients use the same scripts and freshness rules. Measure end-to-end latency on your infrastructure; installing the extension does not remove Redis network round trips.

### PHP-FPM and OPcache

Enable OPcache and size its memory, interned strings and script capacity for the deployed application and generated data. Check capacity and restart counters after representative warmup. Keep timestamp validation enabled for editable deployments; disable it only when releases are immutable and the FPM master restarts on every code change. [PHP configuration reference](https://www.php.net/manual/en/opcache.configuration.php).

Size FPM workers using realistic concurrency, CPU/database capacity and measured process memory. Reserve memory for the OS, other services, shared OPcache and deployment overlap. PHP's per-request allocation peak is not worker RSS; shared pages must not be counted as private memory for every worker. Monitor queueing and slow requests. [FPM reference](https://www.php.net/manual/en/install.fpm.configuration.php).

## Development

Implementation notes, diagnostic switches, tests and measurements are maintained separately in the [internal development documentation](https://github.com/graphcommerce-org/magento2-GraphCommerce_FastBoot/blob/main/dev/README.md).
