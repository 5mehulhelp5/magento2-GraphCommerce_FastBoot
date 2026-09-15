# Performance and validation

These results describe the **RC2 runtime**, tested locally on 15 September 2026 on an M5. RC3 changes documentation only; its runtime files are identical. These are local measurements and compatibility checks, not a production capacity guarantee.

The primary target was Mage-OS 3.5.0 / PHP 8.4.23 with the project’s existing database/services and separate code/cache namespaces. A separate Magento 2.4.8 fixture covered older APIs. The original checkout was unchanged, and no deployment or publication was performed.

## Tested targets

| Target | Evidence | Limit |
|---|---|---|
| Mage-OS 3.5 / PHP 8.4 | 210 GraphQL comparisons, 20 units / 152 assertions, invalidation and configuration-save checks, benchmarks and 8,000-request soak. | Project-specific local workload. |
| Magento 2.4.8 / PHP 8.3 and 8.4 | 96 core GraphQL comparisons in total, fresh DI/preload from the installed ZIP; older-target units: 20 tests / 141 assertions / 2 expected skips. | Copied/adapted database fixture described below; core queries only. |
| PHP 8.5 | 20 units / 152 assertions. | No full Magento runtime coverage. |
| PHP 8.2 | Source syntax checks. | No full Magento runtime coverage. |

For the subsequent regular HTML listing evaluation, see [Luma category pages](LUMA.md). GitHub Actions now runs the portable cache/DI/package checks; the source repository’s `dev/ci/README.md` describes its coverage.

## Performance and memory

Each mode ran in a fresh single-worker FPM master, with 3 warmups and 20 measured requests per query. Values below are medians of three round medians (60 measured requests per cell). **Native has all four FastBoot modules disabled and freshly compiled native DI metadata**, with the same other application cache settings. The native rounds ran first; subsequent rounds alternated FastBoot/preload order. These are PHP request timings over FastCGI, not customer-network latency. Timing comparisons are relative to this host; they cannot predict absolute server timings.

| Query | Native Magento | FastBoot, strict freshness | FastBoot + preload |
|---|---:|---:|---:|
| Small store configuration query | 25.75 ms | 10.47 ms | 5.75 ms |
| 24-item product listing | 50.36 ms | 43.35 ms | 31.38 ms |

That is approximately 59%/78% less time for store configuration and 14%/38% for the listing. Listing round medians ranged from 48.16–53.83 ms native, 40.55–43.60 ms FastBoot, and 27.72–31.50 ms with preload. The listing includes external database/search work and showed more variation than bootstrap. These updated numbers supersede RC1 measurements; individual switch ablation was exploratory, not a precise ranking of every mechanism.

| Memory measurement | Native | FastBoot | FastBoot + preload |
|---|---:|---:|---:|
| Warm PHP allocated peak, store configuration | 10 MiB | 4 MiB | 4 MiB |
| Warm PHP allocated peak, listing | 14 MiB | 8 MiB | 8 MiB |
| First store request PHP allocated peak | 85.53 MiB | 81.02 MiB | 79.02 MiB |
| OPcache used after both queries | 83.44 MiB | 70.65 MiB | 69.18 MiB |
| Worker RSS after both queries | 98.64 MiB | 79.12 MiB | 72.08 MiB |
| Worker macOS physical footprint after both queries | 90.06 MiB | 70.66 MiB | 63.66 MiB |
| FPM master RSS | 32.39 MiB | 32.42 MiB | 78.27 MiB |
| FPM master macOS physical footprint | 15.67 MiB | 15.72 MiB | 60.72 MiB |

OS counters are medians from macOS `proc_pid_rusage`; they are snapshots, not peak or Linux PSS measurements. RSS includes shared mappings and must not be summed across workers as private RAM. Preload reduces worker costs but raises the master footprint. These results do **not** establish a total-host memory saving, particularly for a single-worker deployment. Shared OPcache is not multiplied by worker count. The preload counter was 24.04 MiB and is already represented in OPcache accounting; do not add it again. Cold-start allocation headroom remains necessary even when warm requests allocate only 4–8 MiB.

An 8,000-request, four-worker run used hot queries, 1,000 distinct queries, their readback and repeated mixed traffic with `opcache.validate_timestamps=0`. All responses matched expectations. Query/blob admission limits held; OPcache bytes/scripts were identical over the final 4,000 requests, with no OOM/hash/manual restarts. The largest per-worker RSS increase between the final two 2,000-request phases was 400 KiB. This is bounded soak evidence, not an indefinite leak proof or a capacity forecast.

## Correctness and installation

- Main unit suite: 20 tests / 152 assertions on PHP 8.4.23 and PHP 8.5.8, without PHPUnit warnings/notices. PHP 8.5 has unit coverage only.
- 210 main GraphQL response comparisons: 35 cases, cold and warm, native switches/all/preload. Includes malformed input, variable limits, excessive nesting and custom validation behavior. The native comparison here keeps modules registered with switches disabled; the performance baseline above additionally removes all modules.
- 96 core Magento 2.4.8 comparisons on PHP 8.3.32 and 8.4.23: eight cases, cold/warm, native/all/preload. Older-target units have 20 tests, 141 assertions and two expected skips (the absent Symfony cache adapter and a newer parser nesting policy). The Zend backend TTL path is exercised directly.
- 30 rollback/cache-disable/re-enable comparisons through actual Magento, including config-cache disable and complete feature rollback. Sixteen large-query comparisons cover 64–2,048 sibling fields (up to 33.7 KiB); the largest query allocated 16 MiB native and 18 MiB cold / 16 MiB warm with FastBoot.
- Compiled object-manager checks prove config-cache disable bypasses derived caches and two node-local `var` overrides create distinct schema files with identical data. Direct schema reset retires structural-validation proofs. A regression test reproduces invalidation during query execution and rejects publication into the next generation.
- Six actual Magento admin configuration saves, including normal events/reinitialization, under a 256 MiB limit. Used allocation remained 136 MiB and peak 148.02 MiB. An outer transaction was fully rolled back and database rows were verified restored.
- Native SQL quoting byte parity, including NUL and Ctrl-Z; connected-driver checks for GBK and NO_BACKSLASH_ESCAPES.
- Actual Magento generic-cache writes, promotion, cross-node overwrite and original backend TTL with distinct node-local roots.
- Schema tests: atomic publication, overlapping releases, epoch/key eviction, matching/all/any/inverse tags, expiry versus grace, local corruption/failed writes, admission caps and stale-publication fences. Eight concurrent cold readers coalesced into one promotion; two writers/four readers produced 200 coherent reads.
- Real isolated Redis authentication failure, pause/read timeout, same-client recovery, empty restart and connection refusal. Strict mode never falls back to stale local data during a Redis failure.
- Fresh-bootstrap and DI compilation passed on both application targets. Cache interception is scoped to application cache types so Magento's plugin-list loader can bootstrap. The status/preparation commands run through the installed modules.
- The release builder produces a deterministic Composer ZIP with per-file hashes. Installation smoke uses Composer's artifact repository, actual installed dependency version ranges, four module registrations and extracted-archive autoload paths. It borrows an existing dependency tree and does not provision a fresh Magento database.

The Magento 2.4.8 fixture uses official core source and separately installed Composer dependencies. It has its own local database copy (478 tables/views), cache identity, file sessions and generated artifacts. Two copied EAV attribute metadata rows referencing newer SpecialFromDate/SpecialToDate classes were adapted to their existing parent Attribute class. The original database was not altered by this adaptation. This is core GraphQL/runtime compatibility evidence, **not** a database downgrade/migration test or storefront/static-asset acceptance. PHP 8.3 used a locally built phpredis extension without changing global PHP configuration.

## What customer staging still needs

Exercise the customer's extensions, stores, customer authentication, tax behavior and cache invalidation under representative traffic. Measure the serving FPM service's memory and the real Redis network cost. The local results do not certify Sentinel discovery, Cluster routing or a production TLS topology; the implementation connects to a direct Redis primary.

Extensions that change schema definitions without configuration invalidation must disable `validated_queries` or integrate with invalidation. External cache tooling that bypasses Magento's APIs may also bypass generic invalidation.

Use the [installation and rollback guide](../README.md), [configuration reference](CONFIGURATION.md) and [schema freshness guide](../SCHEMA-L1.md) for the deployment contract.

## Reproduction and artifacts

The developer audit retains raw measurements and installation-specific harnesses under `audit/fastboot/full/` in the separate workspace. Those files are evidence from this investigation, not a runtime package dependency. Portable source tests live under module `Test/Unit` and `dev/tests/schema-l1`; the repository's `dev/tests/README.md` explains how to run them.

The runtime ZIP excludes tests, audit data and installation credentials. Its `BUILD-MANIFEST.json` contains per-file hashes. RC3's documentation refresh retains RC2's runtime files and Composer dependencies; archive verification checks that equality, package hashes and Composer installation independently of the historical runtime tests.
