# Configuration reference

Configure FastBoot under the `fastboot` key in Magento's deployment configuration, normally `app/etc/env.php`. Merge entries into the existing returned array. Use PHP booleans and numbers, not strings such as `'false'`.

See the [installation guide](../README.md#install-and-configure) for the minimum configuration and deployment order.

## Installation and release identities

| Setting | Purpose |
|---|---|
| `fastboot.release` | Namespaces generic local artifacts by application build. Set a new immutable value when code, DI or deployment configuration changes. |
| `fastboot.schema_l1.installation` | Identifies one shop/environment across its servers and active releases. Keep it stable. Use distinct values for production and staging. |
| `fastboot.schema_l1.release` | Namespaces the shared schema record and local schema files by application build. Set it to the same value as `fastboot.release`. |

All nodes serving one build use its release ID. During a rolling deployment, the old and new builds have different release IDs but the same installation ID; configuration invalidation reaches both.

The schema installation ID can fall back to the default cache frontend's `id_prefix`, but an explicit value makes deployment ownership clear. Schema release has no automatic fallback and is required when schema L1 is configured.

## Feature switches

All switches below default to `true`. Set an individual switch directly under `fastboot` to `false` to use the corresponding native path.

| Switch | What it enables | Bypassed when Magento config cache is disabled? |
|---|---|---|
| `cache_files` | Local PHP values for covered Magento configuration caches. | Yes |
| `system_config_array` | Local system configuration loaded by requested scope. | Yes |
| `schema_array` | Local GraphQL schema arrays; also requires schema L1 configuration. | Yes |
| `schema_scalars` | Faster scalar lookup in the GraphQL schema. | No |
| `parsed_queries` | Parsed GraphQL document reuse. | Yes |
| `validated_queries` | Reuse of successful built-in structural validation. | Yes |
| `area_config_diff` | Compiled differences between global and area DI configuration. | No |
| `quote_without_connection` | SQL string quoting without opening a connection when the shortcut's conditions hold. | No |
| `deploy_config_unchanged` | Reuse of deployment-configuration checks, keyed by configuration contents. | Yes |
| `scopes_cache` | Cached scope data. | Yes |
| `website_stores` | Cached website-to-store relationships. | Yes |
| `default_store` | Default-store lookup through the store repository. | No |
| `view_config` | Reuse of theme/area view XML as PHP data. | Yes |
| `guest_tax_factor` | Reuse of guest tax-factor calculations. | Yes |
| `placeholder_url` | Cached placeholder URLs, including theme and transport identity. | Yes |

These switches do not enable PHP class preload; that requires `opcache.preload` at FPM startup.

`validated_queries` retains Magento's query processor, request-specific security checks, custom validation rules and scalar literal coercion. Extensions that change schema definitions without configuration invalidation must disable this switch or integrate their schema changes with invalidation.

## Schema L1 settings

Place these entries under `fastboot.schema_l1`:

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Configures the Redis schema backend and its invalidation hooks. |
| `installation` | Default cache `id_prefix` | Stable installation identity; explicit configuration is recommended. |
| `release` | Required | Immutable application build identity. |
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
| `context` | Empty array | phpredis stream context options. |

For an explicit endpoint, merge the following into the existing `schema_l1` array:

```php
'host' => 'redis-cache.internal',
'port' => 6379,
'database' => 0,
// Supply username/password through your existing secret configuration.
```

Use a direct writable primary. Sentinel discovery and Cluster routing are not implemented. A `tls://` host and phpredis stream context can be configured, but a production TLS topology was not part of the local validation.

`schema_l1.grace` applies only to the schema cache. It does not alter generic-cache freshness. A positive grace interval deliberately permits bounded stale schema reads; the entry's expiry still wins. See [freshness and failures](../SCHEMA-L1.md).

## Generic local-cache limits

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

## Local paths and ownership

| Data | Location relative to Magento's configured `var` directory |
|---|---|
| Generic data blobs and generation indexes | `fastboot/v2/<namespace>/` |
| Schema arrays and freshness stamps | `fastboot-schema/<namespace>/` |
| Recorded preload classes | `fastboot/classes.txt` |

Keep these paths on each server's private filesystem, writable by the application users and outside the public document root. File contents may include decrypted configuration values. Private file permissions mean CLI/FPM ownership must be aligned; testing only as a different CLI user is insufficient.

The preload script currently reads its class list from `<Magento root>/var/fastboot/classes.txt`. If Magento uses a custom `var` path, make the recorded list available at that node-local path before starting preload. `FASTBOOT_MAGENTO_ROOT` selects the application root; it does not select a different `var` path.

## Generic-cache freshness

Generic caches read a shared generation token on first use in each PHP request. The token is reused for the rest of that request unless invalidated locally. This avoids a Redis check for every local value.

The `GraphCommerce\FastBootCache\Model\Version` constructor has a DI `grace` argument, defaulting to zero. Changing it is an advanced customization that allows delayed cross-node invalidation; the schema `grace` setting does not configure it. The documented deployment uses zero for both.
