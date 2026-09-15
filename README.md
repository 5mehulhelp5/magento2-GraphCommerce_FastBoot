# GraphCommerce FastBoot

FastBoot reduces the configuration and GraphQL work Magento repeats in each PHP request. It keeps reusable data in private local PHP files, uses OPcache to load them efficiently, and preserves shared-cache invalidation through Redis and Magento's cache APIs.

**FastBoot and class preload are separate features.** FastBoot works with ordinary PHP-FPM and OPcache. Optional preload loads class definitions when PHP starts; it requires a restart after code changes and is usually unsuitable for everyday local development.

| Guide | Purpose |
|---|---|
| [FastBoot](docs/FASTBOOT.md) | Install, configure, deploy and operate the caches and startup optimizations. |
| [Class preload](docs/PRELOAD.md) | Enable, warm and disable optional PHP class preloading. |
| [Magento performance tips](docs/PERFORMANCE.md) | General Magento and PHP settings, including attribute metadata caching. |
| [Configuration reference](docs/CONFIGURATION.md) | Redis connections, local paths and cache limits. |
| [Cache behavior](docs/CACHE.md) | Freshness, TTL, invalidation and failures. |
| [Development](https://github.com/graphcommerce-org/magento2-GraphCommerce_FastBoot/blob/main/dev/README.md) | Tests, benchmarks and diagnostic feature switches. |

## Requirements

- Magento 2.4.8-era APIs or compatible Mage-OS packages; Composer checks exact module ranges.
- PHP 8.2–8.5 within the version range supported by your Magento installation, with OPcache enabled.
- phpredis and a writable Redis primary for the GraphQL schema cache.
- A private, writable, node-local cache directory.

**Status: release candidate for customer staging.** CI checks syntax on PHP 8.2–8.5 and Magento 2.4.8 units, Redis behavior, DI compilation and packaging on PHP 8.3/8.4. Validate your extensions and infrastructure in staging before production rollout.

See the [changelog](CHANGELOG.md) for release changes.
