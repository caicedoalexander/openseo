# Titles & Meta — Templates por tipo de contenido (cimiento) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a cada tipo de contenido público (Posts, Pages, CPTs) y a cada taxonomía pública (Categorías, Tags, taxonomías custom) su propio template de título y meta-descripción, con default por tipo en runtime, retirando el template global único.

**Architecture:** Capa de datos en `openseo_settings` con dos mapas anidados (`post_types`, `taxonomies`); lógica pura aislada (`TemplateDefaults`, `TemplateContext`) para unit tests sin WordPress; `Resolver` elige el template según la entidad de la request; migración idempotente del template global; UI admin React mínima sobre el REST existente.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey (unit, sin WP), PHPStan nivel 6, `@wordpress/scripts` (React/JS + Jest), WordPress 7.0.

## Global Constraints

- Versiones objetivo: **WordPress 7.0+**, **PHP 8.1+**. `declare(strict_types=1);` en todo PHP nuevo.
- Todos los ajustes bajo la única opción `openseo_settings` (`Options::OPTION_KEY`).
- Prefijos globales `openseo` / `OpenSEO` / `OPENSEO` y text domain `openseo` (enforced por PHPCS).
- Seguridad: sanitizar en entrada, escapar en salida; nunca procesar `$_POST`/`$_GET` completos.
- Gates verdes antes de cada commit que toque su capa: `composer lint`, `composer analyze` (PHPStan 6, `--memory-limit=1G`), `composer test:unit`; para JS `npm run lint:js` y `npm run test:js`.
- Sin atribución en mensajes de commit (deshabilitada globalmente). Conventional commits.
- Cada módulo nuevo `Hookable` se registra en `Plugin::modules()`; nada más descubre módulos.

---

## File Structure

**PHP (nuevos):**
- `src/Meta/TemplateDefaults.php` — defaults de template por superficie (puro).
- `src/Meta/TemplateContext.php` — value object inmutable con primitivos (post/term/none).
- `src/Settings/ContentTypes.php` — única fuente de tipos/taxonomías elegibles (`public` menos `attachment`).
- `src/Lifecycle/SettingsMigrations.php` — migración del template global; gate `openseo_settings_version` en `init`.

**PHP (modificados):**
- `src/Meta/Variables.php` — tokens `%term%`/`%term_description%`; firma `replace(string, ?TemplateContext)`.
- `src/Meta/Resolver.php` — selección por tipo/taxonomía + cascada; dep `TemplateDefaults`.
- `src/Settings/Options.php` — defaults `post_types`/`taxonomies`; retiro de `title_template`/`description_template`; sanitize anidado.
- `src/Admin/Assets.php` — bootstrap `contentTypes`.
- `src/Plugin.php` — construir nuevas deps y registrar `SettingsMigrations`.
- `tests/bootstrap-unit.php` — polyfill `WP_Term`.

**JS (nuevos):**
- `assets/src/admin/templateFields.js` — merge inmutable de un campo de un slug.
- `assets/src/admin/components/TemplateGroup.js` — grupo de campos por tipo/taxonomía.

**JS (modificados):**
- `assets/src/admin/views/Titles.js` — reorganización (global + tipos + taxonomías).

**Tests (nuevos):**
- `tests/Unit/Meta/TemplateDefaultsTest.php`
- `tests/Unit/Meta/TemplateContextTest.php`
- `tests/Unit/Settings/ContentTypesTest.php`
- `tests/Unit/Lifecycle/SettingsMigrationsTest.php`
- `assets/src/admin/templateFields.test.js`

**Tests (modificados):**
- `tests/Unit/Meta/VariablesTest.php`
- `tests/Unit/Meta/ResolverTest.php`
- `tests/Unit/OptionsTest.php` — tests anidados nuevos (Task 5) + retiro de asserts de las claves globales (Task 7). **El OptionsTest existente vive aquí, no en `Settings/`; se extiende, no se duplica.**
- `tests/Unit/Frontend/Head/DescriptionTest.php` — 3er arg del `Resolver` + mocks de taxonomía (Task 6)
- `tests/Unit/Frontend/Head/RobotsTest.php` — 3er arg del `Resolver` (Task 6)
- `tests/Unit/Schema/Pieces/ContentPiecesTest.php` — 3er arg del `Resolver` + mocks de taxonomía (Task 6)
- `tests/Integration/RestSettingsTest.php` — clave de ejemplo superviviente (Task 7)

---

## Task 1: `TemplateDefaults` — defaults por superficie

**Files:**
- Create: `src/Meta/TemplateDefaults.php`
- Test: `tests/Unit/Meta/TemplateDefaultsTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `OpenSEO\Meta\TemplateDefaults` con `singular_title(): string`, `singular_description(): string`, `taxonomy_title(): string`, `taxonomy_description(): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use OpenSEO\Meta\TemplateDefaults;
use PHPUnit\Framework\TestCase;

final class TemplateDefaultsTest extends TestCase {

	public function test_singular_defaults(): void {
		$d = new TemplateDefaults();
		$this->assertSame( '%title% %sep% %sitename%', $d->singular_title() );
		$this->assertSame( '%excerpt%', $d->singular_description() );
	}

	public function test_taxonomy_defaults(): void {
		$d = new TemplateDefaults();
		$this->assertSame( '%term% %sep% %sitename%', $d->taxonomy_title() );
		$this->assertSame( '%term_description%', $d->taxonomy_description() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TemplateDefaultsTest`
Expected: FAIL — `Class "OpenSEO\Meta\TemplateDefaults" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Per-surface default title/description templates.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

/**
 * Pure source of the default templates used when a content type or taxonomy
 * has no stored template. No WordPress dependency, so it is unit-testable and
 * is the single source of truth shared by the Resolver and the admin bootstrap.
 */
final class TemplateDefaults {

	public function singular_title(): string {
		return '%title% %sep% %sitename%';
	}

	public function singular_description(): string {
		return '%excerpt%';
	}

	public function taxonomy_title(): string {
		return '%term% %sep% %sitename%';
	}

	public function taxonomy_description(): string {
		return '%term_description%';
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TemplateDefaultsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Meta/TemplateDefaults.php tests/Unit/Meta/TemplateDefaultsTest.php
git commit -m "feat(meta): add TemplateDefaults for per-surface template defaults"
```

---

## Task 2: `TemplateContext` value object (+ `WP_Term` polyfill)

**Files:**
- Modify: `tests/bootstrap-unit.php` (append `WP_Term` polyfill after the `WP_Post` block, around line 51)
- Create: `src/Meta/TemplateContext.php`
- Test: `tests/Unit/Meta/TemplateContextTest.php`

**Interfaces:**
- Consumes: WP funcs `get_the_title`, `get_the_excerpt`, `wp_strip_all_tags`; class `WP_Term` (props `name`, `description`).
- Produces: `OpenSEO\Meta\TemplateContext` with readonly props `post_id:int`, `title:string`, `excerpt:string`, `term_name:string`, `term_description:string`; static factories `for_post(int): self`, `for_term(\WP_Term): self`, `none(): self`.

- [ ] **Step 1: Add the `WP_Term` polyfill to the unit bootstrap**

Append to `tests/bootstrap-unit.php` (after the closing `}` of the `WP_Post` polyfill):

```php

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {

		public int $term_id = 0;

		public string $name = '';

		public string $description = '';

		public string $taxonomy = '';
	}
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateContext;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class TemplateContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_for_post_reads_primitives(): void {
		Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
		Functions\when( 'get_the_excerpt' )->justReturn( '<p>Summary.</p>' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$ctx = TemplateContext::for_post( 42 );

		$this->assertSame( 42, $ctx->post_id );
		$this->assertSame( 'Hello World', $ctx->title );
		$this->assertSame( '<p>Summary.</p>', $ctx->excerpt );
		$this->assertSame( '', $ctx->term_name );
	}

	public function test_for_term_extracts_name_and_description(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$term              = new WP_Term();
		$term->name        = 'News';
		$term->description = 'All news.';

		$ctx = TemplateContext::for_term( $term );

		$this->assertSame( 0, $ctx->post_id );
		$this->assertSame( 'News', $ctx->term_name );
		$this->assertSame( 'All news.', $ctx->term_description );
		$this->assertSame( '', $ctx->title );
	}

	public function test_none_is_all_empty(): void {
		$ctx = TemplateContext::none();

		$this->assertSame( 0, $ctx->post_id );
		$this->assertSame( '', $ctx->title );
		$this->assertSame( '', $ctx->term_name );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TemplateContextTest`
Expected: FAIL — `Class "OpenSEO\Meta\TemplateContext" not found`.

- [ ] **Step 4: Write minimal implementation**

```php
<?php
/**
 * Immutable rendering context for template variables.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use WP_Term;

/**
 * Carries the primitives a template needs (title, excerpt, term name/description)
 * without retaining WP_Post/WP_Term, so Variables stays pure and unit-testable.
 * All WordPress reads happen in the factories.
 */
final class TemplateContext {

	private function __construct(
		public readonly int $post_id,
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $term_name,
		public readonly string $term_description,
	) {}

	/**
	 * Context for a singular post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function for_post( int $post_id ): self {
		return new self(
			$post_id,
			(string) get_the_title( $post_id ),
			wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ),
			'',
			'',
		);
	}

	/**
	 * Context for a taxonomy term archive.
	 *
	 * @param WP_Term $term Queried term.
	 */
	public static function for_term( WP_Term $term ): self {
		return new self(
			0,
			'',
			'',
			(string) $term->name,
			wp_strip_all_tags( (string) $term->description ),
		);
	}

	/**
	 * Empty context (no post, no term).
	 */
	public static function none(): self {
		return new self( 0, '', '', '', '' );
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TemplateContextTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Meta/TemplateContext.php tests/Unit/Meta/TemplateContextTest.php tests/bootstrap-unit.php
git commit -m "feat(meta): add TemplateContext value object + WP_Term unit polyfill"
```

---

## Task 3: `Variables` — tokens nuevos + firma con `TemplateContext` (migra call sites del Resolver)

This task changes `Variables::replace()` to an **incompatible** signature and migrates the **three** existing call sites in `Resolver` in the same commit, preserving current behavior (singular/home). Taxonomy logic and per-type selection come in Task 6.

**Files:**
- Modify: `src/Meta/Variables.php` (full rewrite of `replace()` and its replacements map)
- Modify: `src/Meta/Resolver.php:45,49,67` (call sites only — no behavior change yet)
- Test: `tests/Unit/Meta/VariablesTest.php` (rewrite for new signature + new tokens)

**Interfaces:**
- Consumes: `TemplateContext` (Task 2); `Options::get('title_separator')`; WP `get_bloginfo`, `gmdate`.
- Produces: `Variables::replace( string $template, ?TemplateContext $context = null ): string`. Tokens: `%sitename%`, `%tagline%`, `%sep%`, `%currentyear%`, `%title%`, `%excerpt%`, `%term%`, `%term_description%`.

- [ ] **Step 1: Rewrite the VariablesTest for the new signature**

Replace the entire body of `tests/Unit/Meta/VariablesTest.php` with:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateContext;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class VariablesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array( 'title_separator' => '-' ) );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_replaces_site_tokens(): void {
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'My Site' : 'My tagline'
		);

		$variables = new Variables( new Options() );

		$this->assertSame(
			'My Site - My tagline',
			$variables->replace( '%sitename% %sep% %tagline%' )
		);
	}

	public function test_replaces_post_tokens(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
		Functions\when( 'get_the_excerpt' )->justReturn( 'A short summary.' );

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_post( 42 );

		$this->assertSame(
			'Hello World - My Site',
			$variables->replace( '%title% %sep% %sitename%', $ctx )
		);
		$this->assertSame( 'A short summary.', $variables->replace( '%excerpt%', $ctx ) );
	}

	public function test_replaces_term_tokens(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );

		$term              = new WP_Term();
		$term->name        = 'News';
		$term->description = 'All the news.';

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_term( $term );

		$this->assertSame(
			'News - My Site',
			$variables->replace( '%term% %sep% %sitename%', $ctx )
		);
		$this->assertSame( 'All the news.', $variables->replace( '%term_description%', $ctx ) );
	}

	public function test_strips_separators_when_tokens_are_empty(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );

		$variables = new Variables( new Options() );

		// none() context → %title% empty → no dangling separator.
		$this->assertSame( '', $variables->replace( '%title% %sep%' ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter VariablesTest`
Expected: FAIL — `replace()` does not accept `TemplateContext` / `%term%` not replaced.

- [ ] **Step 3: Rewrite `Variables::replace()`**

Replace the `replace()` method (lines 26-62) in `src/Meta/Variables.php` with:

```php
	/**
	 * Replace every supported token in the template.
	 *
	 * @param string               $template Template containing %tokens%.
	 * @param TemplateContext|null $context  Rendering context (null = empty).
	 */
	public function replace( string $template, ?TemplateContext $context = null ): string {
		$context = $context ?? TemplateContext::none();

		$replacements = array(
			'%sitename%'         => (string) get_bloginfo( 'name' ),
			'%tagline%'          => (string) get_bloginfo( 'description' ),
			'%sep%'              => (string) $this->options->get( 'title_separator' ),
			'%currentyear%'      => gmdate( 'Y' ),
			'%title%'            => $context->title,
			'%excerpt%'          => $context->excerpt,
			'%term%'             => $context->term_name,
			'%term_description%' => $context->term_description,
		);

		$output = strtr( $template, $replacements );

		// Collapse whitespace left by empty tokens.
		$output = trim( (string) preg_replace( '/\s+/', ' ', $output ) );

		// Strip leading/trailing separators left dangling by empty tokens.
		// Treat the separator as a whole string (it may be multi-character),
		// not as a character set the way trim()'s charlist would.
		$separator = trim( (string) $this->options->get( 'title_separator' ) );

		if ( '' !== $separator ) {
			$quoted = preg_quote( $separator, '/' );
			$output = (string) preg_replace(
				'/^(?:' . $quoted . '\s*)+|(?:\s*' . $quoted . ')+$/',
				'',
				$output
			);
		}

		return trim( $output );
	}
```

Add the import below the existing `use OpenSEO\Settings\Options;` line:

```php
use OpenSEO\Meta\TemplateContext;
```

- [ ] **Step 4: Migrate the three Resolver call sites (no behavior change)**

In `src/Meta/Resolver.php`, add the import below `use OpenSEO\Settings\Options;`:

```php
use OpenSEO\Meta\TemplateContext;
```

Line 45 — inside `title()`'s `is_singular()` branch, change:

```php
			return $this->variables->replace( (string) $this->options->get( 'title_template' ), $id );
```
to:
```php
			return $this->variables->replace( (string) $this->options->get( 'title_template' ), TemplateContext::for_post( $id ) );
```

Line 67 — inside `description()`'s `is_singular()` branch, change:

```php
			return $this->variables->replace( (string) $this->options->get( 'description_template' ), $id );
```
to:
```php
			return $this->variables->replace( (string) $this->options->get( 'description_template' ), TemplateContext::for_post( $id ) );
```

Line 49 (home title, `replace((string) $this->options->get('home_title'))`) needs **no change** — no second argument means `none()`.

- [ ] **Step 5: Run the full unit suite + static analysis**

Run: `vendor/bin/phpunit --filter "VariablesTest|ResolverTest"`
Expected: PASS (VariablesTest 4, ResolverTest unchanged set all green).

Run: `composer analyze`
Expected: No errors (no call site passes `int` to `replace()`).

- [ ] **Step 6: Commit**

```bash
git add src/Meta/Variables.php src/Meta/Resolver.php tests/Unit/Meta/VariablesTest.php
git commit -m "feat(meta): Variables takes TemplateContext; add %term%/%term_description%"
```

---

## Task 4: `ContentTypes` — única fuente de tipos/taxonomías elegibles

**Files:**
- Create: `src/Settings/ContentTypes.php`
- Test: `tests/Unit/Settings/ContentTypesTest.php`

**Interfaces:**
- Consumes: WP `get_post_types`, `get_taxonomies` (with `'objects'`); objects expose `->name` and `->labels->name`.
- Produces: `OpenSEO\Settings\ContentTypes` with `post_types(): array<int,array{slug:string,label:string}>`, `taxonomies(): array<int,array{slug:string,label:string}>`, `post_type_slugs(): array<int,string>`, `taxonomy_slugs(): array<int,string>`. Criterion: `public => true` excluding `attachment`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Settings\ContentTypes;
use PHPUnit\Framework\TestCase;

final class ContentTypesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fake_type( string $name, string $label ): object {
		$labels       = new \stdClass();
		$labels->name = $label;
		$type         = new \stdClass();
		$type->name   = $name;
		$type->labels = $labels;
		return $type;
	}

	public function test_post_types_exclude_attachment(): void {
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => $this->fake_type( 'post', 'Posts' ),
				'page'       => $this->fake_type( 'page', 'Pages' ),
				'attachment' => $this->fake_type( 'attachment', 'Media' ),
			)
		);

		$slugs = ( new ContentTypes() )->post_type_slugs();

		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
		$this->assertNotContains( 'attachment', $slugs );
	}

	public function test_taxonomies_map_slug_and_label(): void {
		Functions\when( 'get_taxonomies' )->justReturn(
			array( 'category' => $this->fake_type( 'category', 'Categories' ) )
		);

		$taxes = ( new ContentTypes() )->taxonomies();

		$this->assertSame(
			array( array( 'slug' => 'category', 'label' => 'Categories' ) ),
			$taxes
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ContentTypesTest`
Expected: FAIL — `Class "OpenSEO\Settings\ContentTypes" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Eligible content types and taxonomies for SEO templates.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Settings;

/**
 * Single source of truth for which post types and taxonomies get SEO templates.
 * Used by Options::sanitize() (whitelist) and Admin\Assets (bootstrap) so the
 * editable set and the validated set never diverge. Criterion: public, minus
 * attachment (a media item needs no SEO title template).
 */
final class ContentTypes {

	private const EXCLUDED_POST_TYPES = array( 'attachment' );

	/**
	 * Eligible post types as slug/label pairs.
	 *
	 * @return array<int, array{slug:string, label:string}>
	 */
	public function post_types(): array {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => (string) $type->name,
				'label' => (string) ( $type->labels->name ?? $type->name ),
			);
		}

		return $out;
	}

	/**
	 * Eligible taxonomies as slug/label pairs.
	 *
	 * @return array<int, array{slug:string, label:string}>
	 */
	public function taxonomies(): array {
		$out = array();

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[] = array(
				'slug'  => (string) $tax->name,
				'label' => (string) ( $tax->labels->name ?? $tax->name ),
			);
		}

		return $out;
	}

	/**
	 * Eligible post type slugs.
	 *
	 * @return array<int, string>
	 */
	public function post_type_slugs(): array {
		return array_column( $this->post_types(), 'slug' );
	}

	/**
	 * Eligible taxonomy slugs.
	 *
	 * @return array<int, string>
	 */
	public function taxonomy_slugs(): array {
		return array_column( $this->taxonomies(), 'slug' );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ContentTypesTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Settings/ContentTypes.php tests/Unit/Settings/ContentTypesTest.php
git commit -m "feat(settings): add ContentTypes helper (eligible types/taxonomies)"
```

---

## Task 5: `Options` — defaults anidados + sanitize slug-a-slug con whitelist

Adds the `post_types`/`taxonomies` maps and their nested sanitization. Does **not** yet remove `title_template`/`description_template` (Resolver still uses them until Task 6).

**The nested branch is conditional on the input carrying those groups.** This is load-bearing: `sanitize()` is called by every settings tab and by `RestSettingsTest`; instantiating `ContentTypes` (→ `get_post_types`/`get_taxonomies`) unconditionally would fatal every existing sanitize unit test (none of them mock those functions). Gating on `isset($input['post_types'])` keeps unrelated saves from touching `ContentTypes` at all.

The OptionsTest **already exists** at `tests/Unit/OptionsTest.php` (namespace `OpenSEO\Tests\Unit`). We **extend** it — do NOT create a second one under `Settings/`.

**Files:**
- Modify: `src/Settings/Options.php` (add defaults; add `sanitize_template_map()`; wire conditional nested sanitize)
- Modify: `tests/Unit/OptionsTest.php` (add `setUp` mocks, `fake_type` helper, and the nested tests)

**Interfaces:**
- Consumes: `ContentTypes` (Task 4, same namespace — no `use` needed); WP `sanitize_text_field`, `sanitize_textarea_field`, `wp_unslash`, `get_option`.
- Produces: `Options::all()['post_types']` / `['taxonomies']` are `array<string, array{title:string,description:string}>`; `sanitize()` preserves unsent slugs, whitelists, merges per field, and `unset`s fully-empty slugs only when the group is submitted.

- [ ] **Step 1: Add `get_post_types`/`get_taxonomies`/`sanitize_textarea_field` mocks + a `fake_type` helper to the existing OptionsTest**

In `tests/Unit/OptionsTest.php`, append these three lines to the **existing** `setUp()` (after `Monkey\setUp();`). They are inert for the current tests (none submit `post_types`/`taxonomies`, so the conditional branch never runs), and feed the new nested tests:

```php
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'get_post_types' )->justReturn(
			array( 'post' => $this->fake_type( 'post' ), 'page' => $this->fake_type( 'page' ) )
		);
		Functions\when( 'get_taxonomies' )->justReturn(
			array( 'category' => $this->fake_type( 'category' ) )
		);
```

Add this private helper to the class (e.g. right after `tearDown()`):

```php
	private function fake_type( string $name ): object {
		$labels       = new \stdClass();
		$labels->name = ucfirst( $name );
		$type         = new \stdClass();
		$type->name   = $name;
		$type->labels = $labels;
		return $type;
	}
```

- [ ] **Step 2: Add the failing nested tests to the same class**

Add these methods to `tests/Unit/OptionsTest.php`:

```php
	public function test_defaults_include_empty_template_maps(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$all = ( new Options() )->all();

		$this->assertSame( array(), $all['post_types'] );
		$this->assertSame( array(), $all['taxonomies'] );
	}

	public function test_sanitize_stores_whitelisted_post_type_template(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => 'Custom %sitename%', 'description' => 'Desc' ) ) )
		);

		$this->assertSame(
			array( 'title' => 'Custom %sitename%', 'description' => 'Desc' ),
			$clean['post_types']['post']
		);
	}

	public function test_sanitize_rejects_unknown_slug(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'bogus' => array( 'title' => 'X', 'description' => 'Y' ) ) )
		);

		$this->assertArrayNotHasKey( 'bogus', $clean['post_types'] );
	}

	public function test_sanitize_preserves_unsent_slugs(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'page' => array( 'title' => 'Kept', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => 'New', 'description' => '' ) ) )
		);

		$this->assertSame( 'Kept', $clean['post_types']['page']['title'] );
		$this->assertSame( 'New', $clean['post_types']['post']['title'] );
	}

	public function test_sanitize_unsets_fully_empty_slug(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => 'Old', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '' ) ) )
		);

		$this->assertArrayNotHasKey( 'post', $clean['post_types'] );
	}

	public function test_sanitize_ignores_missing_group(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'taxonomies' => array( 'category' => array( 'title' => 'Cat', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Submit only post_types; taxonomies must be preserved untouched.
		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => 'P', 'description' => '' ) ) )
		);

		$this->assertSame( 'Cat', $clean['taxonomies']['category']['title'] );
	}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL — `post_types` key missing from defaults / nested sanitize absent.

- [ ] **Step 4: Add the two map defaults**

In `src/Settings/Options.php`, inside `defaults()`, add after the `'og_default_image' => '',` line:

```php
			'post_types'               => array(),
			'taxonomies'               => array(),
```

- [ ] **Step 5: Wire the conditional nested sanitize + add the helper**

In `sanitize()`, immediately before `return $clean;`, add (no `use` — `ContentTypes` is in the same namespace, reference it directly):

```php
		if ( isset( $input['post_types'] ) || isset( $input['taxonomies'] ) ) {
			$content_types = new ContentTypes();

			if ( isset( $input['post_types'] ) ) {
				$clean['post_types'] = $this->sanitize_template_map(
					$input['post_types'],
					is_array( $clean['post_types'] ?? null ) ? $clean['post_types'] : array(),
					$content_types->post_type_slugs()
				);
			}

			if ( isset( $input['taxonomies'] ) ) {
				$clean['taxonomies'] = $this->sanitize_template_map(
					$input['taxonomies'],
					is_array( $clean['taxonomies'] ?? null ) ? $clean['taxonomies'] : array(),
					$content_types->taxonomy_slugs()
				);
			}
		}
```

Add the private helper method just before the closing brace of the class:

```php
	/**
	 * Sanitize one nested template map (post_types or taxonomies) slug-by-slug.
	 *
	 * Conservation of unsent slugs comes from $current already holding the stored
	 * map (sanitize() starts from all()); this is NOT a PHP deep merge. Per slug:
	 * whitelist, merge per field, and unset when both fields end up empty.
	 *
	 * @param mixed                                       $input_map Raw submitted map for the group.
	 * @param array<string, array<string, string>>        $current   Stored map for this group.
	 * @param array<int, string>                          $allowed   Whitelisted slugs.
	 * @return array<string, array<string, string>>
	 */
	private function sanitize_template_map( mixed $input_map, array $current, array $allowed ): array {
		if ( ! is_array( $input_map ) ) {
			return $current;
		}

		foreach ( $input_map as $slug => $fields ) {
			$slug = (string) $slug;

			if ( ! in_array( $slug, $allowed, true ) || ! is_array( $fields ) ) {
				continue;
			}

			$title = array_key_exists( 'title', $fields )
				? sanitize_text_field( wp_unslash( (string) $fields['title'] ) )
				: (string) ( $current[ $slug ]['title'] ?? '' );

			$description = array_key_exists( 'description', $fields )
				? sanitize_textarea_field( wp_unslash( (string) $fields['description'] ) )
				: (string) ( $current[ $slug ]['description'] ?? '' );

			if ( '' === $title && '' === $description ) {
				unset( $current[ $slug ] );
				continue;
			}

			$current[ $slug ] = array(
				'title'       => $title,
				'description' => $description,
			);
		}

		return $current;
	}
```

- [ ] **Step 6: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: PASS (existing tests + 6 new).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(settings): nested post_types/taxonomies template maps + sanitize"
```

---

## Task 6: `Resolver` — selección por tipo/taxonomía + dependencia `TemplateDefaults`

**Files:**
- Modify: `src/Meta/Resolver.php` (constructor + `title()` + `description()` + private helpers)
- Modify: `src/Plugin.php:123` (pass `TemplateDefaults` to `Resolver`)
- Test: `tests/Unit/Meta/ResolverTest.php` (update helper; add per-type and taxonomy tests)

**Interfaces:**
- Consumes: `TemplateDefaults` (Task 1), `TemplateContext` (Task 2), `Options::get('post_types'|'taxonomies'|'home_title'|'home_description')`; WP `is_singular`, `is_category`, `is_tag`, `is_tax`, `is_front_page`, `get_queried_object_id`, `get_queried_object`, `get_post_type`, `get_post_meta`, `get_bloginfo`.
- Produces: `Resolver::__construct(Options, Variables, TemplateDefaults)`; `title()`/`description()` resolve per entity with cascade `override → stored type template → type default`.

- [ ] **Step 1: Update the ResolverTest harness + add failing tests**

In `tests/Unit/Meta/ResolverTest.php`, add the imports below the existing `use` lines:

```php
use OpenSEO\Meta\TemplateDefaults;
use WP_Term;
```

Replace the `resolver()` helper (lines 31-34) with:

```php
	private function resolver(): Resolver {
		$options = new Options();
		return new Resolver( $options, new Variables( $options ), new TemplateDefaults() );
	}
```

In `setUp()`, add taxonomy conditional defaults so non-taxonomy tests don't fatal (add after the existing `is_front_page` line):

```php
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
```

Add these new test methods to the class:

```php
	public function test_title_uses_stored_post_type_template(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'About' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'page' => array( 'title' => '%title% %sep% %sitename% PAGE' ) ) )
		);

		$this->assertSame( 'About - My Site PAGE', $this->resolver()->title() );
	}

	public function test_title_falls_back_to_singular_default_when_no_stored_template(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );

		// Default singular title '%title% %sep% %sitename%'.
		$this->assertSame( 'Post Title - My Site', $this->resolver()->title() );
	}

	public function test_title_resolves_taxonomy_with_default(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( true );

		$term       = new WP_Term();
		$term->name = 'News';
		Functions\when( 'get_queried_object' )->justReturn( $term );

		// taxonomies map empty → default '%term% %sep% %sitename%'.
		$this->assertSame( 'News - My Site', $this->resolver()->title() );
	}

	public function test_description_resolves_taxonomy_template(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( true );

		$term              = new WP_Term();
		$term->name        = 'Tag';
		$term->description = 'Tag desc.';
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		// Default taxonomy description '%term_description%'.
		$this->assertSame( 'Tag desc.', $this->resolver()->description() );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: FAIL — `Resolver::__construct()` arity / taxonomy branches not implemented.

- [ ] **Step 3: Update the Resolver constructor**

In `src/Meta/Resolver.php`, replace the constructor (lines 28-31) with:

```php
	public function __construct(
		private readonly Options $options,
		private readonly Variables $variables,
		private readonly TemplateDefaults $defaults
	) {}
```

Add the import below `use OpenSEO\Meta\TemplateContext;` (added in Task 3):

```php
use OpenSEO\Meta\TemplateDefaults;
use WP_Term;
```

- [ ] **Step 4: Rewrite `title()`**

Replace the `title()` method body with:

```php
	public function title(): string {
		if ( is_singular() ) {
			$id = get_queried_object_id();

			$override = (string) get_post_meta( $id, '_openseo_title', true );
			if ( '' !== $override ) {
				return $override;
			}

			$template = $this->type_template( 'post_types', (string) get_post_type( $id ), 'title' );
			if ( '' === $template ) {
				$template = $this->defaults->singular_title();
			}

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
		}

		if ( $this->is_taxonomy() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				$template = $this->type_template( 'taxonomies', $term->taxonomy, 'title' );
				if ( '' === $template ) {
					$template = $this->defaults->taxonomy_title();
				}

				return $this->variables->replace( $template, TemplateContext::for_term( $term ) );
			}
		}

		if ( is_front_page() ) {
			return $this->variables->replace( (string) $this->options->get( 'home_title' ) );
		}

		return '';
	}
```

- [ ] **Step 5: Rewrite `description()`**

Replace the `description()` method body with:

```php
	public function description(): string {
		if ( is_singular() ) {
			$id = get_queried_object_id();

			$override = (string) get_post_meta( $id, '_openseo_description', true );
			if ( '' !== $override ) {
				return $override;
			}

			$template = $this->type_template( 'post_types', (string) get_post_type( $id ), 'description' );
			if ( '' === $template ) {
				$template = $this->defaults->singular_description();
			}

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
		}

		if ( $this->is_taxonomy() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				$template = $this->type_template( 'taxonomies', $term->taxonomy, 'description' );
				if ( '' === $template ) {
					$template = $this->defaults->taxonomy_description();
				}

				return $this->variables->replace( $template, TemplateContext::for_term( $term ) );
			}
		}

		if ( is_front_page() ) {
			$home = (string) $this->options->get( 'home_description' );

			return '' !== $home ? $home : (string) get_bloginfo( 'description' );
		}

		return '';
	}
```

- [ ] **Step 6: Add the two private helpers**

Add before the existing `meta_value()` private method:

```php
	/**
	 * Whether the current request is a public taxonomy archive.
	 */
	private function is_taxonomy(): bool {
		return is_category() || is_tag() || is_tax();
	}

	/**
	 * Stored template for an entity, or '' when none is configured.
	 *
	 * @param string $group 'post_types' or 'taxonomies'.
	 * @param string $slug  Post type or taxonomy slug.
	 * @param string $field 'title' or 'description'.
	 */
	private function type_template( string $group, string $slug, string $field ): string {
		$map = $this->options->get( $group );

		if ( ! is_array( $map ) ) {
			return '';
		}

		return (string) ( $map[ $slug ][ $field ] ?? '' );
	}
```

- [ ] **Step 7: Update `Plugin.php` to pass the new dependency**

In `src/Plugin.php`, add the import below `use OpenSEO\Meta\Resolver;`:

```php
use OpenSEO\Meta\TemplateDefaults;
```

Change line 123:

```php
		$resolver  = new Resolver( $options, $variables );
```
to:
```php
		$resolver  = new Resolver( $options, $variables, new TemplateDefaults() );
```

- [ ] **Step 8: Fix the three OTHER tests that construct `Resolver`**

These build `new Resolver( $options, new Variables( $options ) )` with the old 2-arg signature and break with `ArgumentCountError` after Step 3. `DescriptionTest` and `ContentPiecesTest` also reach the new `is_taxonomy()` branch on non-singular requests, so they need the taxonomy conditionals mocked or Brain Monkey fatals.

In all three files — `tests/Unit/Frontend/Head/DescriptionTest.php`, `tests/Unit/Frontend/Head/RobotsTest.php`, `tests/Unit/Schema/Pieces/ContentPiecesTest.php` — add the import below `use OpenSEO\Meta\Variables;`:

```php
use OpenSEO\Meta\TemplateDefaults;
```

and change each `resolver()` helper's `return` to:

```php
		return new Resolver( $options, new Variables( $options ), new TemplateDefaults() );
```

In the `setUp()` of `DescriptionTest` **and** `ContentPiecesTest` only, add:

```php
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
```

`RobotsTest` only exercises `robots()`, which never calls `is_taxonomy()`, so it needs the constructor fix only (no taxonomy mocks).

- [ ] **Step 9: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter "ResolverTest|DescriptionTest|RobotsTest|ContentPiecesTest"`
Expected: PASS (ResolverTest original set + 4 new; the other three green again).

Run: `composer test:unit`
Expected: Whole unit suite green (no `ArgumentCountError`).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 10: Commit**

```bash
git add src/Meta/Resolver.php src/Plugin.php tests/Unit/Meta/ResolverTest.php tests/Unit/Frontend/Head/DescriptionTest.php tests/Unit/Frontend/Head/RobotsTest.php tests/Unit/Schema/Pieces/ContentPiecesTest.php
git commit -m "feat(meta): Resolver selects per-type/taxonomy templates with defaults"
```

---

## Task 7: `Options` — retirar el template global único

Now that nothing reads `title_template`/`description_template` (Resolver migrated in Task 6), remove them — and update the **existing** tests that still assert on them, or the gate goes red.

**Files:**
- Modify: `src/Settings/Options.php` (remove from `defaults()` and from the `sanitize()` text-field loop)
- Modify: `tests/Unit/OptionsTest.php` (add retirement assertion; fix the assertions/inputs that referenced the removed keys)
- Modify: `tests/Integration/RestSettingsTest.php` (use a surviving key in the partial-post test)

**Interfaces:**
- Consumes: nothing new.
- Produces: `Options::all()` no longer contains `title_template` / `description_template`.

- [ ] **Step 1: Add the failing retirement assertion**

Add to `tests/Unit/OptionsTest.php`:

```php
	public function test_global_template_keys_are_retired(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$all = ( new Options() )->all();

		$this->assertArrayNotHasKey( 'title_template', $all );
		$this->assertArrayNotHasKey( 'description_template', $all );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_global_template_keys_are_retired`
Expected: FAIL — keys still present in defaults.

- [ ] **Step 3: Remove the defaults**

In `src/Settings/Options.php` `defaults()`, delete these two lines:

```php
			'title_template'           => '%title% %sep% %sitename%',
			'description_template'     => '%excerpt%',
```

- [ ] **Step 4: Remove from the sanitize text-field loop**

In `sanitize()`, change the text-field loop array from:

```php
		foreach ( array( 'title_separator', 'title_template', 'description_template', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model' ) as $key ) {
```
to:
```php
		foreach ( array( 'title_separator', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model' ) as $key ) {
```

- [ ] **Step 5: Fix the existing OptionsTest assertions that referenced the removed keys**

In `tests/Unit/OptionsTest.php`:

In `test_returns_on_page_defaults_when_nothing_is_stored`, delete these two lines:

```php
		$this->assertSame( '%title% %sep% %sitename%', $options->get( 'title_template' ) );
		$this->assertSame( '%excerpt%', $options->get( 'description_template' ) );
```

In `test_stored_values_override_defaults`, replace:

```php
		// Untouched key still falls back to its default.
		$this->assertSame( '%excerpt%', $options->get( 'description_template' ) );
```
with a surviving key:
```php
		// Untouched key still falls back to its default.
		$this->assertSame( '%sitename% %sep% %tagline%', $options->get( 'home_title' ) );
```

In `test_sanitize_cleans_and_normalizes_input`, remove these two input lines:

```php
				'title_template'       => '%title% %sep% %sitename%',
				'description_template' => '%excerpt%',
```
and remove this assertion:
```php
		$this->assertSame( '%title% %sep% %sitename%', $clean['title_template'] );
```

In `test_sanitize_preserves_keys_absent_from_a_partial_tab_submission`, replace the stored option and assertion that use `title_template`:

```php
		Functions\when( 'get_option' )->justReturn(
			array( 'title_template' => 'Stored title %sep% %sitename%' )
		);
```
becomes:
```php
		Functions\when( 'get_option' )->justReturn(
			array( 'home_title' => 'Stored home %sep% %tagline%' )
		);
```
and:
```php
		$this->assertSame( 'Stored title %sep% %sitename%', $clean['title_template'] );
```
becomes:
```php
		$this->assertSame( 'Stored home %sep% %tagline%', $clean['home_title'] );
```

- [ ] **Step 6: Fix the integration test that asserts the removed key**

In `tests/Integration/RestSettingsTest.php`, in `test_partial_post_preserves_other_keys`, replace:

```php
		// Unsent key keeps its value instead of resetting.
		$this->assertSame( '%title% %sep% %sitename%', $data['title_template'] );
```
with a surviving key (its default from `defaults()`):
```php
		// Unsent key keeps its value instead of resetting.
		$this->assertSame( '%sitename% %sep% %tagline%', $data['home_title'] );
```

- [ ] **Step 7: Run the full unit suite (+ integration if wp-env is up)**

Run: `composer test:unit`
Expected: PASS (whole suite, incl. OptionsTest + ResolverTest).

If wp-env is running: `npm run test:integration` → `RestSettingsTest` green. (CI runs it regardless; do not leave it red.)

- [ ] **Step 8: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php tests/Integration/RestSettingsTest.php
git commit -m "refactor(settings): retire single global title/description templates"
```

---

## Task 8: `SettingsMigrations` — migrar el template global + registrar en `Plugin`

**Files:**
- Create: `src/Lifecycle/SettingsMigrations.php`
- Modify: `src/Plugin.php` (import + add to `modules()`)
- Test: `tests/Unit/Lifecycle/SettingsMigrationsTest.php`

**Interfaces:**
- Consumes: `Options::OPTION_KEY`; WP `get_option`, `update_option`, `add_action`.
- Produces: `OpenSEO\Lifecycle\SettingsMigrations implements Hookable` with `register()` (hooks `init`), `maybe_migrate(): void`, and pure `public static function migrate_array(array $stored): array`.

- [ ] **Step 1: Write the failing test (pure transform)**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Lifecycle;

use OpenSEO\Lifecycle\SettingsMigrations;
use PHPUnit\Framework\TestCase;

final class SettingsMigrationsTest extends TestCase {

	public function test_copies_customized_global_title_to_post_and_page(): void {
		$out = SettingsMigrations::migrate_array(
			array( 'title_template' => 'Custom %title%' )
		);

		$this->assertSame( 'Custom %title%', $out['post_types']['post']['title'] );
		$this->assertSame( 'Custom %title%', $out['post_types']['page']['title'] );
		$this->assertArrayNotHasKey( 'title_template', $out );
	}

	public function test_copies_only_customized_field(): void {
		$out = SettingsMigrations::migrate_array(
			array(
				'title_template'       => '%title% %sep% %sitename%', // default → not copied
				'description_template' => 'Custom desc',             // customized → copied
			)
		);

		// Only description was customized: post/page get description, not title.
		$this->assertSame( 'Custom desc', $out['post_types']['post']['description'] );
		$this->assertSame( 'Custom desc', $out['post_types']['page']['description'] );
		$this->assertArrayNotHasKey( 'title', $out['post_types']['post'] );
	}

	public function test_default_templates_produce_no_post_types(): void {
		$out = SettingsMigrations::migrate_array(
			array(
				'title_template'       => '%title% %sep% %sitename%',
				'description_template' => '%excerpt%',
			)
		);

		$this->assertArrayNotHasKey( 'post_types', $out );
		$this->assertArrayNotHasKey( 'title_template', $out );
		$this->assertArrayNotHasKey( 'description_template', $out );
	}

	public function test_is_idempotent(): void {
		$once  = SettingsMigrations::migrate_array( array( 'title_template' => 'Custom' ) );
		$twice = SettingsMigrations::migrate_array( $once );

		$this->assertSame( $once, $twice );
	}
}
```

> Note: `migrate_array` writes an asymmetric shape (`post_types['post']` with only `description`) when a single field was customized. `Resolver::type_template()` and `Options::sanitize_template_map()` both read with `?? ''`, so this is safe; the test above documents it.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SettingsMigrationsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * One-time settings migrations gated by a version option.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Lifecycle;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Migrates legacy settings shapes. Runs on `init` (front + admin) so the
 * front-end never serves a default where a customized value should be, gated by
 * an option separate from the table schema version.
 */
final class SettingsMigrations implements Hookable {

	public const VERSION = '1';

	public const VERSION_OPTION = 'openseo_settings_version';

	private const OLD_TITLE_DEFAULT = '%title% %sep% %sitename%';

	private const OLD_DESCRIPTION_DEFAULT = '%excerpt%';

	/**
	 * Hook the migration runner.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_migrate' ) );
	}

	/**
	 * Run pending migrations once, then mark the version.
	 */
	public function maybe_migrate(): void {
		if ( (string) get_option( self::VERSION_OPTION, '' ) === self::VERSION ) {
			return;
		}

		$stored = get_option( Options::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$migrated = self::migrate_array( $stored );

		if ( $migrated !== $stored ) {
			update_option( Options::OPTION_KEY, $migrated );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Pure transform: copy a customized global template to post/page, drop the
	 * legacy keys. Idempotent. No WordPress calls so it is unit-testable.
	 *
	 * @param array<string, mixed> $stored Raw stored settings array.
	 * @return array<string, mixed>
	 */
	public static function migrate_array( array $stored ): array {
		$title = isset( $stored['title_template'] ) ? (string) $stored['title_template'] : '';
		if ( '' !== $title && self::OLD_TITLE_DEFAULT !== $title ) {
			$stored['post_types']['post']['title'] = $title;
			$stored['post_types']['page']['title'] = $title;
		}

		$description = isset( $stored['description_template'] ) ? (string) $stored['description_template'] : '';
		if ( '' !== $description && self::OLD_DESCRIPTION_DEFAULT !== $description ) {
			$stored['post_types']['post']['description'] = $description;
			$stored['post_types']['page']['description'] = $description;
		}

		unset( $stored['title_template'], $stored['description_template'] );

		return $stored;
	}
}
```

- [ ] **Step 4: Register in `Plugin::modules()`**

In `src/Plugin.php`, add the import below `use OpenSEO\Lifecycle\Schema;`:

```php
use OpenSEO\Lifecycle\SettingsMigrations;
```

In `modules()`, add to the always-on `$modules` array (e.g. right after `new PostMeta(),`):

```php
			new SettingsMigrations(),
```

- [ ] **Step 5: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter SettingsMigrationsTest`
Expected: PASS (4 tests).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Lifecycle/SettingsMigrations.php src/Plugin.php tests/Unit/Lifecycle/SettingsMigrationsTest.php
git commit -m "feat(lifecycle): migrate legacy global templates to post/page on init"
```

---

## Task 9: `Admin\Assets` — bootstrap `contentTypes`

**Files:**
- Modify: `src/Admin/Assets.php` (constructor deps + `bootstrap()` payload + helper)
- Modify: `src/Plugin.php:172` (construct `AdminAssets` with the new deps)

**Interfaces:**
- Consumes: `ContentTypes` (Task 4), `TemplateDefaults` (Task 1).
- Produces: `window.openseoAdmin.contentTypes = { postTypes: [...], taxonomies: [...] }`, each entry `{ slug, label, defaultTitle, defaultDescription }`.

- [ ] **Step 1: Add the constructor dependencies**

In `src/Admin/Assets.php`, add imports below `use OpenSEO\Settings\Options;`:

```php
use OpenSEO\Settings\ContentTypes;
use OpenSEO\Meta\TemplateDefaults;
```

Extend the constructor (add two promoted params after `$not_found_log`):

```php
	public function __construct(
		private readonly Menu $menu,
		private readonly Options $options,
		private readonly Repository $redirects,
		private readonly LogRepository $not_found_log,
		private readonly ContentTypes $content_types,
		private readonly TemplateDefaults $defaults,
	) {}
```

- [ ] **Step 2: Add `contentTypes` to the bootstrap payload**

In `bootstrap()`, add a `contentTypes` key to the `$data` array (after the `connector` entry):

```php
			'contentTypes' => array(
				'postTypes'  => $this->content_type_entries(
					$this->content_types->post_types(),
					$this->defaults->singular_title(),
					$this->defaults->singular_description()
				),
				'taxonomies' => $this->content_type_entries(
					$this->content_types->taxonomies(),
					$this->defaults->taxonomy_title(),
					$this->defaults->taxonomy_description()
				),
			),
```

Add the private helper before `bootstrap()`:

```php
	/**
	 * Decorate slug/label entries with the per-surface default templates.
	 *
	 * @param array<int, array{slug:string, label:string}> $types               Slug/label pairs.
	 * @param string                                        $default_title       Default title template.
	 * @param string                                        $default_description Default description template.
	 * @return array<int, array{slug:string, label:string, defaultTitle:string, defaultDescription:string}>
	 */
	private function content_type_entries( array $types, string $default_title, string $default_description ): array {
		return array_map(
			static fn( array $type ): array => array(
				'slug'               => $type['slug'],
				'label'              => $type['label'],
				'defaultTitle'       => $default_title,
				'defaultDescription' => $default_description,
			),
			$types
		);
	}
```

- [ ] **Step 3: Update the `AdminAssets` construction in `Plugin`**

In `src/Plugin.php`, add the import below `use OpenSEO\Settings\Options;`:

```php
use OpenSEO\Settings\ContentTypes;
```

Change line 172:

```php
			$modules[] = new AdminAssets( $menu, $options, $redirects_repo, $not_found_log );
```
to:
```php
			$modules[] = new AdminAssets( $menu, $options, $redirects_repo, $not_found_log, new ContentTypes(), new TemplateDefaults() );
```

(`TemplateDefaults` is already imported from Task 6.)

- [ ] **Step 4: Verify lint + static analysis**

Run: `composer lint`
Expected: No PHPCS violations.

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Assets.php src/Plugin.php
git commit -m "feat(admin): bootstrap content types + per-type default templates"
```

---

## Task 10: JS — `templateFields` immutable merge helper

**Files:**
- Create: `assets/src/admin/templateFields.js`
- Test: `assets/src/admin/templateFields.test.js`

**Interfaces:**
- Consumes: nothing.
- Produces: `setTemplateField(map, slug, field, value)` returning a new map without mutating the input.

- [ ] **Step 1: Write the failing test**

```js
import { setTemplateField } from './templateFields';

describe( 'setTemplateField', () => {
	it( 'sets a field without mutating the original map', () => {
		const map = { post: { title: 'A', description: 'B' } };
		const next = setTemplateField( map, 'post', 'title', 'New' );

		expect( next.post.title ).toBe( 'New' );
		expect( map.post.title ).toBe( 'A' );
	} );

	it( 'preserves the other field of the same slug', () => {
		const map = { post: { title: 'A', description: 'B' } };
		const next = setTemplateField( map, 'post', 'title', 'New' );

		expect( next.post.description ).toBe( 'B' );
	} );

	it( 'preserves other slugs', () => {
		const map = { post: { title: 'A' }, page: { title: 'P' } };
		const next = setTemplateField( map, 'post', 'title', 'New' );

		expect( next.page.title ).toBe( 'P' );
	} );

	it( 'creates the slug entry when missing', () => {
		const next = setTemplateField( {}, 'post', 'title', 'New' );

		expect( next.post ).toEqual( { title: 'New' } );
	} );
} );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:js -- templateFields.test.js`
Expected: FAIL — cannot find module `./templateFields`.

- [ ] **Step 3: Write minimal implementation**

```js
/**
 * Immutably set one field (title|description) of one slug in a template map.
 *
 * @param {Object} map   Current map keyed by slug → { title, description }.
 * @param {string} slug  Content type or taxonomy slug.
 * @param {string} field 'title' or 'description'.
 * @param {string} value New field value.
 * @return {Object} A new map; the input is not mutated.
 */
export function setTemplateField( map, slug, field, value ) {
	return {
		...map,
		[ slug ]: { ...( map?.[ slug ] ?? {} ), [ field ]: value },
	};
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:js -- templateFields.test.js`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/templateFields.js assets/src/admin/templateFields.test.js
git commit -m "feat(admin): add immutable setTemplateField helper"
```

---

## Task 11: JS — `TemplateGroup` component

**Files:**
- Create: `assets/src/admin/components/TemplateGroup.js`

**Interfaces:**
- Consumes: `setTemplateField` (Task 10); `@wordpress/components` `TextControl`, `TextareaControl`; `@wordpress/i18n` `__`, `sprintf`.
- Produces: `TemplateGroup({ types, mapKey, values, change })` rendering one title + description field per type, writing through `change(mapKey, nextMap)`.

- [ ] **Step 1: Write the component**

```jsx
import { TextControl, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { setTemplateField } from '../templateFields';

export function TemplateGroup( { types, mapKey, values, change } ) {
	const map = values[ mapKey ] ?? {};

	if ( ! types.length ) {
		return null;
	}

	return (
		<>
			{ types.map( ( type ) => (
				<div
					key={ type.slug }
					className="openseo-template-group__item"
				>
					<TextControl
						label={ sprintf(
							/* translators: %s: content type or taxonomy label. */
							__( '%s title', 'openseo' ),
							type.label
						) }
						value={ map[ type.slug ]?.title ?? '' }
						placeholder={ type.defaultTitle }
						onChange={ ( v ) =>
							change(
								mapKey,
								setTemplateField( map, type.slug, 'title', v )
							)
						}
					/>
					<TextareaControl
						label={ sprintf(
							/* translators: %s: content type or taxonomy label. */
							__( '%s description', 'openseo' ),
							type.label
						) }
						value={ map[ type.slug ]?.description ?? '' }
						placeholder={ type.defaultDescription }
						onChange={ ( v ) =>
							change(
								mapKey,
								setTemplateField(
									map,
									type.slug,
									'description',
									v
								)
							)
						}
					/>
				</div>
			) ) }
		</>
	);
}
```

- [ ] **Step 2: Lint the new file**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/TemplateGroup.js
git commit -m "feat(admin): add TemplateGroup component for per-type template fields"
```

---

## Task 12: JS — reorganizar `views/Titles.js`

**Files:**
- Modify: `assets/src/admin/views/Titles.js` (full rewrite)

**Interfaces:**
- Consumes: `SettingsPanel`, `TemplateGroup` (Task 11), `window.openseoAdmin.contentTypes` (Task 9).
- Produces: the "Titles & Meta" admin view with global fields + per-content-type + per-taxonomy template groups.

- [ ] **Step 1: Rewrite the view**

Replace the entire contents of `assets/src/admin/views/Titles.js` with:

```jsx
import { TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';
import { TemplateGroup } from '../components/TemplateGroup';

const contentTypes = window.openseoAdmin?.contentTypes ?? {
	postTypes: [],
	taxonomies: [],
};

export function Titles() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<TextControl
						label={ __( 'Title separator', 'openseo' ) }
						value={ values.title_separator }
						onChange={ ( v ) => change( 'title_separator', v ) }
					/>
					<TextControl
						label={ __( 'Homepage title', 'openseo' ) }
						value={ values.home_title }
						onChange={ ( v ) => change( 'home_title', v ) }
					/>
					<TextareaControl
						label={ __( 'Homepage description', 'openseo' ) }
						value={ values.home_description }
						onChange={ ( v ) =>
							change( 'home_description', v )
						}
					/>

					<h2>{ __( 'Content types', 'openseo' ) }</h2>
					<TemplateGroup
						types={ contentTypes.postTypes }
						mapKey="post_types"
						values={ values }
						change={ change }
					/>

					<h2>{ __( 'Taxonomies', 'openseo' ) }</h2>
					<TemplateGroup
						types={ contentTypes.taxonomies }
						mapKey="taxonomies"
						values={ values }
						change={ change }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
```

- [ ] **Step 2: Lint + build**

Run: `npm run lint:js`
Expected: No ESLint errors.

Run: `npm run build`
Expected: Build succeeds; `assets/build/admin-settings.js` regenerated.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/views/Titles.js
git commit -m "feat(admin): per-content-type and per-taxonomy templates in Titles view"
```

---

## Task 13: Verificación final completa

**Files:** none (verification only).

- [ ] **Step 1: Run all PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green.

- [ ] **Step 2: Run all JS gates**

Run: `npm run lint:js`
Expected: No errors.

Run: `npm run test:js`
Expected: All suites pass (incl. `templateFields`).

Run: `npm run build`
Expected: Production build succeeds.

- [ ] **Step 3: Manual smoke test (wp-env, optional but recommended)**

```bash
npm run env:start
```
In wp-admin → **OpenSEO → Titles & Meta**: the view lists global fields, then a title+description field per content type, then per taxonomy, each showing its default as placeholder. Edit a Page title template (e.g. `%title% %sep% %sitename% — Page`), Save, reload → it persists. View a published page's source → `<title>` reflects the template. Visit a category archive → `<title>` and `<meta name="description">` are emitted.

- [ ] **Step 4: Final commit (if any build artifacts or fixes)**

```bash
git add -A
git commit -m "chore(titles): final verification for per-type templates"
```

(Skip if there is nothing to commit.)

---

## Self-Review (completed during planning)

- **Spec coverage:** modelo de datos anidado (Task 5), `TemplateDefaults` (Task 1), `TemplateContext` + tokens (Tasks 2–3), Resolver por tipo/taxonomía + efecto en presenters (Task 6), retiro del global (Task 7), migración en `init` con versión propia (Task 8), helper único + whitelist (Tasks 4–5), bootstrap (Task 9), UI mínima (Tasks 10–12), testing en cada task. Todos los criterios de aceptación del spec tienen tarea.
- **Placeholder scan:** sin TBD/TODO; todo paso con código muestra el código y comando con salida esperada.
- **Type consistency:** `setTemplateField` (10) usado en `TemplateGroup` (11); `TemplateContext::for_post/for_term/none` (2) usados en Variables (3) y Resolver (6); `TemplateDefaults` métodos (1) usados en Resolver (6) y Assets (9); `ContentTypes::*_slugs` (4) usados en Options (5); `migrate_array` (8) pura y testeada.
- **Orden de dependencias:** las firmas incompatibles (`Variables::replace`) se migran atómicamente con sus call sites (Task 3); `post_types` existe en defaults (Task 5) antes de que el Resolver lo lea (Task 6); el retiro del global (Task 7) ocurre después de migrar el Resolver. Cada commit deja los gates verdes.
- **Consumidores de tests no obvios (corregido tras auditoría del plan):** el cambio de constructor de `Resolver` toca también `DescriptionTest`, `RobotsTest`, `ContentPiecesTest` (Task 6, Step 8); la nueva rama `is_taxonomy()` exige mockear `is_category/is_tag/is_tax` en los tests no-singular (`DescriptionTest`, `ContentPiecesTest`); el `OptionsTest` existente vive en `tests/Unit/` (no en `Settings/`) y se extiende, no se duplica; `RestSettingsTest` y los asserts viejos de `OptionsTest` sobre `title_template`/`description_template` se actualizan en Task 7. La rama anidada de `sanitize()` es condicional al input para no romper los tests de sanitize existentes.
