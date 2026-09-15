# Online comparison

Run these scripts inside the deployed PHP container, as its application user. They use the installed code, database, Redis and OpenSearch connections. Python 3 and the matching PHP-FPM binary are required.

`prepare.py` creates two private application copies with separate Redis cache prefixes and fresh compiled DI. Their `var` directories use private subdirectories of the serving application's mounted `var`, so local-cache I/O uses the same filesystem. The native copy disables all four FastBoot modules. The FastBoot copy retains the deployed configuration. Both disable full-page caching; other Magento cache settings remain identical to the deployment. The serving application's configuration is unchanged.

```sh
python3 prepare.py --source /var/www/html --output /tmp/fastboot-benchmark

python3 compare.py \
  --root native=/tmp/fastboot-benchmark/native \
  --root fastboot=/tmp/fastboot-benchmark/fastboot \
  --base-url https://codex-fastboot-online-ba18c2.m2gc.deployyy.app \
  --store en_CA \
  --category /men/70s \
  --blocks 3 --warmups 10 --runs 30 --profile \
  --output /tmp/fastboot-results
```

The runner starts one private, single-worker FPM master per mode, inheriting the container's PHP configuration and disabling class preload. Requests use loopback FastCGI; `--base-url` supplies the Magento host and scheme. Each workload alternates native/FastBoot order between blocks. A three-second pause before the last warmup allows newly generated PHP files through the default OPcache file-update protection window.

It requires HTTP 200, disabled FPC and identical GraphQL data or canonical Luma main content. Only whitespace and form keys are normalized in HTML. Empty product listings, response differences and exhausted OPcache abort the run.

`results.json` contains aggregate timings and memory, `samples.json` contains individual requests, and `*-smaps.txt` records Linux process memory. PHP time stops before measurement serialization. PHP allocated memory and shared OPcache usage are recorded separately. FPM processes stop on completion or failure; remove the private copies and the runtime-storage directory printed by `prepare.py` after retrieving results.

`--profile` captures a Magento CSV profile for each mode/workload after all measured traffic. Use `--workload products` (repeatable) to restrict a diagnostic run.

For measurements through the public endpoint, run from the client machine:

```sh
python3 dev/bench/online/public.py \
  --base-url https://codex-fastboot-online-ba18c2.m2gc.deployyy.app \
  --store en_CA --category /men/70s \
  --output /tmp/fastboot-public.json
```

This uses a new curl/TLS connection per request with compression negotiated, and reports time to first byte and total response time. GraphQL uses POST; category requests have a unique query parameter. The runner requires Varnish `MISS` or `UNCACHEABLE`, valid responses, exactly 24 products and a single HTML document for Luma. Its network/TLS timings are separate from the PHP comparison.

The preview was seeded from a headless storefront. Its 14 scoped `web/secure/base_link_url` and `web/unsecure/base_link_url` rows were reset to `{{secure_base_url}}` and `{{unsecure_base_url}}`, respectively, in this branch database. This keeps Luma navigation and Varnish ESI requests on the preview host. The PHP comparison used identical original link settings in both copies; the public HTTP measurements follow this correction.
