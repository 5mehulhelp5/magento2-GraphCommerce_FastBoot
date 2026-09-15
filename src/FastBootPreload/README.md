# FastBoot class preload

## Record a release

Recording runs automatically at the end of Magento HTTP requests. There is no separate recorder command. Enable the module before compiling the release:

```sh
bin/magento module:enable GraphCommerce_FastBootPreload
bin/magento setup:di:compile
```

Run the compiled release in a staging or CI environment with its database, Redis and search services, initially without class preloading. The first completed request starts a 15-minute recording window if `var/cache/preload/classes.txt` is missing or older than the compiled DI metadata. Requests during that window append their declared class names to the file.

To start a fresh recording on that environment, remove the list and recording marker, then run the application's HTTP smoke tests:

```sh
rm -f var/cache/preload/classes.txt var/cache/preload/classes.recording

curl --fail --silent --show-error "$BASE_URL/" -o /dev/null
curl --fail --silent --show-error "$BASE_URL/graphql" \
  -H 'Content-Type: application/json' \
  --data '{"query":"{ storeConfig { store_code } }"}' -o /dev/null
```

Set `BASE_URL` to the recording environment. Include the category, product, cart, checkout and GraphQL requests the application actually serves. Route these requests to Magento; responses served entirely by a CDN or Varnish do not record classes. Compilation alone does not populate the list. Record without an existing class preload so it does not contribute classes from an earlier release.

After the requests finish, close the recording window and export the list:

```sh
test -s var/cache/preload/classes.txt
rm -f var/cache/preload/classes.recording
mkdir -p build
cp var/cache/preload/classes.txt build/preload-classes.txt
```

## Build and deployment

Keep the generated list out of Git. Store `preload-classes.txt` with the compiled release as a CI artifact or in the application image, outside the runtime cache mount. Generate it after compilation and HTTP smoke tests; a build without a running Magento environment cannot record request classes.

On each node, restore that release's list after mounting runtime storage and before starting FPM. Run as the application user, from the Magento root:

```sh
mkdir -p var/cache/preload
install -m 0600 /opt/fastboot/preload-classes.txt var/cache/preload/classes.txt
touch var/cache/preload/classes.txt
rm -f var/cache/preload/classes.recording
```

Here `/opt/fastboot/preload-classes.txt` is the artifact bundled with the release. Its restored timestamp must be at least as recent as the compiled DI metadata; otherwise the recorder replaces it on the next request. Do not deploy the recording marker. The FPM preload user must be able to read the list.

Use the same artifact on every node running that release. Each node keeps its own runtime copy. Re-record after code, dependency or module changes, and restart the FPM master with the new release and list.

## Configure

FPM startup configuration:

```ini
opcache.preload=/absolute/magento/root/vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php
; Required when the master runs as root:
; opcache.preload_user=magento
```

Start the FPM master after restoring the list. Updating the file does not change classes already preloaded in a running master. Pools within one master share preloaded definitions; each application needs its own master.

For path/symlink installations, set `FASTBOOT_MAGENTO_ROOT` in the master's environment. Set `FASTBOOT_CACHE_DIR` when Magento uses a custom cache directory. Pool environment settings are applied after preloading.

The startup script remains in the installed package. A missing class list results in an empty preload.

## Disable

Remove `opcache.preload` and restart the master. FastBoot's data caches remain active.
