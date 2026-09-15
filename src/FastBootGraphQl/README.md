# FastBoot GraphQL

Caches assembled schema data, parsed documents and successful built-in structural validation. Magento's request-specific limits, custom validation rules and scalar coercion continue to run.

Optimizations are enabled by default. Schema changes must trigger Magento configuration-cache invalidation, which also invalidates cached structural validation. Extensions with dynamic schemas must participate in that invalidation.
