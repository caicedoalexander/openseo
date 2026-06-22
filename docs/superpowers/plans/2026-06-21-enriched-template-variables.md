# Variables enriquecidas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir seis tokens de plantilla de entrada (`%date%`, `%modified%`, `%author%`, `%category%`, `%tag%`, `%parent_title%`) al sistema de variables de OpenSEO, resueltos también en el preview SERP en vivo del editor.

**Architecture:** Se extiende el sistema existente de 4 capas sin reescribirlo: (1) `TemplateContext` gana 6 primitivos `readonly` poblados en `for_post()` (las únicas lecturas WP nuevas viven ahí); (2) `Variables::replace()` gana 6 entradas en su mapa `strtr` que leen de `$context`; (3) `VariableCatalog::all()` gana 6 entradas de metadata (aparecen solas en el inserter del admin, ya bootstrapeado); (4) el editor resuelve los nuevos tokens en vivo vía un hook `useTemplateTokens()` que usa core-data + `@wordpress/date`, apoyado en helpers puros testeables.

**Tech Stack:** PHP 8.1+ (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey (unit, sin WordPress), PHPStan nivel 6, PHPCS (WPCS); `@wordpress/scripts` (React + Jest + ESLint), `@wordpress/date`, `@wordpress/core-data`, `@wordpress/editor`, `@wordpress/data`.

## Global Constraints

- Los 6 tokens nuevos son **scope `singular`**. En entradas sin categoría/tag/padre resuelven `''` (la lógica de whitespace/separador existente los colapsa).
- **`Variables` permanece puro respecto a la entrada:** las lecturas WP por entrada van SOLO en las factories de `TemplateContext`. `Variables::replace` solo lee de `$context` / globales ya existentes.
- **Invariante anti-drift:** el conjunto de tokens de `VariableCatalog::all()` debe coincidir con los que expande `Variables::replace` (lo verifica `tests/Unit/Meta/VariableCatalogTest.php::test_every_catalog_token_is_replaced_by_variables`).
- **Fechas con el `date_format` del sitio:** servidor `get_the_date('', $id)` / `get_the_modified_date('', $id)`; cliente `@wordpress/date` `dateI18n( getSettings().formats.date, iso )`.
- **`%category%`/`%tag%` = primer término** (orden de core); sin "término primario" configurable. Guards de vacío distintos: `get_the_category()` → `[]`; `get_the_tags()` → `false`.
- **Sin** variables parametrizadas (`%date(...)%`), **sin** tokens ambientales (`%page%`, `%search_query%`), **sin** plurales/listas.
- **Seguridad:** los tokens expanden a texto plano; el escape ocurre en la capa de salida existente (`Frontend\Head\*`). NO escapar dentro de `Variables` (sería doble-escape).
- **`@wordpress/date` se importa como módulo ES** (`import { dateI18n, getSettings } from '@wordpress/date'`) para que `@wordpress/scripts` lo externalice a `wp-date` y entre en `editor.asset.php`.
- **Gates por commit:** PHP → `composer check` (PHPCS + PHPStan nivel 6 + PHPUnit). JS → `npm run lint:js` (0 errores, árbol completo) **y** `npm run test:js`; `npm run build` cuando cambian assets que entran a un bundle.
- Sin trailer `Co-Authored-By` en los commits (config del proyecto).

---

## Task 1: `TemplateContext` — 6 primitivos + lecturas en `for_post` (+ ripple de mocks)

**Files:**
- Modify: `src/Meta/TemplateContext.php`
- Test: `tests/Unit/Meta/TemplateContextTest.php`
- Modify (mocks, para no fatalar): `tests/Unit/Meta/VariablesTest.php`, `tests/Unit/Meta/VariableCatalogTest.php`, `tests/Unit/Meta/ResolverTest.php`

**Interfaces:**
- Produces: `TemplateContext` con 6 propiedades públicas `readonly string`: `date`, `modified`, `author`, `category`, `tag`, `parent_title` (default `''`). `for_post(int $id)` las puebla; `for_term`/`none` las dejan `''`.
- Consumes: funciones WP `get_the_date`, `get_the_modified_date`, `get_post_field`, `get_the_author_meta`, `get_the_category`, `get_the_tags`, `wp_get_post_parent_id`, `get_the_title`.

> **Por qué este task toca 4 ficheros de test:** en cuanto `for_post()` llame a las funciones WP nuevas, **cualquier test que construya un contexto de post fatalará** en Brain Monkey si esas funciones no están mockeadas (las llamadas a funciones WP no definidas lanzan error). Hay que añadir mocks por defecto en los `setUp`/métodos de los tests que ya invocan `for_post` (`ResolverTest` vía resolución singular, `VariablesTest::test_replaces_post_tokens`, `VariableCatalogTest` anti-drift) en el MISMO commit.

- [ ] **Step 1: Escribir el test nuevo (RED)**

En `tests/Unit/Meta/TemplateContextTest.php`, añadir dentro de la clase (las funciones WP nuevas se mockean aquí con valores reales):

```php
	public function test_for_post_reads_enriched_primitives(): void {
		Functions\when( 'get_the_title' )->justReturn( 'Hello' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_the_date' )->justReturn( 'June 21, 2026' );
		Functions\when( 'get_the_modified_date' )->justReturn( 'June 22, 2026' );
		Functions\when( 'get_post_field' )->justReturn( 7 );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );
		$cat       = new WP_Term();
		$cat->name = 'News';
		$tag       = new WP_Term();
		$tag->name = 'Featured';
		Functions\when( 'get_the_category' )->justReturn( array( $cat ) );
		Functions\when( 'get_the_tags' )->justReturn( array( $tag ) );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );

		$ctx = TemplateContext::for_post( 42 );

		$this->assertSame( 'June 21, 2026', $ctx->date );
		$this->assertSame( 'June 22, 2026', $ctx->modified );
		$this->assertSame( 'Jane Doe', $ctx->author );
		$this->assertSame( 'News', $ctx->category );
		$this->assertSame( 'Featured', $ctx->tag );
		$this->assertSame( '', $ctx->parent_title );
	}

	public function test_for_post_resolves_parent_title_and_empty_terms(): void {
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_the_date' )->justReturn( '' );
		Functions\when( 'get_the_modified_date' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn( array() ); // [] empty shape
		Functions\when( 'get_the_tags' )->justReturn( false );       // false empty shape
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 3 );
		Functions\when( 'get_the_title' )->alias(
			static fn( $id ) => 3 === $id ? 'Parent Page' : 'Child'
		);

		$ctx = TemplateContext::for_post( 42 );

		$this->assertSame( '', $ctx->category );
		$this->assertSame( '', $ctx->tag );
		$this->assertSame( 'Parent Page', $ctx->parent_title );
	}
```

- [ ] **Step 2: Ejecutar el test (verificar que falla)**

Run: `vendor/bin/phpunit --filter TemplateContextTest`
Expected: FAIL — `$ctx->date`/`->category`/etc. no existen (propiedad indefinida) o argumentos del constructor.

- [ ] **Step 3: Extender `TemplateContext`**

En `src/Meta/TemplateContext.php`, cambiar la firma del constructor para añadir los 6 primitivos (con default `''` para que `for_term`/`none` no cambien):

```php
	private function __construct(
		public readonly int $post_id,
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $term_name,
		public readonly string $term_description,
		public readonly string $date = '',
		public readonly string $modified = '',
		public readonly string $author = '',
		public readonly string $category = '',
		public readonly string $tag = '',
		public readonly string $parent_title = '',
	) {}
```

Actualizar los PHPDoc del constructor añadiendo las 6 líneas `@param string $date Published date.` … `@param string $parent_title Parent entry title.`

Reescribir `for_post()`:

```php
	public static function for_post( int $post_id ): self {
		$categories = get_the_category( $post_id );
		$tags       = get_the_tags( $post_id );
		$parent     = (int) wp_get_post_parent_id( $post_id );

		// get_post_field stub returns int|string|int[]; is_scalar narrows away
		// the array branch so the (int) cast is PHPStan-level-6 clean (M3).
		$author     = get_post_field( 'post_author', $post_id );
		$author_id  = is_scalar( $author ) ? (int) $author : 0;

		return new self(
			$post_id,
			(string) get_the_title( $post_id ),
			wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ),
			'',
			'',
			(string) get_the_date( '', $post_id ),
			(string) get_the_modified_date( '', $post_id ),
			(string) get_the_author_meta( 'display_name', $author_id ),
			isset( $categories[0] ) ? (string) $categories[0]->name : '',
			is_array( $tags ) && isset( $tags[0] ) ? (string) $tags[0]->name : '',
			$parent > 0 ? (string) get_the_title( $parent ) : '',
		);
	}
```

(No tocar `for_term()` ni `none()`: los nuevos primitivos toman su default `''`.
`$parent` se castea a `int` antes de `> 0` para evitar el ruido de PHPStan sobre
`int|false` (M4).)

- [ ] **Step 4: Añadir mocks por defecto a los tests que ya construyen `for_post` (evitar fatales)**

> **Aplicar este Step 4 en el MISMO commit que el Step 3, antes de correr la suite (Step 5).** En cuanto el Step 3 reescribe `for_post`, los tests existentes que construyen un contexto de post fatalan hasta que estos defaults estén puestos; no hay un estado "verde" entre Step 3 y Step 4 (es esperado dentro del task). Nota: los dos tests nuevos del Step 1 ya redefinen algunas de estas funciones con `Functions\when(...)` locales — eso **sobreescribe** los defaults del `setUp` (el del cuerpo del test gana en Brain Monkey); es intencional, no un duplicado a eliminar.

En `tests/Unit/Meta/TemplateContextTest.php`, añadir al final de `setUp()` (después de `Monkey\setUp();`) defaults seguros:

```php
		Functions\when( 'get_the_date' )->justReturn( '' );
		Functions\when( 'get_the_modified_date' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn( array() );
		Functions\when( 'get_the_tags' )->justReturn( false );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
```

En `tests/Unit/Meta/VariablesTest.php`, añadir esas mismas 7 líneas al final de `setUp()`.

En `tests/Unit/Meta/ResolverTest.php`, añadir esas mismas 7 líneas al final de `setUp()` (después de `Functions\when( 'get_post_type' )->justReturn( 'post' );`). (ResolverTest ya mockea `get_the_title`/`get_the_excerpt`/`wp_strip_all_tags` en su `setUp`; solo faltan estos 7 — cubren de una vez los 8 casos singular-sin-override que resuelven plantilla vía `for_post`.)

En `tests/Unit/Meta/VariableCatalogTest.php`, dentro de `test_every_catalog_token_is_replaced_by_variables()`, añadir esas 7 líneas justo después de `Functions\when( 'wp_strip_all_tags' )->returnArg();`.

- [ ] **Step 5: Ejecutar la suite PHP completa**

Run: `composer check`
Expected: PHPCS limpio, PHPStan nivel 6 sin errores, PHPUnit todo verde (incl. los dos tests nuevos de `TemplateContextTest` y los existentes que ahora mockean las funciones nuevas).

> Si PHPStan se queja del acceso `$categories[0]->name` (tipo de retorno de `get_the_category`), confirmar que `isset()` lo estrecha; si no, no cambiar la lógica — `get_the_category` devuelve `WP_Term[]` en los stubs.

- [ ] **Step 6: Commit**

```bash
git add src/Meta/TemplateContext.php tests/Unit/Meta/TemplateContextTest.php tests/Unit/Meta/VariablesTest.php tests/Unit/Meta/VariableCatalogTest.php tests/Unit/Meta/ResolverTest.php
git commit -m "feat(meta): enriquece TemplateContext con date/modified/author/category/tag/parent_title"
```

---

## Task 2: `Variables::replace` — 6 reemplazos nuevos

**Files:**
- Modify: `src/Meta/Variables.php`
- Test: `tests/Unit/Meta/VariablesTest.php`

**Interfaces:**
- Consumes: `TemplateContext->{date,modified,author,category,tag,parent_title}` (Task 1).
- Produces: `Variables::replace` expande `%date%`, `%modified%`, `%author%`, `%category%`, `%tag%`, `%parent_title%`.

- [ ] **Step 1: Escribir el test (RED)**

En `tests/Unit/Meta/VariablesTest.php`, añadir:

```php
	public function test_replaces_enriched_post_tokens(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_the_date' )->justReturn( 'June 21, 2026' );
		Functions\when( 'get_post_field' )->justReturn( 7 );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );
		$cat       = new WP_Term();
		$cat->name = 'News';
		Functions\when( 'get_the_category' )->justReturn( array( $cat ) );
		Functions\when( 'get_the_tags' )->justReturn( false );

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_post( 42 );

		$this->assertSame( 'June 21, 2026', $variables->replace( '%date%', $ctx ) );
		$this->assertSame( 'Jane Doe', $variables->replace( '%author%', $ctx ) );
		$this->assertSame( 'News', $variables->replace( '%category%', $ctx ) );
		$this->assertSame( '', $variables->replace( '%tag%', $ctx ) );
	}
```

(Las funciones WP no sobrescritas aquí —`get_the_modified_date`, `wp_get_post_parent_id`— ya tienen default seguro en `setUp()` desde Task 1. **Este test depende de Task 1**: no ejecutar Task 2 aislado.)

- [ ] **Step 2: Ejecutar el test (verificar que falla)**

Run: `vendor/bin/phpunit --filter test_replaces_enriched_post_tokens`
Expected: FAIL — `%date%`/`%author%`/`%category%` salen literales (strtr no los conoce aún).

- [ ] **Step 3: Añadir los reemplazos**

En `src/Meta/Variables.php`, dentro del array `$replacements` (después de `'%term_description%' => $context->term_description,`), añadir:

```php
			'%date%'         => $context->date,
			'%modified%'     => $context->modified,
			'%author%'       => $context->author,
			'%category%'     => $context->category,
			'%tag%'          => $context->tag,
			'%parent_title%' => $context->parent_title,
```

- [ ] **Step 4: Ejecutar tests**

Run: `composer check`
Expected: verde (PHPCS alinea el array si hace falta — si marca alineación, correr `composer lint:fix` y re-`composer check`).

- [ ] **Step 5: Commit**

```bash
git add src/Meta/Variables.php tests/Unit/Meta/VariablesTest.php
git commit -m "feat(meta): expande date/modified/author/category/tag/parent_title en Variables"
```

---

## Task 3: `VariableCatalog` — 6 entradas de metadata

**Files:**
- Modify: `src/Meta/VariableCatalog.php`
- Test: `tests/Unit/Meta/VariableCatalogTest.php`

**Interfaces:**
- Consumes: nada nuevo (la expansión ya existe tras Task 2).
- Produces: `VariableCatalog::all()` incluye las 6 entradas nuevas (`scope => 'singular'`); aparecen automáticamente en `window.openseoAdmin.variables` (inserter del admin) sin trabajo de UI.

- [ ] **Step 1: Escribir el test (RED)**

En `tests/Unit/Meta/VariableCatalogTest.php`, añadir:

```php
	public function test_catalog_includes_enriched_tokens(): void {
		$tokens = array_column( ( new VariableCatalog() )->all(), 'token' );

		foreach ( array( '%date%', '%modified%', '%author%', '%category%', '%tag%', '%parent_title%' ) as $expected ) {
			$this->assertContains( $expected, $tokens );
		}
	}
```

- [ ] **Step 2: Ejecutar el test (verificar que falla)**

Run: `vendor/bin/phpunit --filter test_catalog_includes_enriched_tokens`
Expected: FAIL — los tokens no están en el catálogo.

- [ ] **Step 3: Añadir las entradas al catálogo**

En `src/Meta/VariableCatalog.php`, dentro del array devuelto por `all()`, después de la entrada de `%excerpt%` (y antes de `%term%`), añadir:

```php
				array(
					'token'       => '%date%',
					'label'       => __( 'Published date', 'openseo' ),
					'description' => __( 'The entry publication date', 'openseo' ),
					'scope'       => 'singular',
				),
				array(
					'token'       => '%modified%',
					'label'       => __( 'Modified date', 'openseo' ),
					'description' => __( 'The entry last-modified date', 'openseo' ),
					'scope'       => 'singular',
				),
				array(
					'token'       => '%author%',
					'label'       => __( 'Author', 'openseo' ),
					'description' => __( "The entry author's name", 'openseo' ),
					'scope'       => 'singular',
				),
				array(
					'token'       => '%category%',
					'label'       => __( 'Category', 'openseo' ),
					'description' => __( 'The first category of the entry', 'openseo' ),
					'scope'       => 'singular',
				),
				array(
					'token'       => '%tag%',
					'label'       => __( 'Tag', 'openseo' ),
					'description' => __( 'The first tag of the entry', 'openseo' ),
					'scope'       => 'singular',
				),
				array(
					'token'       => '%parent_title%',
					'label'       => __( 'Parent title', 'openseo' ),
					'description' => __( 'The title of the parent entry', 'openseo' ),
					'scope'       => 'singular',
				),
```

- [ ] **Step 4: Ejecutar tests**

Run: `composer check`
Expected: verde. El test anti-drift `test_every_catalog_token_is_replaced_by_variables` ahora itera 14 tokens y todos se expanden (Task 2). El test estructural valida regex/scope de los nuevos. `test_catalog_includes_enriched_tokens` pasa.

- [ ] **Step 5: Commit**

```bash
git add src/Meta/VariableCatalog.php tests/Unit/Meta/VariableCatalogTest.php
git commit -m "feat(meta): añade date/modified/author/category/tag/parent_title al catálogo"
```

---

## Task 4: Helpers puros del editor (`tokens.js`)

**Files:**
- Create: `assets/src/editor/tokens.js`
- Test: `assets/src/editor/tokens.test.js`

**Interfaces:**
- Produces: `recordName(record)` → `record?.name ?? ''`; `recordTitle(record)` → `record?.title?.rendered ?? ''`; `formatTokenDate(iso)` → `''` si falsy, si no `dateI18n( getSettings().formats.date, iso )`.

> **Nota de refinamiento del spec:** el spec mencionaba `firstTermName(records)` / `authorName(user)` por separado. Como el hook (Task 5) obtiene registros **individuales** por su primer ID (más barato y respeta el orden del editor), y autor/categoría/tag leen todos `.name`, se unifican en un único `recordName(record)` (DRY). `recordTitle` cubre el padre (`title.rendered`).

- [ ] **Step 1: Escribir el test (RED)**

Crear `assets/src/editor/tokens.test.js`:

```js
import { recordName, recordTitle, formatTokenDate } from './tokens';

describe( 'recordName', () => {
	it( 'returns the name of a record', () => {
		expect( recordName( { name: 'News' } ) ).toBe( 'News' );
	} );
	it( 'returns empty string for undefined or nameless records', () => {
		expect( recordName( undefined ) ).toBe( '' );
		expect( recordName( {} ) ).toBe( '' );
	} );
} );

describe( 'recordTitle', () => {
	it( 'returns the rendered title of a record', () => {
		expect( recordTitle( { title: { rendered: 'Parent' } } ) ).toBe(
			'Parent'
		);
	} );
	it( 'returns empty string when missing', () => {
		expect( recordTitle( undefined ) ).toBe( '' );
		expect( recordTitle( { title: {} } ) ).toBe( '' );
	} );
} );

describe( 'formatTokenDate', () => {
	it( 'returns empty string for falsy input', () => {
		expect( formatTokenDate( '' ) ).toBe( '' );
		expect( formatTokenDate( undefined ) ).toBe( '' );
		expect( formatTokenDate( null ) ).toBe( '' );
	} );
} );
```

- [ ] **Step 2: Ejecutar el test (verificar que falla)**

Run: `npm run test:js -- tokens.test.js`
Expected: FAIL — `cannot find module './tokens'`.

- [ ] **Step 3: Escribir la implementación**

Crear `assets/src/editor/tokens.js`:

```js
/**
 * Pure helpers for the editor live-preview token map.
 */

import { dateI18n, getSettings } from '@wordpress/date';

/**
 * Read the display name from a core-data record (user or term).
 *
 * @param {Object} record Entity record, or undefined while resolving.
 * @return {string} The record name, or '' when missing.
 */
export function recordName( record ) {
	return record?.name ?? '';
}

/**
 * Read the rendered title from a postType core-data record.
 *
 * @param {Object} record postType entity record, or undefined.
 * @return {string} The rendered title, or '' when missing.
 */
export function recordTitle( record ) {
	return record?.title?.rendered ?? '';
}

/**
 * Format an ISO date with the site's date_format (parity with get_the_date).
 *
 * @param {string} iso ISO date string from the editor, or empty.
 * @return {string} Localized date, or '' when there is no date.
 */
export function formatTokenDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	return dateI18n( getSettings().formats.date, iso );
}
```

- [ ] **Step 4: Ejecutar tests + lint**

Run: `npm run test:js -- tokens.test.js`
Expected: PASS.

Run: `npm run lint:js`
Expected: 0 errores (árbol completo).

> Contingencia (L1): `@wordpress/date` llega transitivamente vía `@wordpress/scripts` y se externaliza a `wp-date` en build (como ya hacen `@wordpress/editor`/`@wordpress/core-data` en `index.js`). Si `lint:js` reportara `import/no-extraneous-dependencies` sobre `@wordpress/date`, declararlo en `devDependencies` de `package.json` (como ya se hizo con `@wordpress/url`) y re-lint.

- [ ] **Step 5: Commit**

```bash
git add assets/src/editor/tokens.js assets/src/editor/tokens.test.js
git commit -m "feat(editor): helpers puros recordName/recordTitle/formatTokenDate"
```

---

## Task 5: Hook `useTemplateTokens` + cableado en `GeneralTab`

**Files:**
- Create: `assets/src/editor/useTemplateTokens.js`
- Modify: `assets/src/editor/index.js`

**Interfaces:**
- Consumes: `recordName`, `recordTitle`, `formatTokenDate` (Task 4); `deriveExcerpt` (de `./preview`); `@wordpress/data` `useSelect`; `@wordpress/core-data` `store as coreStore`; `@wordpress/editor` `store as editorStore`.
- Produces: `useTemplateTokens()` → objeto `{ token: value }` con los 6 tokens existentes + los 6 nuevos, listo para `resolveSnippet`.

- [ ] **Step 1: Crear el hook**

Crear `assets/src/editor/useTemplateTokens.js`:

```js
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { deriveExcerpt } from './preview';
import { recordName, recordTitle, formatTokenDate } from './tokens';

/**
 * Assemble the live token map for the editor SERP preview. Mirrors the tokens
 * that Variables::replace expands server-side. Records resolved via core-data
 * are '' until they load, so the preview never shows a raw token.
 *
 * @return {Object} Map of token -> replacement string.
 */
export function useTemplateTokens() {
	return useSelect( ( select ) => {
		const editor = select( editorStore );
		const core = select( coreStore );
		const cfg = window.openseoEditor ?? {};

		const content = editor.getEditedPostContent() || '';
		const postType = editor.getCurrentPostType();

		const authorId = editor.getEditedPostAttribute( 'author' );
		const author = authorId
			? recordName( core.getEntityRecord( 'root', 'user', authorId ) )
			: '';

		const catIds = editor.getEditedPostAttribute( 'categories' ) || [];
		const category = catIds.length
			? recordName(
					core.getEntityRecord( 'taxonomy', 'category', catIds[ 0 ] )
			  )
			: '';

		const tagIds = editor.getEditedPostAttribute( 'tags' ) || [];
		const tag = tagIds.length
			? recordName(
					core.getEntityRecord( 'taxonomy', 'post_tag', tagIds[ 0 ] )
			  )
			: '';

		const parentId = editor.getEditedPostAttribute( 'parent' );
		const parentTitle = parentId
			? recordTitle(
					core.getEntityRecord( 'postType', postType, parentId )
			  )
			: '';

		return {
			'%title%': editor.getEditedPostAttribute( 'title' ) || '',
			'%excerpt%':
				editor.getEditedPostAttribute( 'excerpt' ) ||
				deriveExcerpt( content ),
			'%sitename%': cfg.siteName ?? '',
			'%tagline%': cfg.tagline ?? '',
			'%sep%': cfg.separator ?? '-',
			'%currentyear%': String( new Date().getUTCFullYear() ),
			'%date%': formatTokenDate(
				editor.getEditedPostAttribute( 'date' )
			),
			'%modified%': formatTokenDate(
				editor.getEditedPostAttribute( 'modified' )
			),
			'%author%': author,
			'%category%': category,
			'%tag%': tag,
			'%parent_title%': parentTitle,
		};
	}, [] );
}
```

- [ ] **Step 2: Cablear `GeneralTab` para usar el hook**

En `assets/src/editor/index.js`:

(a) Cambiar el import de `./preview` para quitar `deriveExcerpt` (ahora lo usa el hook):

```jsx
import { resolveSnippet, formatBreadcrumb } from './preview';
```

(b) Añadir el import del hook (junto a los otros imports relativos, p. ej. tras `import { aiErrorMessage } from './ai';`):

```jsx
import { useTemplateTokens } from './useTemplateTokens';
```

(c) En `GeneralTab`, reemplazar el bloque `useSelect(... postTitle/excerpt/content/permalink ...)` + la construcción inline de `const tokens = {...}` por el hook y un `useSelect` mínimo para el permalink del breadcrumb:

```jsx
	const tokens = useTemplateTokens();
	const permalink = useSelect(
		( select ) => select( editorStore ).getPermalink() || '',
		[]
	);

	const cfg = window.openseoEditor ?? {};
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
```

(Eliminar las variables `postTitle`, `excerpt`, `content` que ya no se usan en `GeneralTab`; el resto de `GeneralTab` —el `return (...)` con `SerpPreview`/campos— no cambia.)

- [ ] **Step 3: Lint + test + build**

Run: `npm run lint:js`
Expected: 0 errores (sin `no-unused-vars`: `deriveExcerpt` ya no se importa en `index.js`; `useSelect`/`editorStore` siguen usándose).

Run: `npm run test:js`
Expected: todas las suites verdes.

Run: `npm run build`
Expected: compila OK.

- [ ] **Step 4: Verificar la externalización de `@wordpress/date`**

Run: `cat assets/build/editor.asset.php`
Expected: el array de `dependencies` incluye `'wp-date'` (y `'wp-core-data'`, `'wp-data'`, `'wp-editor'`). Si falta `wp-date`, revisar que el import en `tokens.js` sea `import { dateI18n, getSettings } from '@wordpress/date'` (módulo ES, no `window.wp.date`).

- [ ] **Step 5: Commit**

```bash
git add assets/src/editor/useTemplateTokens.js assets/src/editor/index.js
git commit -m "feat(editor): useTemplateTokens resuelve los tokens nuevos en el preview en vivo"
```

---

## Task 6: Verificación final

**Files:** ninguno (solo verificación).

- [ ] **Step 1: Gates PHP**

Run: `composer check`
Expected: PHPCS limpio, PHPStan nivel 6 sin errores, PHPUnit todo verde (incl. `TemplateContextTest`, `VariablesTest`, `VariableCatalogTest` anti-drift con 14 tokens, `ResolverTest`).

- [ ] **Step 2: Gates JS**

Run: `npm run lint:js`
Expected: 0 errores.

Run: `npm run test:js`
Expected: todas las suites verdes (incl. `tokens.test.js`).

Run: `npm run build`
Expected: compila OK; `editor.asset.php` incluye `wp-date`.

- [ ] **Step 3: Smoke test manual (wp-env, recomendado — requiere Docker)**

```bash
npm run env:start
```
En *OpenSEO → Titles & Meta*, abrir una pestaña de tipo de contenido y confirmar que el inserter ofrece los nuevos tokens (Published date, Author, Category, Tag, Parent title, Modified date). Poner una plantilla de título como `%title% %sep% %category%` y, en el editor de una entrada con categoría, confirmar que el preview SERP muestra la categoría real; cambiar la categoría y ver que el preview se actualiza. Verificar en el `<head>` del frontend que el título renderizado coincide.

(Si wp-env no está disponible, anotarlo como follow-up diferido — no omitir en silencio.)

- [ ] **Step 4: Regenerar el `.pot` (strings nuevos — requiere wp-env)**

```bash
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo languages/openseo.pot
git add languages/openseo.pot && git commit -m "chore(i18n): regenera .pot para variables enriquecidas"
```
(Si wp-env no está disponible, anotarlo como follow-up diferido.)

---

## Self-Review (completado durante la planificación)

- **Spec coverage:** los 6 tokens (Tasks 1–3 servidor + catálogo; Task 5 preview), guards de vacío distintos `[]`/`false` (Task 1), fechas con `date_format`/`dateI18n` (Tasks 1 y 5), `%author%` vía `getEntityRecord('root','user',id)` (Task 5, corrección H1 de la auditoría), `recordName`/`recordTitle`/`formatTokenDate` puros y testeados (Task 4, M4), importación ES de `@wordpress/date` + verificación de `editor.asset.php` (Task 5, M3), pureza de `Variables` (Task 2 solo lee de `$context`), invariante anti-drift (cubierto por el test existente, mocks añadidos en Task 1). Todos los puntos del spec tienen tarea.
- **Placeholder scan:** sin TBD/TODO; cada paso de código muestra el código completo y el comando con su salida esperada.
- **Type/símbolo consistency:** `TemplateContext->{date,modified,author,category,tag,parent_title}` (Task 1) ↔ leídos por `Variables::replace` (Task 2) ↔ tokens en `VariableCatalog` (Task 3) ↔ mismas claves en el mapa de `useTemplateTokens` (Task 5). `recordName`/`recordTitle`/`formatTokenDate` (Task 4) ↔ usados por el hook (Task 5). `deriveExcerpt` se reubica de `index.js` al hook (import eliminado en `index.js`, Task 5).
- **Verde por commit:** Task 1 añade los primitivos Y los mocks que evitan fatales en los tests que construyen `for_post`; Task 2 (reemplazos) precede a Task 3 (catálogo) para que el anti-drift pase; Tasks 4 (helpers puros) precede a Task 5 (hook que los consume); el cambio de `index.js` (Task 5) deja el import de `deriveExcerpt` limpio.
- **Auditoría de diseño incorporada:** H1 (`getEntityRecord` para autor, Task 5), M1 (guards `[]`/`false`, Task 1), M2 (divergencia de "primer término" documentada en spec), M3 (verificar `wp-date` en asset.php, Task 5 Step 4), M4 (helpers puros testeados, Task 4), H2/L1/L2 (notas en spec, sin código).
