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
  of modules for the request; admin-only modules (`SettingsPage`, `Admin\Assets`) are gated
  behind `is_admin()`. `boot()` calls `register()` on each, exactly once.
- Every feature is a class implementing `Contracts\Hookable` (`register(): void`) that wires its
  own WordPress hooks. **To add a feature: create the `Hookable` class under `src/` and add it to
  `Plugin::modules()`** — nothing else discovers modules.

**Key modules:**
- `Settings/Options.php` — all settings live under a **single option key** `openseo_settings`
  (`Options::OPTION_KEY`). Typed read (`all`/`get` merge over `defaults()`) and `sanitize()` on
  write. Single key keeps activation seeding and uninstall cleanup trivial.
- `Ai/Abilities.php` — registers the ability category + abilities on `wp_abilities_api_init`,
  guarded by `function_exists('wp_register_ability')` so it degrades gracefully pre-7.0. Each
  ability declares `input_schema`, `output_schema`, `execute_callback`, and `permission_callback`.
- `Meta/` — the on-page core: `PostMeta` registers the per-entry `_openseo_*` meta
  (`show_in_rest` + `auth_callback`, with `custom-fields` support so the block editor can
  round-trip it); `Resolver` computes effective values via the cascade *per-entry override →
  content-type template → fallback* (returns `''` when it has no opinion); `Variables` expands
  title/description tokens (`%title%`, `%sep%`, `%sitename%`, …).
- `Frontend/Head/` — `wp_head` output. `HeadPrinter` orchestrates small presenters
  (`Description`, `Robots`, `Canonical`, `OpenGraph`, `Twitter`) and removes core's
  `rel_canonical` to avoid duplicates; `Title` filters `pre_get_document_title`.
- `Admin/SettingsPage.php` — tabbed Settings API page (General · Titles & Meta · Social).
  `Admin/Editor/EditorPanel.php` enqueues the Gutenberg SEO document panel (React, reads/writes
  the meta via `useEntityProp`). `templates/admin/` holds escaped PHP view partials.

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
