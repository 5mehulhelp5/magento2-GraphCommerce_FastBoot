# Legacy local benchmark scripts

These scripts were written for the original development shop. They are retained as investigation tools and are excluded from the runtime ZIP. Use the [validation report](../../docs/VALIDATION.md) for the current measured results and the [test guide](../tests/README.md) for portable checks.

## `phpbench.sh`

Usage:

```sh
MAGENTO_ROOT=/absolute/path/to/magento ./dev/bench/phpbench.sh <url> <query.json> <runs> <label>
```

The script sends profiled requests using `mage-os/module-profiler` and reads the report files. It reports median PHP request time and the point where GraphQL query execution starts. It expects the original shop's catalog-storefront headers and reads its key through Magento's `catalog/storefront_documents/key` setting. Adapt those assumptions before using another installation.

## `ablate.sh`

This script is specific to the original host: it uses a fixed GraphQL URL, reads `var/fastboot/.key`, rewrites `app/etc/config.php` and stops the process listening on port 9084 between measurements. It restores configuration only at the end of normal execution. Its feature list predates the complete package, and its fallback Magento-root calculation depends on the original directory layout.

Do not use it as a customer deployment or general benchmark command. For a new comparison, use an isolated FPM process and explicit configuration backup/restoration. Measure native Magento with the modules disabled and fresh DI metadata, then compare individual features or the combined package under the same workload. Keep PHP time, client time, PHP allocation, OPcache and OS process memory as separate measurements.
