# GraphCommerce FastBoot

FastBoot reduces repeated Magento bootstrap and GraphQL work using node-local PHP data caches with shared invalidation. The package includes an independent PHP class preload module.

| Module | Documentation |
|---|---|
| FastBoot | [Installation and deployment](src/FastBoot/README.md) |
| FastBootCache | [Cache configuration and behavior](src/FastBootCache/README.md) |
| FastBootGraphQl | [GraphQL optimizations](src/FastBootGraphQl/README.md) |
| FastBootPreload | [Class preloading](src/FastBootPreload/README.md) |

[GraphQL and Luma benchmarks](https://github.com/graphcommerce-org/magento2-GraphCommerce_FastBoot/blob/main/dev/bench/RESULTS.md): local M5 Mac and deployed AMD EPYC server measurements.

## Requirements

- Magento 2.4.8 or compatible Mage-OS APIs.
- PHP 8.2–8.5, subject to the installed Magento version.
- A writable Redis primary for schema L1.
- Private, node-local cache storage.

Release candidate.
