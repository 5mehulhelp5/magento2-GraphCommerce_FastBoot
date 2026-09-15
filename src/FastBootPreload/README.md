# GraphCommerce_FastBootPreload

This module records class names used by real Magento requests. Its `preload.php` script loads those definitions when the PHP-FPM master starts, reducing the class-loading work repeated by requests.

It preloads class definitions only. GraphQL schema data, configuration values and cache expiry metadata are never added to the preload list.

## Record representative classes

When the list is missing or older than the compiled global DI metadata, a request starts a 15-minute recording window. Requests during that window append the classes they use to `fastboot/classes.txt` under Magento's configured `var` directory. Representative traffic should cover the application's stores and request types.

Set `FASTBOOT_RECORD=1` in the request process's environment to force recording outside that window. Remove it after recording. Recording resolves dependencies needed by deferred anonymous classes and excludes aliases, internal classes and PHP parser classes from the list. The list is capped at 20,000 names.

## Enable preload

After recording, configure PHP at FPM startup:

```ini
opcache.preload=/absolute/magento/root/vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php
; Set opcache.preload_user to the application user if the FPM master runs as root.
```

Use a dedicated FPM master/service for each preloaded application/release. Restart that master to load the recorded list, then warm and verify the new service. A missing list makes the script load nothing.

Preloaded definitions persist until the server process restarts. Recycling workers is insufficient; updating source timestamps does not replace a preloaded definition. See PHP's [preloading lifecycle](https://www.php.net/manual/en/opcache.preloading.php) and [preload configuration](https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.preload).

## Paths and deployment

Normally the script discovers Magento by walking upward from the package directory. For symlink/path installations, set `FASTBOOT_MAGENTO_ROOT` to the intended Magento root in the master/service environment before startup. A pool-only setting is too late. An explicit root without `vendor/autoload.php` and `app/bootstrap.php` stops startup.

The script reads `<Magento root>/var/fastboot/classes.txt`. If Magento's configured `var` directory differs, make the recorded list available at that node-local path before starting preload. The root override does not change the list's relative path.

Every changed code/DI release needs a fresh recording and master restart. `opcache.validate_timestamps=0` is optional and requires restarting FPM for all code changes. Remove the preload INI setting before uninstalling the package or removing its files.

Preload increases the master's memory footprint. Use the [combined memory measurements](../../docs/VALIDATION.md) when evaluating it; warm PHP allocations alone are insufficient. The [main README](../../README.md) describes the complete deployment sequence.
