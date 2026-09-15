# How the schema cache works

FastBoot stores Magento's assembled GraphQL schema in two places:

- **L1:** a private PHP-array file on each server, reused through OPcache.
- **L2:** the authoritative schema record in Redis, shared by the installation's servers.

Servers never share the L1 directory. A server that needs a schema version it does not have reads it from Redis and creates its own local copy. If Redis has no current schema, Magento rebuilds it from the source configuration.

Use the [README](README.md#install-and-configure) to enable this cache and the [configuration reference](docs/CONFIGURATION.md#schema-l1-settings) to change its connection or limits.

## A warm read

With `schema_l1.grace=0`, loading the schema follows these steps:

1. Read Redis metadata in one atomic operation: the schema revision, installation invalidation generation and remaining TTL.
2. Check that the local copy matches that metadata and has not expired.
3. Include the local PHP array. The schema payload does not need to be downloaded or decoded again.

This Redis check happens when the schema is loaded. Reading individual schema fields or array elements does not trigger another check. Connection establishment can additionally require authentication and database selection; the cost depends on the connection and network.

**Strict freshness means current at the Redis metadata check.** It does not prevent another node invalidating the cache immediately afterward. A request can finish with the schema it already loaded; subsequent schema loads observe the invalidation. Generic FastBoot caches use their own once-per-request generation check.

## A local miss or a cold cache

| State | What happens |
|---|---|
| Local copy is missing or outdated; Redis has a current schema | Fetch and verify the shared payload, then write a local copy. Preserve Redis's remaining TTL. |
| Redis has no current schema | Capture its generation, build through Magento, then publish only if that generation is still valid. |
| Several readers miss on the same server | A local lock coalesces promotion and the waiting readers recheck the result. |
| Several servers miss together | Each server may perform source work. Conditional publication prevents a stale or losing build from replacing a newer result. |

The Redis record publishes the payload, content hash, revision, invalidation generation and expiry atomically. If invalidation, eviction, expiry or another publication overtakes a build, the old build cannot repopulate Redis. Its current request may still finish using its own snapshot.

## Invalidation across servers and releases

FastBoot integrates with Magento's config-cache clean/remove operations, application cache clean, manager flush and direct schema-data reset. The installation has a shared invalidation generation, so a configuration change reaches both old and new release records during a rolling deployment.

Use one installation ID for the shop/environment and a distinct release ID for each application build. Keep these hooks active on every web, admin and CLI node while any node uses schema L1. To temporarily use native schema loading, set `schema_array=false` while retaining `schema_l1.enabled=true`.

Deleting only legacy Magento cache keys through external tooling does not necessarily invalidate FastBoot's schema record. Integrate external writers with the supported cache APIs. A failed invalidation is an error and must be retried; it is not reported as a successful clean.

## TTL and grace

**TTL answers when an entry expires. Invalidation answers whether it became outdated before then.** A TTL alone cannot tell one server that an admin save on another server has changed configuration.

The PHP file contains the immutable data. A separate, mutable JSON stamp holds freshness and expiry metadata. This lets an identical rebuilt schema reuse the same compiled PHP file while its expiry changes. Putting the TTL into that immutable file would couple metadata changes to compiled-file changes without solving cross-server invalidation.

A positive `schema_l1.grace` allows the server to reuse a recently validated local schema without contacting Redis during that interval. This trades immediate validation for fewer network calls. Entry expiry always wins, even inside the grace interval. The default is zero.

## Failure behavior

| Failure or limit | Result |
|---|---|
| Local file missing or syntactically damaged | Fetch a verified shared copy again. |
| Local directory cannot be written | Use current authoritative data without saving a local copy. |
| Local admission limit reached | Use current authoritative data; existing valid local files remain available. |
| Redis validation fails in strict mode | Propagate the failure. Do not serve the old local schema. |
| Redis record or invalidation generation is evicted/flushed | Treat the old local copy as a miss when validation resumes. |
| Invalidation overtakes a schema rebuild | Reject that build's shared-cache publication. |

The local directory is trusted application storage. Warm reads do not hash the complete PHP file on every include. Keep the directory private and writable only by trusted application users.

## OPcache and memory

Local files contain arrays and scalar values. Their names are based on content hashes, and writes use atomic rename. Identical payloads reuse the same filename; genuinely different payloads use new files. This also works with `opcache.validate_timestamps=0` because runtime data is not overwritten at an already compiled path.

These files are **not preloaded**. PHP class preload is a separate optimization whose definitions remain fixed until the FPM master/service restarts. Cache freshness is decided by FastBoot's runtime metadata checks, not by OPcache's source-file timestamp validation.

Schema L1 admits at most 16 files and 32 MiB of PHP source per local namespace by default. Source size is not compiled-memory usage. Include shared OPcache, worker allocations and the preload master's footprint in capacity planning. Deleting files alone does not reclaim compiled memory; retire cache namespaces with the release/FPM lifecycle.

Whole-object serialization is not used here. It would require reconstructing object graphs in each request; the cache instead stores the reusable data from which Magento operates.

## Implementation and evidence

`FastBootCache/Model/Schema` contains the Redis connection, local files and settings. `FastBootGraphQl` connects that cache to Magento's schema data and invalidation paths.

The [combined validation report](docs/VALIDATION.md) covers concurrency, expiry, corruption, failed local writes, Redis timeout/recovery and Magento integration. The [historical schema-only measurements](docs/SCHEMA-BENCHMARK.md) isolate this mechanism's timing and memory costs. Customer staging still needs its real Redis topology, network latency and extension invalidation paths.
