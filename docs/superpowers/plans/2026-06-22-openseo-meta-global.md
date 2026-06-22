# Meta Global — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completar la pantalla "Meta Global" de Rank Math en OpenSEO: separador (selector de caracteres), capitalización de títulos, robots global + metadatos avanzados de robots, noindex de archivos vacíos, miniatura OpenGraph por defecto (uploader real) y tipo de tarjeta de Twitter — todo cableado a los presenters del `wp_head`.

**Architecture:** Una pestaña nueva **"Meta Global"** en la vista React `views/Titles.js` (espejo de Rank Math), con la actual pestaña interna renombrada a "Homepage". El grueso del backend ya existe (separador, robots global, OG fallback); se añaden 3 keys nuevas (`capitalize_titles`, `twitter_card_type`, `advanced_robots`) y su cableado en `Meta\Resolver` y `Frontend\Head\Twitter`, más un helper puro `Support\Str::mb_ucwords`. UI: 3 componentes nuevos (`MediaField`, `SeparatorField`, `AdvancedRobotsField`) + un helper JS puro.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, `@wordpress/scripts` (React/JS + Jest), `@wordpress/media-utils`, WordPress 7.0.

## Global Constraints

- WP 7.0+, PHP 8.1+. `declare(strict_types=1);` en PHP nuevo. Text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS). PSR-4 file naming.
- Booleanos de Meta Global (`capitalize_titles`, `advanced_robots.*.enabled`) usan `'1'`/`''` (estilo robots **global**), NO la tri-estado `'on'/'off'` del robots por-tipo.
- `twitter_card_type` whitelist `['summary_large_image','summary']`, default `summary_large_image`.
- `advanced_robots`: array anidado `{ max_snippet:{enabled,length}, max_video_preview:{enabled,length}, max_image_preview:{enabled,value} }`; `length` entero ≥ `-1` (string); `value` whitelist `['large','standard','none']`.
- Advanced robots se anexan al `<meta robots>` **solo** si el robots efectivo NO es `noindex` ni `nosnippet` (bail como Rank Math). Formato `max-snippet:-1`, `max-video-preview:-1`, `max-image-preview:large`.
- Capitalización: `Support\Str::mb_ucwords` (multibyte, solo inicial de cada palabra, preserva el resto y los espacios). Se aplica al título resuelto solo si `capitalize_titles === '1'`; `''` resuelto queda intacto (cascada "vacío = WordPress decide").
- `og_default_image` se reutiliza como key (URL, `esc_url_raw`); se edita SOLO desde Meta Global. La vista Social queda como `Notice`.
- Uploader: SOLO `MediaUpload` de `@wordpress/media-utils` (NO `MediaUploadCheck`). `Admin\Assets` llama `wp_enqueue_media()`.
- Strings UI en inglés en el source (`__( …, 'openseo' )`); la traducción al español ("Página de inicio", etc.) va por `.pot`/`.po`.
- React hooks desde `@wordpress/element`; i18n vía `@wordpress/i18n`; sin `dangerouslySetInnerHTML`; bootstrap ya usa `JSON_HEX_TAG`.
- Sin override por entrada de advanced robots / twitter card (solo global). Sin `rewrite_title`. Sin migración de datos.
- Gates verdes por commit que toque su capa: `composer lint`, `composer analyze` (`--memory-limit=1G`), `composer test:unit`; JS `npm run lint:js`, `npm run test:js`, `npm run build` al tocar assets.
- Sin atribución en commits. Conventional commits.

---

## File Structure

**PHP (nuevos):** `src/Support/Str.php`.
**PHP (modificados):** `src/Settings/Options.php` (defaults + sanitize de 3 keys + 2 helpers), `src/Meta/Resolver.php` (`title()` capitalize, `robots()` advanced, `twitter_card()`), `src/Frontend/Head/Twitter.php` (card type), `src/Admin/Assets.php` (`wp_enqueue_media`).
**JS (nuevos):** `assets/src/admin/advancedRobots.js` (+ `.test.js`), `assets/src/admin/components/MediaField.js`, `assets/src/admin/components/SeparatorField.js`, `assets/src/admin/components/AdvancedRobotsField.js`.
**JS (modificados):** `assets/src/admin/views/Titles.js` (tab Meta Global + Homepage), `assets/src/admin/views/Social.js` (Notice).
**Tests (nuevos):** `tests/Unit/Support/StrTest.php`, `tests/Unit/Frontend/Head/TwitterTest.php`, `assets/src/admin/advancedRobots.test.js`.
**Tests (modificados):** `tests/Unit/OptionsTest.php`, `tests/Unit/Meta/ResolverTest.php`.

---

## Task 1: `Support\Str::mb_ucwords` (helper puro)

**Files:**
- Create: `src/Support/Str.php`
- Test: `tests/Unit/Support/StrTest.php`

**Interfaces:**
- Produces: `OpenSEO\Support\Str::mb_ucwords(string $value): string` (static, pure) — mayúscula inicial de cada palabra, multibyte, preservando el resto y los espacios.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Support;

use OpenSEO\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase {

	public function test_uppercases_first_letter_of_each_word(): void {
		$this->assertSame( 'Hello World', Str::mb_ucwords( 'hello world' ) );
	}

	public function test_is_multibyte_safe(): void {
		$this->assertSame( 'Café Del Mar', Str::mb_ucwords( 'café del mar' ) );
	}

	public function test_preserves_rest_of_word_uppercase(): void {
		// Only the first char is forced up; the rest is preserved (RM parity).
		$this->assertSame( 'IPhone', Str::mb_ucwords( 'iPhone' ) );
	}

	public function test_preserves_multiple_spaces(): void {
		$this->assertSame( 'A  B', Str::mb_ucwords( 'a  b' ) );
	}

	public function test_empty_string_stays_empty(): void {
		$this->assertSame( '', Str::mb_ucwords( '' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter StrTest`
Expected: FAIL — `Class "OpenSEO\Support\Str" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Small pure string helpers (no WordPress).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Support;

/**
 * Multibyte-safe string utilities.
 */
final class Str {

	/**
	 * Uppercase the first letter of each word, preserving the rest of the word
	 * and the original whitespace. Mirrors Rank Math's Str::mb_ucwords (only the
	 * initial character is forced up; "iPhone" → "IPhone").
	 *
	 * @param string $value Input string.
	 */
	public static function mb_ucwords( string $value ): string {
		$words = preg_split( '/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $words ) ) {
			return $value;
		}

		$out = '';
		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			$out .= mb_strtoupper( mb_substr( $word, 0, 1 ) ) . mb_substr( $word, 1 );
		}

		return $out;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter StrTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Static analysis**

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Support/Str.php tests/Unit/Support/StrTest.php
git commit -m "feat(support): add Str::mb_ucwords multibyte title-case helper"
```

---

## Task 2: `Options` — defaults + sanitize de las 3 keys nuevas

**Files:**
- Modify: `src/Settings/Options.php` (defaults; sanitize; 2 helpers)
- Modify: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Produces: `Options::all()` incluye `capitalize_titles` (`''`), `twitter_card_type` (`'summary_large_image'`), `advanced_robots` (array anidado). `sanitize()` normaliza las tres.

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/OptionsTest.php` (its `setUp` already mocks `sanitize_textarea_field`/`get_post_types`/`get_taxonomies`; `sanitize_text_field`/`wp_unslash`/`esc_url_raw` are mocked per-test):

```php
	public function test_defaults_include_meta_global_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$options = new Options();

		$this->assertSame( '', $options->get( 'capitalize_titles' ) );
		$this->assertSame( 'summary_large_image', $options->get( 'twitter_card_type' ) );
		$this->assertSame(
			array( 'enabled' => '', 'value' => 'large' ),
			$options->get( 'advanced_robots' )['max_image_preview']
		);
	}

	public function test_sanitize_capitalize_titles_checkbox(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$on  = ( new Options() )->sanitize( array( 'capitalize_titles' => '1' ) );
		$off = ( new Options() )->sanitize( array( 'capitalize_titles' => '0' ) );

		$this->assertSame( '1', $on['capitalize_titles'] );
		$this->assertSame( '', $off['capitalize_titles'] );
	}

	public function test_sanitize_twitter_card_type_whitelist(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$valid   = ( new Options() )->sanitize( array( 'twitter_card_type' => 'summary' ) );
		$invalid = ( new Options() )->sanitize( array( 'twitter_card_type' => 'bogus' ) );

		$this->assertSame( 'summary', $valid['twitter_card_type'] );
		$this->assertSame( 'summary_large_image', $invalid['twitter_card_type'] );
	}

	public function test_sanitize_advanced_robots(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'advanced_robots' => array(
					'max_snippet'       => array( 'enabled' => '1', 'length' => '50' ),
					'max_video_preview' => array( 'enabled' => '', 'length' => '-5' ),
					'max_image_preview' => array( 'enabled' => '1', 'value' => 'bogus' ),
				),
			)
		);

		$this->assertSame( array( 'enabled' => '1', 'length' => '50' ), $clean['advanced_robots']['max_snippet'] );
		$this->assertSame( array( 'enabled' => '', 'length' => '-1' ), $clean['advanced_robots']['max_video_preview'] ); // -5 clamped to -1
		$this->assertSame( array( 'enabled' => '1', 'value' => 'large' ), $clean['advanced_robots']['max_image_preview'] ); // bogus → large
	}

	public function test_sanitize_keeps_separator_value_unchanged(): void {
		// Brain Monkey does not load WP, so sanitize_text_field is a passthrough here;
		// this asserts Options itself never mutilates a multibyte separator char.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$clean = ( new Options() )->sanitize( array( 'title_separator' => '—' ) );

		$this->assertSame( '—', $clean['title_separator'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL — new keys missing / not sanitized.

- [ ] **Step 3: Add the three defaults**

In `src/Settings/Options.php` `defaults()`, after the `'robots' => array(),` line, add:

```php
			'capitalize_titles'        => '',
			'twitter_card_type'        => 'summary_large_image',
			'advanced_robots'          => array(
				'max_snippet'       => array( 'enabled' => '', 'length' => '-1' ),
				'max_video_preview' => array( 'enabled' => '', 'length' => '-1' ),
				'max_image_preview' => array( 'enabled' => '', 'value' => 'large' ),
			),
```

- [ ] **Step 4: Add `capitalize_titles` to the checkbox loop**

In `sanitize()`, replace the checkbox `foreach` array line:

```php
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled' ) as $key ) {
```
with:
```php
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled', 'capitalize_titles' ) as $key ) {
```

- [ ] **Step 5: Sanitize `twitter_card_type`**

In `sanitize()`, after the `if ( isset( $input['schema_site_type'] ) )` block (the one that whitelists `Organization`/`Person`) and **before** the `foreach ( array( 'og_default_image', 'schema_logo' ) … )` loop, add:

```php
		if ( isset( $input['twitter_card_type'] ) ) {
			$card                       = sanitize_text_field( wp_unslash( $input['twitter_card_type'] ) );
			$clean['twitter_card_type'] = in_array( $card, array( 'summary_large_image', 'summary' ), true ) ? $card : 'summary_large_image';
		}
```

- [ ] **Step 6: Sanitize `advanced_robots`**

In `sanitize()`, immediately before `return $clean;`, add:

```php
		if ( isset( $input['advanced_robots'] ) && is_array( $input['advanced_robots'] ) ) {
			$adv                      = $input['advanced_robots'];
			$clean['advanced_robots'] = array(
				'max_snippet'       => $this->sanitize_advanced_length( $adv['max_snippet'] ?? null ),
				'max_video_preview' => $this->sanitize_advanced_length( $adv['max_video_preview'] ?? null ),
				'max_image_preview' => $this->sanitize_advanced_image( $adv['max_image_preview'] ?? null ),
			);
		}
```

- [ ] **Step 7: Add the two private helpers**

In `src/Settings/Options.php`, after `sanitize_template_map()` (before the final class closing brace), add:

```php
	/**
	 * Sanitize one length-based advanced robots block (max-snippet / max-video-preview).
	 *
	 * @param mixed $block Raw block ({ enabled, length }).
	 * @return array{enabled:string,length:string}
	 */
	private function sanitize_advanced_length( mixed $block ): array {
		$block   = is_array( $block ) ? $block : array();
		$enabled = '1' === (string) ( $block['enabled'] ?? '' ) ? '1' : '';
		$length  = isset( $block['length'] ) ? (int) wp_unslash( $block['length'] ) : -1;
		if ( $length < -1 ) {
			$length = -1;
		}

		return array(
			'enabled' => $enabled,
			'length'  => (string) $length,
		);
	}

	/**
	 * Sanitize the image-preview advanced robots block (max-image-preview).
	 *
	 * @param mixed $block Raw block ({ enabled, value }).
	 * @return array{enabled:string,value:string}
	 */
	private function sanitize_advanced_image( mixed $block ): array {
		$block   = is_array( $block ) ? $block : array();
		$enabled = '1' === (string) ( $block['enabled'] ?? '' ) ? '1' : '';
		$value   = sanitize_text_field( wp_unslash( (string) ( $block['value'] ?? 'large' ) ) );
		if ( ! in_array( $value, array( 'large', 'standard', 'none' ), true ) ) {
			$value = 'large';
		}

		return array(
			'enabled' => $enabled,
			'value'   => $value,
		);
	}
```

- [ ] **Step 8: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: PASS (existing + 5 new).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 9: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(settings): capitalize_titles, twitter_card_type, advanced_robots options"
```

---

## Task 3: `Resolver::title()` — capitalización

**Files:**
- Modify: `src/Meta/Resolver.php` (extract `resolve_title()`, wrap with `capitalize()`)
- Modify: `tests/Unit/Meta/ResolverTest.php`

**Interfaces:**
- Consumes: `Support\Str::mb_ucwords` (Task 1), `Options['capitalize_titles']` (Task 2).
- Produces: `Resolver::title()` capitaliza el título resuelto cuando `capitalize_titles === '1'`.

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/Meta/ResolverTest.php`:

```php
	public function test_title_capitalizes_when_enabled(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'hello world' );
		Functions\when( 'get_option' )->justReturn( array( 'capitalize_titles' => '1' ) );

		// '%title% %sep% %sitename%' → 'hello world - My Site' → capitalized.
		$this->assertSame( 'Hello World - My Site', $this->resolver()->title() );
	}

	public function test_title_not_capitalized_by_default(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'hello world' );

		$this->assertSame( 'hello world - My Site', $this->resolver()->title() );
	}
```

- [ ] **Step 2: Run tests to verify the new one fails**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: FAIL — `test_title_capitalizes_when_enabled` returns `'hello world - My Site'` (not capitalized yet).

- [ ] **Step 3: Add the import**

In `src/Meta/Resolver.php`, add below `use OpenSEO\Settings\Options;`:

```php
use OpenSEO\Support\Str;
```

- [ ] **Step 4: Wrap `title()` with capitalization**

In `src/Meta/Resolver.php`, rename the existing `public function title(): string { … }` to `private function resolve_title(): string { … }` (change the signature line and update its docblock summary to `Resolve the raw effective title before capitalization.` — keep the whole body), then add a new `title()` and `capitalize()` directly above it:

```php
	/**
	 * Effective document title (empty = let WordPress decide), with optional
	 * global capitalization applied.
	 */
	public function title(): string {
		return $this->capitalize( $this->resolve_title() );
	}

	/**
	 * Apply the global "capitalize titles" setting when enabled. Empty stays empty.
	 *
	 * @param string $title Resolved title.
	 */
	private function capitalize( string $title ): string {
		if ( '' === $title || '1' !== (string) $this->options->get( 'capitalize_titles' ) ) {
			return $title;
		}

		return Str::mb_ucwords( $title );
	}
```

(The renamed `resolve_title()` keeps its body. `title()` has **four** consumers: `Frontend\Head\Title`, `social_title()` (OG/Twitter fallback), `Schema\Pieces\Article` (`headline`), and `Schema\Pieces\WebPage` (`name`). Capitalizing in `title()` is the single source of truth, so the capitalized title propagates to **all** of them, including the JSON-LD `headline`/`name` — accepted as consistent behavior (see spec "Alcance de la capitalización"). No tests regress: `ContentPiecesTest` does not set `capitalize_titles`, so the default `''` keeps `capitalize()` a no-op.)

- [ ] **Step 5: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: PASS (new capitalize tests + all existing title/social tests still green — default `capitalize_titles=''` makes `capitalize()` a no-op).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php
git commit -m "feat(meta): capitalize resolved title when enabled"
```

---

## Task 4: `Resolver::robots()` — metadatos avanzados

**Files:**
- Modify: `src/Meta/Resolver.php` (`robots()` appends advanced; `robots_string()` → `robots_parts()`; new `advanced_robots_parts()`)
- Modify: `tests/Unit/Meta/ResolverTest.php`

**Interfaces:**
- Consumes: `Options['advanced_robots']` (Task 2).
- Produces: `Resolver::robots()` anexa `max-snippet`/`max-video-preview`/`max-image-preview` cuando están habilitados y el robots efectivo no es noindex/nosnippet.

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/Meta/ResolverTest.php`:

```php
	public function test_robots_appends_advanced_when_indexable(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'advanced_robots' => array(
					'max_snippet'       => array( 'enabled' => '1', 'length' => '-1' ),
					'max_image_preview' => array( 'enabled' => '1', 'value' => 'large' ),
				),
			)
		);

		$this->assertSame(
			'index, follow, max-snippet:-1, max-image-preview:large',
			$this->resolver()->robots()
		);
	}

	public function test_robots_skips_advanced_when_nosnippet(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'advanced_robots' => array( 'max_snippet' => array( 'enabled' => '1', 'length' => '50' ) ),
				'post_types'      => array( 'post' => array( 'robots' => array( 'nosnippet' => 'on' ) ) ),
			)
		);

		// nosnippet bail → no max-snippet appended.
		$this->assertSame( 'index, follow, nosnippet', $this->resolver()->robots() );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: FAIL — advanced directives not appended yet.

- [ ] **Step 3: Append advanced parts in `robots()`**

In `src/Meta/Resolver.php`, replace the final line of `robots()`:

```php
		return $this->robots_string( $effective );
```
with:
```php
		$parts = $this->robots_parts( $effective );

		if ( ! $effective['noindex'] && ! $effective['nosnippet'] ) {
			$parts = array_merge( $parts, $this->advanced_robots_parts() );
		}

		return implode( ', ', $parts );
```

- [ ] **Step 4: Convert `robots_string()` to `robots_parts()` + add `advanced_robots_parts()`**

Replace the whole `robots_string()` method with:

```php
	/**
	 * Effective directive list (index/follow + any extra booleans), without advanced robots.
	 *
	 * @param array<string, bool> $e Effective directives.
	 * @return array<int, string>
	 */
	private function robots_parts( array $e ): array {
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

		return $parts;
	}

	/**
	 * Advanced robots directives from the global setting, e.g. "max-snippet:-1".
	 * The caller skips these when the page is noindex or nosnippet.
	 *
	 * @return array<int, string>
	 */
	private function advanced_robots_parts(): array {
		$adv = $this->options->get( 'advanced_robots' );
		$adv = is_array( $adv ) ? $adv : array();

		$blocks = array(
			'max-snippet'       => array( 'max_snippet', 'length', '-1' ),
			'max-video-preview' => array( 'max_video_preview', 'length', '-1' ),
			'max-image-preview' => array( 'max_image_preview', 'value', 'large' ),
		);

		$parts = array();
		foreach ( $blocks as $directive => $meta ) {
			[ $key, $field, $default ] = $meta;
			$block                     = is_array( $adv[ $key ] ?? null ) ? $adv[ $key ] : array();
			if ( '1' === (string) ( $block['enabled'] ?? '' ) ) {
				$parts[] = $directive . ':' . (string) ( $block[ $field ] ?? $default );
			}
		}

		return $parts;
	}
```

- [ ] **Step 5: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: PASS — new advanced tests + existing robots tests (default `index, follow`; `test_robots_adds_extra_directives_from_type` still `'index, follow, noarchive, nosnippet'` because advanced default-disabled AND nosnippet bails).

Run: `composer test:unit`
Expected: Whole suite green (incl. `RobotsTest` presenter — its `setUp` already mocks `get_option`/`is_*`; `advanced_robots` reads tolerate the empty default).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php
git commit -m "feat(meta): append advanced robots directives (max-snippet/video/image)"
```

---

## Task 5: `Resolver::twitter_card()`

**Files:**
- Modify: `src/Meta/Resolver.php` (new `twitter_card()`)
- Modify: `tests/Unit/Meta/ResolverTest.php`

**Interfaces:**
- Consumes: `Options['twitter_card_type']` (Task 2).
- Produces: `Resolver::twitter_card(): string` — el tipo configurado, revalidado a `['summary','summary_large_image']` (default `summary_large_image`).

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/Meta/ResolverTest.php`:

```php
	public function test_twitter_card_defaults_to_summary_large_image(): void {
		$this->assertSame( 'summary_large_image', $this->resolver()->twitter_card() );
	}

	public function test_twitter_card_uses_configured_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'twitter_card_type' => 'summary' ) );
		$this->assertSame( 'summary', $this->resolver()->twitter_card() );
	}

	public function test_twitter_card_rejects_invalid_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'twitter_card_type' => 'bogus' ) );
		$this->assertSame( 'summary_large_image', $this->resolver()->twitter_card() );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: FAIL — `Call to undefined method … twitter_card()`.

- [ ] **Step 3: Add the method**

In `src/Meta/Resolver.php`, after `twitter_image()`, add:

```php
	/**
	 * Effective Twitter card type from the global setting (revalidated).
	 */
	public function twitter_card(): string {
		$type = (string) $this->options->get( 'twitter_card_type' );

		return in_array( $type, array( 'summary', 'summary_large_image' ), true )
			? $type
			: 'summary_large_image';
	}
```

- [ ] **Step 4: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: PASS.

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php
git commit -m "feat(meta): twitter_card() resolves configured card type"
```

---

## Task 6: `Twitter` presenter usa `twitter_card()`

**Files:**
- Modify: `src/Frontend/Head/Twitter.php`
- Test: `tests/Unit/Frontend/Head/TwitterTest.php` (nuevo)

**Interfaces:**
- Consumes: `Resolver::twitter_card()` (Task 5).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend\Head;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\Head\Twitter;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class TwitterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolver(): Resolver {
		$options  = new Options();
		$defaults = new TemplateDefaults();
		return new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) );
	}

	public function test_card_uses_configured_type(): void {
		Functions\when( 'get_option' )->justReturn( array( 'twitter_card_type' => 'summary' ) );

		ob_start();
		( new Twitter( $this->resolver() ) )->output();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="twitter:card" content="summary"', $output );
	}

	public function test_card_defaults_to_summary_large_image(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		ob_start();
		( new Twitter( $this->resolver() ) )->output();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image"', $output );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TwitterTest`
Expected: FAIL — card is still computed from image presence (`summary` regardless of the option).

- [ ] **Step 3: Use the configured card type**

In `src/Frontend/Head/Twitter.php`, inside `output()`, replace the `'twitter:card'` line:

```php
			'twitter:card'        => '' !== $image ? 'summary_large_image' : 'summary',
```
with:
```php
			'twitter:card'        => $this->resolver->twitter_card(),
```

(`$image` is still used for the `twitter:image` tag below — leave the rest unchanged.)

- [ ] **Step 4: Run test + analysis**

Run: `vendor/bin/phpunit --filter TwitterTest`
Expected: PASS.

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/Head/Twitter.php tests/Unit/Frontend/Head/TwitterTest.php
git commit -m "feat(head): Twitter card type from configured option"
```

---

## Task 7: `Admin\Assets` — `wp_enqueue_media()`

**Files:**
- Modify: `src/Admin/Assets.php`

**Interfaces:**
- Produces: el modal de la biblioteca de medios disponible en las pantallas OpenSEO (necesario para `MediaField`).

- [ ] **Step 1: Enqueue the media modal**

In `src/Admin/Assets.php`, inside `enqueue()`, immediately after the `wp_enqueue_script( self::SCRIPT_HANDLE, … );` call (and before the `wp_script_is(...)` block), add:

```php
		wp_enqueue_media();
```

(There is no unit test for `Assets` — this is a single WP API call verified by the build + manual smoke test in Task 14. `wp_enqueue_media()` registers the Backbone media templates that `MediaUpload` needs.)

- [ ] **Step 2: Static analysis + lint**

Run: `composer analyze`
Expected: No errors.

Run: `composer lint`
Expected: No PHPCS errors.

- [ ] **Step 3: Commit**

```bash
git add src/Admin/Assets.php
git commit -m "feat(admin): enqueue media modal on OpenSEO screens"
```

---

## Task 8: `advancedRobots.js` — helper puro + constantes

**Files:**
- Create: `assets/src/admin/advancedRobots.js`
- Test: `assets/src/admin/advancedRobots.test.js`

**Interfaces:**
- Produces: `setAdvancedRobots(map, block, field, value)` → nuevo map (inmutable); `SEPARATOR_PRESETS` (`['-','–','—','»','|','•']`); `MAX_IMAGE_PREVIEW_VALUES` (`['large','standard','none']`). Sin i18n (labels viven en los componentes).

- [ ] **Step 1: Write the failing test**

```js
import {
	setAdvancedRobots,
	SEPARATOR_PRESETS,
	MAX_IMAGE_PREVIEW_VALUES,
} from './advancedRobots';

describe( 'setAdvancedRobots', () => {
	it( 'sets a nested field without mutating the input', () => {
		const map = { max_snippet: { enabled: '', length: '-1' } };
		const next = setAdvancedRobots( map, 'max_snippet', 'enabled', '1' );
		expect( next.max_snippet ).toEqual( { enabled: '1', length: '-1' } );
		expect( map.max_snippet.enabled ).toBe( '' );
	} );

	it( 'creates the block when absent', () => {
		const next = setAdvancedRobots( {}, 'max_image_preview', 'value', 'none' );
		expect( next.max_image_preview ).toEqual( { value: 'none' } );
	} );

	it( 'preserves other blocks', () => {
		const map = {
			max_snippet: { enabled: '1' },
			max_video_preview: { enabled: '' },
		};
		const next = setAdvancedRobots( map, 'max_video_preview', 'enabled', '1' );
		expect( next.max_snippet ).toEqual( { enabled: '1' } );
		expect( next.max_video_preview ).toEqual( { enabled: '1' } );
	} );
} );

describe( 'constants', () => {
	it( 'lists the six separator presets', () => {
		expect( SEPARATOR_PRESETS ).toEqual( [ '-', '–', '—', '»', '|', '•' ] );
	} );

	it( 'lists the image preview values', () => {
		expect( MAX_IMAGE_PREVIEW_VALUES ).toEqual( [ 'large', 'standard', 'none' ] );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- advancedRobots.test.js`
Expected: FAIL — cannot find module `./advancedRobots`.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Pure helpers + constants for the Meta Global panel (no i18n — labels live in
 * the components).
 */

export const SEPARATOR_PRESETS = [ '-', '–', '—', '»', '|', '•' ];

export const MAX_IMAGE_PREVIEW_VALUES = [ 'large', 'standard', 'none' ];

/**
 * Immutably set one field inside one advanced_robots block.
 *
 * @param {Object} map   Current advanced_robots map.
 * @param {string} block Block key (max_snippet | max_video_preview | max_image_preview).
 * @param {string} field Field key (enabled | length | value).
 * @param {string} value New value.
 * @return {Object} A new map; the input is not mutated.
 */
export function setAdvancedRobots( map, block, field, value ) {
	const current = map ?? {};
	return {
		...current,
		[ block ]: {
			...( current[ block ] ?? {} ),
			[ field ]: value,
		},
	};
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- advancedRobots.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/advancedRobots.js assets/src/admin/advancedRobots.test.js
git commit -m "feat(admin): advancedRobots helper + meta-global constants"
```

---

## Task 9: `MediaField` component (uploader)

**Files:**
- Create: `assets/src/admin/components/MediaField.js`

**Interfaces:**
- Consumes: `MediaUpload` de `@wordpress/media-utils`; `Button`/`Flex`/`FlexItem` de `@wordpress/components`.
- Produces: `MediaField({ label, value, onChange })` — `onChange(url)` con la URL del attachment, o `''` al quitar.

- [ ] **Step 1: Write the component**

```jsx
import { MediaUpload } from '@wordpress/media-utils';
import { Button, Flex, FlexItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Image picker backed by the WordPress media library. Stores only the URL.
 * Uses MediaUpload from @wordpress/media-utils (NOT MediaUploadCheck, which
 * lives in @wordpress/block-editor and needs the block-editor store). Capability
 * is already gated by the admin page (manage_options) + the user's upload_files.
 *
 * @param {Object}   props
 * @param {string}   props.label    Field label.
 * @param {string}   props.value    Current image URL.
 * @param {Function} props.onChange Receives the selected URL (or '' when removed).
 */
export function MediaField( { label, value, onChange } ) {
	return (
		<div className="openseo-media-field">
			{ label && (
				<p className="openseo-media-field__label">{ label }</p>
			) }
			{ value && (
				<img
					src={ value }
					alt=""
					className="openseo-media-field__preview"
					style={ { maxWidth: '160px', height: 'auto', display: 'block' } }
				/>
			) }
			<Flex justify="flex-start" gap={ 2 }>
				<FlexItem>
					<MediaUpload
						onSelect={ ( media ) => onChange( media.url ) }
						allowedTypes={ [ 'image' ] }
						render={ ( { open } ) => (
							<Button variant="secondary" onClick={ open }>
								{ value
									? __( 'Replace image', 'openseo' )
									: __( 'Select image', 'openseo' ) }
							</Button>
						) }
					/>
				</FlexItem>
				{ value && (
					<FlexItem>
						<Button
							variant="link"
							isDestructive
							onClick={ () => onChange( '' ) }
						>
							{ __( 'Remove', 'openseo' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>
		</div>
	);
}
```

- [ ] **Step 2: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors (whole project).

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/MediaField.js
git commit -m "feat(admin): MediaField media-library image picker"
```

---

## Task 10: `SeparatorField` component

**Files:**
- Create: `assets/src/admin/components/SeparatorField.js`

**Interfaces:**
- Consumes: `SEPARATOR_PRESETS` (Task 8); `Button`/`TextControl`/`Flex`/`FlexItem` de `@wordpress/components`.
- Produces: `SeparatorField({ value, onChange })` — botones de preset + campo personalizado; `onChange(char)`.

- [ ] **Step 1: Write the component**

```jsx
import { Button, TextControl, Flex, FlexItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SEPARATOR_PRESETS } from '../advancedRobots';

/**
 * Title separator picker: six preset characters (stored as literal UTF-8) plus
 * a free-text "custom" field that preserves arbitrary values.
 *
 * @param {Object}   props
 * @param {string}   props.value    Current separator character.
 * @param {Function} props.onChange Receives the chosen character.
 */
export function SeparatorField( { value, onChange } ) {
	return (
		<div className="openseo-separator-field">
			<p className="openseo-separator-field__label">
				{ __( 'Title separator', 'openseo' ) }
			</p>
			<Flex justify="flex-start" gap={ 1 } wrap>
				{ SEPARATOR_PRESETS.map( ( preset ) => (
					<FlexItem key={ preset }>
						<Button
							variant="secondary"
							isPressed={ value === preset }
							onClick={ () => onChange( preset ) }
						>
							{ preset }
						</Button>
					</FlexItem>
				) ) }
			</Flex>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Custom separator', 'openseo' ) }
				value={ value ?? '' }
				onChange={ onChange }
			/>
		</div>
	);
}
```

- [ ] **Step 2: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/SeparatorField.js
git commit -m "feat(admin): SeparatorField preset + custom separator picker"
```

---

## Task 11: `AdvancedRobotsField` component

**Files:**
- Create: `assets/src/admin/components/AdvancedRobotsField.js`

**Interfaces:**
- Consumes: `setAdvancedRobots`, `MAX_IMAGE_PREVIEW_VALUES` (Task 8); `CheckboxControl`/`TextControl`/`SelectControl` de `@wordpress/components`.
- Produces: `AdvancedRobotsField({ value, onChange })` — `value` es el map `advanced_robots`; `onChange(newMap)`.

- [ ] **Step 1: Write the component**

```jsx
import {
	CheckboxControl,
	TextControl,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { setAdvancedRobots, MAX_IMAGE_PREVIEW_VALUES } from '../advancedRobots';

const IMAGE_PREVIEW_LABELS = {
	large: __( 'Large', 'openseo' ),
	standard: __( 'Standard', 'openseo' ),
	none: __( 'None', 'openseo' ),
};

const IMAGE_PREVIEW_OPTIONS = MAX_IMAGE_PREVIEW_VALUES.map( ( value ) => ( {
	value,
	label: IMAGE_PREVIEW_LABELS[ value ],
} ) );

/**
 * Three advanced robots rows (max-snippet / max-video-preview / max-image-preview),
 * each a checkbox plus a value control shown when enabled.
 *
 * @param {Object}   props
 * @param {Object}   props.value    advanced_robots map.
 * @param {Function} props.onChange Receives the new map.
 */
export function AdvancedRobotsField( { value, onChange } ) {
	const map = value ?? {};
	const block = ( key ) => map[ key ] ?? {};
	const set = ( key, field, v ) => onChange( setAdvancedRobots( map, key, field, v ) );

	return (
		<fieldset className="openseo-advanced-robots">
			<legend>{ __( 'Advanced robots meta', 'openseo' ) }</legend>

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Snippet (max-snippet)', 'openseo' ) }
				checked={ block( 'max_snippet' ).enabled === '1' }
				onChange={ ( on ) => set( 'max_snippet', 'enabled', on ? '1' : '' ) }
			/>
			{ block( 'max_snippet' ).enabled === '1' && (
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					label={ __( 'Max snippet length', 'openseo' ) }
					value={ block( 'max_snippet' ).length ?? '-1' }
					onChange={ ( v ) => set( 'max_snippet', 'length', v ) }
				/>
			) }

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Video preview (max-video-preview)', 'openseo' ) }
				checked={ block( 'max_video_preview' ).enabled === '1' }
				onChange={ ( on ) => set( 'max_video_preview', 'enabled', on ? '1' : '' ) }
			/>
			{ block( 'max_video_preview' ).enabled === '1' && (
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					label={ __( 'Max video preview (seconds)', 'openseo' ) }
					value={ block( 'max_video_preview' ).length ?? '-1' }
					onChange={ ( v ) => set( 'max_video_preview', 'length', v ) }
				/>
			) }

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Image preview (max-image-preview)', 'openseo' ) }
				checked={ block( 'max_image_preview' ).enabled === '1' }
				onChange={ ( on ) => set( 'max_image_preview', 'enabled', on ? '1' : '' ) }
			/>
			{ block( 'max_image_preview' ).enabled === '1' && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Max image preview size', 'openseo' ) }
					value={ block( 'max_image_preview' ).value ?? 'large' }
					options={ IMAGE_PREVIEW_OPTIONS }
					onChange={ ( v ) => set( 'max_image_preview', 'value', v ) }
				/>
			) }
		</fieldset>
	);
}
```

- [ ] **Step 2: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/AdvancedRobotsField.js
git commit -m "feat(admin): AdvancedRobotsField (max-snippet/video/image rows)"
```

---

## Task 12: `Titles.js` — pestaña Meta Global + Homepage

**Files:**
- Modify: `assets/src/admin/views/Titles.js` (replace whole file)

**Interfaces:**
- Consumes: `MediaField` (9), `SeparatorField` (10), `AdvancedRobotsField` (11), `ROBOTS_DIRECTIVES` (`robots.js`), `RobotsFields`/`ROBOTS_LABELS` (existentes).

- [ ] **Step 1: Replace the file**

Replace the entire contents of `assets/src/admin/views/Titles.js` with:

```jsx
import {
	CheckboxControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';
import { VerticalTabs } from '../components/VerticalTabs';
import { TemplateField } from '../components/TemplateField';
import { MediaField } from '../components/MediaField';
import { SeparatorField } from '../components/SeparatorField';
import { AdvancedRobotsField } from '../components/AdvancedRobotsField';
import { setTemplateField } from '../templateFields';
import { ROBOTS_DIRECTIVES } from '../robots';
import { RobotsFields, ROBOTS_LABELS } from '../components/RobotsFields';

const bootstrap = window.openseoAdmin ?? {};
const contentTypes = bootstrap.contentTypes ?? {
	postTypes: [],
	taxonomies: [],
};
const catalog = bootstrap.variables ?? [];

const TWITTER_CARD_OPTIONS = [
	{
		label: __( 'Summary card with large image', 'openseo' ),
		value: 'summary_large_image',
	},
	{ label: __( 'Summary card', 'openseo' ), value: 'summary' },
];

const GROUPS = [
	{
		tabs: [
			{ name: 'meta-global', title: __( 'Meta Global', 'openseo' ) },
			{ name: 'homepage', title: __( 'Homepage', 'openseo' ) },
		],
	},
	...( contentTypes.postTypes.length
		? [
				{
					label: __( 'Content types', 'openseo' ),
					tabs: contentTypes.postTypes.map( ( t ) => ( {
						name: `pt:${ t.slug }`,
						title: t.label,
					} ) ),
				},
		  ]
		: [] ),
	...( contentTypes.taxonomies.length
		? [
				{
					label: __( 'Taxonomies', 'openseo' ),
					tabs: contentTypes.taxonomies.map( ( t ) => ( {
						name: `tax:${ t.slug }`,
						title: t.label,
					} ) ),
				},
		  ]
		: [] ),
];

const TAB_NAMES = GROUPS.flatMap( ( g ) => g.tabs.map( ( t ) => t.name ) );

function MetaGlobalPanel( { values, change } ) {
	const robots = values.robots ?? {};

	return (
		<>
			<SeparatorField
				value={ values.title_separator ?? '' }
				onChange={ ( v ) => change( 'title_separator', v ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Capitalize titles', 'openseo' ) }
				help={ __(
					'Automatically capitalize the first letter of each word in titles.',
					'openseo'
				) }
				checked={ values.capitalize_titles === '1' }
				onChange={ ( on ) => change( 'capitalize_titles', on ? '1' : '' ) }
			/>
			<h3>{ __( 'Default robots', 'openseo' ) }</h3>
			{ ROBOTS_DIRECTIVES.map( ( directive ) => (
				<CheckboxControl
					key={ directive }
					__nextHasNoMarginBottom
					label={ ROBOTS_LABELS[ directive ] }
					checked={ robots[ directive ] === '1' }
					onChange={ ( on ) =>
						change( 'robots', {
							...robots,
							[ directive ]: on ? '1' : '',
						} )
					}
				/>
			) ) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex empty term archives', 'openseo' ) }
				checked={ robots.noindex_empty_terms === '1' }
				onChange={ ( on ) =>
					change( 'robots', {
						...robots,
						noindex_empty_terms: on ? '1' : '',
					} )
				}
			/>
			<AdvancedRobotsField
				value={ values.advanced_robots ?? {} }
				onChange={ ( v ) => change( 'advanced_robots', v ) }
			/>
			<h3>{ __( 'OpenGraph thumbnail', 'openseo' ) }</h3>
			<MediaField
				label={ __(
					'Default image used when a post has no featured or social image.',
					'openseo'
				) }
				value={ values.og_default_image ?? '' }
				onChange={ ( url ) => change( 'og_default_image', url ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Twitter card type', 'openseo' ) }
				value={ values.twitter_card_type ?? 'summary_large_image' }
				options={ TWITTER_CARD_OPTIONS }
				onChange={ ( v ) => change( 'twitter_card_type', v ) }
			/>
		</>
	);
}

function HomepagePanel( { values, change } ) {
	return (
		<>
			<TemplateField
				label={ __( 'Homepage title', 'openseo' ) }
				value={ values.home_title ?? '' }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_title', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage description', 'openseo' ) }
				value={ values.home_description ?? '' }
				multiline
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_description', v ) }
			/>
		</>
	);
}

function TypePanel( { type, mapKey, scope, values, change } ) {
	const map = values[ mapKey ] ?? {};
	const entry = map[ type.slug ] ?? {};
	return (
		<>
			<TemplateField
				label={ __( 'Title', 'openseo' ) }
				value={ entry.title ?? '' }
				placeholder={ type.defaultTitle }
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) =>
					change(
						mapKey,
						setTemplateField( map, type.slug, 'title', v )
					)
				}
			/>
			<TemplateField
				label={ __( 'Description', 'openseo' ) }
				value={ entry.description ?? '' }
				placeholder={ type.defaultDescription }
				multiline
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) =>
					change(
						mapKey,
						setTemplateField( map, type.slug, 'description', v )
					)
				}
			/>
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
		</>
	);
}

function renderPanel( tab, values, change ) {
	if ( tab.startsWith( 'pt:' ) ) {
		const type = contentTypes.postTypes.find(
			( t ) => t.slug === tab.slice( 3 )
		);
		return type ? (
			<TypePanel
				type={ type }
				mapKey="post_types"
				scope="singular"
				values={ values }
				change={ change }
			/>
		) : null;
	}
	if ( tab.startsWith( 'tax:' ) ) {
		const type = contentTypes.taxonomies.find(
			( t ) => t.slug === tab.slice( 4 )
		);
		return type ? (
			<TypePanel
				type={ type }
				mapKey="taxonomies"
				scope="taxonomy"
				values={ values }
				change={ change }
			/>
		) : null;
	}
	if ( tab === 'homepage' ) {
		return <HomepagePanel values={ values } change={ change } />;
	}
	return <MetaGlobalPanel values={ values } change={ change } />;
}

export function Titles() {
	const [ active, setActive ] = useState( 'meta-global' );
	const current = TAB_NAMES.includes( active ) ? active : 'meta-global';

	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<VerticalTabs
					groups={ GROUPS }
					active={ current }
					onSelect={ setActive }
				>
					{ ( tab ) => renderPanel( tab, values, change ) }
				</VerticalTabs>
			) }
		</SettingsPanel>
	);
}
```

- [ ] **Step 2: Lint, test, build**

Run: `npm run lint:js`
Expected: No ESLint errors (no unused imports — `TextControl` is no longer imported here; it's used inside `SeparatorField`).

Run: `npm run test:js`
Expected: All suites pass.

Run: `npm run build`
Expected: Build succeeds; `admin-settings.asset.php` lists `wp-media-utils` among dependencies (pulled in via `MediaField`).

> **Note:** `@wordpress/media-utils` does NOT need `npm install`. `@wordpress/scripts`' DependencyExtractionWebpackPlugin externalizes every `@wordpress/*` import to a core script handle (here `wp-media-utils`) without resolving it on disk. `wp-media-utils` is a WordPress core handle (registered since WP 5.x); the modal it bridges is loaded by `wp_enqueue_media()` (Task 7). Do not "fix" a missing package — there is none to install.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/views/Titles.js
git commit -m "feat(admin): Meta Global tab (separator/capitalize/robots/OG/Twitter) + Homepage tab"
```

---

## Task 13: `Social.js` — aviso de unificación

**Files:**
- Modify: `assets/src/admin/views/Social.js` (replace whole file)

**Interfaces:**
- Produces: la vista Social ya no edita `og_default_image`; muestra un `Notice` que remite a Meta Global. Sin `SettingsPanel`/`SaveBar`.

- [ ] **Step 1: Replace the file**

Replace the entire contents of `assets/src/admin/views/Social.js` with:

```jsx
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function Social() {
	return (
		<Notice status="info" isDismissible={ false }>
			{ __(
				'The default social image is now managed under OpenSEO → Titles & Meta → Meta Global.',
				'openseo'
			) }
		</Notice>
	);
}
```

- [ ] **Step 2: Lint, test, build**

Run: `npm run lint:js`
Expected: No ESLint errors.

Run: `npm run test:js`
Expected: All suites pass.

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/views/Social.js
git commit -m "refactor(admin): Social view points to Meta Global for default OG image"
```

---

## Task 14: Verificación final + i18n

**Files:** none (verification) + `languages/openseo.pot`.

- [ ] **Step 1: PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green (incl. `StrTest`, `OptionsTest`, `ResolverTest`, `TwitterTest`, and the unchanged `RobotsTest`/`DescriptionTest`/`PluginBootTest`).

- [ ] **Step 2: JS gates**

Run: `npm run lint:js`
Expected: No errors.

Run: `npm run test:js`
Expected: All suites pass (incl. `advancedRobots`).

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Manual smoke test (wp-env, recommended)**

```bash
npm run env:start
```
In **OpenSEO → Titles & Meta → Meta Global**:
- Pick a separator char → a post's `<title>` uses it (`%sep%`).
- Toggle "Capitalize titles" → a lowercase post title renders title-cased in the front-end source.
- Enable "Image preview" = Large → a post's source shows `<meta name="robots" content="index, follow, max-image-preview:large">`.
- Click "Select image" on the OpenGraph thumbnail, choose an image, Save → a post with no featured/social image emits that `og:image`.
- Set Twitter card type = "Summary card" → the front-end source shows `<meta name="twitter:card" content="summary">`.
- Confirm the **Homepage** tab still edits home title/description, and **Social** shows the redirect notice.

- [ ] **Step 4: Regenerate the `.pot` (new strings)**

```bash
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo languages/openseo.pot
git add languages/openseo.pot && git commit -m "chore(i18n): regenerate .pot for Meta Global strings"
```
(If wp-env is unavailable, note it as a deferred follow-up — do not skip silently.)

- [ ] **Step 5: Final commit (only if artifacts/fixes remain)**

```bash
git add -A
git commit -m "chore(meta): final verification for Meta Global"
```

(Skip if nothing to commit.)

---

## Self-Review (completed during planning)

- **Spec coverage:** separador selector (Task 12 `SeparatorField`/10), capitalizar (Task 1 `Str` + Task 3 `Resolver::title`), robots global reubicado (Task 12 `MetaGlobalPanel`), advanced robots (Task 2 sanitize + Task 4 `Resolver::robots` + Task 11 component), noindex archivos vacíos (reubicado en Task 12), OG thumbnail uploader (Task 7 `wp_enqueue_media` + Task 9 `MediaField` + Task 12), twitter card type (Task 2 + Task 5 `Resolver::twitter_card` + Task 6 presenter + Task 12 select), Homepage rename (Task 12), Social notice / unificación og_default_image (Task 13). Todos los criterios de aceptación del spec tienen tarea.
- **Placeholder scan:** sin TBD/TODO; cada paso de código muestra el código y el comando con salida esperada.
- **Type/símbolo consistency:** `Str::mb_ucwords` (1) usado en `Resolver::capitalize` (3); `Options` keys `capitalize_titles`/`twitter_card_type`/`advanced_robots` (2) leídos en `Resolver` (3/4/5); `Resolver::twitter_card()` (5) usado por `Twitter` (6); `setAdvancedRobots`/`SEPARATOR_PRESETS`/`MAX_IMAGE_PREVIEW_VALUES` (8) usados por `SeparatorField`/`AdvancedRobotsField` (10/11); `MediaField`/`SeparatorField`/`AdvancedRobotsField` (9/10/11) usados por `Titles.js` (12); `og_default_image` editado solo en Meta Global (12), Social pasa a `Notice` (13).
- **Verde por commit:** Task 2 añade los defaults antes de que 3/4/5 los lean; Task 1 (puro) precede a 3; Task 8 (puro) precede a 10/11; los componentes JS (9/10/11) no se importan hasta Task 12; Task 6 depende de 5; Task 7 (`wp_enqueue_media`) es independiente pero debe ir antes de que el uploader se use en runtime (su orden relativo no rompe gates). Tests existentes que tocan `robots()`/`title()`/`Twitter` siguen verdes (default disabled = no-op; advanced bail con nosnippet).
- **Auditoría de diseño incorporada:** H1 (`MediaField` usa solo `MediaUpload` de `media-utils`, Task 9), M1 (lectura tipada de `advanced_robots` en `advanced_robots_parts`, Task 4), M2 (`'1'/''` para enabled/capitalize, Tasks 2/8), M3 (card sin imagen documentado en spec), M4 (Social sin `SettingsPanel`, Task 13), L1 (`robots_parts(array $e)` conserva parámetro, Task 4), L2 (test bail nosnippet+advanced, Task 4), L3 (caveat capitalización), L4 (test separador multibyte, Task 2).
