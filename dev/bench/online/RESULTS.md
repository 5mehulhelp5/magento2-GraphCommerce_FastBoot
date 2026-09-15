# Online AMD EPYC measurements

Expanded comparison on 2026-09-15 against [project-backend at `530fc4f`](https://github.com/ho-nl/project-backend/commit/530fc4ff5bd33ea32e70c3c36a33ac7740e22d24), deployed from [`codex/fastboot-online`](https://github.com/ho-nl/project-backend/tree/codex/fastboot-online). The image, catalog and server placement are unchanged from the earlier three-mode run.

Frontend: [Luma “70s” category](https://codex-fastboot-online-ba18c2.m2gc.deployyy.app/men/70s), with FastBoot and class preload enabled. The environment sleeps after 30 minutes of inactivity and wakes on access.

## PHP execution

**600 measured requests per workload/mode**, up from 90: 12 rotating blocks, each with 10 warmups and 50 measured requests. “Saved vs native” is Native − (FastBoot + preload), using the displayed timings.

| Workload | Native median | FastBoot median | FastBoot + preload median | Saved vs native | 95% interval for saving |
|---|---:|---:|---:|---:|---:|
| Store configuration | 32.88 ms | 19.32 ms | 14.45 ms | 18.43 ms | 17.80–19.05 ms |
| 24 products, including price ranges | 535.22 ms | 522.46 ms | 499.62 ms | 35.60 ms | 29.01–39.92 ms |
| Luma category, 8 products | 160.89 ms | 145.74 ms | 128.76 ms | 32.13 ms | 26.91–37.39 ms |

The FastBoot-only median savings are now close to a flat 13–15 ms. The additional preload saving is larger for products and Luma. These are differences of whole-request medians, including execution work after bootstrap.

| Workload | Native − FastBoot | 95% interval | FastBoot − preload | 95% interval |
|---|---:|---:|---:|---:|
| Store configuration | 13.56 ms | 12.43–14.43 ms | 4.87 ms | 4.14–5.81 ms |
| 24 products, including price ranges | 12.76 ms | 4.46–21.94 ms | 22.84 ms | 12.72–31.41 ms |
| Luma category, 8 products | 15.15 ms | 11.44–20.12 ms | 16.98 ms | 12.48–19.85 ms |

Intervals use 10,000 paired block-bootstrap resamples with seed `20260915`, calculating differences of pooled medians from unrounded samples. Whole blocks are resampled together across modes to preserve within-block correlation. These are total-request differences; they do not isolate bootstrap time.

| Workload | Native p95 | FastBoot p95 | FastBoot + preload p95 | Saved vs native |
|---|---:|---:|---:|---:|
| Store configuration | 43.38 ms | 29.50 ms | 24.30 ms | 19.08 ms |
| 24 products, including price ranges | 690.26 ms | 660.76 ms | 641.59 ms | 48.67 ms |
| Luma category, 8 products | 205.82 ms | 190.62 ms | 170.26 ms | 35.56 ms |

| Workload | Range of native-to-preload block savings | Blocks with positive saving |
|---|---:|---:|
| Store configuration | 14.58–20.04 ms | 12/12 |
| 24 products, including price ranges | 1.76–44.74 ms | 12/12 |
| Luma category, 8 products | 19.93–49.40 ms | 12/12 |

<details>
<summary>All 12 blocks</summary>

| Workload | Block | Native | FastBoot | FastBoot + preload | Saved vs native |
|---|---:|---:|---:|---:|---:|
| Store configuration | 1 | 30.62 ms | 21.06 ms | 13.53 ms | 17.09 ms |
| Store configuration | 2 | 31.08 ms | 19.76 ms | 13.42 ms | 17.66 ms |
| Store configuration | 3 | 32.54 ms | 17.78 ms | 17.96 ms | 14.58 ms |
| Store configuration | 4 | 33.51 ms | 17.38 ms | 13.75 ms | 19.76 ms |
| Store configuration | 5 | 32.90 ms | 19.70 ms | 14.50 ms | 18.40 ms |
| Store configuration | 6 | 32.92 ms | 20.24 ms | 14.31 ms | 18.61 ms |
| Store configuration | 7 | 33.16 ms | 19.08 ms | 14.19 ms | 18.97 ms |
| Store configuration | 8 | 33.48 ms | 20.91 ms | 14.78 ms | 18.70 ms |
| Store configuration | 9 | 32.28 ms | 18.90 ms | 14.91 ms | 17.37 ms |
| Store configuration | 10 | 33.41 ms | 19.41 ms | 13.87 ms | 19.54 ms |
| Store configuration | 11 | 35.02 ms | 20.61 ms | 14.98 ms | 20.04 ms |
| Store configuration | 12 | 33.62 ms | 19.14 ms | 15.00 ms | 18.62 ms |
| 24 products, including price ranges | 1 | 543.21 ms | 520.53 ms | 501.47 ms | 41.74 ms |
| 24 products, including price ranges | 2 | 532.83 ms | 531.10 ms | 491.43 ms | 41.40 ms |
| 24 products, including price ranges | 3 | 534.02 ms | 518.77 ms | 491.05 ms | 42.97 ms |
| 24 products, including price ranges | 4 | 534.20 ms | 529.22 ms | 497.45 ms | 36.75 ms |
| 24 products, including price ranges | 5 | 532.36 ms | 529.60 ms | 493.01 ms | 39.35 ms |
| 24 products, including price ranges | 6 | 531.58 ms | 508.71 ms | 493.79 ms | 37.79 ms |
| 24 products, including price ranges | 7 | 524.79 ms | 536.09 ms | 511.61 ms | 13.18 ms |
| 24 products, including price ranges | 8 | 544.73 ms | 506.31 ms | 522.92 ms | 21.81 ms |
| 24 products, including price ranges | 9 | 541.84 ms | 525.82 ms | 497.44 ms | 44.40 ms |
| 24 products, including price ranges | 10 | 523.83 ms | 517.30 ms | 522.07 ms | 1.76 ms |
| 24 products, including price ranges | 11 | 556.49 ms | 546.71 ms | 511.75 ms | 44.74 ms |
| 24 products, including price ranges | 12 | 541.46 ms | 509.24 ms | 502.44 ms | 39.02 ms |
| Luma category, 8 products | 1 | 166.78 ms | 149.81 ms | 132.16 ms | 34.62 ms |
| Luma category, 8 products | 2 | 160.54 ms | 139.49 ms | 128.17 ms | 32.37 ms |
| Luma category, 8 products | 3 | 155.09 ms | 140.90 ms | 134.51 ms | 20.58 ms |
| Luma category, 8 products | 4 | 151.81 ms | 139.53 ms | 124.88 ms | 26.93 ms |
| Luma category, 8 products | 5 | 156.32 ms | 142.40 ms | 124.12 ms | 32.20 ms |
| Luma category, 8 products | 6 | 165.04 ms | 146.28 ms | 124.00 ms | 41.04 ms |
| Luma category, 8 products | 7 | 156.59 ms | 137.03 ms | 132.59 ms | 24.00 ms |
| Luma category, 8 products | 8 | 158.40 ms | 154.03 ms | 133.62 ms | 24.78 ms |
| Luma category, 8 products | 9 | 174.54 ms | 154.78 ms | 128.08 ms | 46.46 ms |
| Luma category, 8 products | 10 | 160.64 ms | 150.68 ms | 132.72 ms | 27.92 ms |
| Luma category, 8 products | 11 | 152.99 ms | 146.71 ms | 133.06 ms | 19.93 ms |
| Luma category, 8 products | 12 | 177.24 ms | 144.51 ms | 127.84 ms | 49.40 ms |

</details>

## Memory

Peak PHP allocations per measured request:

| Workload | Native | FastBoot | FastBoot + preload |
|---|---:|---:|---:|
| Store configuration | 14 MiB | 6 MiB | 6 MiB |
| 24 products, including price ranges | 20 MiB | 10 MiB | 10 MiB |
| Luma category, 8 products | 22 MiB | 14 MiB | 14 MiB |

Shared OPcache after all workloads, within the same 256 MiB allocation:

| | Native | FastBoot | FastBoot + preload |
|---|---:|---:|---:|
| Total used | 145.75 MiB | 112.72 MiB | 112.26 MiB |
| Preload portion of that total | 0.00 MiB | 0.00 MiB | 64.00 MiB |

FPM confirmed **4,427 preloaded classes and 5,070 scripts** on every preload response, and zero in the other modes. Preload memory is already included in total OPcache usage. PHP allocations and shared bytecode memory are distinct from worker RSS.

## Public HTTPS endpoint

The serving FastBoot + preload deployment, measured from an **M5 Mac laptop**, with five warmups and **100 measured requests per workload**. Each request uses a new TLS connection with curl compression negotiated. All 315 responses returned HTTP 200 and Varnish `MISS` or `UNCACHEABLE`; GraphQL hashes match the PHP comparison, and Luma returned a single category document.

| Workload | Median TTFB | p95 TTFB | Median total transfer |
|---|---:|---:|---:|
| Store configuration | 80.24 ms | 108.04 ms | 80.74 ms |
| 24 products, including price ranges | 594.44 ms | 728.50 ms | 594.91 ms |
| Luma category, 8 products | 225.70 ms | 284.98 ms | 236.29 ms |

These timings include the serving FPM pool, network and Varnish processing. They are separate from the private single-worker PHP comparison.

## Environment and method

- AMD EPYC Milan, x86-64, on an 8-vCPU node. PHP has a 2-vCPU / 2,560 MiB container limit. Database, Redis and OpenSearch remain on the same node throughout this run.
- PHP 8.4.25, Mage-OS 3.5.0, FastBoot 0.2.0-rc5; Percona 8.4.6, Redis 7.0 and OpenSearch 3.5. The catalog contains 6,304 products; requests use store `en_CA`.
- Separate application copies with fresh compiled DI and private Redis prefixes. Local caches use the serving application's mounted ext4 volume. Native disables all four FastBoot modules. Both FastBoot modes enable every optimization with strict freshness. All three copies disable FPC and preserve other deployment settings.
- One private FPM master and worker per mode, with 256 MiB OPcache, 10,000 configured accelerated files, timestamp validation and a two-second revalidation interval. Only the preload master loads the class list. Both FastBoot copies use the same seed with the recorder inactive.
- Mode order rotates native/FastBoot/preload, FastBoot/preload/native, preload/native/FastBoot, repeated four times. Each block has 10 warmups and 50 measured requests per workload/mode. A three-second pause precedes the final warmup for OPcache file-update protection.
- All **6,480 responses** matched by workload: **5,400 measured requests and 1,080 warmups**. GraphQL compares canonical JSON; Luma compares main content with whitespace and form keys normalized. Nine profiler requests follow the measurements.
- No CPU throttling, OPcache exhaustion or OPcache restart occurred during the run.
- The private FPM processes and temporary application copies were removed afterward. The serving deployment retains FastBoot and preload.

The [earlier 90-sample three-mode run](data/preload/results.json) and [earlier comparison without preload](BASELINE.md) are separate datasets. The [local M5 Mac measurements](../RESULTS.md) use a different catalog and configuration.

[Reproduction commands](README.md), [environment and image digest](data/expanded/environment.json), [aggregate results](data/expanded/results.json), [request samples](data/expanded/samples.csv), [uncertainty calculations](data/expanded/uncertainty.json), [public HTTP samples](data/expanded/public.json), [native product profile](data/expanded/native-products-profile.csv), [FastBoot product profile](data/expanded/fastboot-products-profile.csv), [preloaded product profile](data/expanded/preload-products-profile.csv).
