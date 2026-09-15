# GraphCommerce_FastBoot

This module reduces repeated Magento configuration and lookup work during ordinary PHP-FPM requests.

| Area | Behavior |
|---|---|
| System configuration | Load reusable PHP data for the requested default, website or store scope. |
| Area DI configuration | Apply compiled area differences to the global configuration. |
| Theme view configuration | Reuse parsed `view.xml` data by theme and area. |
| Store resolution | Reuse scope data and website/store relationships; use the store repository for default-store lookup. |
| Deployment configuration | Key reusable checks by the actual configuration contents. |
| SQL quoting | Avoid opening a connection when the shortcut's supported conditions hold; otherwise use the connected driver. |

It also supplies `bin/magento fastboot:status` and `bin/magento fastboot:prepare`.

The combined package installs this module with FastBootCache, FastBootGraphQl and FastBootPreload. Start with the [installation guide](../../README.md); use the [configuration reference](../../docs/CONFIGURATION.md) to disable individual features and the [validation report](../../docs/VALIDATION.md) for measurements.
