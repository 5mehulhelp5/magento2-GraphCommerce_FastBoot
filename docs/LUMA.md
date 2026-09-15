# Luma category-page evaluation

This iteration measures Magento's regular server-rendered Luma category pages. It does not use GraphQL or the catalog-storefront documents path. The runtime is the code published in RC3; no additional runtime override was needed for these results.

## What is different from GraphQL

A Luma category request still benefits from the shared FastBoot work: store resolution, system configuration, frontend-area DI and theme view configuration. It also builds blocks, loads the product collection, resolves layered-navigation attributes and renders templates. The GraphQL schema/parsing/validation caches are not part of this path.

The profile identified repeated attribute-metadata reads inside product collection loading. In the Bags listing, a representative warm request loaded 22 attributes by code, taking about 9 ms. Magento already provides `dev/caching/cache_user_defined_attributes` for this work; it defaults to zero in the tested core version.

Enabling that setting removed those 22 repeated loads. FastBoot's existing cache layer can then reuse the metadata locally. This is a Magento configuration optimization, not a new FastBoot implementation of attribute caching.

## Measured results

Measured on an M5 with Magento 2.4.8 / PHP 8.4.23 and its installed RC3-equivalent runtime. The fixture uses Luma and the separately copied database described in the [validation report](VALIDATION.md). Bags contains 14 visible products: 12 on the first page and two on the second. The Fitness Equipment listing renders 10 products. The top-level Gear page was excluded because it is a static landing page.

Full-page cache was disabled so every request exercised PHP rendering. Configuration, layout, block and EAV caches otherwise remained enabled. Each mode used a fresh, dedicated single-worker FPM master. Native Magento had all four FastBoot modules disabled and freshly compiled native DI metadata.

Each cell below is the median of three round medians, with 10 warmups and 20 measured requests per page per round: **900 timed samples and 450 warmups** in total. Native rounds ran first; subsequent FastBoot rounds rotated mode order. Profiled requests ran separately from timing samples. The canonical main-content hashes matched across all 1,350 renders; only whitespace and form-key values were normalized.

| Mode | Bags, page 1 | Fitness Equipment | Bags, page 2 |
|---|---:|---:|---:|
| Native Magento | 77.64 ms | 81.06 ms | 72.80 ms |
| Native + Magento attribute cache | 73.50 ms | 75.17 ms | 67.14 ms |
| FastBoot | 68.82 ms | 71.89 ms | 66.18 ms |
| FastBoot + preload | 64.21 ms | 64.67 ms | 57.08 ms |
| FastBoot + preload + Magento attribute cache | 54.74 ms | 56.00 ms | 50.64 ms |

FastBoot with preload reduced PHP time by approximately **17–22%**. Adding Magento's attribute cache brought the total reduction to **29–31%** versus native Magento. The additional setting saved approximately 6–9 ms relative to FastBoot with preload alone.

Native + attribute-cache Bags round medians varied from 70.35 to 79.94 ms; FastBoot + preload + attribute-cache Bags varied from 54.49 to 59.08 ms. These are relative local results, not a prediction of production latency or a reason to disable full-page cache in production.

| Warm PHP allocated peak, median | Bags, page 1 | Fitness Equipment | Bags, page 2 |
|---|---:|---:|---:|
| Native Magento | 10 MiB | 10 MiB | 8 MiB |
| FastBoot | 10 MiB | 8 MiB | 8 MiB |
| FastBoot + preload, with or without attribute cache | 8 MiB | 8 MiB | 8 MiB |

These are per-request PHP allocation measurements. They exclude the cost of the FPM master's preload footprint and cannot establish total server memory savings. See the [separate worker/master measurements](VALIDATION.md#performance-and-memory).

## Attribute-cache configuration

Evaluate the built-in setting in customer staging:

```sh
bin/magento config:set dev/caching/cache_user_defined_attributes 1
bin/magento cache:clean config eav
```

If deployment configuration manages this value, change and import it through that deployment process instead. Use a new FastBoot release identity when changing deployment configuration. Keep full-page caching enabled for normal customer traffic.

The setting caches attribute metadata, not product prices, stock or the rendered page. Extensions that write attribute tables directly still need to perform the normal cache invalidation. FastBoot does not enable this Magento setting automatically.

In the disposable fixture, a real attribute-model save changed the Activity filter label. The next page rendered the new label in the **same FPM worker**, with preload and attribute caching enabled, without manually cleaning caches. Restoring the attribute restored the displayed label. Test configuration and the label were restored afterward; the original application's database was not changed.

## Reproduce the page check

The source repository includes `dev/bench/luma.py`. It starts and stops its own FPM master on an available loopback port. It checks for HTTP 200, disabled full-page cache and an actual category product grid, then records timing, PHP allocations, OPcache data, HTML and a separate native Magento profiler CSV.

It does not disable modules, change cache settings, import configuration, compile Magento or restart an existing FPM service. Prepare each mode in a disposable installed Magento instance. Read `dev/bench/README.md` for commands and comparison checks. The harness verifies server-rendered main content; it does not test browser JavaScript, CSS, image loading or layout appearance.

The detailed comparison harnesses, raw rounds, profiles and invalidation test remain in the local audit under `audit/fastboot/full/luma/`. CI covers portable units, Redis invariants, DI and packaging; it currently does not provision this product database or run a Luma browser suite.
