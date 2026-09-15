# Measured results

The [online AMD EPYC results](online/RESULTS.md) cover the deployed `project-backend` branch, including a reproducible frontend URL and public HTTP measurements.

Local measurements on an M5 with PHP 8.4.23. These are PHP execution times, not browser load times or production latency targets. Timings vary with the database/search workload and application extensions.

## GraphQL

Mage-OS 3.5, six blocks in native/FastBoot/FastBoot/native/native/FastBoot order, no class preload. Fresh single-worker FPM masters, alternating queries, 10 warmups and 20 measured requests per query per block. Native disabled all FastBoot modules and used fresh native DI. Existing attribution hooks and Magento attribute metadata caching were enabled in both modes. All 360 responses matched.

| Query | Native | FastBoot | Saving |
|---|---:|---:|---:|
| Store configuration | 25.63 ms | 12.76 ms | 12.87 ms |
| 24-item product listing | 49.22 ms | 37.99 ms | 11.24 ms |

Values are medians of three block medians. The FastBoot listing blocks ranged from 34.18 to 46.63 ms; query/search execution varied substantially with unchanged settings. Time outside GraphQL processing fell by approximately 9 ms for both queries. That boundary includes dispatch and response work as well as startup.

## Luma categories

Magento 2.4.8 with a copied sample catalog, FPC disabled, other Magento caches enabled. Each mode used a fresh single-worker FPM master. Three rounds per mode/page, each with 10 warmups and 20 measured requests: 900 measured requests and 450 warmups. Native rounds preceded FastBoot rounds; FastBoot mode order rotated. Canonical main content matched across all renders, with whitespace and form keys normalized.

| Mode | Bags | Fitness Equipment | Bags page 2 |
|---|---:|---:|---:|
| Native Magento | 77.64 ms | 81.06 ms | 72.80 ms |
| Native + attribute metadata cache | 73.50 ms | 75.17 ms | 67.14 ms |
| FastBoot | 68.82 ms | 71.89 ms | 66.18 ms |
| FastBoot + class preload | 64.21 ms | 64.67 ms | 57.08 ms |
| FastBoot + preload + attribute metadata cache | 54.74 ms | 56.00 ms | 50.64 ms |

These figures combine different mechanisms; the attribute-cache setting is built into Magento. A real attribute-model save changed and restored the Activity filter label in the same FPM worker without manual cache cleaning. Browser JavaScript/layout behavior and production capacity were not established by these response comparisons.

Use [the Luma runner](README.md) for an installed fixture with real products. Warm request memory was 8–10 MiB of PHP allocations; this excludes master/preload costs and is insufficient to size workers. Customer performance and memory require measurements on the target stack.

## Installable package check

The 0.2.0-rc4 artifact was installed through Composer on Magento 2.4.8, compiled with fresh DI, and configured using the existing static-content deployment version without a FastBoot release override. With attribute metadata caching enabled and FPC disabled, a temporary HTTP-to-FPM preview measured:

| Page | FastBoot PHP | FastBoot + preload PHP | With preload HTTP TTFB |
|---|---:|---:|---:|
| Bags | 71.93 ms | 58.80 ms | 60.10 ms |
| Bags page 2 | 62.61 ms | 47.44 ms | 48.57 ms |
| Fitness Equipment | 71.74 ms | 55.93 ms | 57.10 ms |

Each cell is a median of 20 requests after 10 warmups. All 180 renders matched their corresponding canonical main content across both modes. FPM used OPcache with 256 MiB, 65,407 script slots and timestamp validation disabled; preload loaded 2,904 classes. These sequential checks verify installation and rendering, not a controlled estimate of preload's isolated contribution.
