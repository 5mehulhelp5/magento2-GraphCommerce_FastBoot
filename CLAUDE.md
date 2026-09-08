# GraphCommerce_FastBoot

A faster php-fpm bootstrap of Magento: opcache PHP files in front of the configuration caches, and the per-request work of the framework remembered.

## Layout

One composer package, one git repository, one Magento module per directory under `src/`, named as the module. `FastBootCache` is the base every other module depends on: the file layer (`Model\PhpFiles`, `Model\Version`), the two cache plugins, and the `Feature` switch reader. `FastBoot` holds what any request pays; `FastBootGraphQl` what a GraphQL request pays. A mechanism for another area or module goes into a module with that suffix, and depends only on the core modules it plugs into.

## Rules

- An integration changes nothing to get the gain: every mechanism is on by default, works with the php-fpm defaults, and fails soft (a file that cannot be written is a cache miss, not an error). The ini settings and the preload are gains on top, never requirements.
- Every mechanism has a switch, read by `Feature` from the `fastboot` array of env.php, named by a `private const SWITCH` in the class that implements it, listed in the README's switch table with its measured contribution.
- A file the layer writes holds only scalars and arrays through `var_export`, never an object; a cache entry with a lifetime keeps it in the file; a loaded entry of unknown lifetime is re-read after two hours.
- A change is measured before it is kept: `.tmp/perf/phpbench.sh` medians of the trivial query and the 24 item listing, and the switch bench (`.tmp/perf/ablate.sh`) for the README table. The catalog storefront package's parity gate must stay green on php-fpm and the worker.
- `setup:di:compile` reads the DI configuration through the config cache: run `cache:clean config compiled_config` before it, or new arguments and preferences are missing from the metadata while the plugins appear.
- With `opcache.validate_timestamps=0` on the host, a PHP or env.php change needs a php-fpm restart before it is seen.
- READMEs are short; this file holds the reasoning. No mention of the worker runtime outside the README's footnote.
