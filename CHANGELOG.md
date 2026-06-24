# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Titles & Meta: per-content-type and taxonomy panels — Attachments (with redirect-to-parent, on by default), default schema type per content type, and default social image per content type.

### Changed
- Attachment pages now redirect to their parent post by default; disable in OpenSEO → Titles & Meta → Attachments.

## [0.1.0] - 2026-06-17

### Added
- Project scaffold: Composer (PSR-4 `OpenSEO\`), `@wordpress/scripts` build, and `wp-env`.
- Settings page built on the Settings API with capability + nonce enforcement.
- Front-end meta description output via `wp_head`.
- WordPress 7.0 Abilities API integration (`openseo/generate-meta-description`).
- Quality tooling: PHPCS (WPCS), PHPStan (level 6), PHPUnit (unit + integration), CI.

[Unreleased]: https://github.com/openseo/openseo/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/openseo/openseo/releases/tag/v0.1.0
