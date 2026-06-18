=== OpenSEO ===
Contributors: openseo
Tags: seo, ai, meta description, abilities api, open source
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open-source, AI-native SEO toolkit built on the WordPress 7.0 Abilities API and AI Client.

== Description ==

OpenSEO is an open-source SEO toolkit designed for the WordPress 7.0 era. Instead of
bundling its own AI provider, it integrates with the native AI Client and Connectors,
so the API keys you configure once under Settings &rarr; Connectors are reused securely.

Features in this initial release:

* Front-end meta description output with a sensible resolution order.
* A settings screen built on the Settings API (capability- and nonce-checked).
* An Abilities API integration that exposes OpenSEO actions to AI agents and the
  MCP adapter (e.g. "generate meta description").

== Installation ==

1. Upload the `openseo` folder to `/wp-content/plugins/`, or install the ZIP from the
   Plugins screen.
2. Activate OpenSEO through the **Plugins** menu in WordPress.
3. Visit **Settings &rarr; OpenSEO** to configure output.
4. (Optional) Configure an AI provider under **Settings &rarr; Connectors** to enable
   AI-assisted features.

== Frequently Asked Questions ==

= Does OpenSEO require an API key? =

No. Core SEO output works without AI. AI-assisted abilities use the provider you
configure in the native WordPress Connectors screen.

= Which WordPress version is required? =

WordPress 7.0 or newer, because OpenSEO builds on the Abilities API and AI Client.

== Changelog ==

= 0.1.0 =
* Initial scaffold: settings page, meta description output, and Abilities API integration.

== Upgrade Notice ==

= 0.1.0 =
First release.
