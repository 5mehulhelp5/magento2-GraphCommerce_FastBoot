# Configuration reference

Normal setup is covered by the [FastBoot guide](FASTBOOT.md). Configure options under `fastboot` in `app/etc/env.php`, using PHP booleans and numbers.

## Deployment identity

Magento's static-content deployment version identifies the application build. Use the same version on all nodes serving a build and change it on every code/DI/config deployment. `schema_l1.installation` stays stable for one shop/environment; explicit IDs are recommended, with the default cache `id_prefix` as fallback.

## Redis connection

FastBoot uses phpredis when installed and otherwise uses Credis in PHP. No client selection setting is needed.

Place these entries under `fastboot.schema_l1`:

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Configures the Redis schema backend and its invalidation hooks. |
| `installation` | Default cache `id_prefix` | Stable installation identity; explicit configuration is recommended. |
| `grace` | `0` seconds | How long a validated local schema may be reused without checking Redis again. Zero selects strict freshness. |
| `max_files` | `16` | Maximum immutable schema files admitted per local namespace. |
| `max_bytes` | `33554432` (32 MiB) | Maximum PHP source bytes admitted per local namespace. |
| `host` | Default cache `backend_options.server` | Writable Redis primary host. |
| `port` | Default cache port, otherwise `6379` | Redis port. |
| `database` | Default cache database, otherwise `0` | Redis database number. |
| `username` | Default cache username | Redis ACL username, if used with a password. |
| `password` | Default cache password | Redis authentication secret. |
| `timeout` | `0.3` seconds | Connection timeout. |
| `read_timeout` | `0.3` seconds | Socket read timeout. |
| `context` | Empty array | TLS options under `context['stream']`, shared by both clients. |

For an explicit endpoint, merge the following into the existing `schema_l1` array:

```php
'host' => 'redis-cache.internal',
'port' => 6379,
'database' => 0,
// Supply username/password through your existing secret configuration.
```

Use a direct writable primary. Sentinel discovery and Cluster routing are not implemented. Use a `tls://` host and `context['stream']` options for TLS; verify certificate handling and connectivity in your environment.

`schema_l1.grace` applies only to the schema cache. It does not alter generic-cache freshness. A positive grace interval deliberately permits bounded stale schema reads; the entry's expiry still wins. See [freshness and failures](CACHE.md).

## Local cache limits

These optional entries under `fastboot.files` show the defaults:

```php
'files' => [
    'max_files' => 2048,
    'max_bytes' => 67108864,       // 64 MiB of PHP source
    'max_entry_bytes' => 2097152, // 2 MiB per entry
    'max_queries' => 256,
],
```

`max_files` and `max_bytes` apply to immutable blobs in a local release namespace. `max_queries` limits each of the parsed-query and validated-query index groups per generation. Once a limit is reached, new entries use their native computation or authoritative data path. Existing valid entries remain usable.

These are admission limits, not an LRU eviction policy or an OPcache memory limit. Compiled data can occupy a different amount of memory from its source files. Old release/generation directories may remain on disk until deployment cleanup.


## Local paths

Paths below are relative to Magento's configured cache directory, normally `var/cache`:

| Data | Path |
|---|---|
| Generic PHP blobs and indexes | `fastboot/v2/<namespace>/` |
| GraphQL schema files and freshness stamps | `fastboot/schema/<namespace>/` |
| Compiled area differences | `fastboot/metadata/` |
| Recorded preload classes | `preload/classes.txt` |

Use private node-local storage outside the document root. Align CLI/FPM ownership; files can contain decrypted configuration. Removing local FastBoot files causes misses and repopulation. It does not reclaim compiled OPcache memory or unload preloaded classes; restart PHP-FPM as part of release retirement.

For a custom cache path, configure Magento's cache directory and supply the same path as `FASTBOOT_CACHE_DIR` to the preload master's environment if preload is used. See [preload setup](PRELOAD.md).

Diagnostic switches and explicit build-ID overrides are in the [developer guide](https://github.com/graphcommerce-org/magento2-GraphCommerce_FastBoot/blob/main/dev/README.md).
