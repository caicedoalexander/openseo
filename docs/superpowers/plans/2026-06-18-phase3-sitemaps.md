# Phase 3 — XML Sitemaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Customize WordPress core's native XML sitemap so it excludes `noindex` entries, can be toggled off entirely, and drops the author sub-sitemap by default — without OpenSEO printing any XML of its own.

**Architecture:** A single new `Hookable` module (`OpenSEO\Sitemap\Sitemap`) hooks three core filters (`wp_sitemaps_enabled`, `wp_sitemaps_add_provider`, `wp_sitemaps_posts_query_args`). Two new boolean settings live under the existing single `openseo_settings` option and surface as checkboxes on a new "Sitemaps" tab. Core keeps doing all rendering, pagination, escaping, and `robots.txt` advertising.

**Tech Stack:** PHP 8.1+, WordPress 7.0+ (`WP_Sitemaps`), Composer/PSR-4 (`OpenSEO\` → `src/`), PHPUnit + Brain Monkey (unit) and the WP test suite via wp-env (integration), PHPCS (WPCS) + PHPStan level 6.

## Global Constraints

- **PHP** 8.1+; **WordPress** 7.0+. `declare( strict_types=1 );` in every PHP file.
- **PSR-4** file naming: one class per file, path mirrors namespace under `src/`.
- **Single option key:** all settings under `openseo_settings` (`Options::OPTION_KEY`); typed reads merge over `defaults()`, writes go through `sanitize()`.
- **Security:** sanitize on input, escape on output; read explicit `$_POST`/`$_GET` keys with `wp_unslash` — never the whole array. Settings already gated by `current_user_can('manage_options')` + the Settings API nonce.
- **Module discovery:** a feature exists only once it is added to `Plugin::modules()`. Front-end modules are built before the `is_admin()` guard.
- **Prefixes:** `openseo` / `OpenSEO` / `OPENSEO` and text domain `openseo` (enforced by PHPCS).
- **Quality gates kept green before each commit:** `composer lint`, `composer analyze`, `composer test:unit`. PHPStan runs with `--memory-limit=1G` (already wired in the composer script).
- **No new XML output, no ping, no per-type/taxonomy toggles, no `lastmod`/`changefreq`/`priority`** (spec §2 non-goals).

---

### Task 1: `Sitemap` module — filter logic + unit tests

**Files:**
- Create: `src/Sitemap/Sitemap.php`
- Test: `tests/Unit/Sitemap/SitemapTest.php`

**Interfaces:**
- Consumes: `OpenSEO\Contracts\Hookable` (`register(): void`), `OpenSEO\Settings\Options` (`get( string ): mixed`).
- Produces:
  - `Sitemap::__construct( Options $options )`
  - `Sitemap::register(): void` — adds the three core filters.
  - `Sitemap::is_enabled( mixed $core_enabled ): bool` — callback for `wp_sitemaps_enabled`.
  - `Sitemap::filter_provider( mixed $provider, mixed $name ): mixed` — callback for `wp_sitemaps_add_provider`.
  - `Sitemap::exclude_noindex( mixed $args ): array` — callback for `wp_sitemaps_posts_query_args`.

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/Sitemap/SitemapTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Sitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Settings\Options;
use OpenSEO\Sitemap\Sitemap;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Sitemap whose Options reads the given stored settings array.
	 *
	 * @param array<string, mixed> $settings Stored option array.
	 */
	private function sitemap_with( array $settings ): Sitemap {
		Functions\when( 'get_option' )->justReturn( $settings );

		return new Sitemap( new Options() );
	}

	public function test_is_enabled_off_when_master_toggle_disabled(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_enabled' => '' ) );

		$this->assertFalse( $sitemap->is_enabled( true ) );
	}

	public function test_is_enabled_respects_core_when_master_toggle_on(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_enabled' => '1' ) );

		$this->assertTrue( $sitemap->is_enabled( true ) );
		$this->assertFalse( $sitemap->is_enabled( false ) );
	}

	public function test_filter_provider_removes_users_when_authors_disabled(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_include_authors' => '' ) );

		$this->assertFalse( $sitemap->filter_provider( 'provider', 'users' ) );
		$this->assertSame( 'provider', $sitemap->filter_provider( 'provider', 'posts' ) );
	}

	public function test_filter_provider_keeps_users_when_authors_enabled(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_include_authors' => '1' ) );

		$this->assertSame( 'provider', $sitemap->filter_provider( 'provider', 'users' ) );
	}

	public function test_exclude_noindex_builds_or_clause(): void {
		$sitemap = $this->sitemap_with( array() );

		$args = $sitemap->exclude_noindex( array( 'post_type' => 'post' ) );

		$this->assertSame( 'post', $args['post_type'] );
		$this->assertSame( 'OR', $args['meta_query']['relation'] );
		$this->assertSame( '_openseo_robots_noindex', $args['meta_query'][0]['key'] );
		$this->assertSame( 'NOT EXISTS', $args['meta_query'][0]['compare'] );
		$this->assertSame( '1', $args['meta_query'][1]['value'] );
		$this->assertSame( '!=', $args['meta_query'][1]['compare'] );
	}

	public function test_exclude_noindex_preserves_existing_meta_query(): void {
		$sitemap  = $this->sitemap_with( array() );
		$existing = array( array( 'key' => 'other', 'value' => 'x' ) );

		$args = $sitemap->exclude_noindex( array( 'meta_query' => $existing ) );

		$this->assertSame( 'AND', $args['meta_query']['relation'] );
		$this->assertSame( $existing, $args['meta_query'][0] );
		$this->assertSame( 'OR', $args['meta_query'][1]['relation'] );
	}

	public function test_exclude_noindex_normalizes_non_array_args(): void {
		$sitemap = $this->sitemap_with( array() );

		$args = $sitemap->exclude_noindex( null );

		$this->assertArrayHasKey( 'meta_query', $args );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter SitemapTest tests/Unit/Sitemap/SitemapTest.php`
Expected: FAIL — `Class "OpenSEO\Sitemap\Sitemap" not found`.

- [ ] **Step 3: Write the module**

Create `src/Sitemap/Sitemap.php`:

```php
<?php
/**
 * Customizes WordPress core's native XML sitemap.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Sitemap;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Tunes the native WP_Sitemaps output through core's filters: a master on/off
 * switch, author-sitemap removal, and exclusion of noindexed entries. OpenSEO
 * never prints its own XML — core handles rendering, pagination, and escaping.
 */
final class Sitemap implements Hookable {

	/**
	 * Per-entry meta key set when an entry is marked noindex.
	 */
	private const NOINDEX_META_KEY = '_openseo_robots_noindex';

	/**
	 * Initialize the module with the settings accessor.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Hook OpenSEO's adjustments onto core's sitemap filters.
	 */
	public function register(): void {
		add_filter( 'wp_sitemaps_enabled', array( $this, 'is_enabled' ) );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'filter_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_noindex' ) );
	}

	/**
	 * Force the sitemap off when the master toggle is disabled.
	 *
	 * Loosely typed: WordPress passes whatever the previous filter returned, so a
	 * strict scalar hint could fatal under strict_types.
	 *
	 * @param mixed $core_enabled Whether core currently considers sitemaps enabled.
	 */
	public function is_enabled( $core_enabled ): bool {
		if ( '1' !== (string) $this->options->get( 'sitemap_enabled' ) ) {
			return false;
		}

		return (bool) $core_enabled;
	}

	/**
	 * Drop the authors ("users") provider unless the setting opts in.
	 *
	 * @param mixed $provider Provider instance core is about to register.
	 * @param mixed $name     Provider name ("posts" | "taxonomies" | "users").
	 * @return mixed The provider, or false to skip registering it.
	 */
	public function filter_provider( $provider, $name ) {
		if ( 'users' === $name && '1' !== (string) $this->options->get( 'sitemap_include_authors' ) ) {
			return false;
		}

		return $provider;
	}

	/**
	 * Exclude noindexed entries from the posts sub-sitemap.
	 *
	 * The OR clause keeps entries WITHOUT the meta (the majority) and entries
	 * whose value is not exactly '1'; only '1' is excluded. Any meta_query already
	 * present is preserved under an AND relation.
	 *
	 * @param mixed $args WP_Query args core will run for the post type.
	 * @return array<string, mixed> Args with the noindex exclusion merged in.
	 */
	public function exclude_noindex( $args ): array {
		$args = is_array( $args ) ? $args : array();

		$exclusion = array(
			'relation' => 'OR',
			array(
				'key'     => self::NOINDEX_META_KEY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::NOINDEX_META_KEY,
				'value'   => '1',
				'compare' => '!=',
			),
		);

		if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				'relation' => 'AND',
				$args['meta_query'],
				$exclusion,
			);
		} else {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = $exclusion;
		}

		return $args;
	}
}
```

> Note: these callbacks take `mixed` params (refining the spec §3 signatures,
> which showed `bool`/`string`) because WordPress invokes filter callbacks with
> unguaranteed types at the plugin boundary — the same defensive pattern already
> used by `PostMeta::can_edit`. Each method casts the value it needs internally.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SitemapTest tests/Unit/Sitemap/SitemapTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Lint and static-analyze the new file**

Run: `composer lint && composer analyze`
Expected: no PHPCS errors and PHPStan "No errors". If PHPCS reports array-alignment nits, run `composer lint:fix` and re-run `composer lint`.

- [ ] **Step 6: Commit**

```bash
git add src/Sitemap/Sitemap.php tests/Unit/Sitemap/SitemapTest.php
git commit -m "feat(sitemap): add core-sitemap filters for noindex, authors, and on/off"
```

---

### Task 2: `Options` — sitemap defaults + checkbox sanitization

**Files:**
- Modify: `src/Settings/Options.php` (`defaults()` and `sanitize()`)
- Test: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: two settings keys readable via `Options::get()` — `sitemap_enabled` (default `'1'`) and `sitemap_include_authors` (default `''`), both normalized to `'1'`/`''` by `sanitize()`.

- [ ] **Step 1: Write the failing unit tests**

Add these two methods to `tests/Unit/OptionsTest.php` (inside the `OptionsTest` class, after `test_sanitize_preserves_keys_absent_from_a_partial_tab_submission`):

```php
	public function test_sitemap_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( '1', $options->get( 'sitemap_enabled' ) );
		$this->assertSame( '', $options->get( 'sitemap_include_authors' ) );
	}

	public function test_sanitize_normalizes_sitemap_checkboxes(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$on = $options->sanitize(
			array(
				'sitemap_enabled'         => '1',
				'sitemap_include_authors' => '1',
			)
		);
		$this->assertSame( '1', $on['sitemap_enabled'] );
		$this->assertSame( '1', $on['sitemap_include_authors'] );

		$off = $options->sanitize(
			array(
				'sitemap_enabled'         => '0',
				'sitemap_include_authors' => '0',
			)
		);
		$this->assertSame( '', $off['sitemap_enabled'] );
		$this->assertSame( '', $off['sitemap_include_authors'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsTest tests/Unit/OptionsTest.php`
Expected: FAIL — `test_sitemap_defaults` gets `null` (key missing), `test_sanitize_normalizes_sitemap_checkboxes` gets a missing/unset index.

- [ ] **Step 3: Add the defaults**

In `src/Settings/Options.php`, replace the whole `defaults()` return array with this (adds the two keys and re-aligns the arrows):

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
			'ai_model'                => '',
		);
```

- [ ] **Step 4: Add the checkbox sanitization**

In `src/Settings/Options.php`, inside `sanitize()`, immediately after the existing `foreach ( array( 'title_separator', ... 'ai_model' ) ... )` loop and before the `if ( isset( $input['og_default_image'] ) )` block, insert:

```php
		// Checkboxes: a hidden companion field guarantees the key is present (0 or
		// 1) when its tab is submitted, so an explicit '1' check turns it on/off.
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = '1' === $input[ $key ] ? '1' : '';
			}
		}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter OptionsTest tests/Unit/OptionsTest.php`
Expected: PASS (all OptionsTest methods, including the two new ones).

- [ ] **Step 6: Lint and static-analyze**

Run: `composer lint && composer analyze`
Expected: clean. Run `composer lint:fix` first if the arrow re-alignment trips PHPCS.

- [ ] **Step 7: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(settings): add sitemap_enabled and sitemap_include_authors options"
```

---

### Task 3: Settings UI — "Sitemaps" tab with two checkboxes

**Files:**
- Modify: `src/Admin/SettingsPage.php` (new section, new `add_checkbox_field` helper, two fields)
- Modify: `templates/admin/settings-page.php` (new tab entry)
- Test: `tests/Integration/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Options::OPTION_KEY`, `Options::get()`.
- Produces: settings section `openseo_sitemaps` with fields `sitemap_enabled` and `sitemap_include_authors`; a `sitemaps` tab in the admin page.

- [ ] **Step 1: Write the failing integration test**

Add this method to `tests/Integration/SettingsPageTest.php` (inside the class):

```php
	public function test_sitemaps_section_and_fields_register(): void {
		global $wp_settings_fields;

		$page = new SettingsPage( new Options() );
		$page->register_settings();

		$section_fields = $wp_settings_fields['openseo_sitemaps']['openseo_sitemaps'] ?? array();
		$this->assertArrayHasKey( 'sitemap_enabled', $section_fields );
		$this->assertArrayHasKey( 'sitemap_include_authors', $section_fields );
	}
```

- [ ] **Step 2: Run it to verify it fails**

Run (requires wp-env running — `npm run env:start`): `npm run test:integration -- --filter test_sitemaps_section_and_fields_register`
Expected: FAIL — `openseo_sitemaps` key absent from `$wp_settings_fields`.

> If Docker/wp-env is unavailable, note the test is written and proceed; it will be exercised in Task 6's integration run.

- [ ] **Step 3: Register the section and fields**

In `src/Admin/SettingsPage.php`, inside `register_settings()`, add the section right after the existing `add_settings_section( 'openseo_ai', ... )` line:

```php
		add_settings_section( 'openseo_sitemaps', __( 'Sitemaps', 'openseo' ), '__return_false', 'openseo_sitemaps' );
```

Then, after the existing `$this->add_text_field( 'ai_model', ... )` line, add:

```php
		$this->add_checkbox_field( 'sitemap_enabled', __( 'Enable XML sitemap', 'openseo' ), 'openseo_sitemaps' );
		$this->add_checkbox_field( 'sitemap_include_authors', __( 'Include author sitemap', 'openseo' ), 'openseo_sitemaps' );
```

- [ ] **Step 4: Add the checkbox field helper**

In `src/Admin/SettingsPage.php`, add this private method right after the existing `add_text_field()` method:

```php
	/**
	 * Register one checkbox field bound to a single option key.
	 *
	 * A hidden companion field is emitted before the checkbox so the key is always
	 * submitted ('0' when unchecked, '1' when checked); this lets the tab-scoped
	 * sanitizer turn the box off, which a bare checkbox could never do.
	 *
	 * @param string $key     Option key name.
	 * @param string $label   Field label text.
	 * @param string $section Settings section ID.
	 */
	private function add_checkbox_field( string $key, string $label, string $section ): void {
		add_settings_field(
			$key,
			$label,
			function () use ( $key ): void {
				printf(
					'<input type="hidden" name="%1$s[%2$s]" value="0" />'
					. '<input type="checkbox" id="openseo_%2$s" name="%1$s[%2$s]" value="1"%3$s />',
					esc_attr( Options::OPTION_KEY ),
					esc_attr( $key ),
					checked( '1', (string) $this->options->get( $key ), false )
				);
			},
			$section,
			$section,
			array( 'label_for' => 'openseo_' . $key )
		);
	}
```

> `checked()` is recognized as a safe output function by WPCS, so passing its
> return value as a `printf` argument does not trip `WordPress.Security.EscapeOutput`.

- [ ] **Step 5: Add the tab to the template**

In `templates/admin/settings-page.php`, replace the `$openseo_tabs` array with:

```php
$openseo_tabs = array(
	'general'  => __( 'General', 'openseo' ),
	'titles'   => __( 'Titles & Meta', 'openseo' ),
	'social'   => __( 'Social', 'openseo' ),
	'sitemaps' => __( 'Sitemaps', 'openseo' ),
	'ai'       => __( 'AI', 'openseo' ),
);
```

(The rest of the template already iterates `$openseo_tabs` and renders `do_settings_sections( 'openseo_' . $openseo_active )`, so no other change is needed.)

- [ ] **Step 6: Run the integration test to verify it passes**

Run: `npm run test:integration -- --filter test_sitemaps_section_and_fields_register`
Expected: PASS.

- [ ] **Step 7: Lint and static-analyze**

Run: `composer lint && composer analyze`
Expected: clean (run `composer lint:fix` if needed).

- [ ] **Step 8: Commit**

```bash
git add src/Admin/SettingsPage.php templates/admin/settings-page.php tests/Integration/SettingsPageTest.php
git commit -m "feat(admin): add Sitemaps settings tab with master and author toggles"
```

---

### Task 4: Wire the module into the plugin

**Files:**
- Modify: `src/Plugin.php` (import + add to front-end modules)
- Test: `tests/Integration/PluginBootTest.php`

**Interfaces:**
- Consumes: `Sitemap::__construct( Options $options )` (Task 1), the existing `$options` instance built in `modules()`.
- Produces: the sitemap filters are registered on every boot (front-end and admin), so the live `wp-sitemap.xml` reflects OpenSEO's adjustments.

- [ ] **Step 1: Write the failing integration test**

Add this method to `tests/Integration/PluginBootTest.php` (inside the class):

```php
	public function test_sitemap_filters_are_registered_after_boot(): void {
		$this->assertNotFalse( has_filter( 'wp_sitemaps_posts_query_args' ) );
		$this->assertNotFalse( has_filter( 'wp_sitemaps_add_provider' ) );
		$this->assertNotFalse( has_filter( 'wp_sitemaps_enabled' ) );
	}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:integration -- --filter test_sitemap_filters_are_registered_after_boot`
Expected: FAIL — `has_filter()` returns `false` because the module is not wired yet.

- [ ] **Step 3: Import and register the module**

In `src/Plugin.php`, add the import alongside the other `use` statements (keep them alphabetically ordered — place it after the `use OpenSEO\Settings\Options;` line or wherever alphabetical order puts `Sitemap`):

```php
use OpenSEO\Sitemap\Sitemap;
```

Then, in `modules()`, add `new Sitemap( $options )` to the front-end `$modules` array (the one built before the `is_admin()` guard), right after the `new Abilities( $options ),` entry:

```php
			new Abilities( $options ),
			new Sitemap( $options ),
		);
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:integration -- --filter test_sitemap_filters_are_registered_after_boot`
Expected: PASS.

- [ ] **Step 5: Static-analyze (import + usage must type-check)**

Run: `composer analyze && composer lint`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Plugin.php tests/Integration/PluginBootTest.php
git commit -m "feat(sitemap): register the Sitemap module in the composition root"
```

---

### Task 5: Integration tests for live sitemap behavior

**Files:**
- Create: `tests/Integration/SitemapTest.php`

**Interfaces:**
- Consumes: the sitemap filters registered by the booted plugin (Task 4), `Options::OPTION_KEY`, core `WP_Sitemaps` / `WP_Sitemaps_Posts`.
- Produces: end-to-end coverage that noindex exclusion, author-provider removal, and the master toggle behave against a real WordPress runtime.

- [ ] **Step 1: Write the integration tests**

Create `tests/Integration/SitemapTest.php`:

```php
<?php
/**
 * Integration tests for the XML sitemap customizations.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Settings\Options;
use WP_Sitemaps;
use WP_Sitemaps_Posts;
use WP_UnitTestCase;

final class SitemapTest extends WP_UnitTestCase {

	/**
	 * The booted plugin already registers the sitemap filters globally (proven by
	 * PluginBootTest::test_sitemap_filters_are_registered_after_boot), so the tests
	 * drive behavior through the live option instead of re-registering the module.
	 * setUp only resets the option to a known baseline (authors off, sitemap on).
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( Options::OPTION_KEY );
	}

	public function test_noindexed_post_is_excluded_from_post_url_list(): void {
		$visible = self::factory()->post->create();
		$hidden  = self::factory()->post->create();
		update_post_meta( $hidden, '_openseo_robots_noindex', '1' );

		$provider = new WP_Sitemaps_Posts();
		$locs     = wp_list_pluck( $provider->get_url_list( 'post' ), 'loc' );

		$this->assertContains( get_permalink( $visible ), $locs );
		$this->assertNotContains( get_permalink( $hidden ), $locs );
	}

	public function test_users_provider_is_removed_by_default(): void {
		$sitemaps = new WP_Sitemaps();
		$sitemaps->register_sitemaps();

		$providers = $sitemaps->registry->get_providers();
		$this->assertArrayNotHasKey( 'users', $providers );
		$this->assertArrayHasKey( 'posts', $providers );
	}

	public function test_users_provider_is_present_when_authors_enabled(): void {
		update_option( Options::OPTION_KEY, array( 'sitemap_include_authors' => '1' ) );

		$sitemaps = new WP_Sitemaps();
		$sitemaps->register_sitemaps();

		$this->assertArrayHasKey( 'users', $sitemaps->registry->get_providers() );
	}

	public function test_master_toggle_off_disables_sitemaps(): void {
		update_option( Options::OPTION_KEY, array( 'sitemap_enabled' => '' ) );

		$this->assertFalse( apply_filters( 'wp_sitemaps_enabled', true ) );
	}

	public function test_master_toggle_on_keeps_core_value(): void {
		update_option( Options::OPTION_KEY, array( 'sitemap_enabled' => '1' ) );

		$this->assertTrue( apply_filters( 'wp_sitemaps_enabled', true ) );
	}
}
```

> Note: the sitemap filters are registered exactly once, by the booted plugin
> (Task 4) — the tests do not re-register the module, so there is no second filter
> instance to reason about. Each test runs inside a rolled-back DB transaction, so
> the `setUp()` `delete_option` and any `update_option` changes never leak between
> tests.

- [ ] **Step 2: Run the new tests**

Run (wp-env up): `npm run test:integration -- --filter SitemapTest`
Expected: PASS (5 tests). If `get_url_list()` loc strings mismatch `get_permalink()` because of a non-default permalink structure, confirm the test environment uses the default (plain) permalinks — both sides use `get_permalink()`, so they match under any structure.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/SitemapTest.php
git commit -m "test(sitemap): cover noindex exclusion, author provider, and master toggle"
```

---

### Task 6: Full quality gates, docs, and manual verification

**Files:**
- Modify: `NOTES.md` (Phase 3 section + manual verification steps)

**Interfaces:**
- Consumes: everything above.
- Produces: a green full test/lint/analyze run and developer-facing docs for the new behavior.

- [ ] **Step 1: Run the full PHP gate**

Run: `composer check`
Expected: PHPCS clean, PHPStan "No errors", all unit tests green. Fix any issue before continuing (run `composer lint:fix` for style nits).

- [ ] **Step 2: Run the full integration suite**

Run (wp-env up): `npm run test:integration`
Expected: the whole suite green, including `SitemapTest`, `SettingsPageTest`, and `PluginBootTest`.

- [ ] **Step 3: Manual smoke test (optional but recommended)**

1. `npm run env:start`, log in at http://localhost:8888/wp-admin (admin/password).
2. Publish a post; visit http://localhost:8888/wp-sitemap.xml → it lists `wp-sitemap-posts-post-1.xml` and **no** `wp-sitemap-users-*.xml` (authors off by default).
3. Edit the post → OpenSEO panel → mark **noindex** → save. Reload `wp-sitemap-posts-post-1.xml` → the post is gone.
4. Settings → OpenSEO → **Sitemaps** tab → untick **Enable XML sitemap** → save → visit `/wp-sitemap.xml` → 404 (disabled). Re-tick to restore.
5. Tick **Include author sitemap** → save → `/wp-sitemap.xml` now lists a users sub-sitemap.

- [ ] **Step 4: Document the phase in NOTES.md**

In `NOTES.md`, add a subsection under section 5 ("Tests y calidad") titled **"Sitemaps (Fase 3): qué cubre y cómo probar"** with this content:

```markdown
### Sitemaps (Fase 3): qué cubre y cómo probar

OpenSEO no genera XML propio: personaliza el sitemap nativo de WordPress
(`WP_Sitemaps`, URL `wp-sitemap.xml`) vía los filtros `wp_sitemaps_*` desde
`src/Sitemap/Sitemap.php`. Tres comportamientos:

- **Excluye `noindex`:** las entradas con `_openseo_robots_noindex = '1'` se
  omiten del sub-sitemap de posts (`wp_sitemaps_posts_query_args`).
- **Master on/off:** la pestaña *Settings → OpenSEO → Sitemaps* permite
  desactivar todo el sitemap (`wp_sitemaps_enabled`).
- **Autores fuera por defecto:** el sub-sitemap de usuarios se quita salvo que se
  active en esa misma pestaña (`wp_sitemaps_add_provider`).

El descubrimiento ya lo cubre el `robots.txt` virtual de core (`Sitemap:
…/wp-sitemap.xml`); no hay ping a buscadores (obsoleto). IndexNow queda en "Futuro".

Smoke test manual: publicar una entrada y abrir `/wp-sitemap.xml`; marcar la
entrada como noindex y confirmar que desaparece del sub-sitemap de posts.
```

- [ ] **Step 5: Commit the docs**

```bash
git add NOTES.md
git commit -m "docs: document Phase 3 sitemap behavior and manual testing"
```

---

## Self-Review

**Spec coverage (spec §-by-§):**
- §1/§3 motor = extend core via filters → Task 1 (three filters). ✅
- §2 exclude noindex (always) → Task 1 `exclude_noindex` + Task 5 integration. ✅
- §2 master on/off → Task 1 `is_enabled` + Task 2 option + Task 3 checkbox + Task 5. ✅
- §2 disable authors (off by default) → Task 1 `filter_provider` + Task 2 default `''` + Task 3 checkbox + Task 5. ✅
- §2 non-goals (no ping, no per-type toggles, no lastmod, no term overrides, no custom XML) → nothing implements them; Global Constraints restate them. ✅
- §4 robust OR meta_query incl. defensive merge → Task 1 + unit tests `test_exclude_noindex_*`. ✅
- §5 Options keys + checkbox hidden-companion sanitize → Task 2 + Task 3. ✅
- §5 new "Sitemaps" tab in template → Task 3 Step 5. ✅
- §6 degradation/security (no own XML, settings gated) → inherited from existing Settings API wiring; Task 1 prints nothing. ✅
- §7 unit (is_enabled/filter_provider/exclude_noindex/Options) + integration (noindex absent, normal present, users removed, master off) → Tasks 1, 2, 5. ✅
- §8 files-touched list → matches Tasks 1–6 exactly. ✅
- §9 roadmap note (drop ping) → reflected in NOTES.md (Task 6) and Global Constraints. ✅

**Placeholder scan:** no TBD/TODO; every code step shows complete code; commands have expected output. ✅

**Type consistency:** `is_enabled(mixed): bool`, `filter_provider(mixed,mixed): mixed`, `exclude_noindex(mixed): array`, `add_checkbox_field(string,string,string): void`, option keys `sitemap_enabled` / `sitemap_include_authors`, meta key `_openseo_robots_noindex`, section id `openseo_sitemaps`, tab slug `sitemaps` — all identical across tasks and tests. ✅
