# Phase 4 — Schema (JSON-LD) + Breadcrumbs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Emit a single connected JSON-LD `@graph` (WebSite, Organization/Person, WebPage, Article, BreadcrumbList) in the document head, expose breadcrumbs as a theme function and a Gutenberg block, and add an AI ability that recommends a richer schema type per entry.

**Architecture:** Two new namespaces under the existing `Hookable` composition root. `OpenSEO\Schema\` builds the graph from small `Piece` classes (one per node) assembled by `Schema\Graph`, reusing the Phase 1 `Resolver` so structured data matches the existing meta/Open Graph output. `OpenSEO\Breadcrumbs\` has one `Trail` builder shared by the theme function, the block (dynamic render), and the `BreadcrumbList` piece.

**Tech Stack:** PHP 8.1, WordPress 7.0 (`wp_head`, `register_block_type`, AI Client), Composer PSR-4 (`OpenSEO\` → `src/`) + `autoload.files` for the template function, PHPUnit 9.6 + Brain Monkey (unit) / WP test suite via wp-env (integration), `@wordpress/scripts` (webpack, Jest) for the block + editor JS.

## Global Constraints

- **Platform floors:** WordPress 7.0+, PHP 8.1+. Every PHP file starts with `declare( strict_types=1 );`.
- **Prefixes:** namespace `OpenSEO\`, constants `OPENSEO_*`, text domain `openseo`. Postmeta keys prefixed `_openseo_`. Ability names `openseo/<verb-noun>`; error codes `openseo_*`.
- **Single option key:** all settings live under `Options::OPTION_KEY` (`openseo_settings`); typed reads merge over `defaults()`, writes go through `sanitize()`.
- **Security:** sanitize on input, escape on output. JSON-LD printed with `wp_json_encode( …, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )`. Breadcrumb HTML uses `esc_html`/`esc_url`/`esc_attr`. Per-entry schema type and the ability's `type` use a strict whitelist. Ability `permission_callback` = `edit_post`; no silent fallback (returns `WP_Error`).
- **Module discovery:** a feature exists only once added to `Plugin::modules()`. Front-end modules are built before the `is_admin()` guard.
- **Style classes are `final`**, methods small, files focused. PHPCS = WordPress Coding Standards (`composer lint`); PHPStan level 6 (`composer analyze`, runs `--memory-limit=1G`).
- **Keep all gates green before each commit:** `composer lint && composer analyze && composer test:unit`.
- **TDD:** write the failing test first, watch it fail, implement the minimum, watch it pass, commit.
- **Non-goals (do not implement):** full JSON-LD for rich types (FAQPage/HowTo/Recipe/Product) — the ability only *recommends* them; media-library picker for the logo (text URL only); per-post-type/taxonomy schema; breadcrumb config beyond a global separator + block attributes; any external HTTP / schema validation round-trip.

---

## File Structure

**Created (PHP):**
- `src/Schema/Piece.php` — interface (`is_needed`/`id`/`data`).
- `src/Schema/Ids.php` — centralizes every `@id` + `current_url()`.
- `src/Schema/Graph.php` — `Hookable`; assembles applicable pieces, prints one `<script>`.
- `src/Schema/Pieces/WebSite.php`, `Organization.php`, `Person.php`, `WebPage.php`, `Article.php`, `BreadcrumbList.php` — the nodes.
- `src/Breadcrumbs/Trail.php` — `items(): array<{name,url}>`.
- `src/Breadcrumbs/Renderer.php` — `render( items, args ): string`.
- `src/Breadcrumbs/Block.php` — `Hookable`; registers `openseo/breadcrumbs`.
- `src/template-functions.php` — `openseo_breadcrumbs()`.

**Created (JS):**
- `assets/src/blocks/breadcrumbs/index.js`, `edit.js`.

**Modified:**
- `src/Settings/Options.php` — schema/breadcrumb defaults + sanitize.
- `src/Meta/PostMeta.php` — `_openseo_schema_type` + whitelist sanitize.
- `src/Admin/SettingsPage.php` — Schema section + `add_select_field`.
- `src/Ai/Prompts.php` — `system_schema_type()`.
- `src/Ai/Abilities.php` — `openseo/suggest-schema-type` ability.
- `src/Plugin.php` — register `Schema\Graph` + `Breadcrumbs\Block`.
- `assets/src/editor/index.js` — schema type select + AI recommend.
- `templates/admin/settings-page.php` — `schema` tab.
- `webpack.config.js` — `breadcrumbs` entry.
- `composer.json` — `autoload.files`.
- `CLAUDE.md`, `NOTES.md` — Phase 4 docs.

---

## Task 1: `Options` — schema + breadcrumb defaults & sanitize

**Files:**
- Modify: `src/Settings/Options.php`
- Test: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: four option keys readable via `Options::get()` — `schema_site_type` (default `'Organization'`, whitelist `Organization`/`Person`), `schema_site_name` (default `''`), `schema_logo` (default `''`, `esc_url_raw`), `breadcrumb_separator` (default `'›'`).

- [ ] **Step 1: Write the failing unit tests**

Add these methods to `tests/Unit/OptionsTest.php` (inside the class, after the sitemap tests):

```php
	public function test_schema_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( 'Organization', $options->get( 'schema_site_type' ) );
		$this->assertSame( '', $options->get( 'schema_site_name' ) );
		$this->assertSame( '', $options->get( 'schema_logo' ) );
		$this->assertSame( '›', $options->get( 'breadcrumb_separator' ) );
	}

	public function test_sanitize_normalizes_schema_fields(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize(
			array(
				'schema_site_type'     => 'Person',
				'schema_site_name'     => 'Jane Doe',
				'schema_logo'          => 'https://example.com/logo.png',
				'breadcrumb_separator' => '/',
			)
		);

		$this->assertSame( 'Person', $clean['schema_site_type'] );
		$this->assertSame( 'Jane Doe', $clean['schema_site_name'] );
		$this->assertSame( 'https://example.com/logo.png', $clean['schema_logo'] );
		$this->assertSame( '/', $clean['breadcrumb_separator'] );
	}

	public function test_sanitize_rejects_unknown_schema_site_type(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize( array( 'schema_site_type' => 'Robot' ) );

		// Unknown value falls back to the default, never stored verbatim.
		$this->assertSame( 'Organization', $clean['schema_site_type'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL — the four keys are missing / unset.

- [ ] **Step 3: Add the defaults**

In `src/Settings/Options.php`, replace the whole `defaults()` return array with this (adds the four keys, keeps `ai_model` last and re-aligns arrows):

```php
		return array(
			'title_separator'         => '-',
			'title_template'          => '%title% %sep% %sitename%',
			'description_template'    => '%excerpt%',
			'home_title'              => '%sitename% %sep% %tagline%',
			'home_description'        => '',
			'og_default_image'        => '',
			'sitemap_enabled'         => '1',
			'sitemap_include_authors' => '',
			'schema_site_type'        => 'Organization',
			'schema_site_name'        => '',
			'schema_logo'             => '',
			'breadcrumb_separator'    => '›',
			'ai_model'                => '',
		);
```

- [ ] **Step 4: Add the sanitization**

In `src/Settings/Options.php`, inside `sanitize()`, add `schema_site_name` and `breadcrumb_separator` to the existing text-field loop, then add the whitelist + URL handling. Replace the text loop line and add the new blocks so the method body reads:

```php
		foreach ( array( 'title_separator', 'title_template', 'description_template', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		// Checkboxes: a hidden companion field guarantees the key is present (0 or
		// 1) when its tab is submitted, so an explicit '1' check turns it on/off.
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = '1' === $input[ $key ] ? '1' : '';
			}
		}

		// Whitelisted single-choice value: anything off-list resets to the default.
		if ( isset( $input['schema_site_type'] ) ) {
			$type                     = sanitize_text_field( wp_unslash( $input['schema_site_type'] ) );
			$clean['schema_site_type'] = in_array( $type, array( 'Organization', 'Person' ), true )
				? $type
				: 'Organization';
		}

		foreach ( array( 'og_default_image', 'schema_logo' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = esc_url_raw( wp_unslash( $input[ $key ] ) );
			}
		}

		return $clean;
```

Delete the now-superseded standalone `og_default_image` block (it is folded into the loop above).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: PASS (all OptionsTest methods).

- [ ] **Step 6: Lint, analyze, commit**

```bash
composer lint:fix && composer lint && composer analyze
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(schema): add schema and breadcrumb options"
```

---

## Task 2: `PostMeta` — per-entry `_openseo_schema_type`

**Files:**
- Modify: `src/Meta/PostMeta.php`
- Test: `tests/Unit/Meta/PostMetaTest.php` (new), `tests/Integration/MetaRegistrationTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `PostMeta::SCHEMA_TYPES` (`['', 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'none']`) and the registered meta key `_openseo_schema_type` (string, `show_in_rest`, whitelist sanitize). Read by the `WebPage`/`Article` pieces (Task 5) and the editor select (Task 12).

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Meta/PostMetaTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\PostMeta;
use PHPUnit\Framework\TestCase;

final class PostMetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schema_type_accepts_whitelisted_value(): void {
		$meta = new PostMeta();

		$this->assertSame( 'BlogPosting', $meta->sanitize_value( 'BlogPosting', '_openseo_schema_type' ) );
		$this->assertSame( 'none', $meta->sanitize_value( 'none', '_openseo_schema_type' ) );
	}

	public function test_schema_type_rejects_unknown_value(): void {
		$meta = new PostMeta();

		// Off-list values collapse to '' (Default), never stored verbatim.
		$this->assertSame( '', $meta->sanitize_value( 'FAQPage', '_openseo_schema_type' ) );
		$this->assertSame( '', $meta->sanitize_value( '<script>', '_openseo_schema_type' ) );
	}

	public function test_other_keys_still_sanitize_as_before(): void {
		$meta = new PostMeta();

		$this->assertSame( 'https://e.com/i.png', $meta->sanitize_value( 'https://e.com/i.png', '_openseo_og_image' ) );
		$this->assertSame( 'Plain title', $meta->sanitize_value( 'Plain title', '_openseo_title' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter PostMetaTest`
Expected: FAIL — `_openseo_schema_type` is sanitized with `sanitize_text_field` (returns the arg), so `'FAQPage'` is not collapsed to `''`.

- [ ] **Step 3: Add the constant and the meta key**

In `src/Meta/PostMeta.php`, add the constant above `KEYS`:

```php
	/**
	 * Allowed per-entry schema types ('' = automatic, 'none' = suppress).
	 *
	 * @var string[]
	 */
	public const SCHEMA_TYPES = array( '', 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'none' );
```

Add `'_openseo_schema_type',` to the `KEYS` array (after `_openseo_twitter_image`).

- [ ] **Step 4: Branch the sanitizer**

In `src/Meta/PostMeta.php`, replace `sanitize_value()` with:

```php
	public function sanitize_value( mixed $value, string $meta_key ): string {
		if ( '_openseo_schema_type' === $meta_key ) {
			$value = (string) $value;

			return in_array( $value, self::SCHEMA_TYPES, true ) ? $value : '';
		}

		if ( '_openseo_canonical' === $meta_key || str_ends_with( $meta_key, '_image' ) ) {
			return esc_url_raw( (string) $value );
		}

		return sanitize_text_field( (string) $value );
	}
```

- [ ] **Step 5: Add the integration assertion**

Add to `tests/Integration/MetaRegistrationTest.php` (inside the class):

```php
	public function test_schema_type_meta_is_registered(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayHasKey( '_openseo_schema_type', $registered );
		$this->assertTrue( $registered['_openseo_schema_type']['show_in_rest'] );
	}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter PostMetaTest`
Expected: PASS.
Then (wp-env up): `npm run test:integration -- --filter MetaRegistrationTest`
Expected: PASS.

- [ ] **Step 7: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Meta/PostMeta.php tests/Unit/Meta/PostMetaTest.php tests/Integration/MetaRegistrationTest.php
git commit -m "feat(schema): register per-entry schema type meta"
```

---

## Task 3: `Schema\Ids` — centralized @ids

**Files:**
- Create: `src/Schema/Ids.php`
- Test: `tests/Unit/Schema/IdsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `final class Ids` with static methods `website(): string`, `organization(): string`, `person(): string`, `webpage( string $url ): string`, `article( string $url ): string`, `breadcrumb( string $url ): string`, and `current_url(): string` (home url on the front page, else the queried permalink). Used by every piece (Tasks 4, 5, 7).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Schema/IdsTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Schema\Ids;
use PHPUnit\Framework\TestCase;

final class IdsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_site_level_ids_anchor_to_home(): void {
		$this->assertSame( 'https://example.com/#website', Ids::website() );
		$this->assertSame( 'https://example.com/#organization', Ids::organization() );
		$this->assertSame( 'https://example.com/#person', Ids::person() );
	}

	public function test_url_level_ids_append_fragments(): void {
		$url = 'https://example.com/post/';

		$this->assertSame( 'https://example.com/post/#webpage', Ids::webpage( $url ) );
		$this->assertSame( 'https://example.com/post/#article', Ids::article( $url ) );
		$this->assertSame( 'https://example.com/post/#breadcrumb', Ids::breadcrumb( $url ) );
	}

	public function test_current_url_is_home_on_front_page(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$this->assertSame( 'https://example.com/', Ids::current_url() );
	}

	public function test_current_url_is_permalink_on_singular(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );

		$this->assertSame( 'https://example.com/post/', Ids::current_url() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter IdsTest`
Expected: FAIL — `Class "OpenSEO\Schema\Ids" not found`.

- [ ] **Step 3: Implement `Ids`**

Create `src/Schema/Ids.php`:

```php
<?php
/**
 * Stable @id values for the JSON-LD graph.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

/**
 * Single source of truth for every node @id, so pieces can reference each other
 * without coupling to sibling classes.
 */
final class Ids {

	/**
	 * @id of the site-wide WebSite node.
	 */
	public static function website(): string {
		return home_url( '/#website' );
	}

	/**
	 * @id of the Organization identity node.
	 */
	public static function organization(): string {
		return home_url( '/#organization' );
	}

	/**
	 * @id of the Person identity node.
	 */
	public static function person(): string {
		return home_url( '/#person' );
	}

	/**
	 * @id of the WebPage node for a given URL.
	 *
	 * @param string $url Canonical URL of the page.
	 */
	public static function webpage( string $url ): string {
		return $url . '#webpage';
	}

	/**
	 * @id of the Article node for a given URL.
	 *
	 * @param string $url Canonical URL of the page.
	 */
	public static function article( string $url ): string {
		return $url . '#article';
	}

	/**
	 * @id of the BreadcrumbList node for a given URL.
	 *
	 * @param string $url Canonical URL of the page.
	 */
	public static function breadcrumb( string $url ): string {
		return $url . '#breadcrumb';
	}

	/**
	 * Canonical URL of the current request (home on the front page).
	 */
	public static function current_url(): string {
		if ( is_front_page() ) {
			return home_url( '/' );
		}

		return (string) get_permalink( get_queried_object_id() );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter IdsTest`
Expected: PASS.

- [ ] **Step 5: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Schema/Ids.php tests/Unit/Schema/IdsTest.php
git commit -m "feat(schema): add @id helper for the JSON-LD graph"
```

---

## Task 4: `Piece` interface + WebSite / Organization / Person pieces

**Files:**
- Create: `src/Schema/Piece.php`, `src/Schema/Pieces/WebSite.php`, `src/Schema/Pieces/Organization.php`, `src/Schema/Pieces/Person.php`
- Test: `tests/Unit/Schema/Pieces/SitePiecesTest.php`

**Interfaces:**
- Consumes: `Schema\Ids` (Task 3), `Settings\Options` (Task 1).
- Produces:
  - `interface Piece { public function is_needed(): bool; public function id(): string; public function data(): array; }`
  - `final class WebSite implements Piece` (ctor `Options`) — always needed; `data()` includes `@type` `WebSite`, `@id`, `url`, `name`, `publisher` ref to the identity (`Ids::organization()`/`Ids::person()` per `schema_site_type`), and a `SearchAction`.
  - `final class Organization implements Piece` (ctor `Options`) — needed when `schema_site_type === 'Organization'`; `id()` = `Ids::organization()`.
  - `final class Person implements Piece` (ctor `Options`) — needed when `schema_site_type === 'Person'`; `id()` = `Ids::person()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Schema/Pieces/SitePiecesTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema\Pieces;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Schema\Pieces\Organization;
use OpenSEO\Schema\Pieces\Person;
use OpenSEO\Schema\Pieces\WebSite;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class SitePiecesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'My Site' : 'A tagline'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function options( array $stored ): Options {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new Options();
	}

	public function test_website_always_needed_and_links_to_identity(): void {
		$piece = new WebSite( $this->options( array( 'schema_site_type' => 'Organization' ) ) );

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'WebSite', $data['@type'] );
		$this->assertSame( 'https://example.com/#website', $data['@id'] );
		$this->assertSame( 'https://example.com/#organization', $data['publisher']['@id'] );
		$this->assertSame( 'SearchAction', $data['potentialAction']['@type'] );
	}

	public function test_website_publisher_points_to_person_when_chosen(): void {
		$piece = new WebSite( $this->options( array( 'schema_site_type' => 'Person' ) ) );

		$this->assertSame( 'https://example.com/#person', $piece->data()['publisher']['@id'] );
	}

	public function test_organization_needed_only_for_organization_type(): void {
		$org = new Organization( $this->options( array( 'schema_site_type' => 'Organization' ) ) );
		$this->assertTrue( $org->is_needed() );

		$off = new Organization( $this->options( array( 'schema_site_type' => 'Person' ) ) );
		$this->assertFalse( $off->is_needed() );

		$data = $org->data();
		$this->assertSame( 'Organization', $data['@type'] );
		$this->assertSame( 'https://example.com/#organization', $data['@id'] );
		$this->assertSame( 'My Site', $data['name'] );
	}

	public function test_organization_uses_custom_name_and_logo(): void {
		$org = new Organization(
			$this->options(
				array(
					'schema_site_type' => 'Organization',
					'schema_site_name' => 'Acme Inc',
					'schema_logo'      => 'https://example.com/logo.png',
				)
			)
		);

		$data = $org->data();
		$this->assertSame( 'Acme Inc', $data['name'] );
		$this->assertSame( 'ImageObject', $data['logo']['@type'] );
		$this->assertSame( 'https://example.com/logo.png', $data['logo']['url'] );
	}

	public function test_person_needed_only_for_person_type(): void {
		$person = new Person( $this->options( array( 'schema_site_type' => 'Person' ) ) );
		$this->assertTrue( $person->is_needed() );
		$this->assertFalse(
			( new Person( $this->options( array( 'schema_site_type' => 'Organization' ) ) ) )->is_needed()
		);

		$data = $person->data();
		$this->assertSame( 'Person', $data['@type'] );
		$this->assertSame( 'https://example.com/#person', $data['@id'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SitePiecesTest`
Expected: FAIL — the classes do not exist yet.

- [ ] **Step 3: Create the `Piece` interface**

Create `src/Schema/Piece.php`:

```php
<?php
/**
 * One node of the JSON-LD @graph.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

/**
 * A self-contained schema.org node. Pieces are independent and composable: the
 * Graph asks each one whether it applies, then collects its data.
 */
interface Piece {

	/**
	 * Whether this node applies to the current request.
	 */
	public function is_needed(): bool;

	/**
	 * This node's @id, so other pieces can reference it.
	 */
	public function id(): string;

	/**
	 * The node as an associative array (with @type, @id, and refs by @id).
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array;
}
```

- [ ] **Step 4: Implement `WebSite`**

Create `src/Schema/Pieces/WebSite.php`:

```php
<?php
/**
 * WebSite schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The site-wide WebSite node: name, url, publisher, and a site SearchAction.
 */
final class WebSite implements Piece {

	/**
	 * @param Options $options Settings accessor (provides the identity type).
	 */
	public function __construct( private readonly Options $options ) {}

	public function is_needed(): bool {
		return true;
	}

	public function id(): string {
		return Ids::website();
	}

	public function data(): array {
		$identity = 'Person' === (string) $this->options->get( 'schema_site_type' )
			? Ids::person()
			: Ids::organization();

		return array(
			'@type'           => 'WebSite',
			'@id'             => $this->id(),
			'url'             => home_url( '/' ),
			'name'            => (string) get_bloginfo( 'name' ),
			'description'     => (string) get_bloginfo( 'description' ),
			'publisher'       => array( '@id' => $identity ),
			'inLanguage'      => (string) get_bloginfo( 'language' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}
}
```

- [ ] **Step 5: Implement `Organization`**

Create `src/Schema/Pieces/Organization.php`:

```php
<?php
/**
 * Organization identity node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The site's Organization identity (publisher/author root).
 */
final class Organization implements Piece {

	/**
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	public function is_needed(): bool {
		return 'Person' !== (string) $this->options->get( 'schema_site_type' );
	}

	public function id(): string {
		return Ids::organization();
	}

	public function data(): array {
		$name = (string) $this->options->get( 'schema_site_name' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$data = array(
			'@type' => 'Organization',
			'@id'   => $this->id(),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);

		$logo = (string) $this->options->get( 'schema_logo' );
		if ( '' !== $logo ) {
			$data['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
			// image mirrors logo so referencing nodes can use either.
			$data['image'] = array( '@id' => $this->id() . 'Logo' );
			$data['logo']['@id'] = $this->id() . 'Logo';
		}

		return $data;
	}
}
```

- [ ] **Step 6: Implement `Person`**

Create `src/Schema/Pieces/Person.php`:

```php
<?php
/**
 * Person identity node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The site's Person identity (for single-author / personal sites).
 */
final class Person implements Piece {

	/**
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	public function is_needed(): bool {
		return 'Person' === (string) $this->options->get( 'schema_site_type' );
	}

	public function id(): string {
		return Ids::person();
	}

	public function data(): array {
		$name = (string) $this->options->get( 'schema_site_name' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$data = array(
			'@type' => 'Person',
			'@id'   => $this->id(),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);

		$logo = (string) $this->options->get( 'schema_logo' );
		if ( '' !== $logo ) {
			$data['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		return $data;
	}
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter SitePiecesTest`
Expected: PASS.

- [ ] **Step 8: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Schema/Piece.php src/Schema/Pieces/WebSite.php src/Schema/Pieces/Organization.php src/Schema/Pieces/Person.php tests/Unit/Schema/Pieces/SitePiecesTest.php
git commit -m "feat(schema): add WebSite and identity pieces"
```

---

## Task 5: `WebPage` + `Article` pieces

**Files:**
- Create: `src/Schema/Pieces/WebPage.php`, `src/Schema/Pieces/Article.php`
- Test: `tests/Unit/Schema/Pieces/ContentPiecesTest.php`

**Interfaces:**
- Consumes: `Schema\Ids` (Task 3), `Meta\Resolver` (existing), `Settings\Options` (Task 1), `Meta\PostMeta::SCHEMA_TYPES` (Task 2).
- Produces:
  - `final class WebPage implements Piece` (ctor `Resolver`) — needed on `is_singular()` or `is_front_page()`; `data()` has `@type` `WebPage`, `@id` `Ids::webpage(current_url)`, `url`, `name` (resolver title), `isPartOf` → website, `breadcrumb` ref (when not front page), dates, optional `primaryImageOfPage`.
  - `final class Article implements Piece` (ctor `Resolver`, `Options`) — needed on singular posts whose effective type is article-ish; `@type` from the per-entry selector; refs `isPartOf`/`mainEntityOfPage` → webpage, inline `author` Person, `publisher` → identity.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Schema/Pieces/ContentPiecesTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema\Pieces;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\Variables;
use OpenSEO\Schema\Pieces\Article;
use OpenSEO\Schema\Pieces\WebPage;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class ContentPiecesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'en-US' );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );
		Functions\when( 'get_the_date' )->justReturn( '2026-06-01T10:00:00+00:00' );
		Functions\when( 'get_the_modified_date' )->justReturn( '2026-06-02T10:00:00+00:00' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane' );
		Functions\when( 'get_the_author' )->justReturn( 'Jane' );
		Functions\when( 'get_author_posts_url' )->justReturn( 'https://example.com/author/jane/' );
		Functions\when( 'get_post_field' )->justReturn( 7 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolver(): Resolver {
		$options = new Options();

		return new Resolver( $options, new Variables( $options ) );
	}

	public function test_webpage_needed_on_singular_and_references_website(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );

		$piece = new WebPage( $this->resolver() );

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'WebPage', $data['@type'] );
		$this->assertSame( 'https://example.com/post/#webpage', $data['@id'] );
		$this->assertSame( 'https://example.com/#website', $data['isPartOf']['@id'] );
		$this->assertSame( 'https://example.com/post/#breadcrumb', $data['breadcrumb']['@id'] );
	}

	public function test_webpage_not_needed_off_singular_and_front(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertFalse( ( new WebPage( $this->resolver() ) )->is_needed() );
	}

	public function test_article_needed_for_default_post(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );

		$piece = new Article( $this->resolver(), new Options() );

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'Article', $data['@type'] );
		$this->assertSame( 'https://example.com/post/#article', $data['@id'] );
		$this->assertSame( 'https://example.com/post/#webpage', $data['isPartOf']['@id'] );
		$this->assertSame( 'Person', $data['author']['@type'] );
		$this->assertSame( 'https://example.com/#organization', $data['publisher']['@id'] );
	}

	public function test_article_honors_explicit_type_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_schema_type' === $key ? 'NewsArticle' : ''
		);

		$piece = new Article( $this->resolver(), new Options() );

		$this->assertSame( 'NewsArticle', $piece->data()['@type'] );
	}

	public function test_article_suppressed_for_none_and_webpage_types(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );

		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_schema_type' === $key ? 'none' : ''
		);
		$this->assertFalse( ( new Article( $this->resolver(), new Options() ) )->is_needed() );

		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_schema_type' === $key ? 'WebPage' : ''
		);
		$this->assertFalse( ( new Article( $this->resolver(), new Options() ) )->is_needed() );
	}

	public function test_article_suppressed_for_pages_by_default(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'page' );

		$this->assertFalse( ( new Article( $this->resolver(), new Options() ) )->is_needed() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ContentPiecesTest`
Expected: FAIL — the classes do not exist.

- [ ] **Step 3: Implement `WebPage`**

Create `src/Schema/Pieces/WebPage.php`:

```php
<?php
/**
 * WebPage schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Meta\Resolver;
use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;

/**
 * The WebPage node for a singular entry or the front page.
 */
final class WebPage implements Piece {

	/**
	 * @param Resolver $resolver Shared SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	public function is_needed(): bool {
		return is_singular() || is_front_page();
	}

	public function id(): string {
		return Ids::webpage( Ids::current_url() );
	}

	public function data(): array {
		$url = Ids::current_url();

		$data = array(
			'@type'      => 'WebPage',
			'@id'        => Ids::webpage( $url ),
			'url'        => $url,
			'name'       => $this->resolver->title(),
			'isPartOf'   => array( '@id' => Ids::website() ),
			'inLanguage' => (string) get_bloginfo( 'language' ),
		);

		// A breadcrumb trail only exists away from the home root.
		if ( ! is_front_page() ) {
			$data['breadcrumb'] = array( '@id' => Ids::breadcrumb( $url ) );
		}

		if ( is_singular() ) {
			$id                    = get_queried_object_id();
			$data['datePublished'] = (string) get_the_date( 'c', $id );
			$data['dateModified']  = (string) get_the_modified_date( 'c', $id );

			$image = $this->resolver->social_image();
			if ( '' !== $image ) {
				$data['primaryImageOfPage'] = array(
					'@type' => 'ImageObject',
					'url'   => $image,
				);
			}
		}

		return $data;
	}
}
```

- [ ] **Step 4: Implement `Article`**

Create `src/Schema/Pieces/Article.php`:

```php
<?php
/**
 * Article schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Meta\Resolver;
use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The Article node for a singular post, with the @type chosen per entry.
 */
final class Article implements Piece {

	private const ARTICLE_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle' );

	/**
	 * @param Resolver $resolver Shared SEO value resolver.
	 * @param Options  $options  Settings accessor (identity type for publisher).
	 */
	public function __construct(
		private readonly Resolver $resolver,
		private readonly Options $options
	) {}

	public function is_needed(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$override = (string) get_post_meta( get_queried_object_id(), '_openseo_schema_type', true );

		if ( in_array( $override, self::ARTICLE_TYPES, true ) ) {
			return true;
		}

		if ( 'none' === $override || 'WebPage' === $override ) {
			return false;
		}

		// Default ('' override): emit an Article only for the 'post' post type.
		return 'post' === get_post_type( get_queried_object_id() );
	}

	public function id(): string {
		return Ids::article( Ids::current_url() );
	}

	public function data(): array {
		$id  = get_queried_object_id();
		$url = Ids::current_url();

		$identity = 'Person' === (string) $this->options->get( 'schema_site_type' )
			? Ids::person()
			: Ids::organization();

		$author_id = (int) get_post_field( 'post_author', $id );

		$data = array(
			'@type'            => $this->type( (string) get_post_meta( $id, '_openseo_schema_type', true ) ),
			'@id'              => Ids::article( $url ),
			'headline'         => $this->resolver->title(),
			'isPartOf'         => array( '@id' => Ids::webpage( $url ) ),
			'mainEntityOfPage' => array( '@id' => Ids::webpage( $url ) ),
			'datePublished'    => (string) get_the_date( 'c', $id ),
			'dateModified'     => (string) get_the_modified_date( 'c', $id ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => (string) get_the_author_meta( 'display_name', $author_id ),
				'url'   => (string) get_author_posts_url( $author_id ),
			),
			'publisher'        => array( '@id' => $identity ),
		);

		$image = $this->resolver->social_image();
		if ( '' !== $image ) {
			$data['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image,
			);
		}

		return $data;
	}

	/**
	 * Resolve the effective @type from the per-entry override.
	 *
	 * @param string $override Stored per-entry schema type.
	 */
	private function type( string $override ): string {
		return in_array( $override, self::ARTICLE_TYPES, true ) ? $override : 'Article';
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ContentPiecesTest`
Expected: PASS.

- [ ] **Step 6: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Schema/Pieces/WebPage.php src/Schema/Pieces/Article.php tests/Unit/Schema/Pieces/ContentPiecesTest.php
git commit -m "feat(schema): add WebPage and Article pieces"
```

---

## Task 6: `Breadcrumbs\Trail`

**Files:**
- Create: `src/Breadcrumbs/Trail.php`
- Test: `tests/Unit/Breadcrumbs/TrailTest.php`

**Interfaces:**
- Consumes: WordPress conditionals + getters.
- Produces: `final class Trail { public function items(): array; }` returning `array<int, array{name: string, url: string}>` — empty on the front-page root; otherwise Home → (page ancestors | post primary category | archive title) → current. Used by the function (Task 9), the block (Task 10), and `BreadcrumbList` (Task 7).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Breadcrumbs/TrailTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Breadcrumbs\Trail;
use PHPUnit\Framework\TestCase;

final class TrailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		// Default every context to false; each test flips the ones it needs.
		foreach ( array( 'is_front_page', 'is_singular', 'is_category', 'is_tag', 'is_tax', 'is_author', 'is_search', 'is_404' ) as $cond ) {
			Functions\when( $cond )->justReturn( false );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_front_page_has_no_trail(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$this->assertSame( array(), ( new Trail() )->items() );
	}

	public function test_post_trail_includes_home_category_and_self(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_the_title' )->justReturn( 'My Post' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/my-post/' );

		$cat            = new \stdClass();
		$cat->name      = 'News';
		$cat->term_id   = 9;
		Functions\when( 'get_the_category' )->justReturn( array( $cat ) );
		Functions\when( 'get_category_link' )->justReturn( 'https://example.com/cat/news/' );

		$items = ( new Trail() )->items();

		$this->assertCount( 3, $items );
		$this->assertSame( 'Home', $items[0]['name'] );
		$this->assertSame( 'News', $items[1]['name'] );
		$this->assertSame( 'https://example.com/cat/news/', $items[1]['url'] );
		$this->assertSame( 'My Post', $items[2]['name'] );
		$this->assertSame( 'https://example.com/my-post/', $items[2]['url'] );
	}

	public function test_page_trail_includes_ancestors(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 12 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post_ancestors' )->justReturn( array( 3 ) ); // parent id
		Functions\when( 'get_the_title' )->alias(
			static fn( $id = 0 ) => 3 === $id ? 'Parent' : 'Child'
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( $id = 0 ) => 3 === $id
				? 'https://example.com/parent/'
				: 'https://example.com/parent/child/'
		);

		$items = ( new Trail() )->items();

		$this->assertSame( array( 'Home', 'Parent', 'Child' ), array_column( $items, 'name' ) );
	}

	public function test_category_archive_trail(): void {
		Functions\when( 'is_category' )->justReturn( true );
		$term       = new \stdClass();
		$term->name = 'Travel';
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$items = ( new Trail() )->items();

		$this->assertSame( array( 'Home', 'Travel' ), array_column( $items, 'name' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter TrailTest`
Expected: FAIL — `Class "OpenSEO\Breadcrumbs\Trail" not found`.

- [ ] **Step 3: Implement `Trail`**

Create `src/Breadcrumbs/Trail.php`:

```php
<?php
/**
 * Builds the breadcrumb hierarchy for the current request.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Breadcrumbs;

/**
 * Single source of truth for the breadcrumb trail. Returns data only — the
 * theme function, the block, and the BreadcrumbList schema piece all consume it.
 */
final class Trail {

	/**
	 * Ordered crumbs from Home to the current location.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	public function items(): array {
		if ( is_front_page() ) {
			return array();
		}

		$items = array(
			array(
				'name' => __( 'Home', 'openseo' ),
				'url'  => home_url( '/' ),
			),
		);

		if ( is_singular() ) {
			return array_merge( $items, $this->singular_items() );
		}

		if ( is_category() || is_tag() || is_tax() || is_author() ) {
			$object = get_queried_object();
			$name   = is_object( $object ) && isset( $object->name )
				? (string) $object->name
				: (string) get_the_author();
			$items[] = array(
				'name' => $name,
				'url'  => '',
			);

			return $items;
		}

		if ( is_search() ) {
			$items[] = array(
				'name' => __( 'Search results', 'openseo' ),
				'url'  => '',
			);

			return $items;
		}

		if ( is_404() ) {
			$items[] = array(
				'name' => __( 'Not found', 'openseo' ),
				'url'  => '',
			);
		}

		return $items;
	}

	/**
	 * Crumbs for a singular entry: ancestors (pages) or primary category (posts),
	 * then the entry itself.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function singular_items(): array {
		$id    = get_queried_object_id();
		$items = array();

		if ( 'page' === get_post_type( $id ) ) {
			foreach ( array_reverse( get_post_ancestors( $id ) ) as $ancestor ) {
				$items[] = array(
					'name' => (string) get_the_title( $ancestor ),
					'url'  => (string) get_permalink( $ancestor ),
				);
			}
		} else {
			$categories = get_the_category( $id );
			if ( ! empty( $categories ) ) {
				$primary = $categories[0];
				$items[] = array(
					'name' => (string) $primary->name,
					'url'  => (string) get_category_link( $primary->term_id ),
				);
			}
		}

		$items[] = array(
			'name' => (string) get_the_title( $id ),
			'url'  => (string) get_permalink( $id ),
		);

		return $items;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter TrailTest`
Expected: PASS.

- [ ] **Step 5: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Breadcrumbs/Trail.php tests/Unit/Breadcrumbs/TrailTest.php
git commit -m "feat(breadcrumbs): add trail builder"
```

---

## Task 7: `BreadcrumbList` piece

**Files:**
- Create: `src/Schema/Pieces/BreadcrumbList.php`
- Test: `tests/Unit/Schema/Pieces/BreadcrumbListTest.php`

**Interfaces:**
- Consumes: `Breadcrumbs\Trail` (Task 6), `Schema\Ids` (Task 3).
- Produces: `final class BreadcrumbList implements Piece` (ctor `Trail`) — needed when the trail has at least two crumbs; `data()` has `@type` `BreadcrumbList`, `@id` `Ids::breadcrumb(current_url)`, `itemListElement` of `ListItem`s with `position`/`name`/`item` (the latter omitted when a crumb has no URL).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Schema/Pieces/BreadcrumbListTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema\Pieces;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Breadcrumbs\Trail;
use OpenSEO\Schema\Pieces\BreadcrumbList;
use PHPUnit\Framework\TestCase;

final class BreadcrumbListTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A Trail double returning a fixed two-crumb list.
	 */
	private function trail( array $items ): Trail {
		return new class( $items ) extends Trail {
			/** @param array<int, array{name:string,url:string}> $items */
			public function __construct( private array $items ) {}
			public function items(): array {
				return $this->items;
			}
		};
	}

	public function test_not_needed_when_trail_too_short(): void {
		$piece = new BreadcrumbList( $this->trail( array( array( 'name' => 'Home', 'url' => 'https://example.com/' ) ) ) );

		$this->assertFalse( $piece->is_needed() );
	}

	public function test_builds_item_list_with_positions(): void {
		$piece = new BreadcrumbList(
			$this->trail(
				array(
					array( 'name' => 'Home', 'url' => 'https://example.com/' ),
					array( 'name' => 'My Post', 'url' => 'https://example.com/post/' ),
				)
			)
		);

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'BreadcrumbList', $data['@type'] );
		$this->assertSame( 'https://example.com/post/#breadcrumb', $data['@id'] );
		$this->assertCount( 2, $data['itemListElement'] );
		$this->assertSame( 1, $data['itemListElement'][0]['position'] );
		$this->assertSame( 'Home', $data['itemListElement'][0]['name'] );
		$this->assertSame( 'https://example.com/', $data['itemListElement'][0]['item'] );
		$this->assertSame( 2, $data['itemListElement'][1]['position'] );
	}

	public function test_omits_item_url_when_crumb_has_none(): void {
		$piece = new BreadcrumbList(
			$this->trail(
				array(
					array( 'name' => 'Home', 'url' => 'https://example.com/' ),
					array( 'name' => 'Search results', 'url' => '' ),
				)
			)
		);

		$this->assertArrayNotHasKey( 'item', $piece->data()['itemListElement'][1] );
	}
}
```

> Note: `Trail` must not be `final` for the anonymous-class test double to extend
> it. It is already declared `final` in Task 6 — change that declaration to a
> plain `class` in Step 3 below (it has no subclasses in production; the only
> extension is this test double).

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter BreadcrumbListTest`
Expected: FAIL — class missing (and a fatal if `Trail` is still `final`).

- [ ] **Step 3: Make `Trail` extendable for testing**

In `src/Breadcrumbs/Trail.php`, change `final class Trail` to `class Trail` and update the class doc to note it is non-final solely so tests can substitute a fixed trail.

- [ ] **Step 4: Implement `BreadcrumbList`**

Create `src/Schema/Pieces/BreadcrumbList.php`:

```php
<?php
/**
 * BreadcrumbList schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Breadcrumbs\Trail;
use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;

/**
 * The BreadcrumbList node, built from the shared breadcrumb Trail.
 */
final class BreadcrumbList implements Piece {

	/**
	 * @param Trail $trail Shared breadcrumb trail builder.
	 */
	public function __construct( private readonly Trail $trail ) {}

	public function is_needed(): bool {
		return count( $this->trail->items() ) >= 2;
	}

	public function id(): string {
		return Ids::breadcrumb( Ids::current_url() );
	}

	public function data(): array {
		$elements = array();
		$position = 1;

		foreach ( $this->trail->items() as $item ) {
			$element = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $item['name'],
			);

			if ( '' !== $item['url'] ) {
				$element['item'] = $item['url'];
			}

			$elements[] = $element;
			++$position;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $this->id(),
			'itemListElement' => $elements,
		);
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter BreadcrumbListTest`
Expected: PASS. Then `vendor/bin/phpunit --filter TrailTest` — Expected: still PASS (the non-final change is behavior-neutral).

- [ ] **Step 6: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Schema/Pieces/BreadcrumbList.php src/Breadcrumbs/Trail.php tests/Unit/Schema/Pieces/BreadcrumbListTest.php
git commit -m "feat(schema): add BreadcrumbList piece"
```

---

## Task 8: `Schema\Graph` + wire into `Plugin`

**Files:**
- Create: `src/Schema/Graph.php`
- Modify: `src/Plugin.php`
- Test: `tests/Unit/Schema/GraphTest.php`, `tests/Integration/SchemaTest.php`

**Interfaces:**
- Consumes: `Schema\Piece[]` (Tasks 4, 5, 7).
- Produces: `final class Graph implements Hookable { public function __construct( array $pieces ); public function register(): void; public function print_graph(): void; public function build(): array; }` — `build()` returns `array{'@context': string, '@graph': array<int, array<string,mixed>>}` from the needed pieces; `print_graph()` echoes it as a `<script type="application/ld+json">` on `wp_head`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Schema/GraphTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema;

use Brain\Monkey;
use OpenSEO\Schema\Graph;
use OpenSEO\Schema\Piece;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function piece( bool $needed, array $data ): Piece {
		return new class( $needed, $data ) implements Piece {
			/** @param array<string,mixed> $data */
			public function __construct( private bool $needed, private array $data ) {}
			public function is_needed(): bool {
				return $this->needed;
			}
			public function id(): string {
				return $this->data['@id'] ?? '';
			}
			public function data(): array {
				return $this->data;
			}
		};
	}

	public function test_build_includes_only_needed_pieces(): void {
		$graph = new Graph(
			array(
				$this->piece( true, array( '@type' => 'WebSite', '@id' => 'a' ) ),
				$this->piece( false, array( '@type' => 'Article', '@id' => 'b' ) ),
				$this->piece( true, array( '@type' => 'WebPage', '@id' => 'c' ) ),
			)
		);

		$built = $graph->build();

		$this->assertSame( 'https://schema.org', $built['@context'] );
		$this->assertCount( 2, $built['@graph'] );
		$this->assertSame( array( 'WebSite', 'WebPage' ), array_column( $built['@graph'], '@type' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter GraphTest`
Expected: FAIL — `Class "OpenSEO\Schema\Graph" not found`.

- [ ] **Step 3: Implement `Graph`**

Create `src/Schema/Graph.php`:

```php
<?php
/**
 * Assembles and prints the JSON-LD @graph.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

use OpenSEO\Contracts\Hookable;

/**
 * Collects every applicable Piece into one connected @graph and prints it as a
 * single ld+json script. Core/theme markup is untouched; this only adds a script.
 */
final class Graph implements Hookable {

	/**
	 * @param Piece[] $pieces Ordered schema pieces.
	 */
	public function __construct( private readonly array $pieces ) {}

	/**
	 * Print the graph late in wp_head, after the meta presenters.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_graph' ), 10 );
	}

	/**
	 * Build the @graph payload from the pieces that apply to this request.
	 *
	 * @return array{ '@context': string, '@graph': array<int, array<string, mixed>> }
	 */
	public function build(): array {
		$nodes = array();

		foreach ( $this->pieces as $piece ) {
			if ( $piece->is_needed() ) {
				$nodes[] = $piece->data();
			}
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		);
	}

	/**
	 * Echo the graph as an ld+json script.
	 */
	public function print_graph(): void {
		$graph = $this->build();

		if ( empty( $graph['@graph'] ) ) {
			return;
		}

		// JSON_HEX_TAG escapes < and > so a value containing </script> cannot
		// break out of the script element; the JSON itself needs no further esc.
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_TAG produces script-safe output.
	}
}
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `vendor/bin/phpunit --filter GraphTest`
Expected: PASS.

- [ ] **Step 5: Wire the graph into `Plugin`**

In `src/Plugin.php`, add imports (keep alphabetical-ish with the existing ones):

```php
use OpenSEO\Schema\Graph;
use OpenSEO\Schema\Pieces\Article;
use OpenSEO\Schema\Pieces\BreadcrumbList;
use OpenSEO\Schema\Pieces\Organization;
use OpenSEO\Schema\Pieces\Person;
use OpenSEO\Schema\Pieces\WebPage as WebPagePiece;
use OpenSEO\Schema\Pieces\WebSite as WebSitePiece;
use OpenSEO\Breadcrumbs\Trail;
```

In `modules()`, after `$resolver` is built, add the trail and the graph, and append `$graph` to the front-end `$modules` array (after `new Sitemap( $options ),`):

```php
		$trail = new Trail();

		$graph = new Graph(
			array(
				new WebSitePiece( $options ),
				new Organization( $options ),
				new Person( $options ),
				new WebPagePiece( $resolver ),
				new Article( $resolver, $options ),
				new BreadcrumbList( $trail ),
			)
		);
```

```php
			new Sitemap( $options ),
			$graph,
		);
```

- [ ] **Step 6: Write the integration test**

Create `tests/Integration/SchemaTest.php`:

```php
<?php
/**
 * Integration tests for the JSON-LD graph output.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class SchemaTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( Options::OPTION_KEY );
	}

	/**
	 * Extract the first ld+json graph from a wp_head dump.
	 *
	 * @param string $head Rendered head HTML.
	 * @return array<string, mixed>
	 */
	private function graph_from_head( string $head ): array {
		$this->assertMatchesRegularExpression( '#<script type="application/ld\+json">(.+?)</script>#s', $head );
		preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $head, $m );

		return json_decode( $m[1], true );
	}

	public function test_singular_post_emits_connected_graph(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Graph Post',
				'post_excerpt' => 'A summary.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$graph = $this->graph_from_head( (string) ob_get_clean() );

		$types = array_column( $graph['@graph'], '@type' );
		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'Organization', $types );
		$this->assertContains( 'WebPage', $types );
		$this->assertContains( 'Article', $types );
		$this->assertContains( 'BreadcrumbList', $types );
	}

	public function test_person_identity_replaces_organization(): void {
		update_option( Options::OPTION_KEY, array( 'schema_site_type' => 'Person' ) );
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$types = array_column( $this->graph_from_head( (string) ob_get_clean() )['@graph'], '@type' );

		$this->assertContains( 'Person', $types );
		$this->assertNotContains( 'Organization', $types );
	}

	public function test_none_type_suppresses_article(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_openseo_schema_type', 'none' );
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$types = array_column( $this->graph_from_head( (string) ob_get_clean() )['@graph'], '@type' );

		$this->assertContains( 'WebPage', $types );
		$this->assertNotContains( 'Article', $types );
	}
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter GraphTest`
Expected: PASS.
Then (wp-env up): `npm run test:integration -- --filter SchemaTest`
Expected: PASS.

- [ ] **Step 8: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Schema/Graph.php src/Plugin.php tests/Unit/Schema/GraphTest.php tests/Integration/SchemaTest.php
git commit -m "feat(schema): assemble and print the JSON-LD graph"
```

---

## Task 9: `Breadcrumbs\Renderer` + theme function

**Files:**
- Create: `src/Breadcrumbs/Renderer.php`, `src/template-functions.php`
- Modify: `composer.json`
- Test: `tests/Unit/Breadcrumbs/RendererTest.php`

**Interfaces:**
- Consumes: `Settings\Options` (Task 1, for the default separator), `Breadcrumbs\Trail` (Task 6).
- Produces:
  - `final class Renderer { public function __construct( Options $options ); public function render( array $items, array $args = array() ): string; }` — escaped `<nav><ol>…</ol></nav>`; `$args` keys `separator` (default = `breadcrumb_separator` option), `show_home` (default `true`), `text_align` (default `''`).
  - global `openseo_breadcrumbs( array $args = array() ): void` — echoes the rendered trail.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Breadcrumbs/RendererTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Breadcrumbs\Renderer;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function items(): array {
		return array(
			array( 'name' => 'Home', 'url' => 'https://example.com/' ),
			array( 'name' => 'My Post', 'url' => 'https://example.com/post/' ),
		);
	}

	public function test_renders_links_and_marks_current(): void {
		$html = ( new Renderer( new Options() ) )->render( $this->items() );

		$this->assertStringContainsString( '<nav class="openseo-breadcrumbs"', $html );
		$this->assertStringContainsString( '<a href="https://example.com/">Home</a>', $html );
		// Last crumb is not a link.
		$this->assertStringContainsString( '<span aria-current="page">My Post</span>', $html );
		// Default separator from the option default.
		$this->assertStringContainsString( '›', $html );
	}

	public function test_show_home_false_drops_the_home_crumb(): void {
		$html = ( new Renderer( new Options() ) )->render(
			$this->items(),
			array( 'show_home' => false )
		);

		$this->assertStringNotContainsString( '>Home<', $html );
	}

	public function test_custom_separator_and_alignment(): void {
		$html = ( new Renderer( new Options() ) )->render(
			$this->items(),
			array( 'separator' => '/', 'text_align' => 'center' )
		);

		$this->assertStringContainsString( '/', $html );
		$this->assertStringContainsString( 'text-align:center', $html );
	}

	public function test_empty_items_render_nothing(): void {
		$this->assertSame( '', ( new Renderer( new Options() ) )->render( array() ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter RendererTest`
Expected: FAIL — `Class "OpenSEO\Breadcrumbs\Renderer" not found`.

- [ ] **Step 3: Implement `Renderer`**

Create `src/Breadcrumbs/Renderer.php`:

```php
<?php
/**
 * Renders the breadcrumb trail as escaped HTML.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Breadcrumbs;

use OpenSEO\Settings\Options;

/**
 * Turns trail items into an accessible <nav><ol> structure. Every value is
 * escaped here, so callers can echo the returned string directly.
 */
final class Renderer {

	/**
	 * @param Options $options Settings accessor (provides the default separator).
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Render the trail.
	 *
	 * @param array<int, array{name: string, url: string}> $items Trail crumbs.
	 * @param array<string, mixed>                         $args  Display options.
	 */
	public function render( array $items, array $args = array() ): string {
		$args = array_merge(
			array(
				'separator'  => (string) $this->options->get( 'breadcrumb_separator' ),
				'show_home'  => true,
				'text_align' => '',
			),
			$args
		);

		if ( ! $args['show_home'] ) {
			$items = array_values(
				array_filter(
					$items,
					static fn( $item ) => __( 'Home', 'openseo' ) !== $item['name']
				)
			);
		}

		if ( empty( $items ) ) {
			return '';
		}

		$style = '' !== $args['text_align']
			? ' style="text-align:' . esc_attr( (string) $args['text_align'] ) . '"'
			: '';

		$html  = '<nav class="openseo-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'openseo' ) . '"' . $style . '>';
		$html .= '<ol class="openseo-breadcrumbs__list">';

		$last = count( $items ) - 1;
		foreach ( $items as $index => $item ) {
			$html .= '<li class="openseo-breadcrumbs__item">';

			if ( $index !== $last && '' !== $item['url'] ) {
				$html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
			} else {
				$html .= '<span aria-current="page">' . esc_html( $item['name'] ) . '</span>';
			}

			$html .= '</li>';

			if ( $index !== $last ) {
				$html .= '<li class="openseo-breadcrumbs__sep" aria-hidden="true">' . esc_html( (string) $args['separator'] ) . '</li>';
			}
		}

		$html .= '</ol></nav>';

		return $html;
	}
}
```

- [ ] **Step 4: Create the theme function**

Create `src/template-functions.php`:

```php
<?php
/**
 * Public template functions for theme authors.
 *
 * Loaded via Composer's autoload.files so the global is always available.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

use OpenSEO\Breadcrumbs\Renderer;
use OpenSEO\Breadcrumbs\Trail;
use OpenSEO\Settings\Options;

if ( ! function_exists( 'openseo_breadcrumbs' ) ) {
	/**
	 * Echo the OpenSEO breadcrumb trail.
	 *
	 * @param array<string, mixed> $args Optional display overrides
	 *                                   (separator, show_home, text_align).
	 */
	function openseo_breadcrumbs( array $args = array() ): void {
		$renderer = new Renderer( new Options() );

		// Renderer escapes every value it outputs.
		echo $renderer->render( ( new Trail() )->items(), $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns fully-escaped HTML.
	}
}
```

- [ ] **Step 5: Register the file with Composer**

In `composer.json`, change the `autoload` block to add `files`:

```json
    "autoload": {
        "psr-4": {
            "OpenSEO\\": "src/"
        },
        "files": [
            "src/template-functions.php"
        ]
    },
```

Then regenerate the autoloader:

```bash
composer dump-autoload
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter RendererTest`
Expected: PASS.

- [ ] **Step 7: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Breadcrumbs/Renderer.php src/template-functions.php composer.json composer.lock tests/Unit/Breadcrumbs/RendererTest.php
git commit -m "feat(breadcrumbs): add renderer and template function"
```

> If `composer dump-autoload` did not touch `composer.lock`, drop it from the
> `git add` line.

---

## Task 10: `Breadcrumbs\Block` + block JS

**Files:**
- Create: `src/Breadcrumbs/Block.php`, `assets/src/blocks/breadcrumbs/index.js`, `assets/src/blocks/breadcrumbs/edit.js`
- Modify: `src/Plugin.php`, `webpack.config.js`
- Test: `tests/Integration/BreadcrumbsBlockTest.php`

**Interfaces:**
- Consumes: `Breadcrumbs\Trail` (Task 6), `Breadcrumbs\Renderer` (Task 9), `Settings\Options` (Task 1).
- Produces: `final class Block implements Hookable { public function __construct( Options $options ); public function register(): void; public function register_block(): void; public function render( array $attributes ): string; }` — registers the dynamic `openseo/breadcrumbs` block (attributes `showHome` bool default `true`, `textAlign` string) with a PHP `render_callback` and the compiled editor script `assets/build/breadcrumbs.js`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/BreadcrumbsBlockTest.php`:

```php
<?php
/**
 * Integration tests for the breadcrumbs block.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use WP_Block_Type_Registry;
use WP_UnitTestCase;

final class BreadcrumbsBlockTest extends WP_UnitTestCase {

	public function test_block_is_registered(): void {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'openseo/breadcrumbs' )
		);
	}

	public function test_block_renders_a_nav_on_a_post(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Block Post' ) );
		$this->go_to( get_permalink( $post_id ) );

		$html = do_blocks( '<!-- wp:openseo/breadcrumbs /-->' );

		$this->assertStringContainsString( 'openseo-breadcrumbs', $html );
		$this->assertStringContainsString( 'Block Post', $html );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run (wp-env up): `npm run test:integration -- --filter BreadcrumbsBlockTest`
Expected: FAIL — the block is not registered.

- [ ] **Step 3: Implement `Block`**

Create `src/Breadcrumbs/Block.php`:

```php
<?php
/**
 * Registers the dynamic breadcrumbs block.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Breadcrumbs;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * The openseo/breadcrumbs block. Server-rendered so it always reflects the live
 * page position, reusing the shared Trail + Renderer.
 */
final class Block implements Hookable {

	private const NAME   = 'openseo/breadcrumbs';
	private const HANDLE = 'openseo-breadcrumbs-editor';

	/**
	 * @param Options $options Settings accessor (default separator).
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register the block on init (needed on front and editor requests).
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the editor script and the dynamic block type.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$asset_path = OPENSEO_PLUGIN_DIR . 'assets/build/breadcrumbs.asset.php';
		$asset      = is_readable( $asset_path ) ? require $asset_path : array();

		wp_register_script(
			self::HANDLE,
			OPENSEO_PLUGIN_URL . 'assets/build/breadcrumbs.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? OPENSEO_VERSION,
			true
		);

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 3,
				'title'           => __( 'OpenSEO Breadcrumbs', 'openseo' ),
				'category'        => 'theme',
				'icon'            => 'networking',
				'editor_script'   => self::HANDLE,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'showHome'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'textAlign' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Server-render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$items = ( new Trail() )->items();

		if ( empty( $items ) ) {
			return '';
		}

		return ( new Renderer( $this->options ) )->render(
			$items,
			array(
				'show_home'  => $attributes['showHome'] ?? true,
				'text_align' => (string) ( $attributes['textAlign'] ?? '' ),
			)
		);
	}
}
```

- [ ] **Step 4: Add the webpack entry**

In `webpack.config.js`, add the breadcrumbs entry:

```javascript
	entry: {
		'admin-settings': path.resolve(
			process.cwd(),
			'assets/src/admin/index.js'
		),
		editor: path.resolve( process.cwd(), 'assets/src/editor/index.js' ),
		breadcrumbs: path.resolve(
			process.cwd(),
			'assets/src/blocks/breadcrumbs/index.js'
		),
	},
```

- [ ] **Step 5: Write the block editor scripts**

Create `assets/src/blocks/breadcrumbs/edit.js`:

```javascript
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * @param {{ attributes: { showHome: boolean, textAlign: string }, setAttributes: Function }} props
 */
export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Breadcrumbs', 'openseo' ) }>
					<ToggleControl
						label={ __( 'Show home link', 'openseo' ) }
						checked={ attributes.showHome }
						onChange={ ( showHome ) =>
							setAttributes( { showHome } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="openseo/breadcrumbs"
				attributes={ attributes }
			/>
		</div>
	);
}
```

Create `assets/src/blocks/breadcrumbs/index.js`:

```javascript
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import Edit from './edit';

// Attributes mirror the PHP registration (Block::register_block); the server
// owns rendering, so save() returns null.
registerBlockType( 'openseo/breadcrumbs', {
	apiVersion: 3,
	title: __( 'OpenSEO Breadcrumbs', 'openseo' ),
	category: 'theme',
	icon: 'networking',
	attributes: {
		showHome: { type: 'boolean', default: true },
		textAlign: { type: 'string', default: '' },
	},
	edit: Edit,
	save: () => null,
} );
```

- [ ] **Step 6: Register the block module in `Plugin`**

In `src/Plugin.php`, add the import:

```php
use OpenSEO\Breadcrumbs\Block as BreadcrumbsBlock;
```

In `modules()`, append it to the front-end `$modules` array (after `$graph,`):

```php
			$graph,
			new BreadcrumbsBlock( $options ),
		);
```

- [ ] **Step 7: Build, then run the tests**

```bash
npm run build
```
Expected: `assets/build/breadcrumbs.js` + `breadcrumbs.asset.php` are produced.

Run (wp-env up): `npm run test:integration -- --filter BreadcrumbsBlockTest`
Expected: PASS.

- [ ] **Step 8: Lint, analyze, commit**

```bash
composer lint && composer analyze && npm run lint:js
git add src/Breadcrumbs/Block.php src/Plugin.php webpack.config.js assets/src/blocks/breadcrumbs/index.js assets/src/blocks/breadcrumbs/edit.js tests/Integration/BreadcrumbsBlockTest.php
git commit -m "feat(breadcrumbs): add dynamic breadcrumbs block"
```

> `assets/build/` is git-ignored (built during release), so it is not committed.

---

## Task 11: `Ai\Prompts` + `suggest-schema-type` ability

**Files:**
- Modify: `src/Ai/Prompts.php`, `src/Ai/Abilities.php`
- Test: `tests/Unit/Ai/PromptsTest.php`, `tests/Unit/Ai/AbilitiesTest.php`, `tests/Integration/AbilitiesTest.php`

**Interfaces:**
- Consumes: `Ai\Prompts`, `Ai\Connector` (existing), the AI Client.
- Produces:
  - `Prompts::system_schema_type(): string` — instruction listing the allowed types, asking for `{type, reason}` JSON.
  - `Abilities::suggest_schema_type( array $input ): array|WP_Error` returning `array{type: string, reason: string}` (type validated against an allowed set, defaulting to `Article`) or `WP_Error` (`openseo_invalid_post`, `openseo_no_connector`). Registered as `openseo/suggest-schema-type` with `meta.show_in_rest => true` and `meta.annotations.readonly => false` (reuses `ability_meta()`).

- [ ] **Step 1: Write the failing Prompts test**

Add to `tests/Unit/Ai/PromptsTest.php` (inside the class):

```php
	public function test_system_schema_type_lists_types_and_json_keys(): void {
		$system = Prompts::system_schema_type();

		$this->assertStringContainsString( 'FAQPage', $system );
		$this->assertStringContainsString( 'BlogPosting', $system );
		$this->assertStringContainsString( 'type', $system );
		$this->assertStringContainsString( 'reason', $system );
	}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter PromptsTest`
Expected: FAIL — `system_schema_type` not defined.

- [ ] **Step 3: Add `system_schema_type`**

In `src/Ai/Prompts.php`, add this method after `system_title()`:

```php
	/**
	 * System instruction for the schema-type recommendation ability.
	 */
	public static function system_schema_type(): string {
		return 'You are an SEO expert. Analyze the article below and recommend the single most fitting schema.org type from this list: Article, BlogPosting, NewsArticle, WebPage, FAQPage, HowTo, Recipe, Product. Reply as JSON with two keys: "type" (exactly one value from the list) and "reason" (one short sentence explaining why, in the same language as the article).';
	}
```

- [ ] **Step 4: Write the failing Abilities unit tests**

Add to `tests/Unit/Ai/AbilitiesTest.php` (inside the class):

```php
	public function test_registers_suggest_schema_type_over_rest(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ): void {
				$registered[ $name ] = $args;
			}
		);

		$this->abilities()->register_abilities();

		$this->assertArrayHasKey( 'openseo/suggest-schema-type', $registered );
		$this->assertTrue( $registered['openseo/suggest-schema-type']['meta']['show_in_rest'] );
	}

	public function test_suggest_schema_type_returns_type_and_reason(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof WP_Error );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakePromptBuilder( true, '{"type":"FAQPage","reason":"It answers questions."}' )
		);

		$result = $this->abilities()->suggest_schema_type( array( 'post_id' => 7 ) );

		$this->assertSame( 'FAQPage', $result['type'] );
		$this->assertSame( 'It answers questions.', $result['reason'] );
	}

	public function test_suggest_schema_type_defaults_unknown_type(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof WP_Error );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakePromptBuilder( true, '{"type":"Nonsense","reason":"x"}' )
		);

		$result = $this->abilities()->suggest_schema_type( array( 'post_id' => 7 ) );

		// Off-list type collapses to the safe default.
		$this->assertSame( 'Article', $result['type'] );
	}

	public function test_suggest_schema_type_errors_without_connector(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_ai_client_prompt' )->justReturn( new FakePromptBuilder( false, '' ) );

		$result = $this->abilities()->suggest_schema_type( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_no_connector', $result->get_error_code() );
	}
```

- [ ] **Step 5: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter AbilitiesTest`
Expected: FAIL — `suggest_schema_type` not defined; the ability is not registered.

- [ ] **Step 6: Register the ability**

In `src/Ai/Abilities.php`, inside `register_abilities()`, after the `openseo/generate-title` registration, add:

```php
		wp_register_ability(
			'openseo/suggest-schema-type',
			array(
				'label'               => __( 'Suggest schema type', 'openseo' ),
				'description'         => __( 'Analyzes a post and recommends the most fitting schema.org type. Read-only; consumes provider credits.', 'openseo' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->post_id_input_schema(),
				'output_schema'       => $this->suggestion_output_schema(),
				'execute_callback'    => array( $this, 'suggest_schema_type' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'meta'                => $this->ability_meta(),
			)
		);
```

- [ ] **Step 7: Implement the ability + its output schema**

In `src/Ai/Abilities.php`, add the allowed-types constant near the top of the class (after `CONTENT_WORDS`):

```php
	private const SUGGESTABLE_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'FAQPage', 'HowTo', 'Recipe', 'Product' );
```

Add the execute method after `generate_title()`:

```php
	/**
	 * Execute the "suggest schema type" ability.
	 *
	 * @param array<string, mixed> $input Validated input matching the input schema.
	 * @return array{type: string, reason: string}|WP_Error
	 */
	public function suggest_schema_type( array $input ): array|WP_Error {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'openseo_invalid_post',
				__( 'A valid post ID is required.', 'openseo' )
			);
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! Connector::is_text_generation_available() ) {
			return new WP_Error(
				'openseo_no_connector',
				__( 'No AI connector is configured. Add one under Settings → Connectors.', 'openseo' )
			);
		}

		$content = wp_trim_words( wp_strip_all_tags( $post->post_content ), self::CONTENT_WORDS, '' );

		$builder = wp_ai_client_prompt( Prompts::user_for_post( $post->post_title, $content ) )
			->using_system_instruction( Prompts::system_schema_type() )
			->using_max_tokens( self::MAX_TOKENS )
			->as_json_response( $this->suggestion_output_schema() );

		$model = (string) $this->options->get( 'ai_model' );
		if ( '' !== $model ) {
			$builder = $builder->using_model_preference( $model );
		}

		$generated = $builder->generate_text();
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		$decoded = json_decode( (string) $generated, true );
		$type    = is_array( $decoded ) && isset( $decoded['type'] ) && is_string( $decoded['type'] ) ? $decoded['type'] : '';
		$reason  = is_array( $decoded ) && isset( $decoded['reason'] ) && is_string( $decoded['reason'] ) ? $decoded['reason'] : '';

		if ( ! in_array( $type, self::SUGGESTABLE_TYPES, true ) ) {
			$type = 'Article';
		}

		return array(
			'type'   => sanitize_text_field( $type ),
			'reason' => sanitize_text_field( $reason ),
		);
	}

	/**
	 * Output schema for the schema-type recommendation.
	 *
	 * @return array<string, mixed>
	 */
	private function suggestion_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'   => array( 'type' => 'string' ),
				'reason' => array( 'type' => 'string' ),
			),
			'required'   => array( 'type', 'reason' ),
		);
	}
```

- [ ] **Step 8: Add the integration coverage**

Add to `tests/Integration/AbilitiesTest.php` (inside the class):

```php
	public function test_suggest_schema_type_without_connector_errors(): void {
		$post_id = self::factory()->post->create();

		$ability = new Abilities( new Options() );
		$result  = $ability->suggest_schema_type( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_no_connector', $result->get_error_code() );
	}

	public function test_suggest_schema_type_ability_is_exposed_over_rest(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create( array( 'post_author' => $admin ) );

		$request = new \WP_REST_Request(
			'POST',
			'/wp-abilities/v1/openseo/suggest-schema-type/run'
		);
		$request->set_body_params( array( 'input' => array( 'post_id' => $post_id ) ) );
		$response = rest_do_request( $request );

		$this->assertNotSame( 404, $response->get_status() );
		$this->assertNotSame( 'rest_no_route', $response->get_data()['code'] ?? '' );
	}
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter PromptsTest && vendor/bin/phpunit --filter AbilitiesTest`
Expected: PASS.
Then (wp-env up): `npm run test:integration -- --filter AbilitiesTest`
Expected: PASS.

- [ ] **Step 10: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Ai/Prompts.php src/Ai/Abilities.php tests/Unit/Ai/PromptsTest.php tests/Unit/Ai/AbilitiesTest.php tests/Integration/AbilitiesTest.php
git commit -m "feat(ai): add suggest-schema-type ability"
```

---

## Task 12: Editor — schema type select + AI recommend

**Files:**
- Modify: `assets/src/editor/index.js`
- Test: manual verification in wp-env (the React tree is verified manually; the
  pure helper `aiErrorMessage` is already covered by Phase 2's `ai.test.js`).

**Interfaces:**
- Consumes: `useMeta` (existing), `_openseo_schema_type` (Task 2), the
  `openseo/suggest-schema-type` ability (Task 11), `aiErrorMessage` (existing),
  `window.openseoEditor` (existing).
- Produces: a `SchemaField` (a `SelectControl` bound to `_openseo_schema_type` + a
  "Recommend with AI" button) rendered inside `AdvancedTab`.

- [ ] **Step 1: Add the imports**

In `assets/src/editor/index.js`, add `SelectControl` to the `@wordpress/components` import list (it currently imports `Button, Notice, TextControl, TextareaControl, ToggleControl, TabPanel`):

```javascript
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
	TabPanel,
} from '@wordpress/components';
```

- [ ] **Step 2: Add the `SchemaField` component**

In `assets/src/editor/index.js`, add this component above `function GeneralTab()`:

```javascript
const SCHEMA_OPTIONS = [
	{ label: __( 'Default (automatic)', 'openseo' ), value: '' },
	{ label: 'Article', value: 'Article' },
	{ label: 'BlogPosting', value: 'BlogPosting' },
	{ label: 'NewsArticle', value: 'NewsArticle' },
	{ label: 'WebPage', value: 'WebPage' },
	{ label: __( 'None', 'openseo' ), value: 'none' },
];

const APPLICABLE_TYPES = [ 'Article', 'BlogPosting', 'NewsArticle', 'WebPage' ];

function SchemaField() {
	const [ type, setType ] = useMeta( '_openseo_schema_type' );
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ suggestion, setSuggestion ] = useState( null );

	const aiAvailable = window.openseoEditor?.aiAvailable ?? false;

	const onRecommend = async () => {
		setBusy( true );
		setError( '' );
		setSuggestion( null );

		try {
			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/openseo/suggest-schema-type/run',
				method: 'POST',
				data: { input: { post_id: postId } },
			} );
			setSuggestion( {
				type: result?.type ?? '',
				reason: result?.reason ?? '',
			} );
		} catch ( e ) {
			setError( aiErrorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<SelectControl
				label={ __( 'Schema type', 'openseo' ) }
				value={ type }
				options={ SCHEMA_OPTIONS }
				onChange={ setType }
			/>
			{ aiAvailable && (
				<Button
					variant="secondary"
					onClick={ onRecommend }
					isBusy={ busy }
					disabled={ busy }
				>
					{ busy
						? __( 'Analyzing…', 'openseo' )
						: __( 'Recommend with AI', 'openseo' ) }
				</Button>
			) }
			{ suggestion && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Recommended:', 'openseo' ) } <strong>{ suggestion.type }</strong>
					{ suggestion.reason ? ` — ${ suggestion.reason }` : '' }
					{ APPLICABLE_TYPES.includes( suggestion.type ) && (
						<>
							{ ' ' }
							<Button
								variant="link"
								onClick={ () => setType( suggestion.type ) }
							>
								{ __( 'Apply', 'openseo' ) }
							</Button>
						</>
					) }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</>
	);
}
```

- [ ] **Step 3: Render it in `AdvancedTab`**

In `assets/src/editor/index.js`, add `<SchemaField />` at the end of the `AdvancedTab` return, after the canonical `TextControl`:

```javascript
			<TextControl
				label={ __( 'Canonical URL', 'openseo' ) }
				value={ canonical }
				onChange={ setCanonical }
			/>
			<SchemaField />
		</>
	);
}
```

- [ ] **Step 4: Build and lint**

Run: `npm run build && npm run lint:js`
Expected: `assets/build/editor.js` rebuilds; lint passes.

- [ ] **Step 5: Manually verify in wp-env**

```bash
npm run env:start
```
Open `http://localhost:8888/wp-admin/post-new.php`. In the **OpenSEO** panel →
**Advanced** tab, confirm: the **Schema type** select shows the six options and
persists on save; with no connector the "Recommend with AI" button is hidden
(matching the General tab's behavior). With a connector configured (optional, as
in Phase 2), clicking it shows a recommendation notice with an **Apply** action
for applicable types.

- [ ] **Step 6: Commit**

```bash
git add assets/src/editor/index.js
git commit -m "feat(editor): add schema type select and AI recommendation"
```

> `assets/build/` is git-ignored, so it is not committed.

---

## Task 13: Settings — "Schema" tab

**Files:**
- Modify: `src/Admin/SettingsPage.php`, `templates/admin/settings-page.php`
- Test: `tests/Integration/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Options` keys (Task 1).
- Produces: settings section `openseo_schema` with fields `schema_site_type`
  (select), `schema_site_name` (text), `schema_logo` (text), `breadcrumb_separator`
  (text); a new `add_select_field()` helper; a `schema` tab in the admin page.

- [ ] **Step 1: Write the failing integration test**

Add to `tests/Integration/SettingsPageTest.php` (inside the class):

```php
	public function test_schema_section_and_fields_register(): void {
		global $wp_settings_fields;

		$page = new SettingsPage( new Options() );
		$page->register_settings();

		$section_fields = $wp_settings_fields['openseo_schema']['openseo_schema'] ?? array();
		$this->assertArrayHasKey( 'schema_site_type', $section_fields );
		$this->assertArrayHasKey( 'schema_site_name', $section_fields );
		$this->assertArrayHasKey( 'schema_logo', $section_fields );
		$this->assertArrayHasKey( 'breadcrumb_separator', $section_fields );
	}
```

- [ ] **Step 2: Run it to verify it fails**

Run (wp-env up): `npm run test:integration -- --filter test_schema_section_and_fields_register`
Expected: FAIL — `openseo_schema` section absent.

- [ ] **Step 3: Register the section and fields**

In `src/Admin/SettingsPage.php`, inside `register_settings()`, after the
`add_settings_section( 'openseo_sitemaps', ... )` line, add:

```php
		add_settings_section( 'openseo_schema', __( 'Schema', 'openseo' ), '__return_false', 'openseo_schema' );
```

After the two `add_checkbox_field( 'sitemap_*', ... )` lines, add:

```php
		$this->add_select_field(
			'schema_site_type',
			__( 'Site represents', 'openseo' ),
			'openseo_schema',
			array(
				'Organization' => __( 'Organization', 'openseo' ),
				'Person'       => __( 'Person', 'openseo' ),
			)
		);
		$this->add_text_field( 'schema_site_name', __( 'Name (defaults to site name)', 'openseo' ), 'openseo_schema' );
		$this->add_text_field( 'schema_logo', __( 'Logo / image URL', 'openseo' ), 'openseo_schema' );
		$this->add_text_field( 'breadcrumb_separator', __( 'Breadcrumb separator', 'openseo' ), 'openseo_schema' );
```

- [ ] **Step 4: Add the select-field helper**

In `src/Admin/SettingsPage.php`, add this method right after `add_checkbox_field()`:

```php
	/**
	 * Register one select field bound to a single option key.
	 *
	 * @param string                $key     Option key name.
	 * @param string                $label   Field label text.
	 * @param string                $section Settings section ID.
	 * @param array<string, string> $choices value => label map.
	 */
	private function add_select_field( string $key, string $label, string $section, array $choices ): void {
		add_settings_field(
			$key,
			$label,
			function () use ( $key, $choices ): void {
				$current = (string) $this->options->get( $key );

				printf(
					'<select id="openseo_%1$s" name="%2$s[%1$s]">',
					esc_attr( $key ),
					esc_attr( Options::OPTION_KEY )
				);

				foreach ( $choices as $value => $choice_label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $value ),
						selected( $current, $value, false ),
						esc_html( $choice_label )
					);
				}

				echo '</select>';
			},
			$section,
			$section,
			array( 'label_for' => 'openseo_' . $key )
		);
	}
```

> `selected()` is a WPCS-recognized safe output function, so passing its return
> value into `printf` does not trip `WordPress.Security.EscapeOutput`.

- [ ] **Step 5: Add the tab to the template**

In `templates/admin/settings-page.php`, replace the `$openseo_tabs` array with:

```php
$openseo_tabs = array(
	'general'  => __( 'General', 'openseo' ),
	'titles'   => __( 'Titles & Meta', 'openseo' ),
	'social'   => __( 'Social', 'openseo' ),
	'sitemaps' => __( 'Sitemaps', 'openseo' ),
	'schema'   => __( 'Schema', 'openseo' ),
	'ai'       => __( 'AI', 'openseo' ),
);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `npm run test:integration -- --filter test_schema_section_and_fields_register`
Expected: PASS.

- [ ] **Step 7: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Admin/SettingsPage.php templates/admin/settings-page.php tests/Integration/SettingsPageTest.php
git commit -m "feat(admin): add Schema settings tab"
```

---

## Task 14: Full gates, docs, manual verification

**Files:**
- Modify: `CLAUDE.md`, `NOTES.md`

**Interfaces:**
- Consumes: everything above.
- Produces: a green full test/lint/analyze run and developer-facing docs.

- [ ] **Step 1: Run the full PHP gate**

Run: `composer check`
Expected: PHPCS clean, PHPStan "No errors", all unit tests green. Run
`composer lint:fix` for style nits.

- [ ] **Step 2: Run the full integration suite + JS tests + build**

```bash
npm run env:start
npm run test:integration
npm run test:js
npm run build
```
Expected: all green; `editor.js` and `breadcrumbs.js` build.

- [ ] **Step 3: Manual smoke test (optional but recommended)**

1. Publish a post; view source → confirm one `<script type="application/ld+json">`
   with an `@graph` containing WebSite, Organization, WebPage, Article,
   BreadcrumbList. Paste into Google's Rich Results Test if desired.
2. Editor → OpenSEO panel → Advanced → set **Schema type** to `None`, save,
   reload → the `Article` node is gone (WebPage remains).
3. Settings → OpenSEO → **Schema** → switch to **Person**, save → the graph's
   identity node becomes `Person`.
4. Add the **OpenSEO Breadcrumbs** block to a page (or call
   `openseo_breadcrumbs()` in a theme template) → a `<nav class="openseo-breadcrumbs">`
   renders with the configured separator.

- [ ] **Step 4: Document the phase**

In `CLAUDE.md`, under "Key modules", add bullets for the new layers:

```markdown
- `Schema/` — JSON-LD output. `Graph` (Hookable, `wp_head`) assembles small
  `Piece` objects (`WebSite`, `Organization`/`Person`, `WebPage`, `Article`,
  `BreadcrumbList`) into one connected `@graph` printed as a single
  `application/ld+json` script (`JSON_HEX_TAG` for script safety). `Ids`
  centralizes every `@id`. Pieces reuse the Phase 1 `Resolver` so structured data
  matches the head tags. The per-entry `_openseo_schema_type` meta (whitelist) and
  the `openseo/suggest-schema-type` ability drive the editor's type selector.
- `Breadcrumbs/` — `Trail` builds the hierarchy once; `Renderer` turns it into
  escaped `<nav><ol>`; consumed by the `openseo_breadcrumbs()` template function
  (`src/template-functions.php`, Composer `autoload.files`), the dynamic
  `openseo/breadcrumbs` block (`Breadcrumbs\Block`), and the `BreadcrumbList`
  schema piece.
```

In `NOTES.md`, add a subsection under section 5 ("Tests y calidad") titled
**"Schema + Breadcrumbs (Fase 4): qué cubre y cómo probar"**:

```markdown
### Schema + Breadcrumbs (Fase 4): qué cubre y cómo probar

OpenSEO emite un único `@graph` JSON-LD en `wp_head` (`src/Schema/`): WebSite,
Organization/Person (identidad configurable en *Settings → OpenSEO → Schema*),
WebPage, Article (tipo elegible por entrada en el panel del editor), y
BreadcrumbList. Las piezas reutilizan el `Resolver` de la Fase 1.

Los breadcrumbs (`src/Breadcrumbs/`) tienen una sola fuente (`Trail`), consumida
por la función de tema `openseo_breadcrumbs()`, el bloque dinámico
`openseo/breadcrumbs`, y la pieza `BreadcrumbList`.

La ability `openseo/suggest-schema-type` recomienda (no aplica) el tipo más rico
para una entrada; solo se llama on-demand desde el editor. Como en la Fase 2, CI
solo ejercita la ruta `openseo_no_connector` y la exposición REST.

Smoke test manual: publicar una entrada y ver el código fuente → un
`<script type="application/ld+json">` con `@graph`; cambiar el tipo de schema a
*None* y confirmar que desaparece el nodo Article; añadir el bloque de breadcrumbs
a una página.
```

- [ ] **Step 5: Commit the docs**

```bash
git add CLAUDE.md NOTES.md
git commit -m "docs: document Phase 4 schema and breadcrumbs"
```

---

## Self-Review

**Spec coverage (spec §-by-§):**
- §2 `@graph` único conectado → Task 8 (`Graph::build`) + pieces (Tasks 4, 5, 7). ✅
- §2 identidad Organization/Person en pestaña Schema → Tasks 4 (pieces), 1 (option), 13 (tab). ✅
- §2 ability "recomendar tipo más rico", on-demand, readonly:false por consistencia → Task 11. ✅
- §2 selector por entrada (Default/Article/BlogPosting/NewsArticle/WebPage/None) → Tasks 2 (meta+whitelist), 5 (pieces honor it), 12 (editor select). ✅
- §2 breadcrumbs config mínima (separador global + atributos de bloque + args de función) → Tasks 1, 9, 10. ✅
- §3 dos namespaces Hookable + composición → Tasks 8, 10 (Plugin wiring). ✅
- §4.1 tabla de nodo de contenido por selector → Task 5 (`Article::is_needed`/`type`, `WebPage` siempre). ✅
- §4.2 Trail única fuente para función/bloque/pieza → Tasks 6, 7, 9, 10. ✅
- §4.3 postmeta whitelist + ability + panel apiFetch `/run` → Tasks 2, 11, 12. ✅
- §4.4 pestaña Schema + `add_select_field` → Task 13. ✅
- §6 seguridad: `JSON_HEX_TAG`, esc en breadcrumbs, whitelist, `edit_post`, sin fallback → Tasks 8, 9, 2, 11. ✅
- §7 testing unit + integration (graph en head, Person, none suprime Article, bloque registrado, ability REST) → Tasks 8, 10, 11. ✅
- §8 lista de archivos → coincide con Tasks 1–14. ✅

**Placeholder scan:** no TBD/TODO; every code step shows complete code; every command has expected output.

**Type consistency:** `Piece` interface (`is_needed`/`id`/`data`) identical across Tasks 4, 5, 7, 8. `Ids::*` signatures (Task 3) called verbatim in Tasks 4, 5, 7. `Resolver` methods (`title`/`social_image`) match the existing class. `Trail::items()` (Task 6) consumed identically in Tasks 7, 9, 10. `Renderer::render( items, args )` (Task 9) called identically in Tasks 9, 10. `PostMeta::SCHEMA_TYPES` (Task 2) and the editor `SCHEMA_OPTIONS` values (Task 12) share the same six values. `Abilities::suggest_schema_type` output keys `type`/`reason` (Task 11) match the editor read (Task 12) and the output schema. `ability_meta()` reused for the new ability (Task 11) matches the Phase 2 shape asserted in Task 11's unit guard. The `openseo/suggest-schema-type` ability name is identical in Tasks 11 (register), 12 (apiFetch path), and the Task 11 REST test.

**Deviation from spec noted:** the breadcrumbs block is registered via PHP
`register_block_type` args (render_callback + a compiled `editor_script` handle)
rather than `block.json` + `register_block_type_from_metadata`. This is more
robust with the project's custom `webpack.config.js` (which overrides `entry` and
would not reliably trigger `@wordpress/scripts`' block.json copy step). The block
attributes are declared in both PHP (Task 10 Step 3) and JS (Task 10 Step 5) to
keep the server and editor definitions in sync — a small, deliberate duplication.
