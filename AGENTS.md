# eindsuppliersearch development instructions

## Scope and starting references

This file supplements the workspace root AGENTS.md for this module. The module
has its own Git history; inspect its status independently of the parent repo.
When working from a standalone module checkout, retain the same safety rules:
inspect first, preserve user changes, never modify core or expose secrets, and
do not change production data, live supplier configuration, Git history, or
commerce/database architecture without explicit authorization.

Read relevant source and these workspace documents when available:

- `../../docs/development/mock-supplier-mode.md`
- `../../docs/development/product-aggregation.md`
- `../../docs/development/product-search-hit-presentation.md`
- `../../docs/development/grouped-search-ui.md`
- `../../docs/development/offer-action-reference.md`
- `../../docs/development/strict-product-detail.md`

Documentation includes historical implementation stages. For example, the
presenter document describes `product_hits` as unused, while grouped UI code
consumes it. Inspect current callers and report contradictions rather than
assuming all documented stages describe the current checkout.

## Architectural boundaries and conventions

- Keep domain models framework-independent where practical.
- Separate API transport, supplier-specific mapping, search orchestration,
  domain logic, presentation, and PrestaShop commerce integration.
- Keep supplier-specific API formats, authentication, and business rules in
  supplier adapters/mappers; shared logic must not hardcode Farnell behavior.
- Follow existing PHP conventions: `EindSupplierSearch\` namespaced code under
  `src/`, matching PHPUnit tests under `tests/`, and legacy PrestaShop controllers
  under `controllers/front/`. Prefer explicit types and narrowly scoped services.
- `SupplierProviderResolver` selects live/fixture mode; `SearchService`
  orchestrates search and safe failures. Avoid duplicating mode decisions in UI.
- Presenters map data for display; they must not query suppliers, create products,
  mutate stock/carts, or introduce pricing/selection policy.
- Preserve `SearchResult::toLegacyArray()` and current cached JSON contracts
  unless a compatible migration is approved. Do not serialize domain objects
  into the existing cache as an incidental refactor.
- Preserve grouped and legacy search paths and their feature flags:
  `EIND_GROUPED_SEARCH_UI` and `EIND_SUPPLIERSEARCH_API_MODE`.

## Product, offer, and action identity

- Logical product identity primarily uses manufacturer plus manufacturer part
  number with conservative whitespace/case normalization. Do not strip meaningful
  suffixes, punctuation, packaging indicators, or temperature grades casually.
- A logical product can have multiple independent supplier offers. Do not merge
  their prices, currencies, stock, MOQ, packaging, or procurement attribution.
- Durable supplier offer identity requires stable supplier identity plus the
  supplier-specific order reference. Order codes are not globally unique;
  supplier display names and MPNs alone are not persistence identities.
- `ProductSearchHit` groups logical products and offers; it does not select a
  preferred supplier or establish a PrestaShop catalog identity.
- `OfferActionReference` is a cached bucket/row coordinate, not a durable offer
  identifier. Keep that distinction explicit.
- Both add-to-cart and detail resolution must reuse `CachedOfferResolver` through
  the established strict cache entry point. Never add a global cross-supplier
  fallback or trust browser-supplied price/stock/product data.
- Preserve documented valid resolution: exact row first, optional order-code
  verification, and same-supplier order-code recovery when the row is missing.
  Do not describe this as unconditional rejection of every stale row index.
- Invalid, ambiguous, or unresolvable references must fail safely. Current
  duplicate-candidate and snapshot-freshness gaps require separately approved
  hardening; do not silently change resolver behavior during unrelated work.

## PrestaShop persistence and commerce

- Trace identity through lookup, creation/update, cart mutation, checkout, and
  order persistence; strict cache resolution alone does not guarantee attribution.
- The current `getIdBySupplierReference()` searches by order code alone. Treat
  this as known technical debt, not a pattern to copy or extend.
- Verify `Product`, `ProductSupplier`, `Cart`, `OrderDetail`, and payment/order
  hooks in installed PrestaShop source before designing integration changes.
- Setting `product.supplier_reference` does not establish the native
  `product_supplier` association. Native cart lines have no supplier selector;
  native order details have no supplier ID snapshot. Do not assume otherwise.
- Verify supplier mappings before persistence. Synthetic fixture IDs must not
  silently become real supplier identities.
- Do not overwrite unrelated catalog products, stock, or specific prices, adopt
  ambiguous records, or infer ownership from an order-code match alone.
- Preserve historical customer prices and supplier references/attribution.
  Changes to price, currency, stock, MOQ, multiples, cart separation, import,
  or order behavior need explicit scope and approval.
- The P0-01 product-per-offer architecture, registry/bindings, restricted catalog
  visibility, and cart/order snapshots remain proposals until explicitly approved.
  This instruction file does not approve their implementation.
- Existing virtual cards are presentation placeholders; add-to-cart imports real
  products. Do not assume a dummy-product/custom-cart implementation exists.

## Supplier API integration standards

- Target Farnell, Mouser, DigiKey, and additional suppliers through isolated
  adapters. Verify current official API contracts before implementing transport,
  authentication, pagination, filtering, or supplier-specific mapping.
- Use injectable transport and sanitized response fixtures. Validate malformed,
  missing, and unexpected fields; preserve supplier identity and source currency.
- Bound network requests with explicit connection and overall timeouts. Handle
  authentication failures, rate limits, HTTP errors, and malformed responses
  explicitly; retries must be bounded and appropriate for the operation.
- Design multi-supplier searches so one supplier's failure does not discard
  successful suppliers' results. Distinguish failure from an empty result.
- Never log API keys, tokens, full authenticated URLs, or sensitive payloads.
  Adding an adapter does not authorize live calls or configuration changes.

## Dependencies and testing

- Preserve Composer's `vendor-dev` directory and production module autoloader.
  Do not introduce `vendor/autoload.php` into web execution or create a runtime
  `vendor/` tree without reviewing the documented core/PHPUnit parser conflict.
- Prefer deterministic fixtures and injected collaborators. Do not require
  credentials, live network access, or PrestaShop for pure domain/unit tests.
- Existing fixture mode returns a single bucket per scenario; the three-supplier
  fixture is exercised directly by aggregation/resolution tests. Its presence
  does not prove live Farnell, Mouser, or DigiKey API integration.
- The initial verified baseline at commit `08a6d34` is PHPUnit 10.5.64,
  117 tests and 232 assertions. Counts are historical context, not a fixed target;
  investigate unexpected reductions rather than removing tests to match a count.
- From this module directory, run targeted tests as appropriate, then:

  ```sh
  php vendor-dev/bin/phpunit --do-not-cache-result
  ```

- For container runtime verification from the workspace:

  ```sh
  docker exec -w /var/www/html/modules/eindsuppliersearch prestashop_web php vendor-dev/bin/phpunit --do-not-cache-result
  ```

- Verify container/path availability before using these commands. Existing
  framework-free tests do not prove actual controller, database, cart, or order
  behavior. Use a designated test environment for those integration checks.
- Regression coverage should include shared order codes across suppliers, same
  MPN with independent offers, invalid/ambiguous references, legacy cache support,
  repeated searches, scoped local reuse, distinct cart lines, and historical
  order attribution whenever the approved change affects those guarantees.
- Report exact commands/results, skipped integration checks, and remaining risks.

## Documentation, Git, and final verification

- Keep relevant architecture and behavior documentation consistent with approved
  changes. Distinguish implemented behavior from proposals and historical QA.
- Inspect module Git status before and after work. Do not commit, push, merge,
  rebase, switch branches, update parent submodule pointers, or discard changes
  without explicit authorization.
- Verify the final change includes only authorized files, preserves public/cache
  contracts and feature flags, and introduces no credentials or unrelated edits.
- For instruction-only changes, validate Markdown and documentation references;
  do not modify application code, database records, configuration, or tests.
- Deliver file locations, contents/change summary, conflicts, verification
  results, and repository status. Stop when requested and await instructions.
