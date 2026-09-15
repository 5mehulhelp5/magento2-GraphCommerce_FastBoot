# GraphCommerce_FastBootCache

This module provides the local PHP-file cache used by the other FastBoot modules. Shared Magento caches remain authoritative for the values they back; the schema cache has its own Redis record.

## Generic values

`Model/PhpFiles` stores immutable arrays/scalars in files named by their content hash. Separate indexes identify the current generation and expiry. Identical values can reuse the same compiled file across invalidations.

`Model/Version` reads a shared generation token on first use in each request. Successful writes and supported clean/remove operations invalidate covered values across nodes. The default grace is zero. An entry is promoted from a backend only when its expiry can be verified; unknown expiry uses Magento directly.

Cache interception covers configuration, EAV, translation, DDL, reflection and collection cache types, plus selected default-frontend entries. It deliberately leaves Magento's compiled plugin-list cache on its native bootstrap path.

`Model/Feature` reads the per-feature switches and applies Magento's config-cache state to the derived data caches. File and query admission limits stop unbounded additions; reaching a limit falls back to the authoritative or native path.

## GraphQL schema

`Model/Schema` provides atomic Redis publication, strict validation and local schema promotion. See the [schema L1/L2 guide](../../SCHEMA-L1.md) for its distinct read/write and failure behavior.

Use the [combined installation guide](../../README.md) and [configuration reference](../../docs/CONFIGURATION.md) for release identities, filesystem ownership and cache limits.
