# Historical schema-only measurements

These measurements evaluated schema L1 with the other FastBoot features disabled. They explain the cost of that mechanism alone; use the [combined validation report](VALIDATION.md) for the current package results.

On the local M5, PHP 8.4.23, Mage-OS 3.5.0, all other FastBoot mechanisms disabled, the earlier schema-only run measured:

| Metric | Native | Strict schema L1 |
|---|---:|---:|
| storeConfig PHP time | 27.76 ms | 26.37 ms |
| Listing PHP time | 50.47 ms | 49.64 ms |
| Schema loading, storeConfig/listing | 2.78 / 2.60 ms | 0.70 / 0.58 ms |
| PHP allocated peak, storeConfig/listing | 10 / 14 MiB | 8 / 10 MiB |

These are 20 measured requests per cell after warmup. Whole-request gains vary between runs; schema load time and allocated-memory reductions are clearer. PHP allocated peak is not total worker RSS. Four simultaneous clients against four FPM workers returned identical responses for 200 measured mixed requests: median schema time fell from 2.90 to 0.58 ms. This is a local concurrency check, not a production capacity or tail-latency forecast.

Ten identical schema rebuilds retained one 1.39 MiB compiled schema script. Ten genuinely different payloads occupied ten scripts / 13.90 MiB. The schema-only churn run used timestamp validation disabled. These script figures exclude shared interned-string-pool occupancy and other OPcache allocations. Restart FPM as part of the release lifecycle; deleting disk files does not reclaim compiled memory. Retired release directories and Redis tombstones need deployment cleanup. No runtime OPcache compactor or global memory cap is provided.

The package includes unit tests and portable integration scripts in `dev/tests/schema-l1`: two nodes; matching/all/any/inverse tags; expiry versus grace; malformed/missing local files; corruption detection; disk failure; stale publication rejection; overlapping releases; epoch eviction; eight cold readers; two concurrent writers and four readers; real Redis authentication failure, pause/timeout, recovery in the same PHP process/client, empty restart and recovery. The installation-specific harness also tested actual Magento clean/reset/flush/cache-disable paths, 138 schema-only single-worker parity requests, and 232 four-worker requests including warmups.

Production staging still needs the actual Redis authentication/TLS/topology, network latency, deployment hooks, third-party invalidation paths, memory budget and representative traffic. The combined package now also fixes the validation bypass and scoped-config memory behavior; see the release changelog and validation report.
