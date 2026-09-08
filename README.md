# GraphCommerce_FastBoot

A faster php-fpm bootstrap of Magento 2 and Mage-OS, for the deployments where every request starts from nothing.[^1] Three modules in one package: `FastBootCache` puts opcache PHP files in front of the configuration caches and is usable alone, `FastBoot` removes the work the framework repeats per request, `FastBootGraphQl` does the same for a GraphQL request. Install, enable, compile: an integration changes nothing. Every mechanism has a switch for the case where it must be off.

## Where a php-fpm request spends its start

A trivial GraphQL query (`storeConfig { store_code }`) on the shop this was built on took 61 ms in PHP before these modules, and the query itself ran at 51 ms. Sampled with excimer, the 51 ms before the query were:

| Cost | ms | What it is |
| --- | --- | --- |
| Cache loads | 17 | 18 loads from Redis: system config, scopes, stores, event configs, translations, the stitched GraphQL schema |
| Deserialise and decompress | 14 | `json_decode` of the 1.6 MB schema, `unserialize` of the rest, gzip |
| Store tables | 6 | three selects, on every request, when the scopes are not dumped into config.php |
| Connections | 4 | a MySQL and a Redis connection per request |
| Object manager and interception | 10 | fixed, as it seemed |

And the query itself, on every request: a walk over every declared GraphQL type (7 ms), the validation rules over the document (7 ms on a listing), the selects behind the response cache id (4) and the store config (1).

## Measured

The trivial query and the 24 item unfiltered listing on php-fpm, PHP time from the profiler (which adds a few milliseconds of its own) and time over the wire through nginx, medians of 21 and 41 requests, in the order the mechanisms were built:

| | Trivial, PHP | Trivial, before the query | Trivial, wire | Listing, PHP | Listing, wire |
| --- | --- | --- | --- | --- | --- |
| Before | 61 ms | 51 ms | 63 ms | 148 ms | 160 ms |
| Preload, persistent connections, phpredis | 51 ms | 40 ms | 61 ms | 147 ms | 153 ms |
| Opcache layers, schema array, scopes cache | 39 ms | 28 ms | 49 ms | 138 ms | 131 ms |
| Timestamps off, no session, no token reads, compiled config and collections, view config | 28 ms | 20 ms | | 119 ms | |
| System config array, lifetime entries, no type map walk, no MySQL connection | 22 ms | 5 ms | 33 ms | 113 ms | 119 ms |
| Scalars without the type walk, area diff, validated queries, cache id and store config from the cache, assertions off | 9 ms | 6 ms | 19 ms | 109 ms | 111 ms |
| Parsed queries from a file, the three modules | 8 ms | 6 ms | 18 ms | 95 ms | 107 ms |

The trivial request's query itself runs in 2 ms; the 24 item category listing runs in 59 ms. A request sends one Redis command per grace period and no SQL at all. The listing's remaining time is the search engine (the unfiltered search with its 30 aggregations, 45 to 60 ms) and the resolvers; on the PHP side the executor, the schema objects of the types the query touches, the document decoding and core's search request build share the rest.

Each switch alone, medians of 15 requests, PHP time: the row is what the request costs with that one mechanism off and every other one on. A difference under a millisecond is noise here.

| Off | Trivial, PHP | Listing, PHP |
| --- | --- | --- |
| nothing | 6.0 ms | 90 ms |
| `schema_scalars` | 15.6 ms | 100 ms |
| `schema_array` | 15.5 ms | 96 ms |
| `guest_tax_factor` | 14.5 ms | 107 ms |
| `website_stores` | 11.6 ms | 98 ms |
| `cache_files` | 11.0 ms | 105 ms |
| `deploy_config_unchanged` | 10.4 ms | 104 ms |
| `scopes_cache` | 9.5 ms | 103 ms |
| `validated_queries` | 8.3 ms | 102 ms |
| `system_config_array` | 7.8 ms | 91 ms |
| `area_config_diff` | 7.8 ms | 103 ms |
| `default_store` | 7.7 ms | 98 ms |
| `placeholder_url` | 7.7 ms | 102 ms |
| `quote_without_connection` | 7.5 ms | 99 ms |
| `view_config` | 6.0 ms | 90 ms |
| everything | 54.9 ms | 150 ms |

Two mechanisms hide behind others: the MySQL connection stays away only when the store config, the tax factor and the deployment check are all served from the cache, and the view config only matters to a request that asks for an image size, which neither of these queries does.

## What the modules do

**FastBootCache.** Every load of the `config`, `eav`, `translate`, `db_ddl`, `reflection`, `compiled_config` and `collections` cache types, and of the default frontend entries the other modules register (EAV types and attributes, resolved stores, the app config, DDL, the theme), answers from a PHP file under `var/fastboot/<cache>/<version>/` after the first request of a version. Opcache keeps the file in shared memory, so the load is an include of microseconds instead of a network read, a decompress and a deserialise. An entry saved with a lifetime is stored with its expiry; an entry the layer only loaded, whose lifetime the frontend does not tell, is read from the cache again after two hours. A version is a token in the shared cache under the config tag; a clean or a remove on a covered type bumps it on every server and sweeps the files, a flush removes the token and the next request past the grace starts a new version, and a server reads the token once per grace period (2 s) from a stamp file, so a request within the period opens no cache connection at all. The `Feature` switch reader lives here too.

**FastBoot.** The system configuration of every scope as one PHP array, so no scope entry is decrypted or unserialised per request. The theme's view.xml as a PHP array; core parses and validates it on every request that asks for an image or swatch size. The websites, groups and stores from one cache entry instead of three selects. No MySQL connection for a request that runs no query: Zend asks the PDO driver to quote a value, so a request that served every select from the cache still connected and ran the session statements; the deployment config check read a flag table per request. The object manager receives only the entries an area changes: the compiled metadata of an area holds the whole configuration again, and the object manager replaced its 15 000 arguments by themselves on every request. The stores of a website and the default store of a group without a collection load. And `bin/magento fastboot:preload`, which writes `var/fastboot/preload.php` from the classes real requests declared with `FASTBOOT_RECORD=1` in the php-fpm environment.

**FastBootGraphQl.** The stitched schema as a PHP array instead of 1.6 MB of JSON per request. A built-in scalar answered as such: webonyx looks for a scalar override in the schema's type list the first time a scalar value is completed, and Magento's type list is a closure that builds every declared type. A query parsed once, from the array form webonyx exports, and validated once per opcache version: the validation rules walk the document against the schema on every request otherwise. The guest tax rate factor of the response cache id, which loads the customer group, its tax class, its excluded websites and the tax rates on every response, from the cache, dropped by a tax or customer group save. The placeholder image URL from the cache.

Outside the modules, in the deployment: persistent MySQL and Redis connections in env.php (`persistent`), the phpredis extension instead of Predis, `opcache.validate_timestamps=0` with `opcache.file_update_protection=0`, `zend.assertions=-1` (webonyx's executor builds an assertion message per field otherwise; production's default), and `opcache.preload`. Each is worth a few milliseconds; none is needed for the modules to work.

## Switches

Every mechanism is on. A deployment turns one off in `app/etc/env.php`, since the reasons are per environment: a host whose `var/` is not shared the right way, a shop that must not have its configuration values on disk, a bench of one mechanism alone. The same array in `config.php` works as well, and is read together with env.php.

```php
'fastboot' => [
    'system_config_array' => false,
],
```

| Switch | Module | What it turns off |
| --- | --- | --- |
| `cache_files` | FastBootCache | the opcache files in front of the cache types and the default frontend |
| `system_config_array` | FastBoot | the system configuration as one PHP array on disk |
| `view_config` | FastBoot | the theme's view.xml as a PHP array |
| `scopes_cache` | FastBoot | the websites, groups and stores from one cache entry |
| `quote_without_connection` | FastBoot | quoting without a database connection |
| `deploy_config_unchanged` | FastBoot | the deployment config check from the cache |
| `area_config_diff` | FastBoot | the area diff for the object manager |
| `website_stores` | FastBoot | the stores of a website from the cache |
| `default_store` | FastBoot | the default store of a group from the store repository |
| `schema_array` | FastBootGraphQl | the schema as a PHP array |
| `schema_scalars` | FastBootGraphQl | built-in scalars without the type walk |
| `parsed_queries` | FastBootGraphQl | the parsed document from a file |
| `validated_queries` | FastBootGraphQl | validation once per version |
| `guest_tax_factor` | FastBootGraphQl | the guest tax factor of the cache id from the cache |
| `placeholder_url` | FastBootGraphQl | the placeholder image URL from the cache |

## How this was built

The method was the same for every step: sample one request with excimer, take the largest block that is not the query's own work, read the core code behind it, and replace it with the smallest hook Magento offers. Then measure the medians again and run the parity gate of the catalog storefront package on both runtimes. Every block turned out to be one of four kinds:

1. **A cache read that is a network round trip plus a deserialise.** Redis answers in microseconds, but a request made 18 of these reads and decompressed and unserialised each one, 30 ms in total. The fix keeps the same value as a PHP file that opcache holds in shared memory, in front of the cache, with the cache's own tags and lifetimes deciding when the file is dropped.
2. **Work that core repeats per request because it has no cache of its own.** The theme's view.xml, the area configuration merge, the type walk for a scalar override, the validation rules, the tax rates behind the cache id. Each got a plugin, a preference or a replaced factory that remembers the result under the config tag or under the opcache version.
3. **A connection made for nothing.** Quoting asked the PDO driver; the deployment check read a flag table.
4. **php-fpm settings left at their development defaults.**

Most hooks are ordinary plugins on public methods. Three are not: the GraphQL schema factory constructs its class with `new`, so the factory is replaced rather than the class; the object manager's config loader is a shared instance created before DI exists, so it is wired as a constructor argument of `Http` and `Area`; the stitched schema's config data is a virtual type, replaced by its type attribute. Nothing in `vendor/` is patched.

The hard part was finding the blocks, not writing the hooks. None of these costs had a span in the profiler's timeline; they hid inside the object manager, the cache frontend and the executor, and only a sampled trace with a window on the bootstrap shows them.

## Rolling this out

Install, enable, `setup:di:compile`. `var/fastboot/` must be writable by php-fpm and shared by nothing else than the servers that share the cache; two installations that share `var/` but not their cache keep their trees apart by the cache backend's identity. What a deployment may add, in the order of value:

| Part | What to do | Risk |
| --- | --- | --- |
| The modules | Nothing. | Low. The files follow the cache's tags and lifetimes; a config clean on any server drops them on every server within the grace period. |
| System config as a PHP array | Decide whether the values may be on disk. They are the values `app:config:dump` would write to config.php, decrypted, under `var/`, readable by the php-fpm user like `env.php` and the crypt key in it. | Low. Set `system_config_array` to false where they may not. |
| php-fpm ini | `opcache.validate_timestamps=0`, `opcache.file_update_protection=0`, `zend.assertions=-1`, and a php-fpm restart in the deployment after the code changed. | Medium. Without the restart php-fpm runs the old code. Most deployments already do this. |
| Persistent connections, phpredis | `persistent` in env.php for the database and the Redis backend; the phpredis extension. | Low. |
| Opcache preload | Record classes with `FASTBOOT_RECORD=1` on real traffic, run `fastboot:preload`, point `opcache.preload` at the file, restart php-fpm. | Medium. One preload serves one code base per php-fpm master; a preload script that fails stops php-fpm from starting. Worth 10 ms; do it last. |

What to watch after the rollout: opcache memory. Every config invalidation leaves one version of about a thousand files behind as wasted memory until opcache restarts itself; `opcache.max_wasted_percentage` and `opcache.memory_consumption` set how many invalidations a master lives through. A shop that cleans its config cache every minute needs a larger opcache; a shop that cleans it on deployments notices nothing.

What this does not do: it makes no request faster than its own work. A listing still waits for the search engine, and a request that runs SQL still connects. It removes the 50 ms every php-fpm request paid before its work began.

## Open work

- **Magento's own test suites.** The claim that a bootstrap this much faster breaks nothing needs the proof a developer will ask for: the unit, integration and API functional suites of Magento run with the three modules enabled, on a clean installation, and a report of every test that behaves differently. Until then the evidence is the catalog storefront package's parity gate (26 queries, both paths, both runtimes) and the shop this was built on.
- The parsed document from a file loses nothing for error messages, since every node gets the query as its source; a check that webonyx's `AST::toArray` covers every node kind Magento's schema uses belongs to the test suite above.
- What is left is in the framework: the object manager's factory reflects every class it creates (about 3 ms on a listing; Symfony's compiled container generates one method per service and caches its reflectors), the GraphQL config elements of the types a query touches are rebuilt per request (about 5 ms), and the EAV config creates its attribute objects per request (about 2.5 ms).

[^1]: A process that keeps everything between requests, such as [mage-os-lab/module-worker-mode](https://github.com/mage-os-lab/module-worker-mode), pays the start once; these modules are for php-fpm, where it is paid per request.
