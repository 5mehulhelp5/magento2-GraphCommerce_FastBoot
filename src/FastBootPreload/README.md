# FastBoot class preload

## Record

```sh
bin/magento module:enable GraphCommerce_FastBootPreload
bin/magento setup:di:compile
```

The recorder captures classes from requests during the 15 minutes after compilation and writes `var/cache/preload/classes.txt`. Exercise the application paths to include before enabling preload.

## Configure

FPM startup configuration:

```ini
opcache.preload=/absolute/magento/root/vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php
; Required when the master runs as root:
; opcache.preload_user=magento
```

Restart the FPM master after recording or code changes. Pools within one master share preloaded definitions; each application needs its own master.

For path/symlink installations, set `FASTBOOT_MAGENTO_ROOT` in the master's environment. Set `FASTBOOT_CACHE_DIR` when Magento uses a custom cache directory. Pool environment settings are applied after preloading.

The startup script remains in the installed package. A missing class list results in an empty preload.

## Disable

Remove `opcache.preload` and restart the master. FastBoot's data caches remain active.
