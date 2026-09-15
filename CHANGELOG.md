# Changelog

## 0.2.0-rc3 — documentation only

- Rewrote the README around installation, configuration, deployment and rollback.
- Added a configuration reference with feature defaults, Redis options, cache limits and filesystem ownership.
- Explained L1/L2 freshness, TTL, invalidation and failure behavior separately from historical benchmarks.
- Updated module and developer guides, including the preload master lifecycle and custom-var path requirement.
- Kept runtime files and Composer dependencies identical to RC2.

## 0.2.0-rc2

RC2 supersedes RC1 with the following additional fixes:

- Fenced parsed-document, structural-validation and view-config publication against invalidation during the producing request; direct schema resets retire validation proofs.
- Restricted cache interception to application cache types. Magento’s compiled plugin-list cache must bootstrap without the interceptors it is loading; fresh CLI startup and DI compilation now cover this boundary.
- Explicitly wired cache-state and directory dependencies in DI so compiled Magento does not substitute nullable defaults. Disabling config cache bypasses derived caches, and custom node-local var directories are respected.
- Added an explicit Magento root for preload in symlink/path installations and documented the required FPM master restart.
- Verified older Zend backend TTLs and core Magento 2.4.8 on PHP 8.3/8.4, plus sustained query-admission pressure and rollback/re-enable behavior.

## Earlier combined-package work

- Integrated strict server-local schema arrays with atomic Redis revisions, expiry, content hashes and installation-wide invalidation across rolling releases.
- Fenced cold schema publication against concurrent invalidation, eviction and competing writes; tested same-client Redis timeout recovery without retrying uncertain writes.
- Replaced version-specific generic PHP payloads with bounded, content-addressed blobs and collision-resistant indexes. Backend expiry is verified before promotion; successful saves invalidate other nodes.
- Changed system configuration caching from an eager whole-store tree to lazy requested scopes, with reentrancy protection and clean/reset handling.
- Replaced the query-processor bypass with cached structural rules inside the normal processor. Variable complexity, custom rules, literal coercion, operation handling and exceptions retain normal validation. Changed ASTs are revalidated.
- Bounded query admission and reused parsed documents within a request, separating documents accepted under different parser nesting policies.
- Matched PDO's NUL/Ctrl-Z quoting exactly; custom SQL modes and non-UTF8 sessions use the connected driver.
- Bound deployment checks to actual config contents and area metadata to immutable releases; added forced preparation after compilation and recovery from corrupt local area artifacts.
- Included transport security and theme identity in placeholder cache keys.
- Resolved deferred anonymous-class dependencies during preload recording, and excluded aliases/internal/compiler-only classes from the preload list.
- Added customer-facing status/preparation commands, a reproducible Composer artifact builder and installation verification.
