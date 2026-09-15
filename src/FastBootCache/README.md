# FastBoot cache configuration

Settings in `app/etc/env.php`.

## Redis connection

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
| `context` | Empty array | TLS options under `context['stream']`. |

Direct primary connections are supported. Sentinel discovery and Cluster routing are not implemented. For TLS, use a `tls://` host and `context['stream']` options.

## Local cache limits

Defaults under `fastboot.files`:

```php
'files' => [
    'max_files' => 2048,
    'max_bytes' => 67108864,       // 64 MiB of PHP source
    'max_entry_bytes' => 2097152, // 2 MiB per entry
    'max_queries' => 256,
],
```

`max_files` and `max_bytes` apply to immutable blobs in a local release namespace. `max_queries` limits each of the parsed-query and validated-query index groups per generation. Once a limit is reached, new entries use their native computation or authoritative data path. Existing valid entries remain usable.

Limits bound admitted PHP source per namespace. There is no LRU eviction. Retired namespaces remain until deployment cleanup.

## Local paths

Paths below are relative to Magento's configured cache directory, normally `var/cache`:

| Data | Path |
|---|---|
| Generic PHP blobs and indexes | `fastboot/v2/<namespace>/` |
| GraphQL schema files and freshness stamps | `fastboot/schema/<namespace>/` |
| Compiled area differences | `fastboot/metadata/` |

Files can contain decrypted configuration. Removing them causes repopulation; deleting source files does not reclaim their compiled OPcache memory.

## Freshness

The authoritative schema is shared in Redis; its local copy is private to each server. A missing local copy is fetched from Redis and saved locally. If Redis has no current schema, Magento rebuilds it.

Strict freshness is the default. A schema load checks its current revision and remaining TTL in Redis before using the local copy. Generic FastBoot caches check their shared generation once per request. An in-flight request can finish with data it already loaded.

Invalidation hooks must remain active on every web, admin and CLI node. External deletion of legacy Magento cache keys does not necessarily invalidate FastBoot records.

A local disk failure falls back to authoritative data. A Redis validation failure is reported as an error; it does not permit serving an old local schema. A positive schema grace period deliberately allows bounded stale reads; entry expiry still wins.
