# Robots por tipo + Global Meta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir robots por defecto a nivel global y por tipo de contenido/taxonomía (5 directivas booleanas + noindex de términos vacíos), con override por entrada tri-estado, cascada `entrada → tipo → global`, reflejado en el `<meta robots>` del frontend y en el sitemap.

**Architecture:** Una unidad pura `Meta\RobotsResolver::resolve(entry,type,global)` implementa la cascada por directiva; `Meta\Resolver::robots()` se reescribe para singular/taxonomía/otros usándola; los datos viven en `openseo_settings` (`robots` global + `robots` tri-estado dentro de `post_types`/`taxonomies`) y en el meta por entrada (tri-estado, `'1'` legado = `'on'`). UI en la pestaña General, en `TypePanel` y en el editor. El sitemap se alinea con la cascada.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, `@wordpress/scripts` (React/JS + Jest), WordPress 7.0 (`WP_Sitemaps`).

## Global Constraints

- WP 7.0+, PHP 8.1+. `declare(strict_types=1);` en PHP nuevo. Text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS).
- Directivas: `noindex, nofollow, noarchive, nosnippet, noimageindex` (+ global `noindex_empty_terms`). Sin numéricos avanzados.
- Override por entrada **tri-estado** `''` (heredar) | `'on'` | `'off'`; el legado `'1'` se lee como `'on'`. Por entrada solo noindex/nofollow.
- Por tipo/taxonomía: `robots` tri-estado por directiva (`''`/ausente = heredar, `'on'`, `'off'`). Global: `robots` booleano (`'1'`/`''`).
- `RobotsResolver::resolve(string $entry, string $type, bool $global): bool` — entry (`'on'`/`'1'`→true, `'off'`→false, else siguiente) → type (igual) → global (bool). El cast global `'1'→bool` lo hace `Resolver::robots()`, NO `RobotsResolver` (que permanece puro).
- Salida: `index|noindex`, `follow|nofollow`, + `noarchive`/`nosnippet`/`noimageindex` cuando activas. Default global vacío → `index, follow` (sin regresión).
- Sitemap: excluir entradas con meta `'on'` **o** `'1'`; omitir el sub-sitemap de tipos/taxonomías cuyo robots efectivo (tipo→global) sea noindex.
- React hooks desde `@wordpress/element`; i18n vía `@wordpress/i18n`; sin `dangerouslySetInnerHTML`; bootstrap ya usa `JSON_HEX_TAG`.
- Gates verdes por commit que toque su capa: `composer lint`, `composer analyze` (`--memory-limit=1G`), `composer test:unit`; JS `npm run lint:js`, `npm run test:js`, `npm run build` al tocar assets.
- Sin atribución en commits. Conventional commits.

---

## File Structure

**PHP (nuevos):** `src/Meta/RobotsResolver.php`.
**PHP (modificados):** `src/Meta/Resolver.php` (`robots()` + `robots_string()`), `src/Settings/Options.php` (defaults + sanitize global + `sanitize_template_map`), `src/Sitemap/Sitemap.php` (meta `'on'`/`'1'` + 2 filtros).
**JS (nuevos):** `assets/src/admin/robots.js` (+ `.test.js`), `assets/src/admin/components/RobotsFields.js`.
**JS (modificados):** `assets/src/admin/views/Titles.js` (GeneralPanel + TypePanel), `assets/src/editor/index.js` (AdvancedTab + GeneralTab badge).
**Tests (nuevos):** `tests/Unit/Meta/RobotsResolverTest.php`, `assets/src/admin/robots.test.js`.
**Tests (modificados):** `tests/Unit/Meta/ResolverTest.php`, `tests/Unit/OptionsTest.php`, `tests/Unit/Sitemap/SitemapTest.php`, `tests/Unit/Frontend/Head/RobotsTest.php` (setUp mocks), `tests/bootstrap-unit.php` (`WP_Term` `count`).

---

## Task 1: `Meta\RobotsResolver` (cascada pura)

**Files:**
- Create: `src/Meta/RobotsResolver.php`
- Test: `tests/Unit/Meta/RobotsResolverTest.php`

**Interfaces:**
- Produces: `OpenSEO\Meta\RobotsResolver::resolve(string $entry, string $type, bool $global): bool` (static, pure).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use OpenSEO\Meta\RobotsResolver;
use PHPUnit\Framework\TestCase;

final class RobotsResolverTest extends TestCase {

	public function test_entry_on_wins_over_type_and_global(): void {
		$this->assertTrue( RobotsResolver::resolve( 'on', 'off', false ) );
	}

	public function test_entry_off_wins_and_forces_false(): void {
		$this->assertFalse( RobotsResolver::resolve( 'off', 'on', true ) );
	}

	public function test_legacy_1_is_treated_as_on(): void {
		$this->assertTrue( RobotsResolver::resolve( '1', '', false ) );
	}

	public function test_falls_through_entry_to_type(): void {
		$this->assertTrue( RobotsResolver::resolve( '', 'on', false ) );
		$this->assertFalse( RobotsResolver::resolve( '', 'off', true ) );
	}

	public function test_falls_through_to_global_when_all_inherit(): void {
		$this->assertTrue( RobotsResolver::resolve( '', '', true ) );
		$this->assertFalse( RobotsResolver::resolve( '', '', false ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RobotsResolverTest`
Expected: FAIL — `Class "OpenSEO\Meta\RobotsResolver" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Per-directive robots cascade: entry → type → global.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

/**
 * Resolves one robots directive across the three levels. Pure (no WordPress):
 * the most specific level with an opinion wins. Tri-state strings: 'on'/'1' =
 * force on, 'off' = force off, '' (or anything else) = inherit the next level.
 */
final class RobotsResolver {

	/**
	 * Effective boolean for one directive.
	 *
	 * @param string $entry  Per-entry value ('on'|'1'|'off'|'').
	 * @param string $type   Per-type value ('on'|'off'|'').
	 * @param bool   $global Global default (already cast to bool by the caller).
	 */
	public static function resolve( string $entry, string $type, bool $global ): bool {
		$at_entry = self::level( $entry );
		if ( null !== $at_entry ) {
			return $at_entry;
		}

		$at_type = self::level( $type );
		if ( null !== $at_type ) {
			return $at_type;
		}

		return $global;
	}

	/**
	 * Tri-state value to bool|null (null = inherit).
	 *
	 * @param string $value Tri-state string.
	 */
	private static function level( string $value ): ?bool {
		if ( 'on' === $value || '1' === $value ) {
			return true;
		}
		if ( 'off' === $value ) {
			return false;
		}
		return null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RobotsResolverTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Meta/RobotsResolver.php tests/Unit/Meta/RobotsResolverTest.php
git commit -m "feat(meta): add RobotsResolver (per-directive entry/type/global cascade)"
```

---

## Task 2: `Options` — defaults `robots` global + sanitize (global + por tipo)

**Files:**
- Modify: `src/Settings/Options.php` (defaults; `sanitize()` global robots; `sanitize_template_map()` robots field)
- Modify: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Produces: `Options::all()['robots']` (array). `sanitize()` accepts a `robots` group (whitelisted booleans) and per-type `robots` tri-state inside `post_types`/`taxonomies`.

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/OptionsTest.php` (the existing `setUp` already mocks `sanitize_text_field`/`sanitize_textarea_field`/`wp_unslash`/`get_post_types`/`get_taxonomies`):

```php
	public function test_defaults_include_empty_robots(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( array(), ( new Options() )->all()['robots'] );
	}

	public function test_sanitize_global_robots_whitelists_and_normalizes(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$clean = ( new Options() )->sanitize(
			array(
				'robots' => array(
					'noindex'             => '1',
					'nofollow'            => '',
					'noindex_empty_terms' => '1',
					'bogus'               => '1',
				),
			)
		);

		$this->assertSame( '1', $clean['robots']['noindex'] );
		$this->assertSame( '1', $clean['robots']['noindex_empty_terms'] );
		$this->assertArrayNotHasKey( 'nofollow', $clean['robots'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['robots'] );
	}

	public function test_sanitize_per_type_robots_tristate(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'post_types' => array(
					'post' => array(
						'title'  => '',
						'robots' => array( 'noindex' => 'on', 'nofollow' => 'off', 'bogus' => 'x', 'noarchive' => 'maybe' ),
					),
				),
			)
		);

		$this->assertSame( 'on', $clean['post_types']['post']['robots']['noindex'] );
		$this->assertSame( 'off', $clean['post_types']['post']['robots']['nofollow'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['post_types']['post']['robots'] );
		$this->assertArrayNotHasKey( 'noarchive', $clean['post_types']['post']['robots'] ); // 'maybe' invalid → dropped
	}

	public function test_sanitize_keeps_slug_with_only_robots(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '', 'robots' => array( 'noindex' => 'on' ) ) ) )
		);

		$this->assertArrayHasKey( 'post', $clean['post_types'] );
		$this->assertSame( 'on', $clean['post_types']['post']['robots']['noindex'] );
	}

	public function test_sanitize_unsets_slug_when_all_three_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => 'Old', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '', 'robots' => array() ) ) )
		);

		$this->assertArrayNotHasKey( 'post', $clean['post_types'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL — `robots` default missing / robots not sanitized.

- [ ] **Step 3: Add the `robots` default**

In `src/Settings/Options.php` `defaults()`, add after the `'taxonomies' => array(),` line:

```php
			'robots'                   => array(),
```

- [ ] **Step 4: Sanitize the global `robots` group**

In `sanitize()`, immediately before `return $clean;`, add:

```php
		if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
			$allowed_global = array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'noindex_empty_terms' );
			$robots         = array();
			foreach ( $allowed_global as $directive ) {
				if ( '1' === (string) ( $input['robots'][ $directive ] ?? '' ) ) {
					$robots[ $directive ] = '1';
				}
			}
			$clean['robots'] = $robots;
		}
```

- [ ] **Step 5: Extend `sanitize_template_map` for the per-type `robots` field**

In `src/Settings/Options.php`, replace the tail of `sanitize_template_map()` (from the `$description = …` assignment through the end of the loop body) with the version that also handles `robots`:

```php
			$description = array_key_exists( 'description', $fields )
				? sanitize_textarea_field( wp_unslash( (string) $fields['description'] ) )
				: (string) ( $current[ $slug ]['description'] ?? '' );

			if ( array_key_exists( 'robots', $fields ) && is_array( $fields['robots'] ) ) {
				$robots = array();
				foreach ( array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) as $directive ) {
					$value = (string) ( $fields['robots'][ $directive ] ?? '' );
					if ( 'on' === $value || 'off' === $value ) {
						$robots[ $directive ] = $value;
					}
				}
			} else {
				$robots = is_array( $current[ $slug ]['robots'] ?? null ) ? $current[ $slug ]['robots'] : array();
			}

			if ( '' === $title && '' === $description && empty( $robots ) ) {
				unset( $current[ $slug ] );
				continue;
			}

			$entry = array(
				'title'       => $title,
				'description' => $description,
			);
			if ( ! empty( $robots ) ) {
				$entry['robots'] = $robots;
			}

			$current[ $slug ] = $entry;
```

(Update the method's `@return`/`@param` docblock array shapes to allow a nested `robots` map: `array<string, array{title:string,description:string,robots?:array<string,string>}>`.)

- [ ] **Step 6: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: PASS (existing + 5 new).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(settings): global robots defaults + per-type robots tri-state sanitize"
```

---

## Task 3: `Resolver::robots()` — cascada por directiva

**Files:**
- Modify: `src/Meta/Resolver.php` (`robots()` rewrite + `robots_string()` helper)
- Modify: `tests/Unit/Meta/ResolverTest.php`
- Modify: `tests/Unit/Frontend/Head/RobotsTest.php` (setUp mocks for the new code path)
- Modify: `tests/bootstrap-unit.php` (add `count` to the `WP_Term` polyfill)

**Interfaces:**
- Consumes: `RobotsResolver::resolve` (Task 1), `Options` `robots`/`post_types`/`taxonomies` (Task 2).
- Produces: `Resolver::robots()` returns the effective directive string across singular/taxonomy/other.

- [ ] **Step 1: Add failing tests (new cascade behavior)**

Add to `tests/Unit/Meta/ResolverTest.php`:

```php
	public function test_robots_global_noindex_applies_when_no_overrides(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array( 'robots' => array( 'noindex' => '1' ) ) );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_robots_per_type_overrides_global(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'robots'     => array( 'noindex' => '1' ),
				'post_types' => array( 'page' => array( 'robots' => array( 'noindex' => 'off' ) ) ),
			)
		);

		// Type forces index over a global noindex.
		$this->assertSame( 'index, follow', $this->resolver()->robots() );
	}

	public function test_robots_entry_off_overrides_type_noindex(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_robots_noindex' === $key ? 'off' : ''
		);
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'robots' => array( 'noindex' => 'on' ) ) ) )
		);

		$this->assertSame( 'index, follow', $this->resolver()->robots() );
	}

	public function test_robots_adds_extra_directives_from_type(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'robots' => array( 'noarchive' => 'on', 'nosnippet' => 'on' ) ) ) )
		);

		$this->assertSame( 'index, follow, noarchive, nosnippet', $this->resolver()->robots() );
	}

	public function test_robots_taxonomy_empty_term_forces_noindex(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'robots' => array( 'noindex_empty_terms' => '1' ) ) );

		$term        = new WP_Term();
		$term->name  = 'Empty';
		$term->count = 0;
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}
```

(The existing `test_robots_*` tests — default `index, follow`, per-entry `'1'` → noindex/nofollow — must stay green: `'1'` is read as on, and an empty global yields `index, follow`.)

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: FAIL — global/type robots not yet honored.

- [ ] **Step 3: Add the import**

In `src/Meta/Resolver.php`, add below `use OpenSEO\Meta\TypeTemplates;`:

```php
use OpenSEO\Meta\RobotsResolver;
```

- [ ] **Step 4: Rewrite `robots()`**

Replace the existing `robots()` method body with:

```php
	public function robots(): string {
		$global_map = $this->options->get( 'robots' );
		$global_map = is_array( $global_map ) ? $global_map : array();
		$global     = static fn( string $directive ): bool => '1' === (string) ( $global_map[ $directive ] ?? '' );

		$type_robots = array();
		$entry       = array();
		$force_noindex_empty = false;

		if ( is_singular() ) {
			$id   = get_queried_object_id();
			$type = (string) get_post_type( $id );
			$map  = $this->options->get( 'post_types' );
			$type_robots = is_array( $map ) && is_array( $map[ $type ]['robots'] ?? null ) ? $map[ $type ]['robots'] : array();
			$entry = array(
				'noindex'  => (string) get_post_meta( $id, '_openseo_robots_noindex', true ),
				'nofollow' => (string) get_post_meta( $id, '_openseo_robots_nofollow', true ),
			);
		} elseif ( $this->is_taxonomy() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$map = $this->options->get( 'taxonomies' );
				$type_robots = is_array( $map ) && is_array( $map[ $term->taxonomy ]['robots'] ?? null ) ? $map[ $term->taxonomy ]['robots'] : array();
				if ( $global( 'noindex_empty_terms' ) && 0 === (int) $term->count ) {
					$force_noindex_empty = true;
				}
			}
		}

		$effective = array();
		foreach ( array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) as $directive ) {
			$entry_val = ( 'noindex' === $directive || 'nofollow' === $directive ) ? (string) ( $entry[ $directive ] ?? '' ) : '';
			$type_val  = (string) ( $type_robots[ $directive ] ?? '' );
			$effective[ $directive ] = RobotsResolver::resolve( $entry_val, $type_val, $global( $directive ) );
		}

		if ( $force_noindex_empty ) {
			$effective['noindex'] = true;
		}

		return $this->robots_string( $effective );
	}

	/**
	 * Build the robots directive string from the effective map.
	 *
	 * @param array<string, bool> $e Effective directives.
	 */
	private function robots_string( array $e ): string {
		$parts = array(
			$e['noindex'] ? 'noindex' : 'index',
			$e['nofollow'] ? 'nofollow' : 'follow',
		);
		if ( $e['noarchive'] ) {
			$parts[] = 'noarchive';
		}
		if ( $e['nosnippet'] ) {
			$parts[] = 'nosnippet';
		}
		if ( $e['noimageindex'] ) {
			$parts[] = 'noimageindex';
		}

		return implode( ', ', $parts );
	}
```

(`is_taxonomy()` and the `WP_Term` import already exist from sub-project 1.)

- [ ] **Step 5: Update `RobotsTest::setUp` + the `WP_Term` polyfill (the rewritten path needs them)**

The rewritten `robots()` now always calls `get_option('robots')` and, on non-singular requests,
`is_category()/is_tag()/is_tax()` (and `get_post_type()` on singular). `tests/Unit/Frontend/Head/RobotsTest.php`
only mocks `esc_attr` in `setUp`, so its two tests would FATAL under Brain Monkey (unmocked WP call).
In its `setUp()`, after `Functions\when( 'esc_attr' )->returnArg();`, add:

```php
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
```

Also, the new test `test_robots_taxonomy_empty_term_forces_noindex` sets `$term->count`, but the
`WP_Term` polyfill in `tests/bootstrap-unit.php` does not declare it (dynamic-property deprecation on
PHP 8.2+). Add the property to the polyfill class:

```php
		public int $count = 0;
```

- [ ] **Step 6: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter "ResolverTest|RobotsTest"`
Expected: PASS (new cascade tests + existing `RobotsTest` presenter tests, now with the added mocks).

Run: `composer test:unit`
Expected: Whole suite green (incl. `PluginBootTest` noindex reflection).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php tests/Unit/Frontend/Head/RobotsTest.php tests/bootstrap-unit.php
git commit -m "feat(meta): robots() cascade across entry/type/global + extra directives"
```

---

## Task 4: `Sitemap` — alinear con la cascada

**Files:**
- Modify: `src/Sitemap/Sitemap.php` (`exclude_noindex` accepts `'on'`; 2 new filters + callbacks)
- Modify: `tests/Unit/Sitemap/SitemapTest.php`

**Interfaces:**
- Consumes: `RobotsResolver::resolve` (Task 1), `Options` `robots`/`post_types`/`taxonomies`.

- [ ] **Step 1: Update + add failing tests**

In `tests/Unit/Sitemap/SitemapTest.php`, update `test_exclude_noindex_builds_or_clause`: that test
calls `exclude_noindex( array() )` and inspects the returned args under the variable **`$args`** (not
`$clause`). **Replace** its two value/compare assertions (the `[1]['value'] === '1'` and
`[1]['compare'] === '!='` lines) with:

```php
		$this->assertSame( array( '1', 'on' ), $args['meta_query'][1]['value'] );
		$this->assertSame( 'NOT IN', $args['meta_query'][1]['compare'] );
```
And add an exclusion test:

Add:

```php
	public function test_excludes_noindex_post_type_provider(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'page' => array( 'robots' => array( 'noindex' => 'on' ) ) ) )
		);

		$page = new \stdClass();
		$post = new \stdClass();
		$items = array( 'post' => $post, 'page' => $page );

		$result = ( new Sitemap( new Options() ) )->exclude_noindex_post_types( $items );

		$this->assertArrayHasKey( 'post', $result );
		$this->assertArrayNotHasKey( 'page', $result );
	}

	public function test_keeps_post_types_when_not_noindex(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$items = array( 'post' => new \stdClass(), 'page' => new \stdClass() );

		$result = ( new Sitemap( new Options() ) )->exclude_noindex_post_types( $items );

		$this->assertCount( 2, $result );
	}
```

> Adjust the variable name (`$clause`) to match the existing test's call shape. If the existing test calls `exclude_noindex( array() )` and inspects the returned `meta_query`, keep that structure and only change the value/compare assertions.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SitemapTest`
Expected: FAIL — `'on'` not excluded / `exclude_noindex_post_types` missing.

- [ ] **Step 3: Add the import + register the two new filters**

In `src/Sitemap/Sitemap.php`, add below `use OpenSEO\Settings\Options;`:

```php
use OpenSEO\Meta\RobotsResolver;
```

In `register()`, add after the `wp_sitemaps_posts_query_args` filter:

```php
		add_filter( 'wp_sitemaps_post_types', array( $this, 'exclude_noindex_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'exclude_noindex_taxonomies' ) );
```

- [ ] **Step 4: Accept `'on'` in the per-entry exclusion**

In `exclude_noindex()`, replace the second `$exclusion` clause:

```php
			array(
				'key'     => self::NOINDEX_META_KEY,
				'value'   => '1',
				'compare' => '!=',
			),
```
with:
```php
			array(
				'key'     => self::NOINDEX_META_KEY,
				'value'   => array( '1', 'on' ),
				'compare' => 'NOT IN',
			),
```

- [ ] **Step 5: Add the provider-exclusion callbacks**

Add to the class (e.g. after `exclude_noindex()`):

```php
	/**
	 * Drop post type providers whose effective robots (type → global) is noindex.
	 *
	 * @param mixed $post_types Array of WP_Post_Type keyed by slug.
	 * @return mixed Filtered array.
	 */
	public function exclude_noindex_post_types( $post_types ): mixed {
		return $this->filter_noindex_providers( $post_types, 'post_types' );
	}

	/**
	 * Drop taxonomy providers whose effective robots (type → global) is noindex.
	 *
	 * @param mixed $taxonomies Array of WP_Taxonomy keyed by slug.
	 * @return mixed Filtered array.
	 */
	public function exclude_noindex_taxonomies( $taxonomies ): mixed {
		return $this->filter_noindex_providers( $taxonomies, 'taxonomies' );
	}

	/**
	 * Unset providers whose effective noindex (type → global) resolves true.
	 *
	 * @param mixed  $items Array keyed by slug.
	 * @param string $group 'post_types' or 'taxonomies'.
	 * @return mixed
	 */
	private function filter_noindex_providers( $items, string $group ): mixed {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$global_map     = $this->options->get( 'robots' );
		$global_map     = is_array( $global_map ) ? $global_map : array();
		$global_noindex = '1' === (string) ( $global_map['noindex'] ?? '' );

		$map = $this->options->get( $group );
		$map = is_array( $map ) ? $map : array();

		foreach ( array_keys( $items ) as $slug ) {
			$type_val = (string) ( $map[ (string) $slug ]['robots']['noindex'] ?? '' );
			if ( RobotsResolver::resolve( '', $type_val, $global_noindex ) ) {
				unset( $items[ $slug ] );
			}
		}

		return $items;
	}
```

- [ ] **Step 6: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter SitemapTest`
Expected: PASS.

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add src/Sitemap/Sitemap.php tests/Unit/Sitemap/SitemapTest.php
git commit -m "feat(sitemap): exclude noindex providers + accept 'on' meta in cascade"
```

---

## Task 5: `robots.js` — directivas + `setRobotsField` (puro)

**Files:**
- Create: `assets/src/admin/robots.js`
- Test: `assets/src/admin/robots.test.js`

**Interfaces:**
- Produces: `ROBOTS_DIRECTIVES` (`['noindex','nofollow','noarchive','nosnippet','noimageindex']`); `setRobotsField(robots, directive, value)` → new map (value `''` deletes the directive; `'on'`/`'off'` set it; never mutates).

- [ ] **Step 1: Write the failing test**

```js
import { ROBOTS_DIRECTIVES, setRobotsField } from './robots';

describe( 'ROBOTS_DIRECTIVES', () => {
	it( 'lists the five boolean directives', () => {
		expect( ROBOTS_DIRECTIVES ).toEqual( [
			'noindex',
			'nofollow',
			'noarchive',
			'nosnippet',
			'noimageindex',
		] );
	} );
} );

describe( 'setRobotsField', () => {
	it( 'sets a directive without mutating the input', () => {
		const map = { noindex: 'on' };
		const next = setRobotsField( map, 'nofollow', 'off' );
		expect( next ).toEqual( { noindex: 'on', nofollow: 'off' } );
		expect( map ).toEqual( { noindex: 'on' } );
	} );

	it( 'deletes the directive when value is empty (inherit)', () => {
		const next = setRobotsField( { noindex: 'on' }, 'noindex', '' );
		expect( next ).toEqual( {} );
	} );

	it( 'tolerates a missing map', () => {
		expect( setRobotsField( undefined, 'noindex', 'on' ) ).toEqual( { noindex: 'on' } );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- robots.test.js`
Expected: FAIL — cannot find module `./robots`.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Pure helpers for robots directive maps (no i18n — labels live in components).
 */

export const ROBOTS_DIRECTIVES = [
	'noindex',
	'nofollow',
	'noarchive',
	'nosnippet',
	'noimageindex',
];

/**
 * Immutably set one tri-state directive. '' (inherit) removes it from the map.
 *
 * @param {Object} robots    Current directive map.
 * @param {string} directive Directive key.
 * @param {string} value     '' | 'on' | 'off'.
 * @return {Object} A new map; the input is not mutated.
 */
export function setRobotsField( robots, directive, value ) {
	const next = { ...( robots ?? {} ) };
	if ( value === '' ) {
		delete next[ directive ];
	} else {
		next[ directive ] = value;
	}
	return next;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- robots.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/robots.js assets/src/admin/robots.test.js
git commit -m "feat(admin): robots directives + setRobotsField helper"
```

---

## Task 6: `RobotsFields` component (tri-state selects)

**Files:**
- Create: `assets/src/admin/components/RobotsFields.js`

**Interfaces:**
- Consumes: `ROBOTS_DIRECTIVES`, `setRobotsField` (Task 5); `@wordpress/components` `SelectControl`; `@wordpress/i18n` `__`.
- Produces: `RobotsFields({ robots, onChange })` (calls `onChange(newRobotsMap)`); exports `ROBOTS_LABELS` for reuse by the global checkboxes.

- [ ] **Step 1: Write the component**

```jsx
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ROBOTS_DIRECTIVES, setRobotsField } from '../robots';

export const ROBOTS_LABELS = {
	noindex: __( 'No index', 'openseo' ),
	nofollow: __( 'No follow', 'openseo' ),
	noarchive: __( 'No archive', 'openseo' ),
	nosnippet: __( 'No snippet', 'openseo' ),
	noimageindex: __( 'No image index', 'openseo' ),
};

const TRISTATE_OPTIONS = [
	{ label: __( 'Default', 'openseo' ), value: '' },
	{ label: __( 'Yes', 'openseo' ), value: 'on' },
	{ label: __( 'No', 'openseo' ), value: 'off' },
];

export function RobotsFields( { robots, onChange } ) {
	const map = robots ?? {};

	return (
		<>
			{ ROBOTS_DIRECTIVES.map( ( directive ) => (
				<SelectControl
					key={ directive }
					__nextHasNoMarginBottom
					label={ ROBOTS_LABELS[ directive ] }
					value={ map[ directive ] ?? '' }
					options={ TRISTATE_OPTIONS }
					onChange={ ( value ) =>
						onChange( setRobotsField( map, directive, value ) )
					}
				/>
			) ) }
		</>
	);
}
```

- [ ] **Step 2: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors (whole project).

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/RobotsFields.js
git commit -m "feat(admin): add RobotsFields tri-state component"
```

---

## Task 7: `Titles.js` — robots global (General) + `RobotsFields` (TypePanel)

**Files:**
- Modify: `assets/src/admin/views/Titles.js`

**Interfaces:**
- Consumes: `RobotsFields`, `ROBOTS_LABELS` (Task 6), `ROBOTS_DIRECTIVES` (Task 5); `@wordpress/components` `CheckboxControl`, `ToggleControl`.

- [ ] **Step 1: Add imports**

In `assets/src/admin/views/Titles.js`, add `CheckboxControl` and `ToggleControl` to the existing `@wordpress/components` import, and add:

```jsx
import { ROBOTS_DIRECTIVES } from '../robots';
import { RobotsFields, ROBOTS_LABELS } from '../components/RobotsFields';
```

- [ ] **Step 2: Add the global robots section to `GeneralPanel`**

In `GeneralPanel`, after the `home_description` `TemplateField`, add (inside the fragment):

```jsx
			<h3>{ __( 'Default robots', 'openseo' ) }</h3>
			{ ROBOTS_DIRECTIVES.map( ( directive ) => (
				<CheckboxControl
					key={ directive }
					__nextHasNoMarginBottom
					label={ ROBOTS_LABELS[ directive ] }
					checked={ ( values.robots ?? {} )[ directive ] === '1' }
					onChange={ ( on ) =>
						change( 'robots', {
							...( values.robots ?? {} ),
							[ directive ]: on ? '1' : '',
						} )
					}
				/>
			) ) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex empty term archives', 'openseo' ) }
				checked={ ( values.robots ?? {} ).noindex_empty_terms === '1' }
				onChange={ ( on ) =>
					change( 'robots', {
						...( values.robots ?? {} ),
						noindex_empty_terms: on ? '1' : '',
					} )
				}
			/>
```

- [ ] **Step 3: Add `RobotsFields` to `TypePanel`**

In `TypePanel`, after the description `TemplateField`, add (inside the fragment):

```jsx
			<h3>{ __( 'Robots', 'openseo' ) }</h3>
			<RobotsFields
				robots={ entry.robots }
				onChange={ ( nextRobots ) =>
					change( mapKey, {
						...map,
						[ type.slug ]: { ...entry, robots: nextRobots },
					} )
				}
			/>
```

- [ ] **Step 4: Lint, test, build**

Run: `npm run lint:js`
Expected: No ESLint errors.

Run: `npm run test:js`
Expected: All suites pass.

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/views/Titles.js
git commit -m "feat(admin): global robots in General + per-type RobotsFields"
```

---

## Task 8: Editor — `AdvancedTab` tri-state + `GeneralTab` badge

**Files:**
- Modify: `assets/src/editor/index.js`

**Interfaces:**
- Consumes: `@wordpress/components` `SelectControl` (already imported).

- [ ] **Step 1: Define the tri-state options + a helper near the top of the editor module**

In `assets/src/editor/index.js`, after the imports, add:

```jsx
const ROBOTS_TRISTATE = [
	{ label: __( 'Default', 'openseo' ), value: '' },
	{ label: __( 'Yes', 'openseo' ), value: 'on' },
	{ label: __( 'No', 'openseo' ), value: 'off' },
];

// Legacy '1' (old binary toggle) reads as 'on'.
const triValue = ( v ) => ( v === '1' ? 'on' : v );
const isNoindexValue = ( v ) => v === 'on' || v === '1';
```

- [ ] **Step 2: Fix the `GeneralTab` badge (HIGH-1)**

In `GeneralTab`, change the `SerpPreview` prop:

```jsx
				isNoindex={ noindex === '1' }
```
to:
```jsx
				isNoindex={ isNoindexValue( noindex ) }
```

- [ ] **Step 3: Convert the `AdvancedTab` toggles to tri-state selects**

In `AdvancedTab`, replace the two `ToggleControl` blocks:

```jsx
			<ToggleControl
				label={ __( 'No index', 'openseo' ) }
				checked={ noindex === '1' }
				onChange={ ( on ) => setNoindex( on ? '1' : '' ) }
			/>
			<ToggleControl
				label={ __( 'No follow', 'openseo' ) }
				checked={ nofollow === '1' }
				onChange={ ( on ) => setNofollow( on ? '1' : '' ) }
			/>
```
with:
```jsx
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'No index', 'openseo' ) }
				value={ triValue( noindex ) }
				options={ ROBOTS_TRISTATE }
				onChange={ setNoindex }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'No follow', 'openseo' ) }
				value={ triValue( nofollow ) }
				options={ ROBOTS_TRISTATE }
				onChange={ setNofollow }
			/>
```

(`SelectControl` is already imported in `editor/index.js`. After this change `ToggleControl` is no
longer used anywhere in `editor/index.js` — **remove it from the `@wordpress/components` import** or
`no-unused-vars` will fail `lint:js`.)

- [ ] **Step 4: Lint, test, build**

Run: `npm run lint:js`
Expected: No ESLint errors (remove `ToggleControl` from the import if now unused).

Run: `npm run test:js`
Expected: All suites pass.

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 5: Commit**

```bash
git add assets/src/editor/index.js
git commit -m "feat(editor): tri-state noindex/nofollow + noindex badge reads 'on'"
```

---

## Task 9: Verificación final completa

**Files:** none (verification only).

- [ ] **Step 1: PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green (incl. `RobotsResolverTest`, `ResolverTest`, `OptionsTest`, `SitemapTest`, and the unchanged `RobotsTest`/`PluginBootTest`).

- [ ] **Step 2: JS gates**

Run: `npm run lint:js`
Expected: No errors.

Run: `npm run test:js`
Expected: All suites pass (incl. `robots`).

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Manual smoke test (wp-env, recommended)**

```bash
npm run env:start
```
In **OpenSEO → Titles & Meta → General**: tick a default robots checkbox (e.g. noindex) → a post's source shows `<meta name="robots" content="noindex, follow">`. On a content-type tab set its robots to "No" (off) → that type forces index over a global noindex. In the editor (Advanced tab) set No index = "Yes"/"No"/"Default" and confirm the head + the SERP preview badge. Mark a taxonomy noindex → its archive emits noindex and its sub-sitemap disappears from `/wp-sitemap.xml`.

- [ ] **Step 4: Regenerate the `.pot` (new strings)**

```bash
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo languages/openseo.pot
git add languages/openseo.pot && git commit -m "chore(i18n): regenerate .pot for robots strings"
```
(If wp-env is unavailable, note it as a deferred follow-up — do not skip silently.)

- [ ] **Step 5: Final commit (only if artifacts/fixes remain)**

```bash
git add -A
git commit -m "chore(meta): final verification for robots per type + global meta"
```

(Skip if nothing to commit.)

---

## Self-Review (completed during planning)

- **Spec coverage:** `RobotsResolver` (Task 1), global defaults + sanitize global/por-tipo (Task 2), `robots()` cascade + string + términos vacíos (Task 3), sitemap meta `'on'` + 2 filtros de provider (Task 4), helpers JS (Task 5), `RobotsFields` (Task 6), UI global + por tipo (Task 7), editor tri-estado + badge HIGH-1 (Task 8). Todos los criterios de aceptación tienen tarea.
- **Placeholder scan:** sin TBD/TODO; cada paso de código muestra el código y el comando con salida esperada.
- **Type/símbolo consistency:** `RobotsResolver::resolve` (1) usado por `Resolver::robots()` (3) y `Sitemap` (4); `Options['robots']`/`post_types[].robots`/`taxonomies[].robots` producidos en Task 2, leídos en 3/4/7; `ROBOTS_DIRECTIVES`/`setRobotsField` (5) usados en `RobotsFields` (6) y `Titles.js` (7); `ROBOTS_LABELS` (6) reusado por el global en `Titles.js` (7); badge `isNoindexValue` (8).
- **Verde por commit:** Task 2 añade `robots` a defaults antes de que Task 3 lo lea; Task 1 (puro) y Task 5 (puro) preceden a sus consumidores; los componentes JS nuevos (6) no se importan hasta Task 7; el cambio del editor (8) es autónomo. `RobotsTest` **se actualiza en Task 3** (su `setUp` no mockeaba `get_option`/`is_*`/`get_post_type` que el `robots()` reescrito ahora toca — sin esos mocks fatalaría Brain Monkey); `PluginBootTest` se mantiene (`'1'`→on, global vacío→index/follow); `SitemapTest` se actualiza en Task 4.
- **Auditoría del diseño incorporada:** HIGH-1 (GeneralTab badge, Task 8), HIGH-2/HIGH-3 (sitemap providers, Task 4), MEDIUM-1 (`(int)$term->count`, Task 3), MEDIUM-3 (`RobotsResolver` puro + cast en `Resolver`), LOW-1 (`SitemapTest` actualizado, Task 4), LOW-3 (omitir robots vacío del slug, Task 2).
- **Auditoría del plan incorporada:** CRITICAL-1 (`RobotsTest::setUp` mocks, Task 3 Step 5), HIGH-1 (`count` en el polyfill `WP_Term`, Task 3 Step 5), MEDIUM-3 (quitar `ToggleControl` del import del editor — afirmativo, Task 8), MEDIUM-1 (variable `$args` y líneas exactas en `SitemapTest`, Task 4). MEDIUM-2 (TRISTATE_OPTIONS se retira de `robots.js` a propósito; vive en los componentes) y LOW-1/LOW-3 (import único en `Titles.js`; `robots:{}` transitorio que el sanitize limpia) quedan como notas no bloqueantes.
