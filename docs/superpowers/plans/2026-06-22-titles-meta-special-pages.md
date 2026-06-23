# Titles & Meta: páginas especiales (Inicio · Autores · Otras páginas) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolver título/descripción/robots/OpenGraph para la portada de últimas entradas, archivos de autor, resultados de búsqueda y 404; añadir noindex granular (paginación, contraseña) y redirección a portada de archivos de autor/fecha desactivados; todo configurable desde tres sub-pestañas React de Titles & Meta.

**Architecture:** Se extiende `Meta\Resolver` en sitio con ramas nuevas por superficie y un overlay de noindex; los datos viven en `openseo_settings` (~17 claves nuevas); tres tokens nuevos (`%name%`/`%search_query%`/`%page%`) con factories `for_author`/`for_search`/`for_archive` en `TemplateContext`; un módulo nuevo `Frontend\ArchiveRedirect` (Hookable, `template_redirect`@1); UI en `views/Titles.js` (HomepagePanel ampliado + AuthorsPanel + OtherPagesPanel) reusando `TemplateField`/`MediaField` y un componente nuevo `RobotsCheckboxes`.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, `@wordpress/scripts` (React/JS + Jest), WordPress 7.0 conditional tags.

## Global Constraints

- WP 7.0+, PHP 8.1+. `declare(strict_types=1);` en PHP nuevo. Text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS).
- Seguridad: sanitizar en entrada (clave explícita + `wp_unslash`, nunca `$_POST`/`$_GET` completos), escapar en salida. **El `<title>` del core NO se escapa** → `Title::filter_title()` aplica `esc_html()`; los tokens `%search_query%`/`%name%` viajan **crudos** en `TemplateContext`.
- Directivas robots: `noindex, nofollow, noarchive, nosnippet, noimageindex`. Los mapas `home_robots`/`author_robots` son planos `directiva => '1'` (absolutos cuando el toggle custom está on).
- Portada propia = portada de **últimas entradas** (`is_front_page() && ! is_singular()`); con página estática gana el meta de esa página. La página de entradas/blog con front estática queda **fuera de alcance** (No-objetivo).
- Overlay de noindex (búsqueda/paginación/contraseña) muta `$effective['noindex']` **antes** del ensamblado del string, para preservar el skip de `advanced_robots` cuando hay noindex/nosnippet.
- `advanced_robots` sigue siendo **global** (sin variante por superficie).
- React: guardado vía `saveSettings(state.values)` envía el objeto **completo** de valores; `Options::sanitize` lee claves explícitas. Hooks desde `@wordpress/element`; i18n vía `@wordpress/i18n`; sin `dangerouslySetInnerHTML`.
- Gates verdes por commit que toque su capa: `composer lint`, `composer analyze` (`--memory-limit=1G`, PHPStan 6), `composer test:unit`; al tocar assets `npm run lint:js`, `npm run test:js`, `npm run build`.
- Sin atribución en commits. Conventional commits.

---

## File Structure

**PHP (nuevos):**
- `src/Frontend/ArchiveRedirect.php` — Hookable que redirige a portada los archivos de autor/fecha desactivados.

**PHP (modificados):**
- `src/Settings/Options.php` — defaults (17 claves) + sanitize (texto/textarea/checkbox/url/mapas de robots).
- `src/Meta/TemplateDefaults.php` — `author_title()`, `search_title()`, `not_found_title()`.
- `src/Meta/TemplateContext.php` — campos `name`/`search_query`/`page`, factories `for_author`/`for_search`/`for_archive`, helper `current_page_label()`.
- `src/Meta/Variables.php` — tokens `%name%`/`%search_query%`/`%page%`.
- `src/Meta/VariableCatalog.php` — 3 entradas nuevas (scopes `author`/`search`/`global`).
- `src/Meta/Resolver.php` — ramas título/descr/robots/OG para autor/búsqueda/404/portada; `robots()` descompuesto en `effective_robots()` + `force_noindex()`.
- `src/Frontend/Head/Title.php` — `esc_html()` al título resuelto.
- `src/Plugin.php` — registrar `ArchiveRedirect` en módulos always-on.

**JS (nuevos):**
- `assets/src/admin/components/RobotsCheckboxes.js` — 5 checkboxes sobre un mapa `directiva => '1'`.

**JS (modificados):**
- `assets/src/admin/variables.js` — JSDoc de scopes.
- `assets/src/admin/views/Titles.js` — HomepagePanel ampliado + AuthorsPanel + OtherPagesPanel + tabs/ruteo + MetaGlobalPanel usa `RobotsCheckboxes`.

**Tests (nuevos):**
- `tests/Unit/Frontend/ArchiveRedirectTest.php`
- `tests/Unit/Frontend/Head/TitleTest.php`

**Tests (modificados):**
- `tests/Unit/OptionsTest.php`, `tests/Unit/Meta/TemplateDefaultsTest.php`, `tests/Unit/Meta/TemplateContextTest.php`, `tests/Unit/Meta/VariablesTest.php`, `tests/Unit/Meta/VariableCatalogTest.php`, `tests/Unit/Meta/ResolverTest.php`, `tests/Unit/Frontend/Head/RobotsTest.php`, `tests/Unit/Frontend/Head/DescriptionTest.php`, `tests/Unit/Frontend/Head/TwitterTest.php`.

---

## Task 1: `Options` — defaults + sanitize de las 17 claves nuevas

**Files:**
- Modify: `src/Settings/Options.php` (defaults `array` + `sanitize()`)
- Test: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Produces: defaults nuevos accesibles por `Options::get()`; `sanitize()` limpia las claves nuevas. Claves: `home_robots_custom`, `home_robots`, `home_og_title`, `home_og_description`, `home_og_image`, `author_archives`, `author_title`, `author_description`, `author_robots_custom`, `author_robots`, `date_archives`, `title_404`, `search_title`, `noindex_search`, `noindex_paginated`, `noindex_paginated_singular`, `noindex_password_protected`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/OptionsTest.php` (inside the class):

```php
	public function test_defaults_include_special_page_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( '1', $options->get( 'author_archives' ) );
		$this->assertSame( '1', $options->get( 'date_archives' ) );
		$this->assertSame( '1', $options->get( 'noindex_search' ) );
		$this->assertSame( '%name% %sep% %sitename%', $options->get( 'author_title' ) );
		$this->assertSame( 'Page Not Found %sep% %sitename%', $options->get( 'title_404' ) );
		$this->assertSame( '%search_query% %sep% %sitename%', $options->get( 'search_title' ) );
		$this->assertSame( '', $options->get( 'home_robots_custom' ) );
		$this->assertSame( array(), $options->get( 'home_robots' ) );
	}

	public function test_sanitize_handles_special_page_fields(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'home_robots_custom'         => '1',
				'home_robots'                => array( 'noindex' => '1', 'bogus' => '1', 'nofollow' => '' ),
				'home_og_title'              => 'Home OG',
				'home_og_description'        => 'Home OG desc',
				'home_og_image'              => 'https://example.com/og.png',
				'author_archives'            => '0',
				'author_title'               => '%name%',
				'author_robots_custom'       => '1',
				'author_robots'              => array( 'noindex' => '1' ),
				'date_archives'              => '0',
				'title_404'                  => '404 %sitename%',
				'search_title'               => '%search_query%',
				'noindex_search'             => '1',
				'noindex_paginated'          => '1',
				'noindex_paginated_singular' => '0',
				'noindex_password_protected' => '1',
			)
		);

		$this->assertSame( '1', $clean['home_robots_custom'] );
		$this->assertSame( array( 'noindex' => '1' ), $clean['home_robots'] );
		$this->assertSame( 'Home OG', $clean['home_og_title'] );
		$this->assertSame( 'Home OG desc', $clean['home_og_description'] );
		$this->assertSame( 'https://example.com/og.png', $clean['home_og_image'] );
		$this->assertSame( '', $clean['author_archives'] );
		$this->assertSame( '%name%', $clean['author_title'] );
		$this->assertSame( array( 'noindex' => '1' ), $clean['author_robots'] );
		$this->assertSame( '', $clean['date_archives'] );
		$this->assertSame( '1', $clean['noindex_search'] );
		$this->assertSame( '1', $clean['noindex_paginated'] );
		$this->assertSame( '', $clean['noindex_paginated_singular'] );
		$this->assertSame( '1', $clean['noindex_password_protected'] );
	}
```

> Note: `sanitize_textarea_field` is already stubbed in `OptionsTest::setUp` (returnArg), covering `home_og_description`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "test_defaults_include_special_page_keys|test_sanitize_handles_special_page_fields"`
Expected: FAIL — undefined array keys / values don't match (keys not in defaults yet).

- [ ] **Step 3: Add the defaults**

In `src/Settings/Options.php`, inside `defaults()`'s returned array, after the `'home_description' => '',` line add:

```php
			'home_robots_custom'           => '',
			'home_robots'                  => array(),
			'home_og_title'                => '',
			'home_og_description'          => '',
			'home_og_image'                => '',
			'author_archives'              => '1',
			'author_title'                 => '%name% %sep% %sitename%',
			'author_description'           => '',
			'author_robots_custom'         => '',
			'author_robots'                => array(),
			'date_archives'                => '1',
			'title_404'                    => 'Page Not Found %sep% %sitename%',
			'search_title'                 => '%search_query% %sep% %sitename%',
			'noindex_search'               => '1',
			'noindex_paginated'            => '',
			'noindex_paginated_singular'   => '',
			'noindex_password_protected'   => '',
```

- [ ] **Step 4: Add the sanitize handling**

In `src/Settings/Options.php`, in `sanitize()`:

1. Add the text keys to the existing `sanitize_text_field` loop. Change:

```php
		foreach ( array( 'title_separator', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model', 'local_website_name', 'local_website_alternate_name' ) as $key ) {
```

to:

```php
		foreach ( array( 'title_separator', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model', 'local_website_name', 'local_website_alternate_name', 'home_og_title', 'author_title', 'title_404', 'search_title' ) as $key ) {
```

2. Add the textarea keys right after that loop:

```php
		foreach ( array( 'home_og_description', 'author_description' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_textarea_field( wp_unslash( $input[ $key ] ) );
			}
		}
```

3. Add the checkbox keys to the existing checkbox loop. Change:

```php
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled', 'capitalize_titles' ) as $key ) {
```

to:

```php
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled', 'capitalize_titles', 'home_robots_custom', 'author_robots_custom', 'author_archives', 'date_archives', 'noindex_search', 'noindex_paginated', 'noindex_paginated_singular', 'noindex_password_protected' ) as $key ) {
```

4. Add `home_og_image` to the existing `esc_url_raw` loop. Change:

```php
		foreach ( array( 'og_default_image', 'schema_logo', 'local_url' ) as $key ) {
```

to:

```php
		foreach ( array( 'og_default_image', 'schema_logo', 'local_url', 'home_og_image' ) as $key ) {
```

5. Add the robots-map sanitizer. After the global `robots` block (the one ending `$clean['robots'] = $robots;`), add:

```php
		foreach ( array( 'home_robots', 'author_robots' ) as $map_key ) {
			if ( isset( $input[ $map_key ] ) && is_array( $input[ $map_key ] ) ) {
				$map = array();
				foreach ( array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) as $directive ) {
					if ( '1' === (string) ( $input[ $map_key ][ $directive ] ?? '' ) ) {
						$map[ $directive ] = '1';
					}
				}
				$clean[ $map_key ] = $map;
			}
		}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "test_defaults_include_special_page_keys|test_sanitize_handles_special_page_fields"`
Expected: PASS.

- [ ] **Step 6: Run the full PHP gate**

Run: `composer lint && composer analyze && composer test:unit`
Expected: all green (no regression in existing OptionsTest).

- [ ] **Step 7: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(meta): special-page settings defaults + sanitize"
```

---

## Task 2: `TemplateDefaults` — fallbacks de autor/búsqueda/404

**Files:**
- Modify: `src/Meta/TemplateDefaults.php`
- Test: `tests/Unit/Meta/TemplateDefaultsTest.php`

**Interfaces:**
- Produces: `TemplateDefaults::author_title(): string`, `search_title(): string`, `not_found_title(): string` (literales puros).

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Meta/TemplateDefaultsTest.php` (inside the class):

```php
	public function test_special_page_defaults(): void {
		$d = new TemplateDefaults();
		$this->assertSame( '%name% %sep% %sitename%', $d->author_title() );
		$this->assertSame( '%search_query% %sep% %sitename%', $d->search_title() );
		$this->assertSame( 'Page Not Found %sep% %sitename%', $d->not_found_title() );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_special_page_defaults`
Expected: FAIL — `Call to undefined method ...::author_title()`.

- [ ] **Step 3: Add the methods**

In `src/Meta/TemplateDefaults.php`, before the closing brace of the class:

```php
	/**
	 * Default title template for author archives.
	 */
	public function author_title(): string {
		return '%name% %sep% %sitename%';
	}

	/**
	 * Default title template for search results.
	 */
	public function search_title(): string {
		return '%search_query% %sep% %sitename%';
	}

	/**
	 * Default title template for 404 pages.
	 */
	public function not_found_title(): string {
		return 'Page Not Found %sep% %sitename%';
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter test_special_page_defaults`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Meta/TemplateDefaults.php tests/Unit/Meta/TemplateDefaultsTest.php
git commit -m "feat(meta): author/search/404 default title templates"
```

---

## Task 3: `TemplateContext` — campos + factories + `current_page_label()`

**Files:**
- Modify: `src/Meta/TemplateContext.php`
- Test: `tests/Unit/Meta/TemplateContextTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `TemplateContext` gains `public readonly string $name`, `$search_query`, `$page` (default `''`); static `for_author( int $author_id ): self`, `for_search(): self`, `for_archive(): self`. Variables/Resolver read `$ctx->name`, `$ctx->search_query`, `$ctx->page`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Meta/TemplateContextTest.php` (inside the class). Also add `Functions\when( '__' )->returnArg();`, `Functions\when( 'get_query_var' )->justReturn( 0 );`, `Functions\when( 'get_search_query' )->justReturn( '' );` to the existing `setUp()`.

```php
	public function test_for_author_reads_display_name(): void {
		Functions\when( 'get_the_author_meta' )->alias(
			static fn( $field, $id ) => 'display_name' === $field && 7 === $id ? 'Jane Doe' : ''
		);

		$ctx = TemplateContext::for_author( 7 );

		$this->assertSame( 'Jane Doe', $ctx->name );
		$this->assertSame( '', $ctx->search_query );
		$this->assertSame( '', $ctx->page );
	}

	public function test_for_search_reads_raw_query(): void {
		Functions\when( 'get_search_query' )->justReturn( 'tom & jerry' );

		$ctx = TemplateContext::for_search();

		$this->assertSame( 'tom & jerry', $ctx->search_query );
		$this->assertSame( '', $ctx->name );
	}

	public function test_page_label_is_empty_when_not_paginated(): void {
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$this->assertSame( '', TemplateContext::for_archive()->page );
	}

	public function test_page_label_uses_paged_and_total(): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( $key ) => 'paged' === $key ? 2 : 0
		);
		$wp_query                = new \stdClass();
		$wp_query->max_num_pages = 4;
		$GLOBALS['wp_query']     = $wp_query;

		$this->assertSame( 'Page 2 of 4', TemplateContext::for_archive()->page );

		unset( $GLOBALS['wp_query'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "test_for_author_reads_display_name|test_for_search_reads_raw_query|test_page_label"`
Expected: FAIL — `Call to undefined method ...::for_author()`.

- [ ] **Step 3: Add fields, factories, and the page helper**

In `src/Meta/TemplateContext.php`:

1. Add three params at the **end** of the constructor signature (after `string $parent_title = '',`):

```php
		public readonly string $name = '',
		public readonly string $search_query = '',
		public readonly string $page = '',
```

2. Add the factories and helper before the closing brace of the class:

```php
	/**
	 * Context for an author archive.
	 *
	 * @param int $author_id Queried author ID.
	 */
	public static function for_author( int $author_id ): self {
		return new self(
			0,
			'',
			'',
			'',
			'',
			name: (string) get_the_author_meta( 'display_name', $author_id ),
			page: self::current_page_label(),
		);
	}

	/**
	 * Context for a search results page. The query stays RAW; the Title
	 * presenter escapes it (the document <title> is not escaped by core).
	 */
	public static function for_search(): self {
		return new self(
			0,
			'',
			'',
			'',
			'',
			search_query: (string) get_search_query( false ),
			page: self::current_page_label(),
		);
	}

	/**
	 * Context for a paginated archive / posts homepage (only %page% applies).
	 */
	public static function for_archive(): self {
		return new self( 0, '', '', '', '', page: self::current_page_label() );
	}

	/**
	 * "Page X of Y" for a paginated request, or '' when on page 1 / unpaginated.
	 * Reads the archive page ('paged') first, then the in-post page ('page').
	 */
	private static function current_page_label(): string {
		$paged = (int) get_query_var( 'paged' );
		if ( 0 === $paged ) {
			$paged = (int) get_query_var( 'page' );
		}
		if ( $paged < 2 ) {
			return '';
		}

		$total = 0;
		if ( isset( $GLOBALS['wp_query'] ) && is_object( $GLOBALS['wp_query'] ) && isset( $GLOBALS['wp_query']->max_num_pages ) ) {
			$total = (int) $GLOBALS['wp_query']->max_num_pages;
		}

		if ( $total > 1 ) {
			/* translators: 1: current page number, 2: total pages. */
			return sprintf( __( 'Page %1$d of %2$d', 'openseo' ), $paged, $total );
		}

		/* translators: %d: current page number. */
		return sprintf( __( 'Page %d', 'openseo' ), $paged );
	}
```

3. Update the class docblock's primitive list. In the class comment, replace:

```
 * Carries the primitives a template needs (title, excerpt, term name/description,
 * date, modified date, author, primary category/tag, and parent title)
```

with:

```
 * Carries the primitives a template needs (title, excerpt, term name/description,
 * date, modified date, author, primary category/tag, parent title, author name,
 * search query, and page label)
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "TemplateContextTest"`
Expected: PASS (all, including existing).

- [ ] **Step 5: Run the PHP gate**

Run: `composer lint && composer analyze && vendor/bin/phpunit --filter "TemplateContextTest"`
Expected: green (PHPStan 6 clean — note the named-args usage is level-6 safe).

- [ ] **Step 6: Commit**

```bash
git add src/Meta/TemplateContext.php tests/Unit/Meta/TemplateContextTest.php
git commit -m "feat(meta): author/search/archive template contexts + %page% label"
```

---

## Task 4: `Variables` + `VariableCatalog` — tokens `%name%`/`%search_query%`/`%page%`

**Files:**
- Modify: `src/Meta/Variables.php`, `src/Meta/VariableCatalog.php`
- Test: `tests/Unit/Meta/VariablesTest.php`, `tests/Unit/Meta/VariableCatalogTest.php`

**Interfaces:**
- Consumes: `TemplateContext::for_author/for_search` (Task 3).
- Produces: `Variables::replace()` expands `%name%`/`%search_query%`/`%page%`; `VariableCatalog::all()` lists them with scopes `author`/`search`/`global`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Meta/VariablesTest.php` (inside the class):

```php
	public function test_replaces_author_name_token(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_author( 7 );

		$this->assertSame( 'Jane Doe - My Site', $variables->replace( '%name% %sep% %sitename%', $ctx ) );
	}

	public function test_replaces_search_query_token_raw(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_search_query' )->justReturn( 'tom & jerry' );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_search();

		$this->assertSame( 'tom & jerry', $variables->replace( '%search_query%', $ctx ) );
	}

	public function test_replaces_page_token(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_query_var' )->alias( static fn( $k ) => 'paged' === $k ? 3 : 0 );
		$wp_query                = new \stdClass();
		$wp_query->max_num_pages = 5;
		$GLOBALS['wp_query']     = $wp_query;

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_archive();

		$this->assertSame( 'Page 3 of 5', $variables->replace( '%page%', $ctx ) );

		unset( $GLOBALS['wp_query'] );
	}
```

Add `Functions\when( '__' )->returnArg();` and `Functions\when( 'get_search_query' )->justReturn( '' );` to `VariablesTest::setUp`.

Append to `tests/Unit/Meta/VariableCatalogTest.php`:

```php
	public function test_catalog_includes_special_page_tokens(): void {
		$tokens = array_column( ( new VariableCatalog() )->all(), 'token' );

		foreach ( array( '%name%', '%search_query%', '%page%' ) as $expected ) {
			$this->assertContains( $expected, $tokens );
		}
	}
```

And widen the scope whitelist in `test_catalog_has_entries_with_required_keys_and_valid_scopes`. Change:

```php
			$this->assertContains( $entry['scope'], array( 'global', 'singular', 'taxonomy' ) );
```

to:

```php
			$this->assertContains( $entry['scope'], array( 'global', 'singular', 'taxonomy', 'author', 'search' ) );
```

Harden the anti-drift helper so the new scopes expand **non-empty** values (otherwise an un-mapped `%name%`/`%search_query%` would still pass trivially). Add to `VariableCatalogTest::setUp()`:

```php
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );
		Functions\when( 'get_search_query' )->justReturn( 'a keyword' );
```

And extend `context_for_scope()` — add these branches before the final `return TemplateContext::none();`:

```php
		if ( 'author' === $scope ) {
			return TemplateContext::for_author( 7 );
		}
		if ( 'search' === $scope ) {
			return TemplateContext::for_search();
		}
```

> `__` is already stubbed in `VariableCatalogTest::setUp` (returnArg), so `current_page_label()` is safe even though `%page%` (scope `global`) resolves with `none()` and stays empty.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "VariablesTest|VariableCatalogTest"`
Expected: FAIL — `%name%`/`%search_query%`/`%page%` left literal; catalog missing tokens.

- [ ] **Step 3: Add the tokens to `Variables::replace()`**

In `src/Meta/Variables.php`, in the `$replacements` array, after `'%parent_title%'     => $context->parent_title,` add:

```php
			'%name%'             => $context->name,
			'%search_query%'     => $context->search_query,
			'%page%'             => $context->page,
```

- [ ] **Step 4: Add the catalog entries**

In `src/Meta/VariableCatalog.php`, before the closing `);` of the returned array in `all()`, add:

```php
				array(
					'token'       => '%name%',
					'label'       => __( 'Author name', 'openseo' ),
					'description' => __( "The author archive's display name", 'openseo' ),
					'scope'       => 'author',
				),
				array(
					'token'       => '%search_query%',
					'label'       => __( 'Search query', 'openseo' ),
					'description' => __( 'The current search term', 'openseo' ),
					'scope'       => 'search',
				),
				array(
					'token'       => '%page%',
					'label'       => __( 'Page number', 'openseo' ),
					'description' => __( 'Current page of paginated results', 'openseo' ),
					'scope'       => 'global',
				),
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "VariablesTest|VariableCatalogTest"`
Expected: PASS (including the anti-drift `test_every_catalog_token_is_replaced_by_variables`).

- [ ] **Step 6: Commit**

```bash
git add src/Meta/Variables.php src/Meta/VariableCatalog.php tests/Unit/Meta/VariablesTest.php tests/Unit/Meta/VariableCatalogTest.php
git commit -m "feat(meta): %name% / %search_query% / %page% template tokens"
```

---

## Task 5: `Resolver` — título/descripción para autor/búsqueda/404 + contexto de página en portada

**Files:**
- Modify: `src/Meta/Resolver.php` (`resolve_title()`, `description()`)
- Test: `tests/Unit/Meta/ResolverTest.php`, plus setUp hardening in `tests/Unit/Frontend/Head/DescriptionTest.php` and `tests/Unit/Frontend/Head/TwitterTest.php`

**Interfaces:**
- Consumes: `TemplateContext::for_author/for_search/for_archive`, `TemplateDefaults::author_title/search_title/not_found_title`, options `author_title`/`search_title`/`title_404`/`author_description`.
- Produces: `Resolver::title()` resolves author/search/404; `description()` resolves author. Robots/OG branches are Tasks 6–7.

- [ ] **Step 1: Harden the conditional-tag stubs**

In `tests/Unit/Meta/ResolverTest.php` `setUp()`, after the existing `Functions\when( 'is_front_page' )->justReturn( false );` line add:

```php
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'post_password_required' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'get_search_query' )->justReturn( '' );
		Functions\when( '__' )->returnArg();
```

In `tests/Unit/Frontend/Head/DescriptionTest.php` and `tests/Unit/Frontend/Head/TwitterTest.php` `setUp()`, add the same eight `Functions\when(...)` lines plus `Functions\when( 'is_front_page' )->justReturn( false );` if not already present. (These tests build a real `Resolver`; the new branches call these tags.)

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/Meta/ResolverTest.php`:

```php
	public function test_title_resolves_author_archive(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );

		// Default author_title '%name% %sep% %sitename%'.
		$this->assertSame( 'Jane Doe - My Site', $this->resolver()->title() );
	}

	public function test_title_resolves_search_results(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_search_query' )->justReturn( 'hello' );

		// Default search_title '%search_query% %sep% %sitename%'.
		$this->assertSame( 'hello - My Site', $this->resolver()->title() );
	}

	public function test_title_resolves_404(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );

		// Default title_404 'Page Not Found %sep% %sitename%'.
		$this->assertSame( 'Page Not Found - My Site', $this->resolver()->title() );
	}

	public function test_title_uses_default_when_author_title_empty(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane' );
		Functions\when( 'get_option' )->justReturn( array( 'author_title' => '' ) );

		// Cleared option falls back to TemplateDefaults::author_title().
		$this->assertSame( 'Jane - My Site', $this->resolver()->title() );
	}

	public function test_description_resolves_author(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'author_description' => 'About Jane.' ) );

		$this->assertSame( 'About Jane.', $this->resolver()->description() );
	}

	public function test_title_homepage_appends_page_label_when_paginated(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		// A home_title that includes %page% so the archive context is observable.
		Functions\when( 'get_option' )->justReturn( array( 'home_title' => '%sitename% %page%' ) );
		Functions\when( 'get_query_var' )->alias( static fn( $k ) => 'paged' === $k ? 2 : 0 );
		$wp_query                = new \stdClass();
		$wp_query->max_num_pages = 3;
		$GLOBALS['wp_query']     = $wp_query;

		$this->assertSame( 'My Site Page 2 of 3', $this->resolver()->title() );

		unset( $GLOBALS['wp_query'] );
	}

	public function test_title_homepage_without_pagination_has_no_page_label(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'home_title' => '%sitename% %page%' ) );
		// get_query_var defaults to 0 (setUp) → page 1 → no %page%.

		$this->assertSame( 'My Site', $this->resolver()->title() );
	}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "test_title_resolves_author_archive|test_title_resolves_search_results|test_title_resolves_404|test_title_uses_default_when_author_title_empty|test_description_resolves_author|test_title_homepage_appends_page_label_when_paginated|test_title_homepage_without_pagination_has_no_page_label"`
Expected: FAIL (titles come back `''` / without page label).

- [ ] **Step 4: Add the title branches**

In `src/Meta/Resolver.php`, in `resolve_title()`, replace the `is_front_page()` block and the final `return '';` with:

```php
		if ( is_front_page() ) {
			return $this->variables->replace( (string) $this->options->get( 'home_title' ), TemplateContext::for_archive() );
		}

		if ( is_author() ) {
			$template = (string) $this->options->get( 'author_title' );
			if ( '' === $template ) {
				$template = $this->defaults->author_title();
			}

			return $this->variables->replace( $template, TemplateContext::for_author( get_queried_object_id() ) );
		}

		if ( is_search() ) {
			$template = (string) $this->options->get( 'search_title' );
			if ( '' === $template ) {
				$template = $this->defaults->search_title();
			}

			return $this->variables->replace( $template, TemplateContext::for_search() );
		}

		if ( is_404() ) {
			$template = (string) $this->options->get( 'title_404' );
			if ( '' === $template ) {
				$template = $this->defaults->not_found_title();
			}

			return $this->variables->replace( $template );
		}

		return '';
```

- [ ] **Step 5: Add the description branch**

In `src/Meta/Resolver.php`, in `description()`, replace the `is_front_page()` block (the one returning `$home`/`get_bloginfo`) so the author branch is added right after it. Insert before the final `return '';`:

```php
		if ( is_author() ) {
			return (string) $this->options->get( 'author_description' );
		}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "ResolverTest"`
Expected: PASS (all, including existing title/description/front-page tests — the new conditional stubs keep them green).

- [ ] **Step 7: Run the PHP gate + the head tests**

Run: `composer lint && composer analyze && vendor/bin/phpunit --filter "ResolverTest|DescriptionTest|TwitterTest"`
Expected: green.

- [ ] **Step 8: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php tests/Unit/Frontend/Head/DescriptionTest.php tests/Unit/Frontend/Head/TwitterTest.php
git commit -m "feat(meta): resolve title/description for author, search, 404"
```

---

## Task 6: `Resolver` — robots descompuesto + ramas por superficie + overlay de noindex

**Files:**
- Modify: `src/Meta/Resolver.php` (`robots()` + new `effective_robots()`, `force_noindex()`, `custom_surface_map()`, const `DIRECTIVES`)
- Test: `tests/Unit/Meta/ResolverTest.php`, plus setUp hardening in `tests/Unit/Frontend/Head/RobotsTest.php`

**Interfaces:**
- Consumes: `RobotsResolver::resolve` (existing), options `home_robots_custom`/`home_robots`/`author_robots_custom`/`author_robots`/`noindex_search`/`noindex_paginated`/`noindex_paginated_singular`/`noindex_password_protected`.
- Produces: `Resolver::robots()` unchanged signature; adds homepage/author custom maps and the noindex overlay.

- [ ] **Step 1: Harden RobotsTest setUp**

In `tests/Unit/Frontend/Head/RobotsTest.php` `setUp()`, add:

```php
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'post_password_required' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 0 );
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Unit/Meta/ResolverTest.php`:

```php
	public function test_robots_homepage_custom_map_is_absolute(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn(
			array(
				'home_robots_custom' => '1',
				'home_robots'        => array( 'noindex' => '1', 'noarchive' => '1' ),
			)
		);

		$this->assertSame( 'noindex, follow, noarchive', $this->resolver()->robots() );
	}

	public function test_robots_author_custom_map(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn(
			array(
				'author_robots_custom' => '1',
				'author_robots'        => array( 'nofollow' => '1' ),
			)
		);

		$this->assertSame( 'index, nofollow', $this->resolver()->robots() );
	}

	public function test_robots_search_is_noindexed_when_enabled(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'noindex_search' => '1' ) );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_robots_overlay_noindex_on_paginated(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'noindex_paginated' => '1' ) );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_robots_overlay_noindex_on_password_protected(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'post_password_required' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'noindex_password_protected' => '1' ) );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_robots_overlay_noindex_on_paginated_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_query_var' )->alias( static fn( $k ) => 'page' === $k ? 2 : 0 );
		Functions\when( 'get_option' )->justReturn( array( 'noindex_paginated_singular' => '1' ) );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_robots_overlay_suppresses_advanced_when_forced_noindex(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn(
			array(
				'noindex_paginated' => '1',
				'advanced_robots'   => array( 'max_snippet' => array( 'enabled' => '1', 'length' => '50' ) ),
			)
		);

		// Overlay noindex must suppress advanced robots.
		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "test_robots_homepage_custom_map_is_absolute|test_robots_author_custom_map|test_robots_search_is_noindexed_when_enabled|test_robots_overlay_noindex_on_paginated|test_robots_overlay_noindex_on_password_protected|test_robots_overlay_noindex_on_paginated_singular|test_robots_overlay_suppresses_advanced_when_forced_noindex"`
Expected: FAIL (current robots() ignores these).

- [ ] **Step 4: Decompose `robots()`**

In `src/Meta/Resolver.php`, add a class constant near the top of the class body:

```php
	private const DIRECTIVES = array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' );
```

Replace the entire `robots()` method body with:

```php
	public function robots(): string {
		$effective            = $this->effective_robots();
		$effective['noindex'] = $effective['noindex'] || $this->force_noindex();

		$parts = $this->robots_parts( $effective );

		if ( ! $effective['noindex'] && ! $effective['nosnippet'] ) {
			$parts = array_merge( $parts, $this->advanced_robots_parts() );
		}

		return implode( ', ', $parts );
	}

	/**
	 * The five effective robots booleans for the current surface, before the
	 * cross-surface noindex overlay. Custom home/author maps are absolute;
	 * every other surface uses the entry → type → global cascade.
	 *
	 * @return array<string, bool>
	 */
	private function effective_robots(): array {
		$global_map = $this->options->get( 'robots' );
		$global_map = is_array( $global_map ) ? $global_map : array();
		$global     = static fn( string $d ): bool => '1' === (string) ( $global_map[ $d ] ?? '' );

		$custom = $this->custom_surface_map();
		if ( null !== $custom ) {
			$effective = array();
			foreach ( self::DIRECTIVES as $d ) {
				$effective[ $d ] = '1' === (string) ( $custom[ $d ] ?? '' );
			}

			return $effective;
		}

		$type_robots         = array();
		$entry               = array();
		$force_noindex_empty = false;

		if ( is_singular() ) {
			$id          = get_queried_object_id();
			$type        = (string) get_post_type( $id );
			$map         = $this->options->get( 'post_types' );
			$type_robots = is_array( $map ) && is_array( $map[ $type ]['robots'] ?? null ) ? $map[ $type ]['robots'] : array();
			$entry       = array(
				'noindex'  => (string) get_post_meta( $id, '_openseo_robots_noindex', true ),
				'nofollow' => (string) get_post_meta( $id, '_openseo_robots_nofollow', true ),
			);
		} elseif ( $this->is_taxonomy() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$map         = $this->options->get( 'taxonomies' );
				$type_robots = is_array( $map ) && is_array( $map[ $term->taxonomy ]['robots'] ?? null ) ? $map[ $term->taxonomy ]['robots'] : array();
				if ( $global( 'noindex_empty_terms' ) && 0 === (int) $term->count ) {
					$force_noindex_empty = true;
				}
			}
		}

		$effective = array();
		foreach ( self::DIRECTIVES as $d ) {
			$entry_val       = ( 'noindex' === $d || 'nofollow' === $d ) ? (string) ( $entry[ $d ] ?? '' ) : '';
			$type_val        = (string) ( $type_robots[ $d ] ?? '' );
			$effective[ $d ] = RobotsResolver::resolve( $entry_val, $type_val, $global( $d ) );
		}

		if ( $force_noindex_empty ) {
			$effective['noindex'] = true;
		}

		return $effective;
	}

	/**
	 * Absolute robots map for surfaces that define their own (posts homepage,
	 * author archives) when their "custom robots" toggle is on; null otherwise.
	 *
	 * @return array<string, string>|null
	 */
	private function custom_surface_map(): ?array {
		if ( is_front_page() && ! is_singular() && '1' === (string) $this->options->get( 'home_robots_custom' ) ) {
			$map = $this->options->get( 'home_robots' );

			return is_array( $map ) ? $map : array();
		}

		if ( is_author() && '1' === (string) $this->options->get( 'author_robots_custom' ) ) {
			$map = $this->options->get( 'author_robots' );

			return is_array( $map ) ? $map : array();
		}

		return null;
	}

	/**
	 * Cross-surface reasons to force noindex regardless of the resolved
	 * cascade: search results, paginated listings/singulars, and
	 * password-protected posts.
	 */
	private function force_noindex(): bool {
		if ( is_search() && '1' === (string) $this->options->get( 'noindex_search' ) ) {
			return true;
		}

		if ( '1' === (string) $this->options->get( 'noindex_paginated' ) && is_paged() ) {
			return true;
		}

		if ( '1' === (string) $this->options->get( 'noindex_paginated_singular' ) && is_singular() && (int) get_query_var( 'page' ) > 1 ) {
			return true;
		}

		if ( '1' === (string) $this->options->get( 'noindex_password_protected' ) && is_singular() && post_password_required() ) {
			return true;
		}

		return false;
	}
```

> The old `robots()` inline `$global` closure and 5-directive loop now live in `effective_robots()`. `robots_parts()` and `advanced_robots_parts()` are unchanged.
>
> **Note (M-3):** `effective_robots()` is near the ~50-line function-length guideline. If `composer lint` (PHPCS) flags it, extract the `is_singular`/`is_taxonomy` block into a private helper `cascade_inputs( callable $global ): array` returning `[ $type_robots, $entry, $force_noindex_empty ]`, and keep the directive loop in `effective_robots()`. Verify with `composer lint` in Step 6.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "ResolverTest"`
Expected: PASS (all — including the existing robots cascade/advanced tests, which now run through `effective_robots()`).

- [ ] **Step 6: Run the PHP gate + RobotsTest**

Run: `composer lint && composer analyze && vendor/bin/phpunit --filter "ResolverTest|RobotsTest"`
Expected: green.

- [ ] **Step 7: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php tests/Unit/Frontend/Head/RobotsTest.php
git commit -m "feat(meta): robots for homepage/author + noindex overlay (search/paginated/password)"
```

---

## Task 7: `Resolver` — OpenGraph propio de la portada de entradas

**Files:**
- Modify: `src/Meta/Resolver.php` (`social_title()`, `social_description()`, `social_image()`)
- Test: `tests/Unit/Meta/ResolverTest.php`

**Interfaces:**
- Consumes: options `home_og_title`/`home_og_description`/`home_og_image`.
- Produces: on the posts homepage, OG falls back to `home_og_*` then to the resolved value / `og_default_image`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Meta/ResolverTest.php`:

```php
	public function test_social_title_uses_home_og_on_posts_homepage(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'home_og_title' => 'Home OG Title' ) );

		$this->assertSame( 'Home OG Title', $this->resolver()->social_title() );
	}

	public function test_social_description_uses_home_og_on_posts_homepage(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'home_og_description' => 'Home OG Desc' ) );

		$this->assertSame( 'Home OG Desc', $this->resolver()->social_description() );
	}

	public function test_social_image_uses_home_og_on_posts_homepage(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'home_og_image' => 'https://example.com/home-og.jpg' ) );

		$this->assertSame( 'https://example.com/home-og.jpg', $this->resolver()->social_image() );
	}

	public function test_social_title_falls_back_to_resolved_on_homepage_without_home_og(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		// No home_og_title; home_title default '%sitename% %sep% %tagline%'.
		Functions\when( 'get_bloginfo' )->alias( static fn( $k ) => 'name' === $k ? 'My Site' : 'Tagline' );

		$this->assertSame( 'My Site - Tagline', $this->resolver()->social_title() );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "test_social_title_uses_home_og_on_posts_homepage|test_social_description_uses_home_og_on_posts_homepage|test_social_image_uses_home_og_on_posts_homepage|test_social_title_falls_back_to_resolved_on_homepage_without_home_og"`
Expected: FAIL.

- [ ] **Step 3: Add a posts-homepage helper and wire the three methods**

In `src/Meta/Resolver.php`, add a private helper before `social_title()`:

```php
	/**
	 * Whether the current request is the posts homepage (latest-posts front
	 * page, not a static front page — which resolves as a singular instead).
	 */
	private function is_posts_homepage(): bool {
		return is_front_page() && ! is_singular();
	}
```

Update `social_title()`:

```php
	public function social_title(): string {
		if ( $this->is_posts_homepage() ) {
			$home = (string) $this->options->get( 'home_og_title' );

			return '' !== $home ? $home : $this->title();
		}

		return $this->social_value( '_openseo_og_title', $this->title() );
	}
```

Update `social_description()`:

```php
	public function social_description(): string {
		if ( $this->is_posts_homepage() ) {
			$home = (string) $this->options->get( 'home_og_description' );

			return '' !== $home ? $home : $this->description();
		}

		return $this->social_value( '_openseo_og_description', $this->description() );
	}
```

Update `social_image()` — add the homepage branch at the top:

```php
	public function social_image(): string {
		if ( $this->is_posts_homepage() ) {
			$home = (string) $this->options->get( 'home_og_image' );

			return '' !== $home ? $home : (string) $this->options->get( 'og_default_image' );
		}

		$override = $this->meta_value( '_openseo_og_image' );
		if ( '' !== $override ) {
			return $override;
		}

		if ( is_singular() ) {
			$featured = (string) get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
			if ( '' !== $featured ) {
				return $featured;
			}
		}

		return (string) $this->options->get( 'og_default_image' );
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "ResolverTest"`
Expected: PASS (existing social tests still green — they set `is_front_page` false, so they hit the singular branch).

- [ ] **Step 5: Run the PHP gate + head tests**

Run: `composer lint && composer analyze && vendor/bin/phpunit --filter "ResolverTest|TwitterTest"`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php
git commit -m "feat(meta): homepage OpenGraph title/description/image"
```

---

## Task 8: `Title` presenter — escape `esc_html()` del título

**Files:**
- Modify: `src/Frontend/Head/Title.php`
- Test: `tests/Unit/Frontend/Head/TitleTest.php` (new)

**Interfaces:**
- Produces: `Title::filter_title()` returns the resolved title escaped with `esc_html()` (the only unescaped sink in the head pipeline).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Frontend/Head/TitleTest.php`:

```php
<?php
/**
 * Unit tests for the document title presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend\Head;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\Head\Title;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->alias(
			static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'get_search_query' )->justReturn( '' );
		Functions\when( '__' )->returnArg();
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

	public function test_escapes_resolved_search_title(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_search_query' )->justReturn( '<script>alert(1)</script>' );

		$result = ( new Title( $this->resolver() ) )->filter_title( 'WP fallback' );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	public function test_returns_wp_title_when_resolver_empty(): void {
		// All conditionals false → resolver returns '' → keep WP's title.
		$this->assertSame( 'WP fallback', ( new Title( $this->resolver() ) )->filter_title( 'WP fallback' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "TitleTest"`
Expected: FAIL — `test_escapes_resolved_search_title` finds `<script>` (no escape yet).

- [ ] **Step 3: Apply `esc_html()` in the presenter**

In `src/Frontend/Head/Title.php`, change `filter_title()`:

```php
	public function filter_title( string $title ): string {
		$resolved = $this->resolver->title();

		return '' !== $resolved ? esc_html( $resolved ) : $title;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter "TitleTest"`
Expected: PASS.

- [ ] **Step 5: Run the PHP gate**

Run: `composer lint && composer analyze && composer test:unit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Frontend/Head/Title.php tests/Unit/Frontend/Head/TitleTest.php
git commit -m "fix(head): escape resolved document title (esc_html)"
```

---

## Task 9: `ArchiveRedirect` Hookable + wiring en `Plugin`

**Files:**
- Create: `src/Frontend/ArchiveRedirect.php`
- Modify: `src/Plugin.php`
- Test: `tests/Unit/Frontend/ArchiveRedirectTest.php` (new)

**Interfaces:**
- Consumes: `Options` (`author_archives`, `date_archives`).
- Produces: `ArchiveRedirect implements Hookable`; `should_redirect(): bool` (pure decision, unit-tested); `maybe_redirect(): void` (thin side-effect: `wp_safe_redirect(home_url('/'), 301); exit;`). Registered on `template_redirect`@1.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Frontend/ArchiveRedirectTest.php`:

```php
<?php
/**
 * Unit tests for the archive-disable redirect decision.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\ArchiveRedirect;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class ArchiveRedirectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_date' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function redirect( array $stored ): ArchiveRedirect {
		Functions\when( 'get_option' )->justReturn( $stored );
		return new ArchiveRedirect( new Options() );
	}

	public function test_redirects_author_when_archives_disabled(): void {
		Functions\when( 'is_author' )->justReturn( true );

		$this->assertTrue( $this->redirect( array( 'author_archives' => '' ) )->should_redirect() );
	}

	public function test_no_redirect_author_when_archives_enabled(): void {
		Functions\when( 'is_author' )->justReturn( true );

		$this->assertFalse( $this->redirect( array( 'author_archives' => '1' ) )->should_redirect() );
	}

	public function test_redirects_date_when_archives_disabled(): void {
		Functions\when( 'is_date' )->justReturn( true );

		$this->assertTrue( $this->redirect( array( 'date_archives' => '' ) )->should_redirect() );
	}

	public function test_no_redirect_on_a_normal_request(): void {
		$this->assertFalse( $this->redirect( array() )->should_redirect() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "ArchiveRedirectTest"`
Expected: FAIL — `Class "OpenSEO\Frontend\ArchiveRedirect" not found`.

- [ ] **Step 3: Create the module**

Create `src/Frontend/ArchiveRedirect.php`:

```php
<?php
/**
 * Redirects disabled author/date archives to the homepage.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * When author or date archives are turned off in Titles & Meta, redirect those
 * requests to the homepage (301). Runs early on template_redirect so it wins
 * before core's redirect_canonical.
 */
final class ArchiveRedirect implements Hookable {

	/**
	 * Initializes the module with settings.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Hook the redirect early in template_redirect.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirect to the homepage when the current archive is disabled.
	 */
	public function maybe_redirect(): void {
		if ( $this->should_redirect() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Whether the current request is a disabled author/date archive. Pure
	 * decision (no side effects) so it is unit-testable without exit().
	 */
	public function should_redirect(): bool {
		if ( is_author() && '1' !== (string) $this->options->get( 'author_archives' ) ) {
			return true;
		}

		if ( is_date() && '1' !== (string) $this->options->get( 'date_archives' ) ) {
			return true;
		}

		return false;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter "ArchiveRedirectTest"`
Expected: PASS.

- [ ] **Step 5: Wire it into `Plugin::modules()`**

In `src/Plugin.php`:

1. Add the import after `use OpenSEO\Frontend\Head\Twitter;`:

```php
use OpenSEO\Frontend\ArchiveRedirect;
```

2. In `modules()`, in the always-on `$modules = array( ... )` list, add after `new PostMeta(),`:

```php
			new ArchiveRedirect( $options ),
```

- [ ] **Step 6: Run the PHP gate**

Run: `composer lint && composer analyze && composer test:unit`
Expected: all green. (If an integration `PluginBootTest` asserts the module list/count, update it to include `ArchiveRedirect`.)

- [ ] **Step 7: Commit**

```bash
git add src/Frontend/ArchiveRedirect.php src/Plugin.php tests/Unit/Frontend/ArchiveRedirectTest.php
git commit -m "feat(frontend): redirect disabled author/date archives to homepage"
```

---

## Task 10: JS — `RobotsCheckboxes` + JSDoc de scopes + DRY en `MetaGlobalPanel`

**Files:**
- Create: `assets/src/admin/components/RobotsCheckboxes.js`
- Modify: `assets/src/admin/variables.js`, `assets/src/admin/views/Titles.js` (MetaGlobalPanel only)

**Interfaces:**
- Produces: `RobotsCheckboxes({ map, onChange })` — renders the 5 directive checkboxes over a `directive => '1'` map; `onChange` receives the next full map.

- [ ] **Step 1: Create the component**

Create `assets/src/admin/components/RobotsCheckboxes.js`:

```js
import { CheckboxControl } from '@wordpress/components';
import { ROBOTS_DIRECTIVES } from '../robots';
import { ROBOTS_LABELS } from './RobotsFields';

/**
 * Five robots directive checkboxes over a flat `directive => '1'` map.
 * Absolute (no tri-state): used by the global, homepage and author panels.
 *
 * @param {Object}   props
 * @param {Object}   props.map      Current `directive => '1'` map.
 * @param {Function} props.onChange Receives the next full map.
 * @return {JSX.Element} The checkbox group.
 */
export function RobotsCheckboxes( { map, onChange } ) {
	const value = map ?? {};

	return (
		<>
			{ ROBOTS_DIRECTIVES.map( ( directive ) => (
				<CheckboxControl
					key={ directive }
					__nextHasNoMarginBottom
					label={ ROBOTS_LABELS[ directive ] }
					checked={ value[ directive ] === '1' }
					onChange={ ( on ) =>
						onChange( { ...value, [ directive ]: on ? '1' : '' } )
					}
				/>
			) ) }
		</>
	);
}
```

- [ ] **Step 2: Update the JSDoc scope list in `variables.js`**

In `assets/src/admin/variables.js`, change the `@param {string} scope` line of `variablesForScope` to:

```js
 * @param {string} scope   'global' | 'singular' | 'taxonomy' | 'author' | 'search'.
```

- [ ] **Step 3: DRY `MetaGlobalPanel` to use the component**

In `assets/src/admin/views/Titles.js`:

1. Add the import after the `RobotsFields` import:

```js
import { RobotsCheckboxes } from '../components/RobotsCheckboxes';
```

2. In `MetaGlobalPanel`, replace the `ROBOTS_DIRECTIVES.map(...)` block (the `<h3>Default robots</h3>` checkbox loop) with:

```jsx
			<h3>{ __( 'Default robots', 'openseo' ) }</h3>
			<RobotsCheckboxes
				map={ robots }
				onChange={ ( next ) => change( 'robots', next ) }
			/>
```

Keep the `noindex_empty_terms` `ToggleControl` immediately after, unchanged.

3. **Remove the now-unused imports** (this is required — `npm run lint:js` fails on `no-unused-vars`, it does not merely warn). `MetaGlobalPanel` was the only consumer of `CheckboxControl` and `ROBOTS_DIRECTIVES` in this file (`TypePanel` uses `RobotsFields`):
   - In the `@wordpress/components` import, delete `CheckboxControl,` (keep `SelectControl, TextControl, ToggleControl`).
   - Delete the line `import { ROBOTS_DIRECTIVES } from '../robots';` entirely.
   - Keep `import { RobotsFields, ROBOTS_LABELS } from '../components/RobotsFields';` (still used by `TypePanel`).

- [ ] **Step 4: Lint + JS tests + build**

Run: `npm run lint:js && npm run test:js && npm run build`
Expected: green (no new Jest tests required — `RobotsCheckboxes` is presentational and reuses the already-tested `ROBOTS_DIRECTIVES`).

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/components/RobotsCheckboxes.js assets/src/admin/variables.js assets/src/admin/views/Titles.js
git commit -m "refactor(admin): extract RobotsCheckboxes; widen scope jsdoc"
```

---

## Task 11: JS — paneles Homepage (ampliado) · Authors · Other pages + tabs/ruteo

**Files:**
- Modify: `assets/src/admin/views/Titles.js`

**Interfaces:**
- Consumes: `RobotsCheckboxes` (Task 10), `TemplateField`, `MediaField`, the `catalog` bootstrap.
- Produces: two new tabs (`authors`, `other-pages`) and an extended Homepage tab, all saving through `openseo/v1/settings`.

> **Note (M-2):** Do NOT add hidden companion inputs for these toggles. The spec's "hidden companion field" phrasing describes the legacy Settings API; this React surface saves the **full** `state.values` object via `saveSettings`, so every toggle key is always present in `$input` and `Options::sanitize`'s `isset()` checks suffice. `change( key, on ? '1' : '' )` is all that's needed.

- [ ] **Step 1: Add the new tabs to `GROUPS`**

In `assets/src/admin/views/Titles.js`, in the first group's `tabs` array, after the `homepage` entry add:

```js
				{ name: 'authors', title: __( 'Authors', 'openseo' ) },
				{ name: 'other-pages', title: __( 'Other pages', 'openseo' ) },
```

- [ ] **Step 2: Extend `HomepagePanel`**

Replace the `HomepagePanel` function with:

```jsx
function HomepagePanel( { values, change } ) {
	const homeRobots = values.home_robots ?? {};

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
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Custom homepage robots', 'openseo' ) }
				help={ __(
					'Override the global robots meta for the homepage.',
					'openseo'
				) }
				checked={ values.home_robots_custom === '1' }
				onChange={ ( on ) =>
					change( 'home_robots_custom', on ? '1' : '' )
				}
			/>
			{ values.home_robots_custom === '1' && (
				<RobotsCheckboxes
					map={ homeRobots }
					onChange={ ( next ) => change( 'home_robots', next ) }
				/>
			) }
			<h3>{ __( 'Homepage social (OpenGraph)', 'openseo' ) }</h3>
			<TemplateField
				label={ __( 'Homepage title for Facebook', 'openseo' ) }
				value={ values.home_og_title ?? '' }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_og_title', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage description for Facebook', 'openseo' ) }
				value={ values.home_og_description ?? '' }
				multiline
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_og_description', v ) }
			/>
			<MediaField
				label={ __(
					'Homepage thumbnail for Facebook (min. 1200×630px).',
					'openseo'
				) }
				value={ values.home_og_image ?? '' }
				onChange={ ( url ) => change( 'home_og_image', url ) }
			/>
		</>
	);
}
```

- [ ] **Step 3: Add `AuthorsPanel` and `OtherPagesPanel`**

After `HomepagePanel`, add:

```jsx
function AuthorsPanel( { values, change } ) {
	const authorRobots = values.author_robots ?? {};

	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Author archives', 'openseo' ) }
				help={ __(
					'When off, author archives redirect to the homepage.',
					'openseo'
				) }
				checked={ values.author_archives === '1' }
				onChange={ ( on ) =>
					change( 'author_archives', on ? '1' : '' )
				}
			/>
			<TemplateField
				label={ __( 'Author archive title', 'openseo' ) }
				value={ values.author_title ?? '' }
				scope="author"
				catalog={ catalog }
				onChange={ ( v ) => change( 'author_title', v ) }
			/>
			<TemplateField
				label={ __( 'Author archive description', 'openseo' ) }
				value={ values.author_description ?? '' }
				multiline
				scope="author"
				catalog={ catalog }
				onChange={ ( v ) => change( 'author_description', v ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Custom author robots', 'openseo' ) }
				checked={ values.author_robots_custom === '1' }
				onChange={ ( on ) =>
					change( 'author_robots_custom', on ? '1' : '' )
				}
			/>
			{ values.author_robots_custom === '1' && (
				<RobotsCheckboxes
					map={ authorRobots }
					onChange={ ( next ) => change( 'author_robots', next ) }
				/>
			) }
		</>
	);
}

function OtherPagesPanel( { values, change } ) {
	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Date archives', 'openseo' ) }
				help={ __(
					'When off, date archives redirect to the homepage.',
					'openseo'
				) }
				checked={ values.date_archives === '1' }
				onChange={ ( on ) => change( 'date_archives', on ? '1' : '' ) }
			/>
			<TemplateField
				label={ __( '404 title', 'openseo' ) }
				value={ values.title_404 ?? '' }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'title_404', v ) }
			/>
			<TemplateField
				label={ __( 'Search results title', 'openseo' ) }
				value={ values.search_title ?? '' }
				scope="search"
				catalog={ catalog }
				onChange={ ( v ) => change( 'search_title', v ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex search results', 'openseo' ) }
				checked={ values.noindex_search === '1' }
				onChange={ ( on ) => change( 'noindex_search', on ? '1' : '' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex paginated pages', 'openseo' ) }
				checked={ values.noindex_paginated === '1' }
				onChange={ ( on ) =>
					change( 'noindex_paginated', on ? '1' : '' )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Noindex paginated single pages', 'openseo' ) }
				checked={ values.noindex_paginated_singular === '1' }
				onChange={ ( on ) =>
					change( 'noindex_paginated_singular', on ? '1' : '' )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Noindex password-protected pages',
					'openseo'
				) }
				checked={ values.noindex_password_protected === '1' }
				onChange={ ( on ) =>
					change( 'noindex_password_protected', on ? '1' : '' )
				}
			/>
		</>
	);
}
```

- [ ] **Step 4: Route the new tabs**

In `renderPanel()`, add before the `if ( tab === 'homepage' )` line:

```js
	if ( tab === 'authors' ) {
		return <AuthorsPanel values={ values } change={ change } />;
	}
	if ( tab === 'other-pages' ) {
		return <OtherPagesPanel values={ values } change={ change } />;
	}
```

- [ ] **Step 5: Lint + JS tests + build**

Run: `npm run lint:js && npm run test:js && npm run build`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add assets/src/admin/views/Titles.js
git commit -m "feat(admin): Homepage/Authors/Other-pages panels for Titles & Meta"
```

---

## Task 12: Regenerar `.pot` + verificación final completa

**Files:**
- Modify: `languages/openseo.pot`

- [ ] **Step 1: Run the full PHP + JS gates**

Run: `composer check`
Expected: PHPCS + PHPStan 6 + PHPUnit unit all green.

Run: `npm run lint:js && npm run test:js && npm run build`
Expected: green.

- [ ] **Step 2: Regenerate the translation template**

Run (wp-env must be running):

```bash
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo languages/openseo.pot
```

Expected: `.pot` regenerated with the new strings (Authors / Other pages / Page X of Y / etc.).

> If wp-env/Docker is unavailable in the execution environment, leave `.pot` for a follow-up and note it; do not block the feature on it.

- [ ] **Step 3: Commit**

```bash
git add languages/openseo.pot
git commit -m "chore(i18n): regenerate .pot for special-page settings"
```

---

## Self-Review

**Spec coverage:**
- Homepage robots custom + OG title/desc/image → Tasks 1, 6, 7, 11. ✓
- Author title/desc/robots + enable toggle → Tasks 1, 2, 5, 6, 9, 11. ✓
- Search title + noindex; 404 title → Tasks 1, 2, 5, 6, 11. ✓
- Noindex paginated/paginated-singular/password → Tasks 1, 6, 11. ✓
- Date archives disable → Tasks 1, 9, 11. ✓
- Tokens `%name%`/`%search_query%`/`%page%` + scopes → Tasks 3, 4. ✓
- C1 escape (`Title::esc_html` + raw context) → Tasks 3, 8. ✓
- M2 overlay-before-assembly → Task 6 (`force_noindex()` mutates `$effective['noindex']` before `robots_parts()`). ✓
- H2 blog-posts-page out of scope → documented in spec No-objetivos (no task; intentional). ✓
- L1 `RobotsCheckboxes` DRY → Task 10. ✓
- OG-per-surface (M1), `%page%` listados (M3), JSDoc (H1), map persistence (L2), ArchiveRedirect short-circuit (L3) → covered by Tasks 6/7/10 and the spec.

**Placeholder scan:** No TBD/TODO; every code step shows full code; every test step shows assertions and the exact `--filter`.

**Type consistency:** `should_redirect()` used consistently (Task 9 test + impl). `effective_robots()`/`force_noindex()`/`custom_surface_map()`/`is_posts_homepage()` names match across Tasks 6–7. `RobotsCheckboxes({ map, onChange })` props match between Tasks 10 and 11. `for_author/for_search/for_archive` and `current_page_label` match across Tasks 3–7. Option keys identical across Tasks 1, 6, 7, 11.

**Note on test ordering:** Tasks 5/6 add conditional-tag calls to `Resolver`; the setUp-hardening steps (Task 5 Step 1, Task 6 Step 1) MUST run before the branch implementations or existing head-presenter tests will fatal on undefined `is_author()`/`is_search()`/etc.
