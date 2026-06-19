# Phase 4 — Schema (JSON-LD) + Breadcrumbs Design Spec

**Date:** 2026-06-19
**Status:** Approved (design); ready for implementation planning.
**Scope of this document:** the full Phase 4 design. The detailed implementation
plan is written separately (writing-plans skill) once this spec is approved.

---

## 1. Goal

Give OpenSEO structured-data output: a single connected JSON-LD `@graph` in the
document head (WebSite, Organization/Person, WebPage, Article, BreadcrumbList), a
breadcrumb trail exposed as a theme function **and** a Gutenberg block, and an
AI ability that recommends a richer schema type for an entry. All of it built on
the existing `Hookable` composition root, reusing the Phase 1 `Resolver` so the
schema stays consistent with the meta tags and Open Graph already emitted.

This is the Phase 4 entry of the roadmap in
`2026-06-18-openseo-design.md` §7 ("Schema + Breadcrumbs"), delivered in a single
cohesive phase (sized like Phase 1).

---

## 2. Decisions taken during brainstorming

| Topic | Decision |
|-------|----------|
| Phase scope | Everything in one phase: JSON-LD + breadcrumbs (function + block) + AI ability. |
| JSON-LD structure | A **single connected `@graph`** in one `<script>`; pieces carry `@id` and cross-reference. (Yoast/Rank Math style; Google's preference; avoids duplicate entities.) |
| Site identity | A **new "Schema" settings tab**: type (Organization \| Person), name, logo. |
| AI ability | **Recommend the richest fitting type** (FAQPage, HowTo, Recipe, Product, …) with a short reason. On-demand only; never called on page load. |
| Per-entry control | A **type selector** in the editor panel: Default / Article / BlogPosting / NewsArticle / WebPage / None. The AI recommends; the user applies among supported types. Rich types (FAQPage…) are informational recommendations for a future phase. |
| Breadcrumbs config | **Minimal**: a global separator (Schema tab) + basic block attributes + `openseo_breadcrumbs()` args with defaults. |
| Code organization | **Composable pieces** (Approach A): each schema node is a small `Piece` class; a `Graph` assembles applicable pieces. Matches the existing `Frontend\Head\` presenter pattern; best isolation for TDD; extensible to rich types without touching existing pieces. |

### Non-goals (explicitly out of scope for Phase 4)

- Generating full JSON-LD for rich types (FAQPage/HowTo/Recipe/Product): the AI
  ability *recommends* such a type, but the generator only emits
  Article/BlogPosting/NewsArticle/WebPage nodes. Rich-type generation is a future
  phase.
- A media-library picker for the schema logo (use a text URL field, like
  `og_default_image`).
- Per-post-type schema defaults, taxonomy/term schema, breadcrumb config beyond a
  global separator + block attributes.
- Any schema validation service round-trip / external HTTP.

---

## 3. Architecture

Two new namespaces, both following the established `Hookable` + composition-root
pattern (`Plugin::modules()`):

- **`OpenSEO\Schema\`** — builds and emits the JSON-LD `@graph`.
- **`OpenSEO\Breadcrumbs\`** — the trail builder, its HTML renderer, and the block.

New modules registered in `Plugin::modules()`:

- `Schema\Graph` (front-end) — hooks `wp_head`; assembles the graph and prints a
  single `<script type="application/ld+json">`. It is **not** folded into
  `HeadPrinter` (which owns meta/link tags) — the graph logic is distinct enough
  to warrant its own module, keeping `HeadPrinter` focused.
- `Breadcrumbs\Block` (always, on `init`) — registers the dynamic
  `openseo/breadcrumbs` block via `register_block_type_from_metadata`.

The theme template function `openseo_breadcrumbs()` lives in
`src/template-functions.php`, loaded through `composer.json` `autoload.files` — the
clean way to expose a global function under PSR-4.

`Schema\Graph` and the breadcrumb pieces reuse the existing Phase 1 `Resolver`
(title/description/canonical/social image) so structured data matches the rest of
the head output.

---

## 4. Component design

### 4.1 Schema generator (pieces + graph)

**`Schema\Piece` (interface)** — each unit answers three questions:

```php
interface Piece {
    public function is_needed(): bool;  // does this node apply to the current request?
    public function id(): string;       // its @id, e.g. home_url( '/#website' )
    public function data(): array;      // the full node array: @type, @id, and refs by @id
}
```

**`Schema\Ids`** — a small helper that centralizes every `@id` value
(`#website`, `#organization`/`#person`, `<url>#webpage`, `<url>#article`,
`<url>#breadcrumb`). Pieces use it both for their own id and to reference each
other **without coupling** to sibling classes.

**`Schema\Graph` (Hookable)** — constructed with `Piece[]`. On `wp_head`:
1. keep pieces whose `is_needed()` is true,
2. collect each `data()`,
3. wrap as `{ "@context": "https://schema.org", "@graph": [ … ] }`,
4. print with
   `wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )`
   inside the `<script>` (`JSON_HEX_TAG` neutralizes a `</script>` break-out).

If no piece is needed (unlikely — WebSite + identity are always needed), it prints
nothing.

**Concrete pieces** (`src/Schema/Pieces/`):

| Piece | `is_needed()` | Key fields |
|-------|---------------|-----------|
| `WebSite` | always | `name`, `url`, `publisher`→identity `@id`, `potentialAction` (`SearchAction` for the site search) |
| `Organization` / `Person` | always (per `schema_site_type`) | `name`, `url`, `logo` as `ImageObject` (Organization) / `image` (Person); referenced as publisher/author |
| `WebPage` | `is_singular()` or `is_front_page()` | `isPartOf`→website, `breadcrumb`→breadcrumb `@id`, `url`, `name`, `datePublished`/`dateModified`, `primaryImageOfPage` |
| `Article` | post singular, unless per-entry type is `none`/`WebPage` | `@type` from the per-entry selector (Article/BlogPosting/NewsArticle); `isPartOf`→webpage, `author`, `publisher`, `headline`, dates, `image`, `mainEntityOfPage` |
| `BreadcrumbList` | a trail exists (not on the home root) | `itemListElement` built from `Breadcrumbs\Trail` |

Exactly which content node appears for a singular entry:
- page → `WebPage` only.
- post, selector `''` (Default) → `WebPage` + `Article`.
- post, selector `Article`/`BlogPosting`/`NewsArticle` → `WebPage` + that `@type`.
- selector `WebPage` → `WebPage` only (no Article).
- selector `none` → `WebPage` only; no content node beyond the page itself.

Each piece is unit-tested in isolation with Brain Monkey: `data()` returns an
array, asserted directly; `is_needed()` depends on `is_singular()`/
`is_front_page()`, which are mocked.

### 4.2 Breadcrumbs (one source, three consumers)

**`Breadcrumbs\Trail`** — `items(): array<int, array{ name: string, url: string }>`.
Builds home → ancestors/archive → current, by context: pages (ancestors via
`post_parent`), posts (primary category), archives (category/tag/custom
tax/author/date), search, 404. Single source of truth for the hierarchy; returns
data only (no printing).

**`Breadcrumbs\Renderer`** — `render( array $items, array $args ): string`.
Returns `<nav aria-label="Breadcrumb"><ol>…</ol></nav>`, escaped (`esc_html`
names, `esc_url` urls, `esc_attr` classes/alignment). `$args`: `separator`
(default = `breadcrumb_separator` setting), `show_home`, `text_align`.

The **three** consumers share `Trail`:
1. `openseo_breadcrumbs( array $args = array() ): void` — echoes
   `Renderer::render( Trail::items(), $args )`; for theme templates.
2. The **block** `openseo/breadcrumbs` — dynamic (`render.php` / `render_callback`
   calls `Renderer`); a minimal `edit.js` using `ServerSideRender`; attributes
   `showHome` (bool) + `textAlign`. Built with `@wordpress/scripts`, registered
   with `register_block_type_from_metadata`.
3. `Schema\Pieces\BreadcrumbList` — the same `Trail` rendered as JSON-LD,
   referenced by `WebPage.breadcrumb`.

### 4.3 AI ability + per-entry control

**Per-entry meta** (added to `Meta\PostMeta::KEYS`):
- `_openseo_schema_type` — string, `sanitize_callback` enforces a whitelist:
  `''` (Default) · `Article` · `BlogPosting` · `NewsArticle` · `WebPage` · `none`.
  Read by the `Article`/`WebPage` pieces (see §4.1).

**Ability `openseo/suggest-schema-type`** (`src/Ai/Abilities.php`):
- `input_schema`: `{ post_id: integer }` (required).
- `output_schema`: `{ type: string, reason: string }` (both required). `type` is
  validated against the per-entry whitelist **plus** richer suggestible types
  (`FAQPage`, `HowTo`, `Recipe`, `Product`) which this phase surfaces as
  informational recommendations.
- `permission_callback`: `edit_post`. No connector → `WP_Error`
  `openseo_no_connector` (reuses `Ai\Connector`). Invalid post →
  `openseo_invalid_post`.
- Reuses the Phase 2 `wp_ai_client_prompt()` flow and JSON-parse fallback;
  `Ai\Prompts` gains `system_schema_type()`.
- **Annotation note:** the brainstorming option was labelled "readonly". The
  ability *is* readonly in the meaningful sense — **it never writes to the site**.
  However, for consistency with the Phase 2 AI abilities (it invokes the model, is
  not idempotent, and spends provider credits) it registers with
  `meta.show_in_rest => true` and `meta.annotations.readonly => false`, so clients
  invoke it via **POST**. The user-facing intent — "doesn't spend credits on every
  load" — holds because it is **only called on demand** (button click), never
  automatically. *(Open to flipping `readonly => true` if preferred; flagged for
  spec review.)*

**Editor panel** (`assets/src/editor/index.js`, Advanced tab):
- a `SelectControl` bound to `_openseo_schema_type` (Default / Article /
  BlogPosting / NewsArticle / WebPage / None), and
- a "Recommend type with AI" button that calls the ability via `apiFetch` POST to
  `/wp-abilities/v1/.../run` (the corrected Phase 2 pattern — **not**
  `executeAbility`), then shows `{ type, reason }` as a `Notice` with an "Apply"
  action that sets the select.

### 4.4 Settings — "Schema" tab

New tab `schema` → section `openseo_schema`, using a new small
`SettingsPage::add_select_field()` helper (mirrors `add_text_field` /
`add_checkbox_field`):
- `schema_site_type` — select Organization | Person (default `Organization`).
- `schema_site_name` — text (empty = `get_bloginfo( 'name' )`).
- `schema_logo` — text URL (same pattern as `og_default_image`; no media picker).
- `breadcrumb_separator` — text (default `›`).

`Options::defaults()` + `sanitize()` gain these four keys: `schema_site_type`
(whitelist Organization/Person), `schema_site_name` (text), `schema_logo`
(`esc_url_raw`), `breadcrumb_separator` (text). `templates/admin/settings-page.php`
adds `'schema'` to `$openseo_tabs`.

---

## 5. Data flow

```
wp_head (front-end)
  └─ Schema\Graph::print()
       ├─ for each Piece: is_needed()? → data()        (pieces pull from Resolver,
       │                                                 Options, get_post, Trail, Ids)
       └─ wp_json_encode({ @context, @graph: [...] })   → <script type="application/ld+json">

Theme call / Block render
  └─ openseo_breadcrumbs() | block render.php
       └─ Renderer::render( Trail::items(), args )       → escaped <nav>…</nav>

Editor "Recommend type with AI" (on demand)
  └─ apiFetch POST /wp-abilities/v1/openseo/suggest-schema-type/run { input:{ post_id } }
       └─ Abilities::suggest_schema_type()
            ├─ Connector::is_text_generation_available()? else openseo_no_connector
            └─ wp_ai_client_prompt(...)→ { type, reason }  → Notice + "Apply" → sets select

Save (existing Phase 1 flow)
  └─ _openseo_schema_type round-trips via useEntityProp / REST meta
```

---

## 6. Error handling, security, degradation

- **JSON-LD output:** `wp_json_encode` with `JSON_HEX_TAG` (cuts `</script>`
  injection); values derive from WP data, not raw input.
- **Breadcrumb HTML:** `esc_html` (names), `esc_url` (urls), `esc_attr`
  (classes/alignment).
- **`_openseo_schema_type` and the ability `type`:** strict whitelist.
- **Ability:** `edit_post` permission via the abilities framework; `reason`
  through `sanitize_text_field`; no silent fallback (returns `WP_Error`).
- **Degradation:** pieces with `is_needed() === false` are omitted; optional fields
  with no value are dropped (no empty keys emitted); the graph is always
  well-formed. With no AI Client, the ability returns `WP_Error` and the rest of
  the schema is fully deterministic, independent of AI.

---

## 7. Testing strategy

- **Unit (Brain Monkey, no WordPress):** each `Piece` (`is_needed` + `data`
  shape), `Ids`, `Trail::items()` per context, `Renderer` (escaped HTML),
  `Options` new defaults/sanitize, ability `suggest-schema-type`
  (invalid_post / no_connector / JSON parse success + fallback), and the JS
  recommendation/apply helper if a pure helper is extracted.
- **Integration (wp-env / WP test suite):** the `<script ld+json>` with `@graph`
  appears on a singular; `BreadcrumbList` has the correct `itemListElement`;
  Organization vs Person follows the setting; selector `none` suppresses Article;
  the block registers; the ability is exposed over REST (`/run` route exists,
  no-connector path returns `openseo_no_connector`); `_openseo_schema_type`
  round-trips through REST meta.

CI reality (no provider key) means the AI ability is exercised only on the
deterministic `openseo_no_connector` / REST-exposure paths, like Phase 2.

---

## 8. Files touched

**New (PHP):**
- `src/Schema/Piece.php` (interface)
- `src/Schema/Graph.php` (Hookable)
- `src/Schema/Ids.php`
- `src/Schema/Pieces/WebSite.php`
- `src/Schema/Pieces/Organization.php`
- `src/Schema/Pieces/Person.php`
- `src/Schema/Pieces/WebPage.php`
- `src/Schema/Pieces/Article.php`
- `src/Schema/Pieces/BreadcrumbList.php`
- `src/Breadcrumbs/Trail.php`
- `src/Breadcrumbs/Renderer.php`
- `src/Breadcrumbs/Block.php` (Hookable)
- `src/template-functions.php` (`openseo_breadcrumbs()`)

**New (JS/block):**
- `assets/src/blocks/breadcrumbs/block.json`
- `assets/src/blocks/breadcrumbs/index.js`
- `assets/src/blocks/breadcrumbs/edit.js`
- `assets/src/blocks/breadcrumbs/render.php`

**Modified:**
- `src/Settings/Options.php` (defaults + sanitize: schema_site_type,
  schema_site_name, schema_logo, breadcrumb_separator)
- `src/Meta/PostMeta.php` (KEYS += `_openseo_schema_type` + whitelist sanitize)
- `src/Admin/SettingsPage.php` (Schema section + `add_select_field`)
- `templates/admin/settings-page.php` (schema tab)
- `src/Ai/Abilities.php` (suggest-schema-type ability)
- `src/Ai/Prompts.php` (`system_schema_type()`)
- `src/Plugin.php` (register `Schema\Graph`, `Breadcrumbs\Block`; wire deps)
- `assets/src/editor/index.js` (schema type select + recommend button)
- `webpack.config.js` (breadcrumbs block entry, if the custom config needs it)
- `composer.json` (`autoload.files` → `src/template-functions.php`)
- `CLAUDE.md`, `NOTES.md` (document Phase 4)

---

## 9. Self-review

**Placeholder scan:** no TBD/TODO; every component, field, and file is named
concretely. The one open question (ability `readonly` annotation) has a concrete
default (`false`, matching Phase 2) with the alternative explicitly flagged for
spec review — not a placeholder.

**Internal consistency:** the `@graph` pieces in §4.1 match the architecture in §3
and the data flow in §6/§5; the per-entry selector values are identical in §4.1,
§4.3, and the whitelist in §6; `Trail` is the single source for the function,
block, and `BreadcrumbList` in §4.2; reused names (`Resolver`, `Connector`,
`Prompts`, the apiFetch `/run` pattern) match the Phase 1/2 code.

**Scope check:** focused enough for a single implementation plan, sized like
Phase 1. Rich-type generation, media picker, and per-type defaults are explicitly
deferred in §2.

**Ambiguity check:** the one genuine fork (which content node appears per selector
value) is made explicit as a table in §4.1; the ability annotation nuance is
spelled out in §4.3.
