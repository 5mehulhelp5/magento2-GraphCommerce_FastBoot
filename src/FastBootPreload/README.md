# FastBoot class preload

## Enable

```sh
bin/magento module:enable GraphCommerce_FastBootPreload
bin/magento setup:di:compile
```

Configure the FPM master:

```ini
opcache.preload=/absolute/magento/root/vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php
; Required when the master runs as root:
; opcache.preload_user=magento
```

Restart FPM. A missing class list produces an empty preload.

## Automatic recording

Magento HTTP requests automatically populate `var/cache/preload/classes.txt`. The first completed request that detects a missing list, or a list older than the compiled DI metadata, starts a 15-minute recording window.

After recording, restart the FPM master to load the collected classes.

To start a fresh recording:

```sh
rm -f var/cache/preload/classes.txt var/cache/preload/classes.recording
```

## Commit a seed for deployment

Commit a recorded class-name list to preload from the first FPM start of a deployment.

Copy a recording from the project's local, staging or production installation:

```sh
cp var/cache/preload/classes.txt preload-classes.txt
```

Commit `preload-classes.txt`. Keep the runtime list and recording marker under `var/cache` untracked. During deployment, after DI compilation and mounting runtime storage, restore the seed before starting FPM. Run as the application user:

```sh
mkdir -p var/cache/preload
install -m 0600 preload-classes.txt var/cache/preload/classes.txt
touch var/cache/preload/classes.txt
rm -f var/cache/preload/classes.recording
```

The fresh timestamp prevents the recorder from replacing the seed because compiled metadata is newer. For containers, perform this copy at startup if a runtime volume covers `var/cache`.

Refresh the seed when the application's class usage changes. It can also be distributed as a release artifact.

## FPM configuration

Restart the master after deploying code changes. Pools within one master share preloaded definitions; each application needs its own master.

For path/symlink installations, set `FASTBOOT_MAGENTO_ROOT` in the master's environment. Set `FASTBOOT_CACHE_DIR` when Magento uses a custom cache directory and adjust the recording/deployment paths accordingly. Pool environment settings are applied after preloading.

## Disable

Remove `opcache.preload` and restart the master. FastBoot's data caches remain active.
