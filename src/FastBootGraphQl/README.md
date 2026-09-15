# GraphCommerce_FastBootGraphQl

This module reduces repeated GraphQL schema, parsing and validation work while keeping Magento's normal query processor.

| Feature | Reused work |
|---|---|
| Schema arrays | Assembled schema data, loaded through a private local L1 and authoritative Redis L2. |
| Parsed queries | Parsed documents, including source locations. Parser identity and available nesting policy participate in the key. |
| Structural validation | Successful built-in structural checks for an unchanged document/schema generation. |
| Scalar lookup | Lookup of scalar definitions in the schema. |
| Storefront helpers | Guest tax-factor calculations and placeholder URLs with theme/transport identity. |

Request-specific security checks, custom validation rules and scalar literal coercion continue to run. Changed documents are revalidated. A schema reset retires validation proofs; invalidation during execution cannot publish an old proof into the next generation.

Structural reuse assumes schema definitions stay stable within a release/cache generation. If an extension changes definitions dynamically without invalidation, disable `validated_queries` or integrate those changes with invalidation.

See the [installation guide](../../README.md), [feature switches](../../docs/CONFIGURATION.md#feature-switches), [schema design](../../SCHEMA-L1.md) and [validation results](../../docs/VALIDATION.md).
