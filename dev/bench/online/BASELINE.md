# Earlier online comparison without preload

Measured on 2026-09-15 against [project-backend at `7e6cedd`](https://github.com/ho-nl/project-backend/commit/7e6ceddda627cde7385d093498b5676c4c6dc7c6), deployed from [`codex/fastboot-online`](https://github.com/ho-nl/project-backend/tree/codex/fastboot-online). The [image build passed](https://github.com/ho-nl/project-backend/actions/runs/34957175055), and the environment completed its schema upgrade and indexing.

Frontend: [Luma “70s” category](https://codex-fastboot-online-ba18c2.m2gc.deployyy.app/men/70s). This branch environment sleeps after 30 minutes of inactivity and wakes on access.

The live preview enables class preloading from [project commit `530fc4f`](https://github.com/ho-nl/project-backend/commit/530fc4ff5bd33ea32e70c3c36a33ac7740e22d24). The measurements below remain the **non-preloaded baseline** at `7e6cedd`.

## PHP execution

| Workload | Native median | FastBoot median | Change | Native p95 | FastBoot p95 |
|---|---:|---:|---:|---:|---:|
| Store configuration | 34.78 ms | 21.72 ms | −37.5% | 63.30 ms | 32.43 ms |
| 24 products, including price ranges | 963.22 ms | 1,015.33 ms | +5.4% | 1,121.93 ms | 1,210.19 ms |
| Luma category, 8 visible products | 198.22 ms | 184.63 ms | −6.9% | 265.20 ms | 259.91 ms |

Store configuration and Luma improved in every block. The priced listing did **not** demonstrate an improvement: its pooled median was slower, and the direction varied between blocks. These shared-server results do not establish a repeatable FastBoot regression or improvement for that query.

Per-block medians below list native / FastBoot. Execution order reverses in block 2.

| Workload | Block 1 | Block 2 | Block 3 |
|---|---:|---:|---:|
| Store configuration | 34.84 / 21.02 ms | 34.04 / 22.68 ms | 36.27 / 20.06 ms |
| Priced listing | 955.74 / 913.03 ms | 967.67 / 1,038.45 ms | 1,004.32 / 1,021.10 ms |
| Luma | 204.63 / 174.81 ms | 187.03 / 179.54 ms | 198.22 / 193.15 ms |

The post-run product profiles spent approximately **93%** of Magento's profiled time in `Product\PriceRange`, across 24 resolver calls. Native Magento issued 812 SQL calls; FastBoot issued 808. Repeated configurable-product and attribute loads dominate this workload. The profiles locate that work; their instrumented times are excluded from the timing table.

## Memory

| Workload | Native PHP peak allocation | FastBoot PHP peak allocation |
|---|---:|---:|
| Store configuration | 14 MiB | 6 MiB |
| Priced listing | 20 MiB | 12 MiB |
| Luma | 22 MiB | 14 MiB |

After all three workloads, shared OPcache used **142.07 MiB native / 109.49 MiB FastBoot**, within the same 256 MiB allocation. These are PHP allocations and shared bytecode-cache usage, not total worker RSS. No OPcache exhaustion, OPcache restart or container CPU throttling occurred during the recorded comparison.

## Public HTTPS endpoint

FastBoot enabled, measured from an **M5 Mac laptop** against the deployed server: five warmups and 20 measured requests per workload, new TLS connections, curl compression negotiated. All responses returned HTTP 200 and Varnish `MISS` or `UNCACHEABLE`.

| Workload | Median TTFB | p95 TTFB | Median total transfer |
|---|---:|---:|---:|
| Store configuration | 85.10 ms | 95.46 ms | 85.33 ms |
| 24 products, including price ranges | 634.33 ms | 751.17 ms | 634.94 ms |
| Luma category | 242.26 ms | 303.57 ms | 254.73 ms |

These are end-to-end observations of the FastBoot deployment, not a native/FastBoot comparison. Before this run, Kubernetes rescheduled serving PHP onto the database/Redis/OpenSearch node; the private PHP comparison above used cross-node services. This placement difference matters especially for the SQL-heavy product query, so the two tables cannot be subtracted to estimate network overhead.

The copied headless storefront's base-link URL overrides were normalized in the branch database before this HTTP run. Luma returned one complete HTML document with navigation on the preview domain. GraphQL content hashes match the private comparison. [Public request samples](data/public.json).

## Environment and method

- AMD EPYC Milan, x86-64, on an 8-vCPU node. The PHP container has a 2-vCPU / 2,560 MiB limit. Database, Redis and OpenSearch run on another node.
- PHP 8.4.25, Mage-OS 3.5.0, FastBoot 0.2.0-rc5; Percona 8.4.6, Redis 7.0 and OpenSearch 3.5. The copied catalog contains 6,304 products; requests use store `en_CA`.
- All FastBoot optimizations enabled, strict freshness, no class preload. FPM uses 256 MiB OPcache, 10,000 configured accelerated files, timestamp validation and a two-second revalidation interval. Magento's user-defined attribute cache remains at this deployment's disabled setting in both modes.
- Separate native/FastBoot application copies with fresh compiled DI and private Redis prefixes. Their local caches use the same mounted ext4 volume as the serving application's `var`. Native disables all four FastBoot modules; both copies disable FPC and preserve the other deployment settings.
- One private FPM worker per mode; three alternating blocks, 10 warmups and 30 measured requests per workload/mode/block. The three-second pause before the final warmup accounts for OPcache file-update protection.
- All **720 responses** matched by workload: 540 measured requests and 180 warmups. GraphQL compares canonical JSON; Luma compares main content with whitespace and form keys normalized. Six additional profiler requests follow the measurements.

These are cloud-server measurements. The [M5 Mac measurements](../RESULTS.md) use different catalogs and configurations and are a separate dataset.

[Reproduction commands](README.md), [environment and image digest](data/environment.json), [aggregate results](data/results.json), [request samples](data/samples.csv), [native product profile](data/native-products-profile.csv), [FastBoot product profile](data/fastboot-products-profile.csv).
