# Online AMD EPYC measurements

Measured on 2026-09-15 against [project-backend at `530fc4f`](https://github.com/ho-nl/project-backend/commit/530fc4ff5bd33ea32e70c3c36a33ac7740e22d24), deployed from [`codex/fastboot-online`](https://github.com/ho-nl/project-backend/tree/codex/fastboot-online). The [image build passed](https://github.com/ho-nl/project-backend/actions/runs/34963450399).

Frontend: [Luma “70s” category](https://codex-fastboot-online-ba18c2.m2gc.deployyy.app/men/70s), with FastBoot and class preload enabled. The environment sleeps after 30 minutes of inactivity and wakes on access.

## PHP execution

All three modes use the same image, catalog, node and service placement in this comparison. Each figure is the median of 90 measured requests.

| Workload | Native Magento | FastBoot | FastBoot + preload | Preload saving vs FastBoot |
|---|---:|---:|---:|---:|
| Store configuration | 34.61 ms | 19.42 ms | 14.63 ms | 24.7% |
| 24 products, including price ranges | 540.22 ms | 516.74 ms | 486.68 ms | 5.8% |
| Luma category, 8 products | 157.03 ms | 140.07 ms | 128.51 ms | 8.3% |

Preload improved every workload's median in all three blocks. Relative to native Magento, FastBoot with preload reduced the pooled medians by **57.7% for store configuration, 9.9% for priced products and 18.2% for Luma**.

| Workload | Native p95 | FastBoot p95 | FastBoot + preload p95 |
|---|---:|---:|---:|
| Store configuration | 44.41 ms | 28.04 ms | 22.62 ms |
| 24 products, including price ranges | 682.86 ms | 608.53 ms | 588.39 ms |
| Luma category, 8 products | 203.71 ms | 192.47 ms | 169.28 ms |

Per-block medians, shown as native / FastBoot / FastBoot + preload:

| Workload | Block 1 | Block 2 | Block 3 |
|---|---:|---:|---:|
| Store configuration | 34.43 / 19.47 / 15.10 ms | 35.12 / 19.84 / 14.49 ms | 33.55 / 19.12 / 14.48 ms |
| 24 products, including price ranges | 522.61 / 517.94 / 490.38 ms | 548.39 / 505.55 / 483.08 ms | 546.38 / 525.22 / 485.17 ms |
| Luma category, 8 products | 156.52 / 132.61 / 125.06 ms | 159.15 / 147.23 / 130.36 ms | 157.91 / 145.02 / 128.98 ms |

The post-run product profiles spent **85–94%** of Magento's profiled time in `Product\PriceRange`, across 24 resolver calls. Native issued 812 SQL calls; both FastBoot modes issued 808. Preloading reduces PHP work while leaving the price-resolution query workload largely intact. These are single instrumented requests, excluded from the timing tables.

## Memory

Peak PHP allocations per measured request:

| Workload | Native | FastBoot | FastBoot + preload |
|---|---:|---:|---:|
| Store configuration | 14 MiB | 6 MiB | 6 MiB |
| 24 products, including price ranges | 20 MiB | 10 MiB | 10 MiB |
| Luma category, 8 products | 22 MiB | 14 MiB | 14 MiB |

Shared OPcache after all three workloads, within the same 256 MiB allocation:

| | Native | FastBoot | FastBoot + preload |
|---|---:|---:|---:|
| Total used | 145.72 MiB | 112.68 MiB | 112.23 MiB |
| Preload portion of that total | 0.00 MiB | 0.00 MiB | 63.96 MiB |

FPM confirmed **4,427 preloaded classes and 5,070 scripts** on every preload response, and zero in the other modes. Preload's 63.96 MiB is already included in its 112.23 MiB OPcache total. It did not lower peak PHP allocation further in this workload mix. These figures describe PHP allocations and shared bytecode memory, not total worker RSS.

## Public HTTPS endpoint

The deployed FastBoot + preload endpoint, measured from an **M5 Mac laptop**: five warmups and 20 measured requests per workload, fresh TLS connections and curl compression negotiated. All 75 responses returned HTTP 200 and Varnish `MISS` or `UNCACHEABLE`; GraphQL hashes match the PHP comparison, and Luma returned a single valid category document.

| Workload | Median TTFB | p95 TTFB | Median total transfer |
|---|---:|---:|---:|
| Store configuration | 77.50 ms | 89.25 ms | 77.95 ms |
| 24 products, including price ranges | 589.39 ms | 717.31 ms | 590.04 ms |
| Luma category, 8 products | 236.01 ms | 264.06 ms | 239.71 ms |

This is an end-to-end observation of the serving deployment, including its normal FPM pool and Varnish processing. The PHP comparison above uses dedicated single-worker FPM masters.

## Environment and method

- AMD EPYC Milan, x86-64, on an 8-vCPU node. PHP has a 2-vCPU / 2,560 MiB container limit. Database, Redis and OpenSearch are on the same node for all measurements here.
- PHP 8.4.25, Mage-OS 3.5.0, FastBoot 0.2.0-rc5; Percona 8.4.6, Redis 7.0 and OpenSearch 3.5. The catalog contains 6,304 products; requests use store `en_CA`.
- Separate application copies with fresh compiled DI and private Redis prefixes. Local caches use the serving application's mounted ext4 volume. Native disables all four FastBoot modules. Both FastBoot modes enable every optimization with strict freshness. All three copies disable FPC and preserve the remaining deployment settings.
- One private FPM master and worker per mode, with 256 MiB OPcache, 10,000 configured accelerated files, timestamp validation and a two-second revalidation interval. Only the preload master loads the class list. Both FastBoot copies use the same seed with the recorder inactive.
- Three blocks rotate mode order: native/FastBoot/preload, FastBoot/preload/native, preload/native/FastBoot. Each has 10 warmups and 30 measured requests per workload/mode. A three-second pause precedes the final warmup for OPcache file-update protection.
- All **1,080 responses** matched by workload: 810 measured requests and 270 warmups. GraphQL compares canonical JSON; Luma compares main content with whitespace and form keys normalized. Nine profiler requests follow the measurements.
- No CPU throttling, OPcache exhaustion or OPcache restart occurred during the run. The private FPM processes and temporary application copies were removed afterward.

The [earlier non-preloaded comparison](BASELINE.md) used a different image and cross-node services for its PHP tests. Its timings are a separate dataset. The [local M5 Mac measurements](../RESULTS.md) also use a different catalog and configuration.

[Reproduction commands](README.md), [environment and image digest](data/preload/environment.json), [aggregate results](data/preload/results.json), [request samples](data/preload/samples.csv), [public HTTP samples](data/preload/public.json), [native product profile](data/preload/native-products-profile.csv), [FastBoot product profile](data/preload/fastboot-products-profile.csv), [preloaded product profile](data/preload/preload-products-profile.csv).
