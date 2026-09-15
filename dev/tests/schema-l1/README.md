# Schema L1 integration tests

These source-repository tests exercise the schema cache against real Redis. They are excluded from the runtime ZIP.

Set `MAGENTO_ROOT` to a disposable Magento installation containing this package and phpredis. From this directory, run:

```sh
php tests.php
php edge-cases.php
python3 concurrency.py
python3 redis-failure.py
```

| Script | Checks |
|---|---|
| `tests.php` | Two local nodes, shared publication, invalidation and local reuse. |
| `edge-cases.php` | Tag modes, expiry/grace, corruption, failed local writes, admission limits and stale-publication rejection. |
| `concurrency.py` | Eight cold readers, then concurrent writers/readers producing coherent values. |
| `redis-failure.py` | Authentication failure, real read timeout, same-client recovery, empty restart and connection refusal. |

The first three use unique test keys on localhost Redis, port 6379, database 12. They delete only their own keys and do not flush a database. Change the fixture options if using a different dedicated Redis instance. Local fixture files remain under Magento's `var` directory for inspection.

The failure test requires Docker and the `valkey/valkey:8-alpine` image. It creates a uniquely named, loopback-only container and removes it afterward. It pauses/restarts that container, not the installation's Redis service.

Actual Magento clean/reset/flush behavior and GraphQL response parity require an application-specific harness. See the [validation report](../../../docs/VALIDATION.md) for completed application checks and the [test/build guide](../README.md) for units and artifact verification.
