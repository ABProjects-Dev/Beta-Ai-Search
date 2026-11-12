# Beta AI Search — Development Guide

## Module map

```
beta-ai-search/
  beta-ai-search.php          Bootstrap, requirements guard, autoloader, activation/deactivation
  uninstall.php               Opt-in data removal on uninstall
  includes/
    Core/                     Settings, encrypted secrets, schema, reports, rate limiting, plugin wiring
    AI/                       Provider contract, registry, HTTP client, strict query schema, native providers
    Index/                    Public product extractor, queue, background indexer
    Search/                   Text normalization/price parsing, deterministic matcher, search service/cache
    Frontend/                 Shortcode/widget, assets, REST API, existing-grid renderer
    Admin/                    Dashboard, 12 settings sections, index controls, reports, errors
  assets/                     Frontend/admin JS and CSS
```

Namespace: `BetaAI\Search\` → `includes/`. Constants: `BAS_VERSION`, `BAS_FILE`, `BAS_PATH`, `BAS_URL`, optional `BAS_ENCRYPTION_KEY`.

## Runtime contracts

- Options: `bas_settings`, `bas_provider_secrets`, `bas_schema_version`, `bas_index_revision`, `bas_active_model`, `bas_active_config`.
- Tables: `{prefix}bas_index`, `bas_queue`, `bas_jobs`, `bas_cache`, `bas_searches`, `bas_usage`, `bas_errors`, `bas_rates`.
- REST namespace: `beta-ai-search/v1`.
  - `POST /search` — public, rate-limited, `{query}` → `{ids,total,catalog_matches,cached}`.
  - `POST /render` — public, rate-limited, `{ids,page,page_size,columns,context}` → native card HTML for the existing grid.
  - `GET /admin/index` — `manage_woocommerce` / `manage_options`.
- Admin AJAX actions are prefixed `bas_` and require the `bas_admin` nonce plus manager capability.
- Cron events: `bas_index_worker`, `bas_index_cleanup`, `bas_privacy_cleanup`.

## Adding an AI provider

For an OpenAI-compatible endpoint, no code is required: configure **Custom OpenAI-compatible**, declare whether it supports embeddings and/or chat, pin the model IDs, set dimensions for embeddings, and store the key. Automatic selection only uses a custom model when its capability is explicitly declared.

For a native provider:

1. Implement `BetaAI\Search\AI\ProviderInterface`:
   - `id()`, `catalog()`
   - `embed(array $texts, string $model, int $dimensions, bool $is_query)`
   - `understand(string $text, array $context, string $model)`
   - `list_models()`
2. Return validated usage arrays through `HttpClient::usage()` where possible. Never log keys or raw responses.
3. Register the definition and instance:

```php
add_filter( 'bas_ai_provider_definitions', function ( array $definitions ): array {
    $definitions['acme'] = [
        'label' => 'Acme AI',
        'base_url' => 'https://api.example.com/v1',
        'models' => [
            [ 'id' => 'acme-embed', 'embedding' => true, 'chat' => false, 'dimensions' => 1024 ],
            [ 'id' => 'acme-chat', 'embedding' => false, 'chat' => true ],
        ],
    ];
    return $definitions;
} );

add_filter( 'bas_ai_provider_instance', function ( $provider, string $id, array $definition, array $config ) {
    if ( 'acme' === $id ) {
        return new \Acme\AI\WordPressProvider( $config['base_url'] ?? $definition['base_url'], $definition );
    }
    return $provider;
}, 10, 4 );
```

Capability flags are authoritative for automatic selection. Do not mark a chat-only provider as embedding-capable. Embedding model changes require a full rebuild; the previous active index remains searchable until the rebuild completes successfully.

## Product data boundary

The extractor uses WooCommerce CRUD and public taxonomies. Protected/internal meta keys beginning with `_` are excluded from the public custom-field allowlist and do not trigger index churn. Sensitive key patterns are rejected even if someone tries to allowlist them.

Filters for integrations:

- `bas_product_public_meta_keys` — add public field keys for a product.
- `bas_product_public_custom_fields` — add sanitized public field values.
- `bas_product_brand` — resolve brand values from a third-party brand system.
- `bas_product_facts` — adjust structured facts used by gates.
- `bas_index_document` — adjust the textual document sent for embeddings.
- `bas_index_entities` — adjust parent/variation entities.
- `bas_variation_indexable` — control variation visibility.
- `bas_index_document_max_chars` — document cap (default 60000).
- `bas_embedding_chunk_chars` — chunk size (clamped 1000–6000).
- `bas_index_worker_budget` — worker time budget in seconds (default 12).

Never add private customer/order data, credentials, internal cost fields, or raw AI instructions to public documents.

## Matching and query understanding

- `bas_minimum_query_confidence` — default `0.60`; lower-confidence structured intent returns no results rather than rewriting the query or falling back to keywords.
- `bas_attribute_aliases` — synonym groups for attribute scoring.
- `bas_search_rate_limit` — public search requests per minute per hashed IP (default 12).

Acceptance formulas are implemented in `Search/Matcher.php` and documented in `ARCHITECTURE.fa.md`. Price bounds explicitly parsed from the query override the LLM price; currency conversion requires explicit rates except IRT↔IRR.

## Existing-grid integration

Supported render paths:

1. **WooCommerce loop** — detects `ul.products`, renders cards with `wc_get_template_part('content','product')`, restores loop props and global post/product state.
2. **Elementor Loop Grid descriptor** — signed server-side descriptor for an existing Loop Item template; intended for Pro installations and marked untested without a real Pro license.
3. **Query-ID bridge** — `elementor/query/bas_search` applies IDs supplied through `bas_current_grid_ids` for custom/Pro query integrations.

Frontend events after a successful commit:

- DOM: `beta-ai-search:updated` with `{container}`.
- jQuery: `bas_grid_updated` on `document.body` with `[container]`.

For a third-party grid, prefer an explicit `target` selector if it uses the native Woo loop. If it renders cards through a different engine, write a custom adapter that:

1. detects the existing container without creating a replacement grid;
2. calls the public search endpoint for IDs;
3. renders those IDs through the store's existing card template;
4. commits only after all target grids render successfully;
5. snapshots original HTML/classes/pagination and restores them on reset;
6. reinitializes the grid's own frontend handlers and emits the events above.

Do not claim universal compatibility. React/Store-API grids that rebuild DOM nodes usually need a dedicated adapter and real browser tests.

## Security checklist for changes

- Keys only through `Secrets`; never inline them into JS or admin HTML.
- Manager capability + nonce for admin mutations.
- Prepared statements for user-derived SQL values; fixed allowlists for table names.
- Validate AI output with `QuerySchema`; never execute AI-generated SQL/code.
- Escape all widget/admin output; sanitize settings on save.
- Keep error logs structured and secret-free.
- Preserve the public/private product-data boundary.

## Local checks

```sh
find beta-ai-search -name '*.php' -print0 | xargs -0 -n1 php -l
node --check beta-ai-search/assets/js/frontend.js
node --check beta-ai-search/assets/js/admin.js
```

The repository also contains a disposable WordPress integration harness under `tests/integration/`. It uses a controlled AI test double and must never be packaged inside the production plugin ZIP. See `TEST-REPORT.fa.md` for what was actually executed and what still requires live keys/licensed Pro.
