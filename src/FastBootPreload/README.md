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

Restart FPM. With no recorded class list yet, it starts without preloaded classes and Magento works normally.

## Automatic recording

Normal Magento HTTP requests populate `var/cache/preload/classes.txt`. No recorder command, test script or CI job is required. The first completed request that detects a missing list, or a list older than the compiled DI metadata, starts a 15-minute recording window. Each request during that window adds the classes it loaded.

Use the storefront or let normal traffic reach Magento during that window. Requests served entirely by a CDN or Varnish do not reach the recorder. After recording, restart the FPM master to load the collected classes. The restart does not compile the application again.

Keep the list available across that restart. A container replacement that discards `var/cache` also discards the recording; use a persistent cache volume or a committed seed as described below. Updating the list does not change definitions already preloaded in a running master.

To record a fresh list, remove these two files and let requests populate it again:

```sh
rm -f var/cache/preload/classes.txt var/cache/preload/classes.recording
```

## Commit a seed for deployment

You can commit a recorded list to the project's Git repository. It contains class names, not serialized objects, customer data or absolute source paths. This lets every node preload from its first FPM start, without recording during installation or running Magento in the build pipeline.

Copy a recording from the same project's local, staging or production installation into a tracked file, for example at the project root:

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

The fresh timestamp prevents the recorder from replacing the seed because compiled metadata is newer. The FPM preload user must be able to read the restored file. For containers, perform this copy at startup if a runtime volume covers `var/cache`.

Refresh the committed seed when the application's class usage changes, particularly after adding modules. Missing entries still autoload normally; the seed determines preload coverage. CI only needs to package the committed file. Keeping it as a release artifact instead is also supported.

## FPM configuration

Restart the master after deploying code changes. Pools within one master share preloaded definitions; each application needs its own master.

For path/symlink installations, set `FASTBOOT_MAGENTO_ROOT` in the master's environment. Set `FASTBOOT_CACHE_DIR` when Magento uses a custom cache directory and adjust the recording/deployment paths accordingly. Pool environment settings are applied after preloading.

The startup script remains in the installed package.

## Disable

Remove `opcache.preload` and restart the master. FastBoot's data caches remain active.
