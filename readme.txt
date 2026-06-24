=== OpenSEO ===
Contributors: openseo
Tags: seo, redirects, schema, sitemap, ai
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

**On-page meta.** Titles and meta descriptions with per-content-type templates and
variables (`%title%`, `%sitename%`, `%sep%`, &hellip;), resolved through a clear cascade
(per-entry override &rarr; content-type template &rarr; fallback). Robots directives
(noindex/nofollow), canonical URLs, Open Graph, and Twitter Cards.

**AI assistance (optional).** Generate meta descriptions and titles, and get a suggested
schema.org type for a post, using the native WordPress AI Client &mdash; no bundled
provider and no separate API key. The same actions are exposed through the Abilities API
to AI agents and the MCP adapter.

**Structured data (JSON-LD).** A single connected `@graph` (WebSite, Organization or
Person, WebPage, Article, and BreadcrumbList) printed in the document head.

**Breadcrumbs.** A single source of truth feeds the `openseo_breadcrumbs()` theme
function, the dynamic `openseo/breadcrumbs` block, and the BreadcrumbList schema.

**XML sitemaps.** Enhances WordPress's native sitemap: excludes noindex entries, keeps
the author sitemap off by default (opt-in), and offers a master on/off switch.

**Redirects &amp; 404 monitor.** A redirect engine (301/302/307/410, exact and regex
rules) with an automatic 301 when a published entry's slug changes, plus an opt-in 404
monitor that lets you create a redirect straight from a logged 404.

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

= Unreleased =
* Titles &amp; Meta: per-content-type and taxonomy panels — Attachments (with redirect-to-parent, on by default), default schema type per content type, and default social image per content type.
* Attachment pages now redirect to their parent post by default; disable in OpenSEO &rarr; Titles &amp; Meta &rarr; Attachments.

= 0.1.0 =
* On-page meta: titles, meta descriptions, robots, canonical, Open Graph, and Twitter Cards with a per-entry/template/fallback cascade and title-template variables.
* AI assistance via the WordPress AI Client: generate meta descriptions and titles, and suggest a schema.org type, exposed through the Abilities API.
* Structured data: a single JSON-LD `@graph` (WebSite, Organization/Person, WebPage, Article, BreadcrumbList).
* Breadcrumbs: `openseo_breadcrumbs()` theme function, the `openseo/breadcrumbs` block, and BreadcrumbList schema from one source.
* XML sitemaps: noindex exclusion, opt-in author sitemap, and a master on/off switch over the native WordPress sitemap.
* Redirects and a 404 monitor: 301/302/307/410 exact and regex rules, automatic 301 on slug change, and create-redirect-from-404.

== Upgrade Notice ==

= 0.1.0 =
First release.
