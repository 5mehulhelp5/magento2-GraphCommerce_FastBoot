# FastBoot GraphQL

This module reduces repeated GraphQL work by caching reusable schema data, parsed documents and successful built-in structural validation. Magento's request-specific limits, custom validation rules and scalar coercion still run.

Install it through the [FastBoot setup](../FastBoot/README.md). Its optimizations are enabled by default. The shared schema backend and freshness settings are covered in the [cache README](../FastBootCache/README.md).

Configuration changes must trigger Magento's normal configuration-cache invalidation. When deploying schema changes, use a new Magento static-content deployment version and follow the normal compilation and deployment process. Warm representative store-configuration, product and customer queries before sending traffic to a new release.

Extensions that change the schema dynamically must integrate those changes with configuration invalidation. Validate custom GraphQL operations, authorization and query limits in staging before rollout.
