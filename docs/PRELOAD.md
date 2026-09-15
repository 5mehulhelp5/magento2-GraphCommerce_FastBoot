# Optional PHP class preload

Class preload loads PHP class definitions when PHP-FPM starts. It is independent of [FastBoot's data caches](FASTBOOT.md): disabling preload keeps FastBoot and regular OPcache available.

Preloaded definitions remain fixed until the FPM master restarts. Use preload for immutable production releases. Leave it disabled during development when PHP files change frequently.

## Record classes

Enable the recorder module and compile the application:

```sh
bin/magento module:enable GraphCommerce_FastBootPreload
bin/magento setup:di:compile
```

Warm representative requests without preload, covering your stores, Luma pages, GraphQL operations and other relevant application paths. The recorder captures classes during a 15-minute window after compilation and writes `var/cache/preload/classes.txt`. It records definitions and their dependencies, not configuration values or customer objects.

## Enable

Configure the serving FPM master's PHP startup settings:

```ini
opcache.enable=1
opcache.preload=/absolute/magento/root/vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php
; Set the real application user when the master runs as root:
; opcache.preload_user=magento
```

The entry point stays in the installed package so cache-directory cleanup cannot delete the configured startup script. If the recorded class list is missing, startup succeeds without preloading those classes; warm and restart to restore preload.

Use an FPM master dedicated to this application. An existing service is sufficient if it serves only this application; separate pools in one shared master do not isolate preloaded classes. Restart the **master/service**, then warm and verify the application. Recycling individual workers does not replace preloaded definitions.

For symlink/path installations, set `FASTBOOT_MAGENTO_ROOT` to the Magento release root in the master/service environment before startup. With a custom Magento cache directory, also set `FASTBOOT_CACHE_DIR` to that directory. Pool-only environment settings are too late. Invalid explicit Magento roots stop startup.

Verify `opcache_get_status(false)` in the serving FPM service through private diagnostics: confirm preloaded classes and sufficient memory/script capacity. A CLI check cannot describe another service's OPcache. Budget for master, shared OPcache and worker memory, including overlapping services during deployment.

## Deploy changes

Record classes for each changed code/DI release and restart the master against that release before it receives traffic. Keep its Magento static-content deployment version aligned with the build. If `opcache.validate_timestamps=0`, every PHP change requires a master restart, including changes to classes outside the preload list.

## Disable

Remove `opcache.preload` from the service's startup configuration and restart its master. If the setting was supplied as a command-line argument or service environment/configuration, update that source and reload the service definition too.

Regular OPcache and FastBoot stay enabled. With preload disabled, local development can use `opcache.validate_timestamps=1` to detect PHP file changes. Remove the preload setting before uninstalling its package files.
