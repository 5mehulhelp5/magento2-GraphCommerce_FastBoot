# GraphCommerce_FastBoot

A faster php-fpm bootstrap of Magento 2 and Mage-OS, for the deployments where every request starts from nothing.[^1] Four modules in one package: `FastBootCache` puts opcache PHP files in front of the configuration caches and is usable alone, `FastBoot` removes the work the framework repeats per request, `FastBootGraphQl` does the same for a GraphQL request, `FastBootPreload` keeps an opcache preload list that records itself. Install, enable, compile: an integration changes nothing. Every mechanism has a switch for the case where it must be off.

## Numbers

PHP time from the profiler, medians, on a shop with 500 000 products; the trivial query is `storeConfig { store_code }`, the listings ask 24 products with facets.

| | Trivial | Unfiltered listing | Category listing |
| --- | --- | --- | --- |
| Before | 61 ms | 148 ms | 109 ms |
| With the modules | 8 ms | 95 ms | 50 ms |

Before, the 51 ms in front of the trivial query were 18 cache loads from Redis with their decompress and unserialise (30 ms), three store selects (6), a MySQL and a Redis connection (4) and the object manager (10); the query itself walked every declared GraphQL type (7) and loaded the tax rates for the response cache id (4). Now a request sends one Redis command per two seconds and no SQL, the trivial query runs in 2 ms, and the listings' remaining time is the search engine (45 to 60 ms of the unfiltered one) and the resolvers.

What each mechanism is worth alone, as the increase when only it is off, from the switch bench in `dev/bench` (medians of 15; preload from its own before and after; under a millisecond is noise, and the sum exceeds the total because the MySQL connection only stays away when the store config, the tax factor and the deployment check are all cached):

| Switch | Module | What it remembers | Trivial | Listing |
| --- | --- | --- | --- | --- |
| `opcache.preload` (ini line) | Preload | the classes a request declares, linked once at php-fpm start | 10 ms | 1 ms |
| `schema_scalars` | GraphQl | a built-in scalar without a walk over every declared type | 10 ms | 10 ms |
| `schema_array` | GraphQl | the stitched schema as a PHP array, not 1.6 MB of JSON | 10 ms | 5 ms |
| `guest_tax_factor` | GraphQl | the guest tax factor of the response cache id, four selects | 9 ms | 17 ms |
| `cache_files` | Cache | the configuration cache types and default frontend entries as opcache files | 5 ms | 15 ms |
| `website_stores` | FastBoot | the stores of a website, a three-table select | 6 ms | 7 ms |
| `deploy_config_unchanged` | FastBoot | the deployment config check, a flag table read | 4 ms | 13 ms |
| `scopes_cache` | FastBoot | the websites, groups and stores, three selects | 4 ms | 13 ms |
| `validated_queries` | GraphQl | the validation rules over the document, once per version | 2 ms | 12 ms |
| `area_config_diff` | FastBoot | only the entries an area changes for the object manager, not 15 000 again | 2 ms | 12 ms |
| `placeholder_url` | GraphQl | the placeholder image URL, theme and asset context | 2 ms | 12 ms |
| `default_store` | FastBoot | the default store of a group, a collection load | 2 ms | 8 ms |
| `quote_without_connection` | FastBoot | quoting without a database connection | 2 ms | 9 ms |
| `system_config_array` | FastBoot | the system configuration as one PHP array, no decrypt or unserialise | 2 ms | 1 ms |
| `parsed_queries` | GraphQl | the parsed document from a file | 0 ms | 3 ms |
| `view_config` | FastBoot | the theme's view.xml as a PHP array; only a request that asks an image size pays it | 0 ms | 0 ms |

Outside the modules, each worth a few milliseconds: persistent MySQL and Redis connections, phpredis instead of Predis, `opcache.validate_timestamps=0` with `opcache.file_update_protection=0`, and `zend.assertions=-1` (webonyx's executor builds an assertion message per field otherwise; production's default). Tracing JIT was measured and made both requests slower.

## What the modules do

**FastBootCache.** A load of a covered cache type (`config`, `eav`, `translate`, `db_ddl`, `reflection`, `compiled_config`, `collections`) or default frontend entry (EAV, resolved stores, app config, DDL, theme; other modules register theirs) answers from a PHP file under `var/fastboot/<cache>/<version>/` after the first request of a version: an include of shared memory instead of a network read, a decompress and a deserialise. An entry saved with a lifetime keeps its expiry in the file; an entry only loaded, whose lifetime the frontend does not tell, is read from the cache again after two hours. The version is a token in the shared cache under the config tag: a clean or a remove on a covered type bumps it on every server and sweeps the files, a flush removes it and the next request past the grace starts a new version, and a server reads it once per two second grace from a stamp file, so a request within the grace opens no cache connection. A file holds scalars and arrays through `var_export`, never an object. `Model\Feature` reads the switches.

**FastBoot.** The system configuration as one PHP array; the theme's view.xml as one; the scopes, the stores of a website and the default store of a group without selects; the deployment config check from the cache; quoting without a database connection (Zend asks the PDO driver, so a request that served every select from the cache still connected and ran the session statements); and only the entries an area changes for the object manager (the compiled area metadata holds the whole configuration again, and it was merged entry by entry on every request).

**FastBootGraphQl.** The stitched schema as a PHP array; a built-in scalar answered as such (webonyx looks for a scalar override in the schema's type list the first time a scalar value is completed, and Magento's type list is a closure that builds every declared type); a query parsed once, from the array form webonyx exports with the query as every node's source, and validated once per opcache version; the guest tax factor of the response cache id from the cache, dropped by a tax or customer group save; the placeholder image URL from the cache.

**FastBootPreload.** A list of the classes real requests declare, in `var/fastboot/classes.txt`, that records itself for fifteen minutes after every compile, and a `preload.php` that loads it at php-fpm start. A shipped list cannot do this: a fifth of the 5 000 classes a request declares are the interceptors, proxies and factories generated for the shop's plugin configuration, an eighth its own and third party modules. What preload costs and saves: the master runs the script once before it forks a worker and compiles and links every class into opcache's shared memory; the workers share that segment and keep the classes between requests. Without preload a request pays per class for the autoloader, the include and the linking into its own process, about 2 µs a class and 10 ms for a listing. That work is gone, not moved; the memory is paid once per master (55 MB here) whatever the number of workers, and an unused preloaded class costs nothing per request. The costs sit at the edges: the master starts slower, the segment must fit `opcache.memory_consumption`, a class whose parent is missing warns, a script error stops the master, and preloaded classes ignore `opcache.validate_timestamps` until a restart. The list only grows while preload is on, since a preloaded class is declared in every request; a deleted class is skipped at start, one no longer used stays until the list is deleted and recorded again with the ini line off for one restart.

## Switches

Every mechanism is on. A deployment turns one off in `app/etc/env.php` (the same array in `config.php` is read too), since the reasons are per environment: a host whose `var/` is not shared the right way, a shop that must not have its configuration values on disk, a bench of one mechanism alone.

```php
'fastboot' => [
    'system_config_array' => false,
],
```

## How this was built

Sample one request with excimer, take the largest block that is not the query's own work, read the core code behind it, replace it with the smallest hook Magento offers, measure the medians again, run the parity gate of the catalog storefront package on both runtimes. Every block was one of four kinds: a cache read that is a network round trip plus a deserialise; work core repeats per request because it has no cache of its own; a connection made for nothing; a php-fpm setting left at its development default. Most hooks are plugins on public methods. Three are not: the GraphQL schema factory constructs its class with `new`, so the factory is replaced; the object manager's config loader is a shared instance created before DI exists, so it is a constructor argument of `Http` and `Area`; the stitched schema's config data is a virtual type, replaced by its type attribute. Nothing in `vendor/` is patched. The hard part was finding the blocks: none had a span in the profiler's timeline, and only a sampled trace with a window on the bootstrap shows them.

## Rolling this out

Install, enable, `setup:di:compile`. `var/fastboot/` must be writable by php-fpm and shared by nothing else than the servers that share the cache; two installations that share `var/` but not their cache keep their trees apart by the cache backend's identity. Then, in the order of value:

| Part | What to do | Risk |
| --- | --- | --- |
| The modules | Nothing. | Low. The files follow the cache's tags and lifetimes; a clean on any server reaches every server within the grace. |
| System config as a PHP array | Decide whether the values may be on disk: they are the values `app:config:dump` writes, decrypted, under `var/`, readable by the php-fpm user like `env.php` and the crypt key in it. | Low. Set `system_config_array` to false where they may not. |
| php-fpm ini | `opcache.validate_timestamps=0`, `opcache.file_update_protection=0`, `zend.assertions=-1`, and a php-fpm restart in the deployment. | Medium. Without the restart php-fpm runs the old code. |
| Persistent connections, phpredis | `persistent` in env.php for the database and the Redis backend; the phpredis extension. | Low. |
| Preload | `opcache.preload=<root>/app/code/GraphCommerce/FastBootPreload/preload.php` (or its vendor path), `opcache.preload_user` where php-fpm runs as root, restart. The list fills itself; the script loads nothing without one. | Medium. One preload serves one code base per master. |

What to watch: opcache memory. Every config invalidation leaves one version of about a thousand files as wasted memory until opcache restarts itself; `opcache.max_wasted_percentage` and `opcache.memory_consumption` set how many invalidations a master lives through. A shop that cleans its config cache every minute needs a larger opcache; one that cleans it on deployments notices nothing. And what this does not do: it makes no request faster than its own work; it removes the 50 ms every php-fpm request paid before its work began.

## Open work

- **Magento's own test suites.** The proof a developer asks for: the unit, integration and API functional suites run with the modules enabled on a clean installation, and a report of every test that behaves differently. Until then the evidence is the catalog storefront package's parity gate (26 queries, both paths, both runtimes) and the shop this was built on.
- **Symfony's cache adapters.** `PhpArrayAdapter` is the deploy-time form of the file layer: one warmed, read-only file with a fallback pool, right for the schema, the system and the view configuration, so production never writes an executable file. `PhpFilesAdapter` is the per-item form and could carry the file format. Neither answers the cross-server invalidation the version token does.
- **The framework.** The object manager's factory reflects every class it creates (about 3 ms on a listing; Symfony's compiled container generates a method per service and caches its reflectors), the GraphQL config elements of the types a query touches are rebuilt per request (5 ms), the EAV config creates its attribute objects per request (2.5 ms).
- **The placeholder URL** has one caller, the product fields prefill of the catalog storefront package, and belongs there.

[^1]: A process that keeps everything between requests, such as [mage-os-lab/module-worker-mode](https://github.com/mage-os-lab/module-worker-mode), pays the start once; these modules are for php-fpm, where it is paid per request.
