# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

OpenSEO is an open-source, AI-native SEO plugin for WordPress, built on the **WordPress 7.0
Abilities API**. It does not ship its own AI provider — it reuses keys configured under
**Settings → Connectors** (the WP AI Client). Targets **WordPress 7.0+** and **PHP 8.1+**;
GPL-2.0-or-later.

The plugin is dual-stack: **PHP** (the plugin itself, PSR-4 `OpenSEO\` → `src/`) plus a small
**JS/SCSS admin bundle** built with `@wordpress/scripts`.

## Working directories

- `openseo/` — **the project**. All work happens here.
- `seo-by-rank-math/` — the Rank Math SEO plugin, present **only as a reference/inspiration**
  codebase (mature SEO plugin to study for feature parity). It shares no code with OpenSEO
  (different namespace, build system, and architecture). Do not edit it unless explicitly asked.

## Commands

PHP quality tooling runs **locally** (needs `composer install`). Anything touching a real
WordPress runtime (integration tests, WP-CLI) runs **through `wp-env`**, which requires Docker.

```bash
# Install
composer install        # PHP deps + tooling + autoloader (plugin will not boot without vendor/autoload.php)
npm install             # @wordpress/scripts + @wordpress/env

# PHP quality gates (keep all three green before committing)
composer lint           # PHPCS (WordPress Coding Standards)
composer lint:fix       # PHPCBF auto-fix
composer analyze        # PHPStan level 6 (runs with --memory-limit=1G)
composer test:unit      # PHPUnit unit tests (Brain Monkey, no WordPress loaded)
composer check          # lint + analyze + test:unit in one go

# Run a single unit test
vendor/bin/phpunit --filter test_sanitize_cleans_and_normalizes_input

# JS / CSS
npm run build           # production asset build (one-off)
npm run start           # watch mode
npm run lint:js
npm run lint:css
npm run test:js

# Local WordPress (Docker required) → http://localhost:8888 (admin/password)
npm run env:start
npm run env:stop
npm run env:clean       # reset the environment / DB

# Integration tests (requires wp-env running; PHPUnit runs inside the container)
npm run test:integration

# WP-CLI is bundled inside wp-env — no global install
npm run env:run -- cli wp plugin list
npm run env:run -- cli wp option get openseo_settings

# Release ZIP (ships vendor/ prod autoloader + assets/build/, excludes sources/tests/tooling)
composer install --no-dev --optimize-autoloader
npm ci && npm run build
npm run plugin-zip      # honors .distignore
```

CI (`.github/workflows/ci.yml`) runs the PHP gates across PHP 8.1/8.2/8.3, the JS lint+build,
and the wp-env integration suite.

## Architecture

**Bootstrap → composition root → Hookable modules.**

- `openseo.php` is the only file that does work at load time: defines `OPENSEO_*` constants,
  loads the Composer autoloader (shows an admin notice instead of fataling if it's missing),
  registers activation/deactivation hooks at the top level, and boots `Plugin` on
  `plugins_loaded`. No heavy work happens at file-load time.
- `src/Plugin.php` is a tiny composition root (singleton). `Plugin::modules()` builds the list
  of modules for the request; `Rest\RedirectsController` and `Rest\NotFoundController` are
  always-on (registered outside `is_admin()`); admin-only modules (`Menu`, `Admin\Assets`,
  `EditorPanel`) are gated behind `is_admin()`. `boot()` calls `register()` on each, exactly once.
- Every feature is a class implementing `Contracts\Hookable` (`register(): void`) that wires its
  own WordPress hooks. **To add a feature: create the `Hookable` class under `src/` and add it to
  `Plugin::modules()`** — nothing else discovers modules.

**Key modules:**
- `Settings/Options.php` — all settings live under a **single option key** `openseo_settings`
  (`Options::OPTION_KEY`). Typed read (`all`/`get` merge over `defaults()`) and `sanitize()` on
  write. Single key keeps activation seeding and uninstall cleanup trivial.
- `Ai/` — the AI layer. `Abilities` registers the `openseo` category on
  `wp_abilities_api_categories_init` (the category MUST register on this hook, not on
  `wp_abilities_api_init`, or it is rejected) and the `generate-meta-description` /
  `generate-title` abilities on `wp_abilities_api_init` (all `function_exists`-guarded for
  pre-7.0). Each ability declares `input_schema`, `output_schema`, `execute_callback`,
  `permission_callback`, and `meta` (`show_in_rest => true` — load-bearing, so the editor can
  reach the ability over REST — plus `annotations.{readonly:false,destructive:false,idempotent:false}`).
  Each `execute_callback` calls the WP 7.0 AI Client (`wp_ai_client_prompt()`), guarded by
  `Ai\Connector::is_text_generation_available()`, and returns a suggestion or a `WP_Error`
  (`openseo_no_connector` when Settings → Connectors has none — no silent fallback). `Prompts`
  builds the prompt strings (pure, unit-tested); `Connector` is the shared readiness check +
  `settings_url()`. The editor invokes the same abilities via `apiFetch` POST to
  `/wp-abilities/v1/abilities/<name>/run` (NOT `@wordpress/abilities`'s `executeAbility`, which
  runs client-side JS abilities, not PHP ones; and WP 7.0 ships no `wp-abilities` script handle).
- `Meta/` — the on-page core: `PostMeta` registers the per-entry `_openseo_*` meta
  (`show_in_rest` + `auth_callback`, with `custom-fields` support so the block editor can
  round-trip it); `Resolver` computes effective values via the cascade *per-entry override →
  content-type template → fallback* (returns `''` when it has no opinion); `Variables` expands
  title/description tokens (`%title%`, `%sep%`, `%sitename%`, …).
- `Frontend/Head/` — `wp_head` output. `HeadPrinter` orchestrates small presenters
  (`Description`, `Robots`, `Canonical`, `OpenGraph`, `Twitter`) and removes core's
  `rel_canonical` to avoid duplicates; `Title` filters `pre_get_document_title`.
- `Admin/Menu.php` — the single registrar of the **top-level OpenSEO menu** and all
  9 submenus (Dashboard · General · Titles & Meta · Social · Sitemaps · Schema ·
  Redirects · 404s · AI). All submenus are React — every screen renders
  `templates/admin/app-page.php` (a `#openseo-app[data-view]` mount + shared
  `templates/admin/header.php`); the React app lives in `assets/src/admin/` and
  reads/writes `openseo_settings` via the `Rest/SettingsController` route
  `openseo/v1/settings` (apiFetch, partial-merge through `Options::sanitize`).
  `Redirects/404` are now React views too: `Rest/RedirectsController`
  (`openseo/v1/redirects`, CRUD + bulk, validating through `Redirects/RuleValidator`
  over the `Redirects/RedirectLookup` interface) and `Rest/NotFoundController`
  (`openseo/v1/notfound`) back the `DataTable`-based `views/Redirects.js` /
  `views/NotFound.js`; the behavior toggles save via `openseo/v1/settings`.
  The tabbed Settings API surface is fully retired (no `BehaviorSettings`, no
  `WP_List_Table`). `Admin/Assets` enqueues the CSS + React bundle +
  `window.openseoAdmin` bootstrap on every OpenSEO screen.
- `Schema/` — JSON-LD output. `Graph` (Hookable, `wp_head`) assembles small
  `Piece` objects (`WebSite`, `Organization`/`Person`, `WebPage`, `Article`,
  `BreadcrumbList`) into one connected `@graph` printed as a single
  `application/ld+json` script (`JSON_HEX_TAG` for script safety). `Ids`
  centralizes every `@id`. Pieces reuse the Phase 1 `Resolver` so structured data
  matches the head tags. The per-entry `_openseo_schema_type` meta (whitelist) and
  the `openseo/suggest-schema-type` ability drive the editor's type selector.
- `Breadcrumbs/` — `Trail` (implements `TrailSource`) builds the hierarchy once;
  `Renderer` turns it into escaped `<nav><ol>`; consumed by the
  `openseo_breadcrumbs()` template function (`src/template-functions.php`, Composer
  `autoload.files`), the dynamic `openseo/breadcrumbs` block (`Breadcrumbs\Block`),
  and the `BreadcrumbList` schema piece. The block is registered from PHP
  (`register_block_type` with a `render_callback` + a compiled `editor_script`
  handle) rather than `block.json`/`register_block_type_from_metadata`, because the
  custom `webpack.config.js` overrides `entry`; its attributes are declared in both
  PHP and JS (a small, deliberate duplication kept in sync).
- `Redirects/` — redirect engine. `Dispatcher` (Hookable, `template_redirect`
  priority **5**, before core `redirect_canonical`@10; defers hit-count writes to
  `shutdown`). Pure, WP-free units: `Normalizer` (request path), `Regex`
  (plugin-controlled delimiter), `Ruleset` (exact O(1) map + ordered regex list),
  `Matcher` (exact-wins-then-regex, `$1` substitution, anti-loop). `Repository`
  (all `$wpdb` SQL for `{prefix}openseo_redirects`; implements `RedirectLookup`).
  `Cache` (ruleset in object cache → transient fallback; dual-store invalidation;
  cached active-count avoids per-request COUNT; degrades to indexed lookups above
  threshold). `SlugWatcher` (auto-301 on permalink change via
  `pre_post_update`+`post_updated`; on by default for all public CPTs).
  `RuleValidator` (normalisation/regex/target/whitelist/anti-loop validation over
  the `RedirectLookup` interface). Admin surface: React `views/Redirects.js` backed
  by `Rest/RedirectsController` (`openseo/v1/redirects`: GET/POST, PUT/DELETE
  `/<id>`, POST `/bulk`; always-on, registered outside `is_admin()`). `openseo_settings`
  keys: `redirects_auto_slug`, `redirects_default_status`, `redirects_track_hits`.
- `NotFound/` — 404 monitor (opt-in via `notfound_monitor_enabled`). `Monitor`
  (Hookable, `template_redirect` priority **99**; aggregated logging). `LogRepository`
  (aggregated `INSERT … ON DUPLICATE KEY UPDATE` upsert keyed by `url_hash`; UTC
  datetimes; no IP stored — the plugin's only raw SQL). `Pruner` (daily
  `openseo_404_prune` cron; retention via `notfound_retention_days`, default 30).
  Admin surface: React `views/NotFound.js` backed by `Rest/NotFoundController`
  (`openseo/v1/notfound`: GET list, DELETE one, DELETE all; always-on, registered
  outside `is_admin()`). "Create redirect from 404" links into the Redirects view.
  `openseo_settings` keys: `notfound_monitor_enabled`, `notfound_retention_days`.
- `Lifecycle/Schema.php` — creates the plugin's **first custom tables**
  (`openseo_redirects`, `openseo_404_logs`) via `dbDelta()` behind an
  `openseo_db_version` gate checked on `admin_init`; both tables are dropped on
  uninstall.

## Conventions & non-obvious gotchas

- **Security is non-negotiable:** sanitize on input, escape on output; pair a nonce **with**
  `current_user_can()` for any state-changing action; never process whole `$_POST`/`$_GET` — read
  explicit keys with `wp_unslash`.
- **Global prefixes** `openseo` / `OpenSEO` / `OPENSEO` and the `openseo` text domain are enforced
  by PHPCS (`phpcs.xml.dist`). PSR-4 file naming is used, so `WordPress.Files.FileName` is excluded.
- **PHPStan stubs:** `stubs/abilities-api.php` provides the WP 7.0 Abilities API signatures
  (`wordpress-stubs` may not ship them yet). The `OPENSEO_*` path/version constants are listed as
  `dynamicConstantNames` to avoid false `require.fileNotFound` errors. PHPStan needs the 1G memory
  limit because the WP stubs exhaust the default 128M.
- **`vendor/` and `assets/build/` are git-ignored but MUST ship in the release ZIP** — they are
  produced by the release flow, not committed to the repo.
- Two PHPUnit configs: `phpunit.xml.dist` (unit, Brain Monkey) and `phpunit-integration.xml.dist`
  (WordPress test suite, run via wp-env). Unit tests mock WP functions and never load WordPress.
- For more @NOTES.md
