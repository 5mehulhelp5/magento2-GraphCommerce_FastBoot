# Production setup

FastBoot's configuration and PHP's configuration are separate. Composer installation enables neither PHP preload nor an appropriately sized FPM service. This guide covers the deployment settings needed to evaluate the release candidate in customer staging and carry a validated configuration into production.

## Serve the intended FPM service

Use your normal web server with Magento's supported routing and `pub` document root, forwarding PHP requests to the FPM service for this release. Serve static files and media through the web server or CDN. PHP's `php -S` development server is useful for browsing a fixture, but is not the runtime used for the published measurements.

OPcache is a PHP extension, not an FPM-only feature. The built-in server can use it too: our PHP 8.4 preview reported active OPcache and cached scripts under `cli-server`, even with `opcache.enable_cli=0`. It had no class preload configured. Check the serving process rather than inferring its state from a CLI setting. FPM is the production runtime tested by this package, not a prerequisite for the local-cache algorithm. PHP's [built-in server documentation](https://www.php.net/manual/en/features.commandline.webserver.php) explains its development-only scope.

Give each preloaded Magento application/release its own FPM master/service. Separate pools inside a shared master are not separate preload lifecycles. During a rolling deployment, route warmup requests to the new service explicitly before switching customer traffic.

## OPcache and preload

The following values reproduce the explicit OPcache overrides in the Luma benchmark. They are a measured starting configuration, not universal capacity recommendations:

```ini
; PHP configuration loaded by the dedicated FPM service at startup.
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=65407

; Use only with immutable releases and a master restart on every code change.
opcache.validate_timestamps=0

; Benchmark setting: files are published completely before use.
opcache.file_update_protection=0

; Add after representative requests have recorded the class list.
opcache.preload=/absolute/magento/root/vendor/graphcommerce/magento-fast-boot/src/FastBootPreload/preload.php
; If the master starts as root, set the actual application user:
; opcache.preload_user=magento
```

Size the OPcache memory and script capacity for the customer's code, generated code and FastBoot files. Increasing a budget that already has sufficient headroom is not an established speed improvement. The generic and schema file admission limits do not size OPcache for you.

If deployment can edit code in place, retain timestamp validation. Preloaded classes still require a master restart. Leave file-update protection at its normal value unless all writers publish complete files safely; zero was an explicit benchmark choice, not a FastBoot requirement. See PHP's [OPcache configuration](https://www.php.net/manual/en/opcache.configuration.php).

Keep other PHP settings aligned with the supported Magento installation. The reported gains do not depend on JIT or a special optimizer bitmask. Do not disable extension-required PHP behavior merely to match a synthetic configuration.

For symlink/path installations, set `FASTBOOT_MAGENTO_ROOT` to the absolute Magento release root in the **master/service environment before startup**. A pool's `env[...]` setting is too late for preload. The script reads `<Magento root>/var/fastboot/classes.txt`; a missing list loads no classes. See [recording and custom-var requirements](../src/FastBootPreload/README.md).

## FPM capacity

The benchmark used `pm=static` and `pm.max_children=1` to measure a warm worker without concurrent traffic. **Do not copy that worker count into a customer deployment.** It establishes per-request cost, not throughput or a production sizing result.

Choose `dynamic` or `static` according to the hosting platform. For dynamic pools, keep enough starting/spare workers for normal traffic. Set `pm.max_children` using representative loaded-worker memory, available RAM and a concurrency test. `pm.max_requests` can recycle workers but does not reload preloaded code. PHP documents these controls in its [FPM configuration reference](https://www.php.net/manual/en/install.fpm.configuration.php).

For an initial memory estimate, reserve OS, web server, other services, master and shared-memory headroom; divide the remaining worker budget by measured per-worker private memory under realistic requests. Validate that estimate against whole-service memory under load. Do not multiply the reported 8 MiB PHP allocation peak by a worker count, or sum worker RSS as though shared OPcache pages were private. Include overlap between old and new services during deployment. CPU and database capacity may require fewer workers than RAM permits.

Measure queueing, CPU, database/search latency and memory under concurrency. More workers do not necessarily make an individual request faster. Keep FPM status/slow-request diagnostics available through the platform's private monitoring path.

## Magento settings

Use production mode, fresh compiled DI and deployed static assets through the project's normal deployment process. Keep Magento's configuration, EAV, layout, block and full-page caches enabled for customer traffic. Adobe's [software recommendations](https://experienceleague.adobe.com/en/docs/commerce-operations/performance-best-practices/software) cover the broader Magento stack.

For Luma, evaluate the existing user-defined attribute metadata cache in staging:

```sh
bin/magento config:set dev/caching/cache_user_defined_attributes 1
bin/magento cache:clean config eav
```

If deployment configuration owns this setting, update and import it through that process instead. FastBoot does not enable it automatically. It caches attribute definitions, not product values. Test extension-driven attribute updates and invalidation. See the [measured Luma findings](LUMA.md#attribute-cache-configuration) and [Adobe's attribute documentation](https://developer.adobe.com/commerce/php/development/components/attributes).

When Magento is configured for Varnish, send customer HTML requests through Varnish and verify actual hits and invalidation. An enabled Magento full-page cache flag does not mean a direct origin request is a Varnish hit. Measure cache hits and PHP-rendered misses separately. Keep existing session storage and customer-state handling; FastBoot does not replace either.

Configure the [release IDs, Redis primary and node-local paths](CONFIGURATION.md) on every applicable web, admin and CLI node. Keep strict schema freshness at `grace=0` for the documented configuration. Redis remains part of the request path for freshness checks, so measure with the customer's real Redis topology rather than extrapolating localhost latency.

## Deploy and warm each release

1. Build a new immutable release with dependencies, fresh generated code/DI, static assets and the project's normal Magento deployment steps. Set new matching FastBoot release IDs and retain the installation identity.
2. Run `fastboot:status` and `fastboot:prepare` on each node as described in the [installation guide](../README.md#3-compile-and-prepare). Check filesystem permissions as the FPM user too.
3. Start the new dedicated service without preload. Send representative requests directly to that release so FPC/CDN hits cannot hide the PHP paths. Cover relevant stores, Luma pages and GraphQL operations. The recorder normally runs for 15 minutes after compilation; inspect the resulting class list before proceeding.
4. Enable the preload startup setting and restart the new master/service. Warm it again. CLI preparation alone cannot warm the serving FPM service's OPcache.
5. Verify the serving service, then direct traffic to it. Drain the old service before retiring its files and local caches. Keep enough capacity for both services during the transition.

For rollback, route to a verified prior release with its corresponding service and IDs. Removing FastBoot requires removing the preload setting before removing its files, rebuilding DI and restarting the master. See [rollback instructions](../README.md#disable-or-roll-back).

## Verify the service that receives traffic

Use private diagnostics in the actual serving FPM service to confirm the loaded INI, preload class count, OPcache memory/script headroom and restart counters after representative warmup. Neither CLI `php -i` nor `fastboot:status` verifies that service's preload state. Avoid leaving public `phpinfo()` or OPcache diagnostic endpoints behind.

Verify rendered data and cross-node invalidation after a normal configuration/attribute save. Monitor FPM errors and cache failures. Repeat a concurrency test with the customer extension set and realistic traffic before choosing worker capacity.

Record three distinct latency measurements:

| Measurement | What it includes |
|---|---|
| PHP execution | Application work measured inside the worker. |
| Document TTFB | Connection, web/proxy path, queueing and time to the first response byte. |
| Browser page loading | HTML plus CSS, JavaScript, media and browser work. |

The [Luma report](LUMA.md) measured warm PHP rendering with FPC disabled in a disposable fixture. Its 54.7 ms Bags result is not a promised browser load time or a production latency target. Compare native and FastBoot with the same FPM settings, workload and cache state; then separately compare preload and Magento attribute caching. Report relative changes on the target hardware.
