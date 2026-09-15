# FastBoot development

The combined Composer package contains FastBootCache, FastBoot, FastBootGraphQl and FastBootPreload. Keep implementation in these modules; prototypes are not runtime dependencies.

- Optimize ordinary PHP-FPM. Each mechanism has an independently configurable `fastboot` switch. Schema L1 uses the installation identity and Magento's static-content deployment version; do not add a separate release setting. Never weaken request-specific GraphQL security checks or bypass Magento's processor/plugin chain to cache validation.
- Local PHP files contain scalars and arrays only. Redis/shared Magento caches remain authoritative. Preserve proven backend TTLs; unknown expiry must not be promoted. Schema metadata uses atomic Redis generation fences and strict validation by default; generic files snapshot the shared version once per request.
- Files are immutable, content-addressed, private and admission-bounded. Mutable indexes carry expiry. Do not preload runtime data files. A failed local write is a cache miss; a strict schema Redis failure is an error, not permission to serve stale data.
- Changed PHP/DI/config artifacts need a new Magento static-content deployment version and an FPM restart. Clean config/compiled_config before DI compilation. The preparation command must rebuild area differences after compilation, including when operating repeatedly in a development workspace.
- Keep unit and portable Redis tests under `dev/tests` and module `Test/Unit` directories. Validate combined real Magento responses, configuration saves, cache invalidation and memory as well as individual shortcuts. Compare timings relatively on the same host; PHP allocated memory and shared OPcache memory are separate measurements.
- `dev/release/build.py` builds the reproducible Composer artifact; `install-smoke.py` validates archive hashes and installation against an existing dependency tree. Neither publishes anything. Keep installation and operation in the root/module READMEs. Keep implementation/design notes, switches, tests and measurements under dev.

- Never attach cache interception to broad bootstrap decorators such as TagScope: the compiled plugin-list cache needs those decorators before interceptors can be resolved. Bind only the supported application cache types.
- Capture cache generation before computing derived artifacts and fence publication with it. Direct schema reset must retire structural-validation proofs.
- Explicitly configure optional object dependencies in di.xml when correctness depends on their presence; exercise compiled Magento, which may use constructor defaults.
- Preload belongs to a dedicated FPM master/service and changes require restarting that master. Respect FASTBOOT_MAGENTO_ROOT for path repositories.
