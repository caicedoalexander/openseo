# OpenSEO

Open-source, AI-native SEO toolkit for WordPress, built on the **WordPress 7.0 Abilities API**
and **AI Client**. OpenSEO does not ship its own AI provider — it reuses the keys configured
once under **Settings → Connectors**.

- **Requires:** WordPress 7.0+, PHP 8.1+
- **License:** GPL-2.0-or-later

## Architecture

```
openseo/
├── openseo.php              # Bootstrap: header, constants, autoload, lifecycle hooks
├── uninstall.php            # Data cleanup (guards on WP_UNINSTALL_PLUGIN)
├── src/                     # PSR-4: OpenSEO\  ->  src/
│   ├── Plugin.php           # Composition root: builds + registers modules
│   ├── Contracts/
│   │   └── Hookable.php     # register(): void
│   ├── Settings/
│   │   ├── Options.php          # Typed read/write + sanitize of the single option
│   │   └── BehaviorSettings.php # Redirect/404 toggles (Settings API registration)
│   ├── Meta/
│   │   ├── PostMeta.php     # Registers per-entry _openseo_* meta (REST + auth)
│   │   ├── Resolver.php     # Effective SEO cascade: override -> template -> fallback
│   │   └── Variables.php    # Title/description tokens (%title%, %sep%, %sitename%, ...)
│   ├── Admin/
│   │   ├── Menu.php         # Top-level menu + submenus (Settings, Redirects, 404s)
│   │   ├── Assets.php       # Enqueues compiled admin bundle
│   │   └── Editor/
│   │       └── EditorPanel.php # Enqueues the Gutenberg SEO document panel
│   ├── Rest/
│   │   └── SettingsController.php # GET/POST openseo/v1/settings (manage_options)
│   ├── NotFound/
│   │   └── Admin/
│   │       └── NotFoundPage.php # 404 log list table page (Tools → OpenSEO 404s)
│   ├── Frontend/
│   │   └── Head/            # wp_head output, one presenter per concern:
│   │       ├── HeadPrinter.php  # Orchestrates presenters; drops core rel_canonical
│   │       ├── Presenter.php    # output(): void contract
│   │       ├── Title.php        # pre_get_document_title filter
│   │       ├── Description.php  # Robots.php, Canonical.php,
│   │       └── OpenGraph.php    # Twitter.php
│   ├── Ai/
│   │   └── Abilities.php    # wp_register_ability() on wp_abilities_api_init
│   └── Lifecycle/
│       ├── Activator.php
│       ├── Deactivator.php
│       └── Uninstaller.php
├── templates/admin/         # PHP view partials (escaped output)
├── assets/
│   ├── src/
│   │   ├── admin/           # React settings app (views/ per tab, components/, hooks/)
│   │   └── editor/          # Gutenberg panel source
│   └── build/               # Compiled output (git-ignored, shipped in release)
├── tests/
│   ├── Unit/                # Brain Monkey, no WordPress
│   └── Integration/         # WordPress test suite via wp-env
└── stubs/                   # Static-analysis stubs (constants, Abilities API)
```

**Principles:** single bootstrap, no heavy work at file load, each module registers its
own hooks, admin code stays behind `is_admin()`, sanitize on input + escape on output.

## Getting started

```bash
# PHP dependencies + tooling
composer install

# JS dependencies
npm install

# Build admin/front-end assets
npm run build           # one-off
npm run start           # watch mode

# Local WordPress 7.0 + PHP 8.1 environment (Docker required)
npm run env:start       # http://localhost:8888  (admin/password)
npm run env:stop
```

OpenSEO will not run until `composer install` has generated `vendor/autoload.php`.

## WP-CLI

WP-CLI ships inside `wp-env`, so no global install is needed:

```bash
npm run env:run -- cli wp plugin list
npm run env:run -- cli wp option get openseo_settings
npm run env:run -- cli wp eval 'var_dump( function_exists("wp_register_ability") );'
```

## Quality gates

```bash
composer lint           # PHPCS (WordPress Coding Standards)
composer lint:fix       # PHPCBF auto-fix
composer analyze        # PHPStan (level 6)
composer test:unit      # Unit tests (Brain Monkey)
npm run test:integration  # Integration tests inside wp-env
composer check          # lint + analyze + unit in one go

npm run lint:js
npm run lint:css
```

## Release

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
npm run plugin-zip      # produces openseo.zip honoring .distignore
```

The ZIP intentionally ships `vendor/` (production autoloader) and `assets/build/`,
while excluding sources, tests, and tooling.
