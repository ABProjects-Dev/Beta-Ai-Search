# Beta AI Search

A real, installable WordPress/WooCommerce plugin for **AI semantic natural-language product search** in Persian and English. It indexes public product data into plugin-owned WordPress database tables, understands structured query intent, and updates the store's **existing product grid in place** through an Elementor Free widget or shortcode.

This is not a keyword filter, UI mockup, or demo-results shell. AI failures are explicit and never silently degrade into keyword search. No product-image analysis is included.

## Deliverables

- Installable plugin ZIP: `release/beta-ai-search-1.0.0.zip`
- Full source ZIP: `release/beta-ai-search-source-1.0.0.zip`
- Plugin source: `beta-ai-search/`
- Architecture: `docs/ARCHITECTURE.fa.md`
- User/installation guide: `docs/USER-GUIDE.fa.md`
- Developer/extensibility guide: `docs/DEVELOPMENT.md`
- Honest test report: `TEST-REPORT.fa.md`
- Disposable integration harness: `tests/integration/` (not packaged in the production plugin ZIP)

## Implemented core

- Elementor Free widget + shortcode fallback; central widget texts; FA/EN RTL/LTR UI.
- Search only on button click or Enter; loading, clear text, separate filter reset, error + retry.
- Global catalog search independent of current category/shop filters.
- Multi-provider AI registry: OpenAI, Gemini, Anthropic, DeepSeek, Groq, Mistral, OpenRouter, custom OpenAI-compatible endpoints.
- Independent embedding and query-understanding model roles; capability-aware automatic selection.
- Plugin-owned index/queue/jobs/cache/reports tables; staged background indexing, pause/resume/rebuild, targeted CRUD/variation updates.
- Variable products indexed per variation, displayed once as the parent card; in-stock-first relevance ordering.
- Deterministic flexibility gates, bounded price overflow, strict AI schema validation, no AI-generated SQL execution.
- Existing WooCommerce card rendering without page refresh or a parallel grid; signed Loop Grid descriptor path for Pro (untested without a Pro license).
- Cache invalidation, rate limiting, encrypted server-side keys, manager capability + nonce checks, privacy-respecting reports and usage-based cost only when real usage/rates exist.

## Quick start

1. Install/activate WooCommerce, then upload and activate `release/beta-ai-search-1.0.0.zip`.
2. Add API keys under **Beta AI Search → Settings → AI providers**.
3. Select an embedding-capable model and a query-understanding model. They may use different providers.
4. Test connections, then build the index under **Product index**.
5. Place the Elementor widget above the existing shop grid, or use:

```text
[beta_ai_search]
```

See `docs/USER-GUIDE.fa.md` for full Persian instructions, cron setup, flexibility semantics, privacy and troubleshooting.

## Test status

Executed in a disposable WordPress 7.1.4 / WooCommerce 11.3.0 / Elementor Free 4.4.0 environment with PHP 8.5.12 and the WordPress SQLite integration drop-in:

- **59 PHP integration checks — 0 failures**
- **30 browser checks — 0 failures**
- PHP lint and JS syntax checks clean

These tests used a **controlled AI test double**, not live provider keys. They verify the real plugin pipeline, database, indexing, cache, security and browser/grid behavior. They do **not** certify live model quality, real provider billing, MySQL production behavior, or licensed Elementor Pro compatibility. Remaining manual checks are listed explicitly in `TEST-REPORT.fa.md`.

## Repository layout

```text
beta-ai-search/        Production plugin source
docs/                  Architecture, user guide, development guide
tests/integration/     Disposable WP harness + controlled AI test double
tests/results/         Raw executed test/lint results
release/               Packaged ZIP deliverables
```

## License

GPL-2.0-or-later. See plugin header and `https://www.gnu.org/licenses/gpl-2.0.html`.
