# SERP/snippet preview en vivo en el editor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el panel SEO del editor muestre un SERP/snippet preview fiel y en vivo (título y descripción que realmente emitirá el frontend), con favicon + URL/breadcrumb, toggle escritorio/móvil, barras de longitud con color y badge noindex.

**Architecture:** El template efectivo del tipo se computa en PHP (nuevo `Meta\TypeTemplates`, reutilizado por el `Resolver` para no divergir del frontend) y se pasa al editor por el bootstrap `window.openseoEditor`. En JS solo se expanden tokens (réplica acotada de `Variables.php`) leyendo el post en vivo del editor store; la cascada `override → template del tipo → default` se resuelve en cliente sin ida y vuelta al servidor.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, `@wordpress/scripts` (React/JS + Jest + SCSS), Gutenberg (`@wordpress/editor`, `@wordpress/data`, `@wordpress/core-data`), WordPress 7.0.

## Global Constraints

- Versiones objetivo: **WordPress 7.0+**, **PHP 8.1+**. `declare(strict_types=1);` en todo PHP nuevo.
- Todos los ajustes bajo la única opción `openseo_settings` (`Options::OPTION_KEY`); text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS).
- Seguridad: bootstrap impreso con `wp_json_encode(..., JSON_HEX_TAG)`; render React auto-escapado; **ningún** `dangerouslySetInnerHTML`; encolar assets solo si `is_readable`.
- `%currentyear%` en el editor usa año **UTC** (`new Date().getUTCFullYear()`) para coincidir con `gmdate('Y')` de `Variables.php`.
- `expandTokens` es un **port literal** de `Variables::replace`: sustituir tokens → colapsar `\s+`→`' '` + `trim` → quitar separador colgante tratándolo como **cadena escapada en regex** (no `String.trim`) → `trim`.
- `Meta\TypeTemplates` cubre **solo** post types singulares (`post_types[type][field] ?: singular_default`); la rama de taxonomía del `Resolver` **no cambia**.
- Gates verdes antes de cada commit que toque su capa: `composer lint`, `composer analyze` (`--memory-limit=1G`), `composer test:unit`; para JS `npm run lint:js`, `npm run test:js`, y `npm run build` cuando se toquen assets.
- Sin atribución en mensajes de commit. Conventional commits.

---

## File Structure

**PHP (nuevos):**
- `src/Meta/TypeTemplates.php` — template efectivo por post type (stored ?: default singular).

**PHP (modificados):**
- `src/Meta/Resolver.php` — constructor recibe `TypeTemplates`; rama singular lo usa; taxonomía intacta.
- `src/Plugin.php` — construir una `TemplateDefaults` + una `TypeTemplates`, inyectarlas en `Resolver` y `EditorPanel`.
- `src/Admin/Editor/EditorPanel.php` — deps `Options` + `TypeTemplates`; bootstrap con templates/sep/site/icon/url; resolución del post type; encolar `style-editor.css`.

**JS (nuevos):**
- `assets/src/editor/length.js` — `lengthState` (puro).
- `assets/src/editor/components/LengthIndicator.js`
- `assets/src/editor/components/PreviewDevices.js`
- `assets/src/editor/components/SerpPreview.js`
- `assets/src/editor/editor.scss`

**JS (modificados):**
- `assets/src/editor/preview.js` — reescrito: `expandTokens`, `resolveSnippet`, `truncate`, `deriveExcerpt`, `formatBreadcrumb`.
- `assets/src/editor/index.js` — `GeneralTab` compone el preview; import del SCSS.

**Tests (nuevos):**
- `tests/Unit/Meta/TypeTemplatesTest.php`
- `assets/src/editor/length.test.js`

**Tests (modificados):**
- `tests/Unit/Meta/ResolverTest.php`, `tests/Unit/Frontend/Head/DescriptionTest.php`, `tests/Unit/Frontend/Head/RobotsTest.php`, `tests/Unit/Schema/Pieces/ContentPiecesTest.php` — 4º arg `TypeTemplates` en el constructor del `Resolver`.
- `assets/src/editor/preview.test.js` — reescrito para las nuevas funciones puras.

---

## Task 1: `Meta\TypeTemplates` — template efectivo por post type

**Files:**
- Create: `src/Meta/TypeTemplates.php`
- Test: `tests/Unit/Meta/TypeTemplatesTest.php`

**Interfaces:**
- Consumes: `Settings\Options` (`get('post_types')`), `Meta\TemplateDefaults` (`singular_title()`, `singular_description()`).
- Produces: `OpenSEO\Meta\TypeTemplates::__construct(Options, TemplateDefaults)`; `title_for(string $post_type): string`; `description_for(string $post_type): string` (stored si no vacío, si no el default singular).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class TypeTemplatesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function type_templates(): TypeTemplates {
		return new TypeTemplates( new Options(), new TemplateDefaults() );
	}

	public function test_stored_template_wins(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => 'Stored %sitename%', 'description' => 'Stored desc' ) ) )
		);

		$this->assertSame( 'Stored %sitename%', $this->type_templates()->title_for( 'post' ) );
		$this->assertSame( 'Stored desc', $this->type_templates()->description_for( 'post' ) );
	}

	public function test_falls_back_to_singular_default_when_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '' ) ) )
		);

		$this->assertSame( '%title% %sep% %sitename%', $this->type_templates()->title_for( 'post' ) );
		$this->assertSame( '%excerpt%', $this->type_templates()->description_for( 'post' ) );
	}

	public function test_falls_back_for_unknown_type(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '%title% %sep% %sitename%', $this->type_templates()->title_for( 'book' ) );
		$this->assertSame( '%excerpt%', $this->type_templates()->description_for( 'book' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TypeTemplatesTest`
Expected: FAIL — `Class "OpenSEO\Meta\TypeTemplates" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Effective title/description template for a post type.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Settings\Options;

/**
 * Resolves the effective singular template for a post type: the stored
 * per-type template, or the singular default when none is set. Single source
 * of "effective per-type template" shared by the frontend Resolver and the
 * editor SERP preview so the two never diverge.
 */
final class TypeTemplates {

	/**
	 * Constructor.
	 *
	 * @param Options          $options  Settings accessor.
	 * @param TemplateDefaults $defaults Per-surface defaults.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly TemplateDefaults $defaults
	) {}

	/**
	 * Effective title template for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	public function title_for( string $post_type ): string {
		$stored = $this->stored( $post_type, 'title' );

		return '' !== $stored ? $stored : $this->defaults->singular_title();
	}

	/**
	 * Effective description template for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	public function description_for( string $post_type ): string {
		$stored = $this->stored( $post_type, 'description' );

		return '' !== $stored ? $stored : $this->defaults->singular_description();
	}

	/**
	 * Stored per-type template field, or '' when absent.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $field     'title' or 'description'.
	 */
	private function stored( string $post_type, string $field ): string {
		$map = $this->options->get( 'post_types' );

		if ( ! is_array( $map ) ) {
			return '';
		}

		return (string) ( $map[ $post_type ][ $field ] ?? '' );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TypeTemplatesTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Meta/TypeTemplates.php tests/Unit/Meta/TypeTemplatesTest.php
git commit -m "feat(meta): add TypeTemplates (effective per-type singular template)"
```

---

## Task 2: `Resolver` usa `TypeTemplates` en la rama singular

Refactor dirigido: la rama `is_singular()` de `title()`/`description()` pasa a usar `TypeTemplates`; la rama de taxonomía y `type_template()` quedan intactos. El constructor del `Resolver` gana un 4º argumento, así que se actualizan `Plugin.php` y los cuatro tests que construyen `Resolver`.

**Files:**
- Modify: `src/Meta/Resolver.php` (constructor + bloques singulares de `title()`/`description()`)
- Modify: `src/Plugin.php` (construir `TemplateDefaults` + `TypeTemplates` una vez; pasar al `Resolver`)
- Modify: `tests/Unit/Meta/ResolverTest.php`, `tests/Unit/Frontend/Head/DescriptionTest.php`, `tests/Unit/Frontend/Head/RobotsTest.php`, `tests/Unit/Schema/Pieces/ContentPiecesTest.php`

**Interfaces:**
- Consumes: `TypeTemplates` (Task 1).
- Produces: `Resolver::__construct(Options, Variables, TemplateDefaults, TypeTemplates)`. Behavior for singular/taxonomy/home unchanged.

- [ ] **Step 1: Update the ResolverTest harness (and add a TypeTemplates import) — keep existing tests green**

In `tests/Unit/Meta/ResolverTest.php`, add below the existing imports:

```php
use OpenSEO\Meta\TypeTemplates;
```

Replace the `resolver()` helper with:

```php
	private function resolver(): Resolver {
		$options  = new Options();
		$defaults = new TemplateDefaults();
		return new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) );
	}
```

- [ ] **Step 2: Run to verify it fails (constructor arity)**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: FAIL — `Resolver::__construct()` expects 3 args (the 4-arg helper does not match yet) or `TypeTemplates` not used by Resolver.

- [ ] **Step 3: Add the constructor dependency**

In `src/Meta/Resolver.php`, add the import below `use OpenSEO\Meta\TemplateDefaults;`:

```php
use OpenSEO\Meta\TypeTemplates;
```

Change the constructor (lines 33-36) to:

```php
		private readonly Options $options,
		private readonly Variables $variables,
		private readonly TemplateDefaults $defaults,
		private readonly TypeTemplates $type_templates
	) {}
```

- [ ] **Step 4: Use TypeTemplates in the singular branch of `title()`**

In `title()`, replace these lines (the post-type stored+fallback block):

```php
			$template = $this->type_template( 'post_types', (string) get_post_type( $id ), 'title' );
			if ( '' === $template ) {
				$template = $this->defaults->singular_title();
			}

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
```
with:
```php
			$template = $this->type_templates->title_for( (string) get_post_type( $id ) );

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
```

- [ ] **Step 5: Use TypeTemplates in the singular branch of `description()`**

In `description()`, replace:

```php
			$template = $this->type_template( 'post_types', (string) get_post_type( $id ), 'description' );
			if ( '' === $template ) {
				$template = $this->defaults->singular_description();
			}

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
```
with:
```php
			$template = $this->type_templates->description_for( (string) get_post_type( $id ) );

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
```

> Leave the taxonomy branches (`type_template( 'taxonomies', … )` + `$this->defaults->taxonomy_*`) and the private `type_template()` method UNCHANGED — taxonomy still uses them.

- [ ] **Step 6: Update `Plugin.php` to build and inject the shared instances**

In `src/Plugin.php`, add the import below `use OpenSEO\Meta\TemplateDefaults;`:

```php
use OpenSEO\Meta\TypeTemplates;
```

In `modules()`, replace the line:

```php
		$resolver  = new Resolver( $options, $variables, new TemplateDefaults() );
```
with:
```php
		$defaults       = new TemplateDefaults();
		$type_templates = new TypeTemplates( $options, $defaults );
		$resolver       = new Resolver( $options, $variables, $defaults, $type_templates );
```

(`$type_templates` is reused by `EditorPanel` in Task 3.)

- [ ] **Step 7: Update the other three Resolver call sites in tests**

In `tests/Unit/Frontend/Head/DescriptionTest.php`, `tests/Unit/Frontend/Head/RobotsTest.php`, and `tests/Unit/Schema/Pieces/ContentPiecesTest.php`, add the import below `use OpenSEO\Meta\TemplateDefaults;`:

```php
use OpenSEO\Meta\TypeTemplates;
```

and change each `resolver()` helper's `return` to:

```php
		$defaults = new TemplateDefaults();
		return new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) );
```

- [ ] **Step 8: Run the full unit suite + static analysis**

Run: `composer test:unit`
Expected: PASS (whole suite green, including the existing singular per-type tests and the two taxonomy tests `test_title_resolves_taxonomy_with_default` / `test_description_resolves_taxonomy_template`).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 9: Commit**

```bash
git add src/Meta/Resolver.php src/Plugin.php tests/Unit/Meta/ResolverTest.php tests/Unit/Frontend/Head/DescriptionTest.php tests/Unit/Frontend/Head/RobotsTest.php tests/Unit/Schema/Pieces/ContentPiecesTest.php
git commit -m "refactor(meta): Resolver singular branch uses shared TypeTemplates"
```

---

## Task 3: `EditorPanel` — bootstrap del preview + encolado del CSS

> **Depends on Task 2 Step 6:** `Plugin::modules()` must already define `$defaults` and
> `$type_templates` (added in Task 2) before Step 5 here passes `$type_templates` to `EditorPanel`.
> The `composer analyze` gate in Step 6 catches an undefined `$type_templates`, but do not run this
> task before Task 2 is committed.

**Files:**
- Modify: `src/Admin/Editor/EditorPanel.php` (constructor deps + bootstrap data + post-type resolution + enqueue CSS)
- Modify: `src/Plugin.php` (construct `EditorPanel` with `Options` + `TypeTemplates`)

**Interfaces:**
- Consumes: `Options` (`get('title_separator')`), `TypeTemplates` (Task 1).
- Produces: `window.openseoEditor` gains `titleTemplate`, `descriptionTemplate`, `separator`, `siteName`, `tagline`, `siteUrl`, `siteIcon` (consumed by Task 8).

> No unit test for `EditorPanel` (it calls `enqueue_block_editor_assets`/`get_current_screen` and has no testable return — consistent with the codebase, which does not unit-test enqueue classes). The testable per-type logic lives in `TypeTemplatesTest` (Task 1). Gate is lint + analyze + the full unit suite staying green.

- [ ] **Step 1: Add constructor dependencies**

In `src/Admin/Editor/EditorPanel.php`, add imports below `use OpenSEO\Contracts\Hookable;`:

```php
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Settings\Options;
```

Add a constructor before `register()`:

```php
	/**
	 * Constructor.
	 *
	 * @param Options       $options        Settings accessor (separator).
	 * @param TypeTemplates $type_templates Effective per-type templates.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly TypeTemplates $type_templates
	) {}
```

- [ ] **Step 2: Add a private helper to resolve the current post type**

Add this method to the class (e.g. after `register()`):

```php
	/**
	 * Best-effort current post type on the editor screen, defaulting to 'post'.
	 */
	private function current_post_type(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen && '' !== (string) $screen->post_type ) {
			return (string) $screen->post_type;
		}

		if ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$type = (string) get_post_type( $GLOBALS['post'] );
			if ( '' !== $type ) {
				return $type;
			}
		}

		return 'post';
	}
```

- [ ] **Step 3: Extend the bootstrap payload**

In `enqueue()`, replace the existing `wp_add_inline_script( ... )` block's array with the extended payload:

```php
		$post_type = $this->current_post_type();

		wp_add_inline_script(
			self::HANDLE,
			'window.openseoEditor = ' . wp_json_encode(
				array(
					'aiAvailable'         => Connector::is_text_generation_available(),
					'connectorsUrl'       => Connector::settings_url(),
					'titleTemplate'       => $this->type_templates->title_for( $post_type ),
					'descriptionTemplate' => $this->type_templates->description_for( $post_type ),
					'separator'           => (string) $this->options->get( 'title_separator' ),
					'siteName'            => (string) get_bloginfo( 'name' ),
					'tagline'             => (string) get_bloginfo( 'description' ),
					'siteUrl'             => (string) home_url( '/' ),
					'siteIcon'            => (string) get_site_icon_url(),
				),
				JSON_HEX_TAG
			) . ';',
			'before'
		);
```

- [ ] **Step 4: Enqueue the editor stylesheet (defensively)**

In `enqueue()`, after the `wp_enqueue_script( ... )` call, add:

```php
		$style_path = OPENSEO_PLUGIN_DIR . 'assets/build/style-editor.css';
		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				self::HANDLE,
				OPENSEO_PLUGIN_URL . 'assets/build/style-editor.css',
				array(),
				$asset['version'] ?? OPENSEO_VERSION
			);
		}
```

(`style-editor.css` is produced once Task 8 imports `editor.scss`; until then the guard skips it.)

- [ ] **Step 5: Construct `EditorPanel` with its deps in `Plugin.php`**

In `src/Plugin.php`, change:

```php
			$modules[] = new EditorPanel();
```
to:
```php
			$modules[] = new EditorPanel( $options, $type_templates );
```

(`$type_templates` already exists from Task 2 Step 6.)

- [ ] **Step 6: Run lint + analysis + unit suite**

Run: `composer lint`
Expected: No PHPCS violations.

Run: `composer analyze`
Expected: No errors.

Run: `composer test:unit`
Expected: Whole suite green (Plugin wiring change did not break module construction).

- [ ] **Step 7: Commit**

```bash
git add src/Admin/Editor/EditorPanel.php src/Plugin.php
git commit -m "feat(editor): bootstrap effective templates + site data for the SERP preview"
```

---

## Task 4: `preview.js` — funciones puras de resolución/formato

**Files:**
- Modify: `assets/src/editor/preview.js` (full rewrite)
- Modify: `assets/src/editor/preview.test.js` (full rewrite)

**Interfaces:**
- Consumes: nothing.
- Produces: `expandTokens(template, tokens)`, `resolveSnippet({ override, template, tokens })`, `truncate(text, max)`, `deriveExcerpt(content)`, `formatBreadcrumb(url)`. `buildSnippetPreview` is removed.

- [ ] **Step 1: Rewrite the test**

Replace the entire contents of `assets/src/editor/preview.test.js` with:

```js
import {
	expandTokens,
	resolveSnippet,
	truncate,
	deriveExcerpt,
	formatBreadcrumb,
} from './preview';

const tokens = {
	'%title%': 'Hello',
	'%excerpt%': 'A summary.',
	'%sitename%': 'My Site',
	'%tagline%': 'Tag',
	'%sep%': '-',
	'%currentyear%': '2026',
};

describe( 'expandTokens', () => {
	it( 'replaces tokens', () => {
		expect( expandTokens( '%title% %sep% %sitename%', tokens ) ).toBe(
			'Hello - My Site'
		);
	} );

	it( 'strips a dangling separator left by an empty token', () => {
		expect( expandTokens( '%title% %sep%', { ...tokens, '%title%': '' } ) ).toBe(
			''
		);
	} );

	it( 'collapses whitespace from empty tokens mid-template', () => {
		expect(
			expandTokens( '%title% %sep% %sitename%', { ...tokens, '%title%': '' } )
		).toBe( 'My Site' );
	} );

	it( 'treats a multi-character separator as a whole string', () => {
		const multi = { ...tokens, '%sep%': '—', '%title%': '' };
		expect( expandTokens( '%title% %sep% %sitename%', multi ) ).toBe( 'My Site' );
	} );

	it( 'escapes regex metacharacters in the separator', () => {
		const meta = { ...tokens, '%sep%': '|', '%title%': '' };
		expect( expandTokens( '%title% %sep% %sitename%', meta ) ).toBe( 'My Site' );
	} );
} );

describe( 'resolveSnippet', () => {
	it( 'uses the override when present', () => {
		expect(
			resolveSnippet( { override: 'Manual', template: '%title%', tokens } )
		).toBe( 'Manual' );
	} );

	it( 'expands the template when there is no override', () => {
		expect(
			resolveSnippet( { override: '', template: '%title% %sep% %sitename%', tokens } )
		).toBe( 'Hello - My Site' );
	} );
} );

describe( 'truncate', () => {
	it( 'adds an ellipsis past the max', () => {
		expect( truncate( 'a'.repeat( 10 ), 5 ) ).toBe( 'aaaaa…' );
	} );

	it( 'leaves short text untouched', () => {
		expect( truncate( 'short', 60 ) ).toBe( 'short' );
	} );
} );

describe( 'deriveExcerpt', () => {
	it( 'strips block comments and tags and collapses whitespace', () => {
		const content =
			'<!-- wp:paragraph --><p>Hello   <strong>world</strong>.</p><!-- /wp:paragraph -->';
		expect( deriveExcerpt( content ) ).toBe( 'Hello world.' );
	} );
} );

describe( 'formatBreadcrumb', () => {
	it( 'drops the protocol and joins path segments with a chevron', () => {
		expect( formatBreadcrumb( 'https://example.com/blog/my-post/' ) ).toBe(
			'example.com › blog › my-post'
		);
	} );

	it( 'shows just the host for a root URL', () => {
		expect( formatBreadcrumb( 'https://example.com/' ) ).toBe( 'example.com' );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- preview.test.js`
Expected: FAIL — new exports not defined.

- [ ] **Step 3: Add the new pure helpers to `preview.js` (keep `buildSnippetPreview` for now)**

**Append** the functions below to `assets/src/editor/preview.js`, **keeping** the existing
`MAX_DESCRIPTION` constant and `buildSnippetPreview` export in place. They are removed in
Task 8 Step 3 once `GeneralTab` stops importing `buildSnippetPreview` — keeping them now means
`editor/index.js` (which still imports `buildSnippetPreview` until Task 8) keeps compiling, so the
JS bundle stays green on every commit between here and Task 8.

> Deliberate token subset: only the singular tokens apply in the post editor;
> `%term%`/`%term_description%` are intentionally NOT handled here (see spec §1).

```js
/**
 * Pure helpers for the editor SERP preview. expandTokens mirrors
 * Variables::replace (PHP): substitute tokens, collapse whitespace, then strip
 * a dangling separator treated as a whole (regex-escaped) string.
 */

/**
 * @param {string} template Template containing %tokens%.
 * @param {Object} tokens   Map of token -> replacement (includes %sep%).
 * @return {string} The expanded, cleaned string.
 */
export function expandTokens( template, tokens ) {
	let out = template;
	Object.keys( tokens ).forEach( ( token ) => {
		out = out.split( token ).join( tokens[ token ] ?? '' );
	} );

	out = out.replace( /\s+/g, ' ' ).trim();

	const sep = String( tokens[ '%sep%' ] ?? '' ).trim();
	if ( sep ) {
		const esc = sep.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		out = out
			.replace( new RegExp( `^(?:${ esc }\\s*)+`, '' ), '' )
			.replace( new RegExp( `(?:\\s*${ esc })+$`, '' ), '' );
	}

	return out.trim();
}

/**
 * @param {Object} input
 * @param {string} input.override Per-entry override (wins verbatim if non-empty).
 * @param {string} input.template Type template to expand when no override.
 * @param {Object} input.tokens   Token map.
 * @return {string} Resolved field value.
 */
export function resolveSnippet( { override, template, tokens } ) {
	return override ? override : expandTokens( template, tokens );
}

/**
 * @param {string} text
 * @param {number} max
 * @return {string} text truncated with an ellipsis for display only.
 */
export function truncate( text, max ) {
	return text.length > max ? `${ text.slice( 0, max ) }…` : text;
}

/**
 * Best-effort excerpt from serialized block content (NOT parity with
 * get_the_excerpt): strip block comments and tags, collapse whitespace.
 *
 * @param {string} content
 * @return {string}
 */
export function deriveExcerpt( content ) {
	return content
		.replace( /<!--[\s\S]*?-->/g, '' )
		.replace( /<[^>]*>/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * Format a URL as a Google-style breadcrumb (host › segment › segment).
 *
 * @param {string} url
 * @return {string}
 */
export function formatBreadcrumb( url ) {
	const noProtocol = String( url ).replace( /^[a-z]+:\/\//i, '' ).replace( /\/+$/, '' );
	const parts = noProtocol.split( '/' ).filter( Boolean );
	return parts.join( ' › ' );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- preview.test.js`
Expected: PASS (all describe blocks green).

- [ ] **Step 5: Commit**

```bash
git add assets/src/editor/preview.js assets/src/editor/preview.test.js
git commit -m "feat(editor): pure snippet resolve/format helpers (expandTokens, breadcrumb)"
```

---

## Task 5: `length.js` — estado de las barras de longitud

**Files:**
- Create: `assets/src/editor/length.js`
- Test: `assets/src/editor/length.test.js`

**Interfaces:**
- Consumes: nothing.
- Produces: `lengthState(len, { min, max, hardMax })` → `{ count, status: 'ok'|'warn'|'over', percent }`.

- [ ] **Step 1: Write the failing test**

```js
import { lengthState } from './length';

const TITLE = { min: 30, max: 60, hardMax: 70 };

describe( 'lengthState', () => {
	it( 'is over when empty', () => {
		expect( lengthState( 0, TITLE ).status ).toBe( 'over' );
	} );

	it( 'warns below the minimum', () => {
		expect( lengthState( 20, TITLE ).status ).toBe( 'warn' );
	} );

	it( 'is ok inside the range', () => {
		expect( lengthState( 45, TITLE ).status ).toBe( 'ok' );
	} );

	it( 'warns between max and hardMax', () => {
		expect( lengthState( 65, TITLE ).status ).toBe( 'warn' );
	} );

	it( 'is over past hardMax', () => {
		expect( lengthState( 80, TITLE ).status ).toBe( 'over' );
	} );

	it( 'reports count and a capped percent', () => {
		const result = lengthState( 90, TITLE );
		expect( result.count ).toBe( 90 );
		expect( result.percent ).toBe( 100 );
	} );

	const DESC = { min: 120, max: 160, hardMax: 180 };

	it( 'applies the description bounds: ok inside range', () => {
		expect( lengthState( 140, DESC ).status ).toBe( 'ok' );
	} );

	it( 'applies the description bounds: over past hardMax', () => {
		expect( lengthState( 200, DESC ).status ).toBe( 'over' );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- length.test.js`
Expected: FAIL — cannot find module `./length`.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Status + bar fill for a length indicator.
 *
 * @param {number} len      Current text length (excludes display ellipsis).
 * @param {Object} bounds
 * @param {number} bounds.min     Below this → warn.
 * @param {number} bounds.max     Above this → warn (up to hardMax).
 * @param {number} bounds.hardMax 0 or above this → over.
 * @return {{ count: number, status: 'ok'|'warn'|'over', percent: number }}
 */
export function lengthState( len, { min, max, hardMax } ) {
	let status = 'ok';
	if ( len === 0 || len > hardMax ) {
		status = 'over';
	} else if ( len < min || len > max ) {
		status = 'warn';
	}

	const percent = Math.min( 100, Math.round( ( len / max ) * 100 ) );

	return { count: len, status, percent };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- length.test.js`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add assets/src/editor/length.js assets/src/editor/length.test.js
git commit -m "feat(editor): add lengthState helper for length indicators"
```

---

## Task 6: `LengthIndicator` component

**Files:**
- Create: `assets/src/editor/components/LengthIndicator.js`

**Interfaces:**
- Consumes: `lengthState` (Task 5).
- Produces: `LengthIndicator({ value, min, max, hardMax })` rendering a colored bar + `N / max` counter.

- [ ] **Step 1: Write the component**

```jsx
import { lengthState } from '../length';

export function LengthIndicator( { value, min, max, hardMax } ) {
	const { count, status, percent } = lengthState( value.length, {
		min,
		max,
		hardMax,
	} );

	return (
		<div className={ `openseo-length openseo-length--${ status }` }>
			<div className="openseo-length__track">
				<div
					className="openseo-length__bar"
					style={ { width: `${ percent }%` } }
				/>
			</div>
			<span className="openseo-length__count">{ `${ count } / ${ max }` }</span>
		</div>
	);
}
```

- [ ] **Step 2: Lint the new file**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 3: Commit**

```bash
git add assets/src/editor/components/LengthIndicator.js
git commit -m "feat(editor): add LengthIndicator component"
```

---

## Task 7: `PreviewDevices` + `SerpPreview` components

**Files:**
- Create: `assets/src/editor/components/PreviewDevices.js`
- Create: `assets/src/editor/components/SerpPreview.js`

**Interfaces:**
- Consumes: `truncate` (Task 4); `@wordpress/components` `Button`; `@wordpress/i18n` `__`.
- Produces: `PreviewDevices({ device, onChange })`; `SerpPreview({ title, description, url, favicon, device, isNoindex })`.

- [ ] **Step 1: Write `PreviewDevices`**

```jsx
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function PreviewDevices( { device, onChange } ) {
	return (
		<div className="openseo-serp-devices">
			<Button
				variant={ device === 'desktop' ? 'primary' : 'secondary' }
				onClick={ () => onChange( 'desktop' ) }
			>
				{ __( 'Desktop', 'openseo' ) }
			</Button>
			<Button
				variant={ device === 'mobile' ? 'primary' : 'secondary' }
				onClick={ () => onChange( 'mobile' ) }
			>
				{ __( 'Mobile', 'openseo' ) }
			</Button>
		</div>
	);
}
```

- [ ] **Step 2: Write `SerpPreview`**

```jsx
import { __ } from '@wordpress/i18n';
import { truncate } from '../preview';

const TITLE_MAX = 60;
const DESC_MAX = 160;

export function SerpPreview( { title, description, url, favicon, device, isNoindex } ) {
	return (
		<div className={ `openseo-serp is-${ device }` }>
			{ isNoindex && (
				<p className="openseo-serp__noindex">
					{ __(
						'This page is set to noindex — it will not appear in search results.',
						'openseo'
					) }
				</p>
			) }
			<div className="openseo-serp__url">
				{ favicon && (
					<img
						className="openseo-serp__favicon"
						src={ favicon }
						alt=""
						width="16"
						height="16"
					/>
				) }
				<span className="openseo-serp__breadcrumb">{ url }</span>
			</div>
			<div className="openseo-serp__title">{ truncate( title, TITLE_MAX ) }</div>
			<div className="openseo-serp__desc">
				{ truncate( description, DESC_MAX ) }
			</div>
		</div>
	);
}
```

- [ ] **Step 3: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 4: Commit**

```bash
git add assets/src/editor/components/PreviewDevices.js assets/src/editor/components/SerpPreview.js
git commit -m "feat(editor): add PreviewDevices and SerpPreview components"
```

---

## Task 8: `editor.scss` + integración en `GeneralTab`

**Files:**
- Create: `assets/src/editor/editor.scss`
- Modify: `assets/src/editor/index.js` (import SCSS; rewrite `GeneralTab`)

**Interfaces:**
- Consumes: `resolveSnippet`, `deriveExcerpt`, `formatBreadcrumb` (Task 4); `LengthIndicator` (Task 6); `PreviewDevices`, `SerpPreview` (Task 7); `window.openseoEditor` (Task 3); `@wordpress/editor` store live attributes.
- Produces: the live SERP preview in the General tab.

- [ ] **Step 1: Create `assets/src/editor/editor.scss`**

```scss
.openseo-serp {
	margin-top: 12px;
	padding: 12px;
	border: 1px solid #dcdcde;
	border-radius: 8px;
	background: #fff;
	font-family: arial, sans-serif;

	&.is-mobile {
		max-width: 360px;
	}

	&__noindex {
		margin: 0 0 8px;
		padding: 6px 8px;
		border-radius: 4px;
		background: #fcf0f1;
		color: #8a1f11;
		font-size: 12px;
	}

	&__url {
		display: flex;
		align-items: center;
		gap: 6px;
		color: #4d5156;
		font-size: 12px;
	}

	&__favicon {
		display: block;
		border-radius: 50%;
	}

	&__title {
		margin-top: 4px;
		color: #1a0dab;
		font-size: 18px;
		line-height: 1.3;

		.openseo-serp.is-mobile & {
			font-size: 16px;
		}
	}

	&__desc {
		margin-top: 2px;
		color: #4d5156;
		font-size: 13px;
		line-height: 1.45;
	}
}

.openseo-serp-devices {
	display: flex;
	gap: 6px;
	margin-bottom: 8px;
}

.openseo-length {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 4px 0 12px;

	&__track {
		flex: 1;
		height: 4px;
		border-radius: 2px;
		background: #e0e0e0;
		overflow: hidden;
	}

	&__bar {
		height: 100%;
		background: #1e8a3c;
		transition: width 150ms ease;
	}

	&__count {
		font-size: 11px;
		color: #757575;
	}

	&--warn &__bar {
		background: #dba617;
	}

	&--over &__bar {
		background: #d63638;
	}
}
```

- [ ] **Step 2: Rewrite `GeneralTab` and import the SCSS in `index.js`**

In `assets/src/editor/index.js`:

Add the SCSS import at the top (after the existing imports):

```js
import './editor.scss';
```

Update the import from `./preview` (replace the `buildSnippetPreview` import line):

```js
import { resolveSnippet, deriveExcerpt, formatBreadcrumb } from './preview';
```

Add component + length imports near the other imports. **`useState` is ALREADY imported at
`index.js:10` — do NOT re-import it** (a duplicate import fails `lint:js`):

```js
import { LengthIndicator } from './components/LengthIndicator';
import { PreviewDevices } from './components/PreviewDevices';
import { SerpPreview } from './components/SerpPreview';
```

Replace the entire `GeneralTab` function with:

```jsx
function GeneralTab() {
	const [ title, setTitle ] = useMeta( '_openseo_title' );
	const [ description, setDescription ] = useMeta( '_openseo_description' );
	const [ noindex ] = useMeta( '_openseo_robots_noindex' );
	const [ device, setDevice ] = useState( 'desktop' );

	const { postTitle, excerpt, content, permalink } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postTitle: editor.getEditedPostAttribute( 'title' ) || '',
			excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
			content: editor.getEditedPostContent() || '',
			permalink: editor.getPermalink() || '',
		};
	}, [] );

	const cfg = window.openseoEditor ?? {};
	const tokens = {
		'%title%': postTitle,
		'%excerpt%': excerpt || deriveExcerpt( content ),
		'%sitename%': cfg.siteName ?? '',
		'%tagline%': cfg.tagline ?? '',
		'%sep%': cfg.separator ?? '-',
		'%currentyear%': String( new Date().getUTCFullYear() ),
	};

	const resolvedTitle = resolveSnippet( {
		override: title,
		template: cfg.titleTemplate ?? '',
		tokens,
	} );
	const resolvedDescription = resolveSnippet( {
		override: description,
		template: cfg.descriptionTemplate ?? '',
		tokens,
	} );
	const breadcrumb = formatBreadcrumb( permalink || cfg.siteUrl || '' );

	return (
		<>
			<PreviewDevices device={ device } onChange={ setDevice } />
			<SerpPreview
				title={ resolvedTitle }
				description={ resolvedDescription }
				url={ breadcrumb }
				favicon={ cfg.siteIcon ?? '' }
				device={ device }
				isNoindex={ noindex === '1' }
			/>

			<TextControl
				label={ __( 'SEO title', 'openseo' ) }
				value={ title }
				onChange={ setTitle }
			/>
			<LengthIndicator value={ title } min={ 30 } max={ 60 } hardMax={ 70 } />
			<GenerateButton
				abilityName="openseo/generate-title"
				field="title"
				onResult={ setTitle }
			/>
			<TextareaControl
				label={ __( 'Meta description', 'openseo' ) }
				value={ description }
				onChange={ setDescription }
			/>
			<LengthIndicator
				value={ description }
				min={ 120 }
				max={ 160 }
				hardMax={ 180 }
			/>
			<GenerateButton
				abilityName="openseo/generate-meta-description"
				field="meta_description"
				onResult={ setDescription }
			/>
		</>
	);
}
```

> The old inline preview `<div>` and the `siteName` read via `select('core').getSite()` are removed — `siteName` now comes from `window.openseoEditor`. The `./preview` import line (previously `import { buildSnippetPreview } from './preview';`) is replaced by the `resolveSnippet`/`deriveExcerpt`/`formatBreadcrumb` import above, so nothing imports `buildSnippetPreview` anymore.

- [ ] **Step 3: Remove the now-unused `buildSnippetPreview` from `preview.js`**

Now that `GeneralTab` no longer imports it, delete the leftover `MAX_DESCRIPTION` constant and the
`buildSnippetPreview` function from `assets/src/editor/preview.js` (the five new helpers from Task 4
stay). This was kept only to keep the bundle compiling between Task 4 and Task 8.

- [ ] **Step 4: Lint, test, build**

Run: `npm run lint:js`
Expected: No ESLint errors.

Run: `npm run test:js`
Expected: All suites pass (preview, length, plus existing).

Run: `npm run build`
Expected: Build succeeds and emits `assets/build/style-editor.css` (confirm the file exists).

- [ ] **Step 5: Commit**

```bash
git add assets/src/editor/editor.scss assets/src/editor/index.js assets/src/editor/preview.js
git commit -m "feat(editor): live SERP preview with devices, length bars, noindex"
```

---

## Task 9: Verificación final completa

**Files:** none (verification only).

- [ ] **Step 1: PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green (incl. `TypeTemplatesTest`, `ResolverTest`, the three Head/Schema tests).

- [ ] **Step 2: JS gates**

Run: `npm run lint:js`
Expected: No errors.

Run: `npm run test:js`
Expected: All suites pass (incl. `preview`, `length`).

Run: `npm run build`
Expected: Build succeeds; `assets/build/style-editor.css` present.

- [ ] **Step 3: Manual smoke test (wp-env, recommended)**

```bash
npm run env:start
```
Edit a post with the OpenSEO panel open (General tab): with SEO title/description **empty**, the SERP card shows the resolved template (post title + real separator + site name; excerpt approximation). Type an SEO title → it wins live. Toggle Desktop/Mobile. Watch the length bars change color. Enable noindex on the Advanced tab → the badge appears on General without reload.

- [ ] **Step 4: Final commit (only if build artifacts/fixes remain)**

```bash
git add -A
git commit -m "chore(editor): final verification for live SERP preview"
```

(Skip if nothing to commit.)

---

## Self-Review (completed during planning)

- **Spec coverage:** resolución en vivo cliente (Tasks 4/8), template efectivo PHP compartido (Tasks 1/2/3), bootstrap (Task 3), SerpPreview/PreviewDevices/LengthIndicator (Tasks 6/7), barras de color (Task 5/6), badge noindex (Tasks 7/8, `isNoindex` vivo), favicon/URL/breadcrumb (Tasks 4/7/8), escritorio/móvil (Tasks 7/8), CSS (Task 8), expandTokens como port literal (Task 4), `%currentyear%` UTC (Task 8 tokens), excerpt best-effort (Task 4 `deriveExcerpt`). Todos los criterios de aceptación tienen tarea.
- **Placeholder scan:** sin TBD/TODO; cada paso de código muestra el código y el comando con salida esperada.
- **Type/símbolo consistency:** `TypeTemplates::title_for/description_for` (Task 1) usados por Resolver (Task 2) y EditorPanel (Task 3); `resolveSnippet/expandTokens/truncate/deriveExcerpt/formatBreadcrumb` (Task 4) usados por componentes (Task 7) e integración (Task 8); `lengthState` (Task 5) usado por `LengthIndicator` (Task 6); bootstrap keys (`titleTemplate`, `descriptionTemplate`, `separator`, `siteName`, `tagline`, `siteUrl`, `siteIcon`) producidas en Task 3 y consumidas en Task 8.
- **Orden/verde por commit:** el cambio de constructor del `Resolver` (Task 2) migra atómicamente sus 5 call sites (Plugin.php + ResolverTest harness + Description/Robots/ContentPieces) y corre la suite completa; `EditorPanel` recibe `TypeTemplates` en Task 3 (ya construido en Task 2; nota de dependencia añadida); el CSS se importa en Task 8, y el encolado en `EditorPanel` (Task 3) es defensivo (`is_readable`) hasta entonces. **`buildSnippetPreview` se conserva en `preview.js` hasta Task 8** (Task 4 solo añade las nuevas funciones), de modo que `editor/index.js` —que lo importa hasta Task 8— sigue compilando; Task 8 lo elimina al reescribir `GeneralTab`. Así `npm run build` queda verde en cada commit, no solo al final.
- **Correcciones de la auditoría del plan incorporadas:** H1 (nota de dependencia Task 3→Task 2), H2 (no re-importar `useState`, ya está en index.js:10), M1 (conservar `buildSnippetPreview` hasta Task 8), M2 (exclusión deliberada de `%term%` documentada), M4 (`$GLOBALS['post'] instanceof \WP_Post` antes de `get_post_type`), L3 (asserts de descripción en `length.test.js`).
