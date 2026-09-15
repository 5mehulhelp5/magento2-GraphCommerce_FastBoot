# Benchmark FastBoot

## Luma category HTML

`luma.py` measures real category-page rendering in a dedicated local FPM master. Use an installed, disposable Magento instance with Luma, category products and full-page cache disabled for the benchmark. Prepare configuration, compiled metadata and preload recording for the mode you want to measure first.

Run from the source package repository root:

```sh
python3 dev/bench/luma.py \
  --magento-root /absolute/path/to/magento \
  --php-fpm /absolute/path/to/php-fpm \
  --base-url https://your-configured-store.example \
  --path /gear/bags.html \
  --path '/gear/bags.html?p=2' \
  --label fastboot \
  --output /absolute/path/to/an/empty/benchmark-output
```

Use real category paths for your fixture. `--base-url` must match the configured store to avoid Magento's canonical-host redirect. The requests travel over local FastCGI; the URL supplies the store host/scheme, not an external HTTP destination.

The default is 10 warmups and 20 measured requests per path. Add `--preload` to start the dedicated master with the installed package's preload script. Use `--compare /path/to/previous/results.json` to require identical rendered main content between modes. The comparison normalizes whitespace and CSRF form keys, including the toolbar JSON form key; product data and prices remain part of the comparison.

The output directory must be empty and outside the public document root. It receives `results.json`, request samples, rendered HTML, native Magento profiler CSVs and FPM logs. The script uses an available loopback port and stops only its own FPM process. It leaves application configuration alone; Magento can still write ordinary caches, sessions and profiler files while serving requests.

The harness refuses a successful-looking measurement if full-page cache is enabled, the response is not HTTP 200 or the page has no product grid. It compares server-rendered HTML, not browser appearance or JavaScript. Keep full-page cache enabled in production.

See [measured results](RESULTS.md). CI coverage is described in the [CI guide](../ci/README.md).
