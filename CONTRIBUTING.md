# Contributing to OpenSEO

Thanks for your interest in improving OpenSEO!

## Development setup

```bash
composer install
npm install
npm run env:start
```

## Before opening a pull request

Run the full local gate and make sure it passes:

```bash
composer check        # PHPCS + PHPStan + unit tests
npm run lint:js
npm run lint:css
npm run build
```

## Standards

- **PHP:** WordPress Coding Standards (`composer lint:fix` to auto-fix), PHP 8.1+ syntax.
- **Architecture:** small, single-responsibility classes under `src/` (PSR-4 `OpenSEO\`).
  New behavior is a `Hookable` module registered from `Plugin::modules()`.
- **Security:** sanitize on input, escape on output; pair nonces with capability checks.
- **Tests:** add unit tests for logic (Brain Monkey) and integration tests for WordPress
  interactions. Target 80%+ coverage on new code.

## Commit messages

Use Conventional Commits, e.g. `feat: add sitemap module`, `fix: escape title output`.

## Reporting issues

Open a GitHub issue with steps to reproduce, expected vs. actual behavior, and your
WordPress/PHP versions.
