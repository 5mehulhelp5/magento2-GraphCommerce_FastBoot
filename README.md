# GraphCommerce_FastBoot

A faster php-fpm bootstrap of Magento. The worker runtime keeps everything between requests; this module is for the deployments that run php-fpm, where every request starts from nothing.

## Where a php-fpm request spends its start

A trivial GraphQL query (`storeConfig { store_code }`) on this shop, before this module, took 61 ms in PHP, and the query itself ran at 51 ms. Sampled with excimer, the 51 ms before the query were:

| Cost | ms | What it is |
| --- | --- | --- |
| Cache loads | 17 | 18 loads from Redis: system config, scopes, stores, event configs, translations, the stitched GraphQL schema |
| Deserialise and decompress | 14 | `json_decode` of the 1.6 MB schema, `unserialize` of the rest, gzip |
| Store tables | 6 | three selects, on every request, when the scopes are not dumped into config.php |
| Connections | 4 | a MySQL and a Redis connection per request |
| Object manager and interception | 10 | fixed |

## What the module does

- **Opcache in front of the configuration caches.** Every load of the `config`, `eav`, `translate`, `db_ddl`, `reflection`, `compiled_config` and `collections` cache types, and of the default frontend entries for EAV types and attributes, resolved stores, the app config, DDL and the theme, answers from a PHP file under `var/fastboot/<cache>/<version>/` after the first request of a version. Opcache keeps the file in shared memory, so the load is an include of microseconds instead of a network read, a decompress and a deserialise. An entry with a lifetime is stored with its expiry. A version is a token in the shared cache under the config tag; a clean or a remove on a covered type bumps it on every server and sweeps the files, and a server reads the token once per grace period (2 s) from a stamp file, so a request within the period opens no cache connection at all. (`Plugin/Cache/OpcacheLayer`, `Plugin/Cache/OpcacheDefaultLayer`, `Model/Cache/PhpFiles`, `Model/Cache/Version`)
- **The stitched GraphQL schema as a PHP array.** The 1.6 MB schema config is a `Magento\Framework\Config\Data` virtual type; `Model/Config/OpcacheData` takes its place by the di.xml type attribute and includes the array from a file, so the `json_decode` of 4 to 10 ms is gone too.
- **The system configuration as one PHP array.** `Plugin/Config/SystemFromFile` answers every `get` from one array of all scopes, so no scope entry is decrypted or unserialised per request. The values on disk are the values the database holds, the same exposure as a dumped config.php; a deployment that must not have them on disk removes the plugin in its di.xml.
- **The theme's view.xml as a PHP array.** Core parses and validates the view configuration on every request that asks for an image or swatch size, since it has no cache of its own. `Model/Config/OpcacheView` reads it from a file per area and theme. (`Model/Config/OpcacheViewFactory` names the theme)
- **The scopes from the cache.** The websites, groups and stores come from one cache entry instead of three selects per request, under the store tags that a store save cleans. (`Plugin/Store/ScopesCache`)
- **No MySQL connection for a request that runs no query.** Building a select quotes its conditions, and Zend asks the PDO driver for that, so a request that serves every select from the cache still connected and ran the three session statements. `Model/Db/Mysql` quotes without a connection until one exists. The deployment config check, which read the flag table on every request, remembers an unchanged configuration in the config cache. (`Plugin/Deploy/ConfigUnchanged`)
- **A built-in scalar answered as such.** webonyx looks for a scalar override in the schema's type list the first time a scalar value is completed, and Magento's type list is a closure that builds every declared type: hundreds of config elements, 7 ms per request. Magento maps the built-in scalar names to webonyx's own instances, so `Model/GraphQl/Schema` answers them at once. (`Model/GraphQl/SchemaFactory` hands it to the generator; the framework's factory constructs its own class)
- **Only the entries an area changes for the object manager.** The compiled metadata of an area holds the whole configuration again, and the object manager replaces its 15 000 arguments by themselves on every request. `Model/ObjectManager/AreaConfigLoader` hands `Http` and `Area` a diff against the global metadata from `var/fastboot/metadata/<area>.php`, which follows the metadata files' modification times.
- **A query validated once per opcache version.** The validation rules walk the document against the schema on every request under php-fpm, 7 ms for a product listing. A query that ran without errors is recorded under the version and runs without the rules from then on; a config cache clean, which every schema change makes, drops the record. (`Plugin/Query/ValidatedQueries`)
- **No select for the response cache id and the store config.** The guest tax rate factor of the cache id loads the customer group, its tax class, its excluded websites and the tax rates on every response; the storeConfig resolver loads the website's stores with a three-table select; the Store header asks the group for its default store through a collection load. The factor and the stores come from the cache (`Plugin/CacheId/GuestTaxFactor`, dropped by `Plugin/Tax/ForgetTaxFactors` on a tax or customer group save; `Plugin/Store/WebsiteStores`), the default store from the store repository (`Plugin/Store/DefaultStore`), the placeholder image URL from the cache (`Plugin/Catalog/PlaceholderUrl`).
- **Opcache preload from real requests.** `FASTBOOT_RECORD=1` in the php-fpm environment records the classes every request declares; `bin/magento fastboot:preload` writes `var/fastboot/preload.php` for `opcache.preload`. Preloaded classes change only with a php-fpm restart, and one preload serves one code base per php-fpm master.

Outside the module: persistent MySQL and Redis connections in env.php (`persistent`), the phpredis extension instead of Predis, `opcache.validate_timestamps=0` with `opcache.file_update_protection=0`, `zend.assertions=-1` (webonyx's executor builds an assertion message per field otherwise; production's default), the GraphQL session disabled (`graphql/session/disable`), and in the catalog storefront package: the prefilled field routing per materialised type instead of a walk of the whole type map, and the worker memos out of the way under php-fpm.

## Measured

The trivial query and the 24 item unfiltered listing on php-fpm, PHP time from the profiler (which adds a few milliseconds of its own) and time over the wire through nginx, medians of 21 and 41 requests:

| | Trivial, PHP | Trivial, before the query | Trivial, wire | Listing, PHP | Listing, wire |
| --- | --- | --- | --- | --- | --- |
| Before | 61 ms | 51 ms | 63 ms | 148 ms | 160 ms |
| Preload, persistent connections, phpredis | 51 ms | 40 ms | 61 ms | 147 ms | 153 ms |
| Opcache layers, schema array, scopes cache | 39 ms | 28 ms | 49 ms | 138 ms | 131 ms |
| Timestamps off, no session, no token reads, compiled config and collections, view config | 28 ms | 20 ms | | 119 ms | |
| System config array, lifetime entries, no type map walk, no MySQL connection | 22 ms | 5 ms | 33 ms | 113 ms | 119 ms |
| Scalars without the type walk, area diff, validated queries, cache id and store config from the cache, assertions off | 9 ms | 6 ms | 19 ms | 109 ms | 111 ms |

The last row's "before the query" holds the parse of the query; the trivial request's query itself runs in 2 ms. The 24 item category listing runs in 59 ms. A request sends one Redis command per grace period and no SQL at all. The listing's remaining time is the search engine (the unfiltered search with its 30 aggregations, 45 to 60 ms) and the resolvers, which the worker runtime serves the same way; on the PHP side the executor, the schema objects of the types the query touches, the document decoding and core's search request build share the rest.

## How this was built

The method was the same for every step: sample one request with excimer (`X-Mage-Profiler-Sample: 50`), take the largest block that is not the query's own work, read the core code behind it, and replace it with the smallest hook Magento offers. Then measure the medians again and run the parity gate on both runtimes. Every block turned out to be one of four kinds:

1. **A cache read that is a network round trip plus a deserialise.** Redis answers in microseconds, but a request made 18 of these reads and decompressed and unserialised each one, 30 ms in total. The fix is to keep the same value as a PHP file that opcache holds in shared memory, in front of the cache, with the cache's own tags and lifetimes deciding when the file is dropped. A plugin on the cache frontend does this for whole cache types; a second plugin covers the entries that go through no type.
2. **Work that core repeats per request because it has no cache of its own.** The theme's view.xml is parsed and validated on every request; the object manager merges the area configuration into the global one; webonyx materialises every declared type to look for a scalar override; the validation rules walk the query; the response cache id loads the tax rates. Each got a plugin, a preference or a replaced factory that remembers the result under the config tag or under the opcache version.
3. **A connection made for nothing.** Quoting a value asked the PDO driver, so a request that served every select from the cache still opened MySQL and ran the session statements; the deployment config check read a flag table. A subclass quotes without a connection, a plugin remembers an unchanged configuration.
4. **php-fpm settings that were left at their development defaults.** Timestamp validation and file update protection in opcache, assertions, persistent connections, the Redis client library.

Most of the hooks are ordinary plugins on public methods. Three are not: the GraphQL schema factory constructs its class with `new`, so the factory is replaced rather than the class; the object manager's config loader is a shared instance created before DI exists, so it is wired as a constructor argument of `Http` and `Area`; and the stitched schema's config data is a virtual type, replaced by its type attribute. Nothing in `vendor/` is patched.

The hard part was not writing the hooks but finding the blocks. The profiler's timeline shows spans, and none of these costs had a span: they hid inside the object manager, the cache frontend and the executor, and only a sampled trace with a window on the bootstrap shows them. Two of them, the type walk and the area merge, sat behind what looked like fixed framework cost.

## Rolling this out

The module is a normal Magento module: install, enable, `setup:di:compile`. The plugins and preferences apply to every area, so the admin and REST benefit from the cache layers as well; the GraphQL specific parts only run on GraphQL requests. What a deployment needs beyond that, in the order of value:

| Part | What to do | Risk |
| --- | --- | --- |
| Opcache layers, scopes, quoting, deployment check | Nothing. `var/fastboot/` must be writable by php-fpm and shared by nothing else than the servers that share the cache. | Low. The files follow the cache's tags; a config clean on any server drops them on every server within the grace period. |
| System config as a PHP array | Nothing, but decide whether the values may be on disk. They are the values `app:config:dump` would write to config.php. | Low. Remove the plugin in di.xml where they may not. |
| Validated queries, tax factor, website stores, placeholder | Nothing. | Low. A schema or tax change cleans the config cache and with it the records. |
| Area diff, schema scalars | Nothing. | Low. The diff follows the metadata files; the scalar answer is what Magento's own type registry returns. |
| php-fpm ini | `opcache.validate_timestamps=0`, `opcache.file_update_protection=0`, `zend.assertions=-1`, and a php-fpm restart in the deployment after the code changed. | Medium. Without the restart php-fpm runs the old code. Most deployments already do this. |
| Persistent connections, phpredis | `persistent` in env.php for the database and the Redis backend; the phpredis extension. | Low. |
| Opcache preload | Record classes with `FASTBOOT_RECORD=1` on real traffic, run `fastboot:preload`, point `opcache.preload` at the file, restart php-fpm. | Medium. One preload serves one code base per php-fpm master; a preload script that fails stops php-fpm from starting. Worth 10 ms; do it last. |

What to watch after the rollout: opcache memory. Every config invalidation leaves one version of about a thousand files behind as wasted memory until opcache restarts itself; `opcache.max_wasted_percentage` and `opcache.memory_consumption` set how many invalidations a master lives through. A shop that cleans its config cache every minute needs a larger opcache or a longer restart cadence; a shop that cleans it on deployments notices nothing.

What this does not do: it makes no request faster than its own work. A listing still waits for the search engine, and a request that runs SQL still connects. It removes the 50 ms every php-fpm request paid before its work began, which the worker runtime removed by keeping the process alive, and it does so with a module and three ini lines instead of a new runtime.

## Costs and limits

- Opcache keeps the compiled copy of a dropped file as wasted memory until its own restart. Each config invalidation leaves one version of files behind; `opcache.max_wasted_percentage` decides after how many invalidations a php-fpm master restarts opcache, and the file count per version (about a thousand here, most of them EAV attributes) is what a version costs.
- Two installations that share `var/` but not their cache, such as a php-fpm host and a worker container, keep their trees apart by the cache backend's identity.
- Another server's config clean reaches a server at the end of the grace period.

## Next

What is left is in the framework: the object manager's factory reflects every class it creates (about 3 ms on a listing), the GraphQL config elements of the types a query touches are rebuilt per request (about 5 ms), and the EAV config creates its attribute objects per request (about 2.5 ms). The parse of a large query (3 ms) could come from an opcache file through `AST::fromArray`, at the price of error locations. The Redis client is connected at the grace boundary by the cache backend factory even when no command follows.
