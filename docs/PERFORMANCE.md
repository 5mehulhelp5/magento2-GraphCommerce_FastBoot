# Magento performance tips

These settings apply to Magento independently of FastBoot. Validate them with the customer's extensions and infrastructure.

## Attribute metadata caching

Magento can cache user-defined EAV attribute definitions, including labels, backend types and source-model settings:

```sh
bin/magento config:set dev/caching/cache_user_defined_attributes 1
bin/magento cache:clean config eav
```

If deployment configuration owns this setting, update and import it through that process. This caches metadata, not each product's attribute values, prices or stock. Confirm that extensions which update attribute tables perform normal cache invalidation. See [Adobe's attribute documentation](https://developer.adobe.com/commerce/php/development/components/attributes).

## Application and page caches

Use production mode, fresh compiled DI and deployed static assets. Keep configuration, EAV, layout, block and full-page caches enabled. If Magento uses Varnish, verify that traffic passes through Varnish and that both hits and invalidation work. An enabled Magento cache flag does not make a direct origin request a Varnish hit.

## PHP-FPM and OPcache

Enable OPcache and size its memory, interned strings and script capacity for the deployed application and generated data. Check capacity and restart counters after representative warmup. Keep timestamp validation enabled for editable deployments; disable it only when releases are immutable and the FPM master restarts on every code change. [PHP configuration reference](https://www.php.net/manual/en/opcache.configuration.php).

Size FPM workers using realistic concurrency, CPU/database capacity and measured process memory. Reserve memory for the OS, other services, shared OPcache and deployment overlap. PHP's per-request allocation peak is not worker RSS; shared pages must not be counted as private memory for every worker. Monitor queueing and slow requests. [FPM reference](https://www.php.net/manual/en/install.fpm.configuration.php).

## Measure the right boundary

Separate application execution time, HTTP time to first byte and browser page loading. Compare PHP-rendered misses separately from full-page cache hits. Use the same workload and settings, alternate comparison runs, and inspect database/search latency before attributing a whole-request difference to startup. [Developer tools](https://github.com/graphcommerce-org/magento2-GraphCommerce_FastBoot/blob/main/dev/bench/README.md).
