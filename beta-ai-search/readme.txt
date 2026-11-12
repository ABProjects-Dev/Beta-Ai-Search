=== Beta AI Search ===
Contributors: betaaisearch
Tags: woocommerce, search, ai, semantic-search, elementor
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI semantic natural-language product search for WooCommerce. Persian + English, Elementor Free widget, shortcode, and in-place updates of the store's existing product grid.

== Description ==

Beta AI Search understands natural-language product requests (including colloquial Persian and English), extracts product type, use case, color, brand, price, material, size and technical needs, then searches a pre-computed semantic index stored in plugin-owned WordPress database tables.

It is not a keyword filter and does not silently fall back to keyword search when AI fails. It does not invent demo products, analyse product images, ask follow-up questions, or show automatic substitutes when nothing acceptable matches.

= Core behavior =

* Search fires only on button click or Enter; no AI request while typing.
* Results replace the contents of the existing WooCommerce product grid without a page refresh and without creating a parallel grid.
* Native WooCommerce card templates/classes are reused.
* In-stock matches are shown first, then out-of-stock matches; each group is ordered by relevance.
* Variable products are indexed per variation but displayed once as the parent card.
* The whole published catalog is searched, regardless of the current category or previous shop filters.
* «محصولی مطابق درخواست شما پیدا نشد.» is shown when no acceptable match exists.
* AI errors show a retry action and leave the current grid untouched.

= AI providers =

OpenAI, Google Gemini, Anthropic Claude, DeepSeek, Groq, Mistral, OpenRouter, and custom OpenAI-compatible endpoints are supported through an extensible provider registry. Embedding and query-understanding models are selected independently. Automatic selection only considers models explicitly declared capable of the requested role; Anthropic, DeepSeek and Groq are not assumed to provide embeddings.

API keys are stored server-side, encrypted at rest, and never sent to browser JavaScript. No AI-generated SQL is executed. Structured AI output is validated against a strict schema.

= Elementor and shortcode =

The Elementor widget works with Elementor Free. Elementor Pro is not required for the core widget or shortcode. Style controls include colors, typography, dimensions, borders, radius, shadow, padding/margin, input height, button size, icons and responsive Desktop/Tablet/Mobile settings. Three ready-made templates are included: Classic, Minimal and Pill. All widget texts are managed centrally in plugin settings.

Shortcode fallback:

`[beta_ai_search]`

Optional attributes:

* `target`: CSS selector of an existing grid, e.g. `[beta_ai_search target="#shop-grid ul.products"]`
* `template`: `classic`, `minimal`, or `pill`
* `scope`: `first` (default) or `all` matching grids

= Indexing and performance =

Background indexing uses a queue, database lease, bounded batches, cursor, pause/resume and full rebuild. Product CRUD, price, sale, stock, attributes, categories, brands, public custom fields, ACF allowlisted fields and variation changes queue targeted updates. WP-Cron should be connected to a real system cron in production.

= Matching flexibility =

Global flexibility (0–100) and per-attribute flexibility (price, color, brand, size, material, technical, other) are configurable. Acceptance thresholds are deterministic and documented in `docs/ARCHITECTURE.fa.md` and `docs/USER-GUIDE.fa.md`. Missing explicit requirements are not treated as matches, and price overflow is bounded.

= Reports and privacy =

Admin reports include search totals, top queries, zero-result/successful searches, history, active models, API request counts, token usage and provider/model breakdowns. Estimated cost is calculated only from real API usage multiplied by explicit stored model rates; otherwise cost is unknown. Query text storage is opt-in; by default only a salted hash is retained. IP addresses are not stored in reports. Error logs exclude secrets and raw provider responses.

= Compatibility notice =

Tested environments and remaining manual checks are listed in `TEST-REPORT.fa.md`. Elementor Pro Loop Grid/Products/Archive Products adapters are provided as integration paths but were not tested with a licensed Pro installation in this build. Third-party compatibility is not claimed without real tests.

== Installation ==

1. Upload the `beta-ai-search` folder to `/wp-content/plugins/`, or install the ZIP through WordPress → Plugins → Add New.
2. Activate WooCommerce first, then activate Beta AI Search.
3. Go to Beta AI Search → Settings → AI providers and enter server-side API keys.
4. Configure an embedding-capable model and a query-understanding model (they may use different providers).
5. Save settings, test the connections, then build the product index under Beta AI Search → Product index.
6. Add the Elementor widget above the existing shop grid, or place `[beta_ai_search]` above the grid.
7. In production, configure a system cron for WordPress cron and review privacy/retention settings.

== Frequently Asked Questions ==

= Does it require Elementor Pro? =

No. The core widget works with Elementor Free, and the shortcode works without Elementor. Pro-specific grid paths require real Pro testing.

= Does it fall back to keyword search if the AI provider fails? =

No. It shows an error and retry action, leaving existing products untouched.

= Does it analyse product images? =

No. This version uses textual/public product data only.

= Where are embeddings stored? =

In plugin-owned tables inside the WordPress database. An external vector database is not required.

= Why is cost shown as unknown? =

Cost requires both real token usage from the provider and a valid stored rate for that exact model. Missing data is never fabricated as zero.

= Does deactivation delete data? =

No. Uninstall deletes plugin tables/options only if the store manager explicitly enabled data removal in Settings → General.

== Changelog ==

= 1.0.0 =
* Initial release: semantic indexing, multi-provider AI, Elementor Free widget, shortcode, existing-grid rendering, flexibility gates, cache, reports, security controls and Persian/English UI.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Configure AI providers and build the index before searching.
