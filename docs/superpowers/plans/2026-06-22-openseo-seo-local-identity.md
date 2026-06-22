# SEO Local 2a (identidad + unificación) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Traer la pantalla "SEO Local" básica de Rank Math (7 campos de identidad) a OpenSEO como una pestaña en Titles & Meta que es la fuente única de la identidad de schema, reflejada en los nodos `WebSite`/`Organization`/`Person`, retirando el menú General.

**Architecture:** Cuatro keys nuevas en `Options` (`local_website_name`, `local_website_alternate_name`, `local_url`, `local_email`) que reutilizan `schema_site_type/name/logo`. Las piezas de schema `WebSite` (name configurable + `alternateName`) y `Organization`/`Person` (`email` + `url` override) las leen. Una pestaña `seo-local` nueva en `views/Titles.js` (reutiliza `MediaField`) edita los 7 campos; el menú/vista `General` se retira (Menu + App.js + borrar `General.js`).

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, `@wordpress/scripts` (React/JS), WordPress 7.0 (WP_Sitemaps/Schema), wp-env (integración).

## Global Constraints

- WP 7.0+, PHP 8.1+. `declare(strict_types=1);` en PHP. Text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS). PSR-4 file naming.
- 4 keys nuevas, todas default `''`: `local_website_name`, `local_website_alternate_name`, `local_url`, `local_email`. Sin migración de datos (reutiliza `schema_site_type` whitelist Organization/Person, `schema_site_name`, `schema_logo`).
- Sanitize: `local_website_name`/`local_website_alternate_name` → `sanitize_text_field(wp_unslash())`; `local_url` → `esc_url_raw(wp_unslash())`; `local_email` → `sanitize_email(wp_unslash())` y `is_email()` (inválido → `''`).
- `WebSite.name` = `local_website_name` (fallback `get_bloginfo('name')`); `alternateName` solo si `local_website_alternate_name` no vacío. `Organization`/`Person`: `url` = `local_url` (fallback `home_url('/')`); `email` solo si `local_email` no vacío. Name/logo de las piezas NO cambian. `Person` mantiene `@type:"Person"` (no el array de Rank Math).
- Con defaults vacíos, `WebSite`/`Organization`/`Person` producen el MISMO `@graph` que antes (sin regresión).
- UI: pestaña `seo-local` entre `meta-global` y `homepage` en `views/Titles.js`; `renderPanel` con rama EXPLÍCITA `seo-local` antes del fallback `MetaGlobalPanel`. Logo con `MediaField`. Selector persona/org = `SelectControl`. Help diferenciado: `local_website_name` = "Name of the WebSite node…"; `schema_site_name` = "Name of the Organization/Person entity…". Strings en inglés `__( …, 'openseo' )`.
- Retiro de General: quitar `openseo-general` de `Menu::pages()`; quitar import + mapeo `general` de `App.js`; borrar `views/General.js`. Solo `MenuTest` cambia (quitar `'openseo-general'`); `MenuWiringTest` NO. Regenerar `.pot`; actualizar `CLAUDE.md`/`NOTES.md` (9 → 8 submenús, sin "General").
- React desde `@wordpress/*`; i18n `@wordpress/i18n`; sin `dangerouslySetInnerHTML`. Conventional commits, SIN atribución.
- Gates por commit que toque su capa: `composer lint`, `composer analyze` (`--memory-limit=1G`), `composer test:unit`; JS `npm run lint:js`, `npm run test:js`, `npm run build`. Integración (`MenuTest`) vía wp-env en la verificación final.

---

## File Structure

**PHP (modificados):** `src/Settings/Options.php` (4 defaults + sanitize), `src/Schema/Pieces/WebSite.php` (name + alternateName), `src/Schema/Pieces/Organization.php` (email + url), `src/Schema/Pieces/Person.php` (email + url), `src/Admin/Menu.php` (quitar `openseo-general`).
**JS (modificados):** `assets/src/admin/views/Titles.js` (tab `seo-local` + `SeoLocalPanel`), `assets/src/admin/App.js` (quitar `general`).
**JS (borrado):** `assets/src/admin/views/General.js`.
**Tests (modificados):** `tests/Unit/OptionsTest.php`, `tests/Unit/Schema/Pieces/SitePiecesTest.php`, `tests/Integration/MenuTest.php`.
**Docs:** `CLAUDE.md`, `NOTES.md`, `languages/openseo.pot`.

---

## Task 1: `Options` — 4 keys de identidad + sanitize

**Files:**
- Modify: `src/Settings/Options.php` (defaults; sanitize text/url/email)
- Modify: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Produces: `Options::all()` incluye `local_website_name`/`local_website_alternate_name`/`local_url`/`local_email` (todas `''`). `sanitize()` las normaliza (email inválido → `''`).

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/OptionsTest.php` (su `setUp` ya mockea `sanitize_textarea_field`/`get_post_types`/`get_taxonomies`):

```php
	public function test_defaults_include_local_identity_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$o = new Options();

		$this->assertSame( '', $o->get( 'local_website_name' ) );
		$this->assertSame( '', $o->get( 'local_website_alternate_name' ) );
		$this->assertSame( '', $o->get( 'local_url' ) );
		$this->assertSame( '', $o->get( 'local_email' ) );
	}

	public function test_sanitize_local_text_and_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'local_website_name'           => 'My Brand',
				'local_website_alternate_name' => 'MB',
				'local_url'                    => 'https://example.com',
			)
		);

		$this->assertSame( 'My Brand', $clean['local_website_name'] );
		$this->assertSame( 'MB', $clean['local_website_alternate_name'] );
		$this->assertSame( 'https://example.com', $clean['local_url'] );
	}

	public function test_sanitize_local_email_valid_and_invalid(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->alias(
			static fn( $v ) => str_contains( (string) $v, '@' ) ? $v : false
		);

		$valid   = ( new Options() )->sanitize( array( 'local_email' => 'hi@example.com' ) );
		$invalid = ( new Options() )->sanitize( array( 'local_email' => 'not-an-email' ) );

		$this->assertSame( 'hi@example.com', $valid['local_email'] );
		$this->assertSame( '', $invalid['local_email'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL — keys missing / not sanitized.

- [ ] **Step 3: Add the four defaults**

In `src/Settings/Options.php` `defaults()`, after the `'advanced_robots' => array( … ),` block (the last entry), add:

```php
			'local_website_name'           => '',
			'local_website_alternate_name' => '',
			'local_url'                    => '',
			'local_email'                  => '',
```

- [ ] **Step 4: Add the two text keys to the text loop**

In `sanitize()`, replace the text-field `foreach` array line:

```php
		foreach ( array( 'title_separator', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model' ) as $key ) {
```
with:
```php
		foreach ( array( 'title_separator', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model', 'local_website_name', 'local_website_alternate_name' ) as $key ) {
```

- [ ] **Step 5: Add `local_url` to the URL loop**

In `sanitize()`, replace the URL `foreach` array line:

```php
		foreach ( array( 'og_default_image', 'schema_logo' ) as $key ) {
```
with:
```php
		foreach ( array( 'og_default_image', 'schema_logo', 'local_url' ) as $key ) {
```

- [ ] **Step 6: Sanitize `local_email`**

In `sanitize()`, immediately after the URL `foreach` loop (the one that now includes `local_url`), add:

```php
		if ( isset( $input['local_email'] ) ) {
			$email                = sanitize_email( wp_unslash( $input['local_email'] ) );
			$clean['local_email'] = is_email( $email ) ? $email : '';
		}
```

- [ ] **Step 7: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: PASS (existing + 3 new).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 8: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(settings): local identity options (website name/alt, url, email)"
```

---

## Task 2: `WebSite` piece — nombre configurable + `alternateName`

**Files:**
- Modify: `src/Schema/Pieces/WebSite.php` (`data()`)
- Modify: `tests/Unit/Schema/Pieces/SitePiecesTest.php`

**Interfaces:**
- Consumes: `Options` `local_website_name`/`local_website_alternate_name` (Task 1).
- Produces: `WebSite::data()['name']` = configurable (fallback bloginfo); `['alternateName']` cuando hay valor.

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/Schema/Pieces/SitePiecesTest.php` (su `setUp` mockea `home_url`→`https://example.com…` y `get_bloginfo` name→`My Site`):

```php
	public function test_website_uses_configured_name_and_alternate(): void {
		$piece = new WebSite(
			$this->options(
				array(
					'schema_site_type'             => 'Organization',
					'local_website_name'           => 'Acme Site',
					'local_website_alternate_name' => 'Acme',
				)
			)
		);

		$data = $piece->data();
		$this->assertSame( 'Acme Site', $data['name'] );
		$this->assertSame( 'Acme', $data['alternateName'] );
	}

	public function test_website_name_falls_back_to_bloginfo_and_omits_alternate(): void {
		$data = ( new WebSite( $this->options( array( 'schema_site_type' => 'Organization' ) ) ) )->data();

		$this->assertSame( 'My Site', $data['name'] );
		$this->assertArrayNotHasKey( 'alternateName', $data );
	}

	public function test_website_and_organization_names_do_not_cross(): void {
		$stored = array(
			'schema_site_type'   => 'Organization',
			'local_website_name' => 'A',
			'schema_site_name'   => 'B',
		);

		$this->assertSame( 'A', ( new WebSite( $this->options( $stored ) ) )->data()['name'] );
		$this->assertSame( 'B', ( new Organization( $this->options( $stored ) ) )->data()['name'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SitePiecesTest`
Expected: FAIL — `name` still hardcoded to bloginfo / no `alternateName`.

- [ ] **Step 3: Rewrite `WebSite::data()`**

Replace the whole `data()` method in `src/Schema/Pieces/WebSite.php` with:

```php
	public function data(): array {
		$identity = 'Person' === (string) $this->options->get( 'schema_site_type' )
			? Ids::person()
			: Ids::organization();

		$name = (string) $this->options->get( 'local_website_name' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$data = array(
			'@type'           => 'WebSite',
			'@id'             => $this->id(),
			'url'             => home_url( '/' ),
			'name'            => $name,
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

		$alternate = (string) $this->options->get( 'local_website_alternate_name' );
		if ( '' !== $alternate ) {
			$data['alternateName'] = $alternate;
		}

		return $data;
	}
```

(The `Organization` import is already present in the test file; no production import change is needed in `WebSite.php`.)

- [ ] **Step 4: Run tests + analysis**

Run: `vendor/bin/phpunit --filter SitePiecesTest`
Expected: PASS (new + existing `test_website_*` still green — empty default name falls back to `My Site`).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add src/Schema/Pieces/WebSite.php tests/Unit/Schema/Pieces/SitePiecesTest.php
git commit -m "feat(schema): WebSite configurable name + alternateName"
```

---

## Task 3: `Organization` + `Person` pieces — `email` + `url` override

**Files:**
- Modify: `src/Schema/Pieces/Organization.php` (`data()`)
- Modify: `src/Schema/Pieces/Person.php` (`data()`)
- Modify: `tests/Unit/Schema/Pieces/SitePiecesTest.php`

**Interfaces:**
- Consumes: `Options` `local_url`/`local_email` (Task 1).
- Produces: `Organization::data()` / `Person::data()` include `email` (when set) and use `local_url` for `url` (fallback `home_url('/')`).

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/Schema/Pieces/SitePiecesTest.php`:

```php
	public function test_organization_emits_email_and_url_override(): void {
		$org = new Organization(
			$this->options(
				array(
					'schema_site_type' => 'Organization',
					'local_url'        => 'https://brand.example',
					'local_email'      => 'hi@example.com',
				)
			)
		);

		$data = $org->data();
		$this->assertSame( 'https://brand.example', $data['url'] );
		$this->assertSame( 'hi@example.com', $data['email'] );
	}

	public function test_organization_url_falls_back_and_omits_email(): void {
		$data = ( new Organization( $this->options( array( 'schema_site_type' => 'Organization' ) ) ) )->data();

		$this->assertSame( 'https://example.com/', $data['url'] );
		$this->assertArrayNotHasKey( 'email', $data );
	}

	public function test_person_emits_email_and_url_override(): void {
		$person = new Person(
			$this->options(
				array(
					'schema_site_type' => 'Person',
					'local_url'        => 'https://me.example',
					'local_email'      => 'me@example.com',
				)
			)
		);

		$data = $person->data();
		$this->assertSame( 'https://me.example', $data['url'] );
		$this->assertSame( 'me@example.com', $data['email'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SitePiecesTest`
Expected: FAIL — no `email`; `url` ignores `local_url`.

- [ ] **Step 3: Update `Organization::data()`**

In `src/Schema/Pieces/Organization.php`, replace the `$data = array( … );` literal (the base node, `@type`/`@id`/`name`/`url`) with:

```php
		$url = (string) $this->options->get( 'local_url' );
		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		$data = array(
			'@type' => 'Organization',
			'@id'   => $this->id(),
			'name'  => $name,
			'url'   => $url,
		);

		$email = (string) $this->options->get( 'local_email' );
		if ( '' !== $email ) {
			$data['email'] = $email;
		}
```

(Leave the `$logo` block that follows unchanged.)

- [ ] **Step 4: Update `Person::data()`**

In `src/Schema/Pieces/Person.php`, replace the `$data = array( … );` literal (`@type`/`@id`/`name`/`url`) with:

```php
		$url = (string) $this->options->get( 'local_url' );
		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		$data = array(
			'@type' => 'Person',
			'@id'   => $this->id(),
			'name'  => $name,
			'url'   => $url,
		);

		$email = (string) $this->options->get( 'local_email' );
		if ( '' !== $email ) {
			$data['email'] = $email;
		}
```

(Leave the `$logo`/`image` block that follows unchanged.)

- [ ] **Step 5: Run tests + analysis**

Run: `vendor/bin/phpunit --filter SitePiecesTest`
Expected: PASS (new + existing — `url` fallback equals the previous `home_url('/')`, so `test_organization_*`/`test_person_*` stay green).

Run: `composer test:unit`
Expected: Whole unit suite green.

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Schema/Pieces/Organization.php src/Schema/Pieces/Person.php tests/Unit/Schema/Pieces/SitePiecesTest.php
git commit -m "feat(schema): Organization/Person email + url override"
```

---

## Task 4: `Titles.js` — pestaña SEO Local + `SeoLocalPanel`

**Files:**
- Modify: `assets/src/admin/views/Titles.js`

**Interfaces:**
- Consumes: `MediaField` (existing), `SelectControl`/`TextControl` from `@wordpress/components`, `Options` keys `schema_site_type`/`schema_site_name`/`schema_logo`/`local_*`.

- [ ] **Step 1: Add `TextControl` to the components import**

In `assets/src/admin/views/Titles.js`, change the `@wordpress/components` import to include `TextControl`:

```jsx
import {
	CheckboxControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
```

- [ ] **Step 2: Add the `seo-local` tab**

In the `GROUPS` first group `tabs` array, insert `seo-local` between `meta-global` and `homepage`:

```jsx
		tabs: [
			{ name: 'meta-global', title: __( 'Meta Global', 'openseo' ) },
			{ name: 'seo-local', title: __( 'SEO Local', 'openseo' ) },
			{ name: 'homepage', title: __( 'Homepage', 'openseo' ) },
		],
```

- [ ] **Step 3: Add the `SeoLocalPanel` component**

In `assets/src/admin/views/Titles.js`, add this function (e.g. right after `MetaGlobalPanel`):

```jsx
function SeoLocalPanel( { values, change } ) {
	return (
		<>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Site represents', 'openseo' ) }
				value={ values.schema_site_type ?? 'Organization' }
				options={ [
					{
						label: __( 'Organization', 'openseo' ),
						value: 'Organization',
					},
					{ label: __( 'Person', 'openseo' ), value: 'Person' },
				] }
				onChange={ ( v ) => change( 'schema_site_type', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Website name', 'openseo' ) }
				help={ __(
					'Name of the WebSite node (defaults to site name).',
					'openseo'
				) }
				value={ values.local_website_name ?? '' }
				onChange={ ( v ) => change( 'local_website_name', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Alternate website name', 'openseo' ) }
				value={ values.local_website_alternate_name ?? '' }
				onChange={ ( v ) =>
					change( 'local_website_alternate_name', v )
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Person or Organization name', 'openseo' ) }
				help={ __(
					'Name of the Organization/Person entity (defaults to site name).',
					'openseo'
				) }
				value={ values.schema_site_name ?? '' }
				onChange={ ( v ) => change( 'schema_site_name', v ) }
			/>
			<h3>{ __( 'Logo', 'openseo' ) }</h3>
			<MediaField
				label={ __( 'Minimum size 112×112px.', 'openseo' ) }
				value={ values.schema_logo ?? '' }
				onChange={ ( url ) => change( 'schema_logo', url ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'URL', 'openseo' ) }
				help={ __( 'Defaults to the site URL.', 'openseo' ) }
				value={ values.local_url ?? '' }
				onChange={ ( v ) => change( 'local_url', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				type="email"
				label={ __( 'Email', 'openseo' ) }
				value={ values.local_email ?? '' }
				onChange={ ( v ) => change( 'local_email', v ) }
			/>
		</>
	);
}
```

- [ ] **Step 4: Route the `seo-local` tab in `renderPanel`**

In `renderPanel`, add an EXPLICIT branch before the final `return <MetaGlobalPanel … />;`. The function's tail should read:

```jsx
	if ( tab === 'homepage' ) {
		return <HomepagePanel values={ values } change={ change } />;
	}
	if ( tab === 'seo-local' ) {
		return <SeoLocalPanel values={ values } change={ change } />;
	}
	return <MetaGlobalPanel values={ values } change={ change } />;
```

- [ ] **Step 5: Lint, test, build**

Run: `npm run lint:js`
Expected: No ESLint errors (no unused imports).

Run: `npm run test:js`
Expected: All suites pass.

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 6: Commit**

```bash
git add assets/src/admin/views/Titles.js
git commit -m "feat(admin): SEO Local tab with identity fields"
```

---

## Task 5: Retirar el menú/vista General

**Files:**
- Modify: `src/Admin/Menu.php` (quitar `openseo-general`)
- Modify: `tests/Integration/MenuTest.php` (quitar el slug)
- Modify: `assets/src/admin/App.js` (quitar import + mapeo `general`)
- Delete: `assets/src/admin/views/General.js`

**Interfaces:**
- Produces: la identidad ya no tiene menú propio; se edita solo desde la pestaña SEO Local.

- [ ] **Step 1: Remove the submenu entry from `Menu::pages()`**

In `src/Admin/Menu.php` `pages()`, delete this array element:

```php
				array(
					'slug'  => 'openseo-general',
					'title' => __( 'General', 'openseo' ),
					'view'  => 'general',
				),
```

- [ ] **Step 2: Update the integration menu test**

In `tests/Integration/MenuTest.php` `test_registers_parent_and_all_submenus`, remove `'openseo-general',` from the asserted slugs array (leave the rest: `'openseo'`, `'openseo-titles'`, `'openseo-social'`, `'openseo-sitemaps'`, `'openseo-schema'`, `'openseo-redirects'`, `'openseo-404s'`, `'openseo-ai'`).

- [ ] **Step 3: Remove `General` from `App.js`**

In `assets/src/admin/App.js`, delete the import line:

```jsx
import { General } from './views/General';
```
and delete the `general: General,` entry from the `VIEWS` object. (`VIEWS[view] ?? Dashboard` already covers any stray `general` request by falling back to the Dashboard.)

- [ ] **Step 4: Delete the General view file**

```bash
git rm assets/src/admin/views/General.js
```

- [ ] **Step 5: Verify gates (PHP + JS; integration deferred to Task 6)**

Run: `composer lint`
Expected: PHPCS clean.

Run: `composer analyze`
Expected: No errors.

Run: `npm run lint:js`
Expected: No ESLint errors (no dangling `General` import).

Run: `npm run build`
Expected: Build succeeds (no `general` view referenced).

(The integration `MenuTest` runs under wp-env in Task 6; it cannot run with the unit suite.)

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Menu.php tests/Integration/MenuTest.php assets/src/admin/App.js assets/src/admin/views/General.js
git commit -m "refactor(admin): retire General menu; identity lives in SEO Local"
```

---

## Task 6: Verificación final + docs + i18n

**Files:**
- Modify: `CLAUDE.md`, `NOTES.md`
- Modify: `languages/openseo.pot`

- [ ] **Step 1: PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green (incl. `OptionsTest`, `SitePiecesTest`).

- [ ] **Step 2: JS gates**

Run: `npm run lint:js` → no errors. `npm run test:js` → all pass. `npm run build` → succeeds.

- [ ] **Step 3: Integration suite (wp-env)**

```bash
npm run env:start
npm run test:integration
```
Expected: `MenuTest` green (submenu set no longer includes `openseo-general`; `test_all_screens_are_react` and `test_dashboard_hook_is_the_top_level_hook` still pass). If wp-env/Docker is unavailable, note it as a deferred follow-up — do not skip silently.

- [ ] **Step 4: Update the docs (submenu count 9 → 8)**

In `CLAUDE.md`, the `Admin/Menu.php` description reads "the single registrar of the **top-level OpenSEO menu** and all 9 submenus (Dashboard · General · Titles & Meta · Social · Sitemaps · Schema · Redirects · 404s · AI)". Change `9 submenus` → `8 submenus` and remove `General · ` from the list. Add a short note that the site identity (Organization/Person, name, logo, URL, email) now lives in the **SEO Local** tab of Titles & Meta.

In `NOTES.md`, update the same "9 submenús" / "General" references in the admin-consolidation section to **8 submenús** without "General", noting SEO Local as a Titles & Meta tab.

(Grep to locate: `grep -n "9 submenu" CLAUDE.md NOTES.md` and `grep -n "General" CLAUDE.md NOTES.md`.)

- [ ] **Step 5: Regenerate the `.pot`**

```bash
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo wp-content/plugins/openseo/languages/openseo.pot
```
(IMPORTANT: the destination MUST be the plugin-relative path `wp-content/plugins/openseo/languages/openseo.pot` — a bare `languages/openseo.pot` writes to the container's WP root, not the plugin. Confirm the host file changed with `git status --short languages/openseo.pot`.)

Then verify the new SEO Local strings landed:
```bash
grep -cE "Website name|Alternate website name|SEO Local" languages/openseo.pot   # expected: >= 1
```

Verify the strings exclusive to the deleted `General.js` are gone:
```bash
grep -c "Name (defaults to site name)\|Logo / image URL" languages/openseo.pot   # expected: 0
```

> **Note:** `msgid "General"` will STILL be present after `make-pot` — it is also emitted by the editor's `general` tab (`assets/src/editor/index.js`, a different surface this sub-project does not touch). Do not expect it to disappear. Likewise `"Site represents"` survives because `SeoLocalPanel` reuses it.

- [ ] **Step 6: Manual smoke test (wp-env)**

In **OpenSEO → Titles & Meta → SEO Local**: set "Alternate website name", "URL" and "Email", Save. View a page's source → the `@graph` shows `WebSite.alternateName` and `Organization.email`/`url`. Confirm the **General** menu item is gone and identity is editable only from SEO Local.

- [ ] **Step 7: Commit docs + .pot**

```bash
git add CLAUDE.md NOTES.md languages/openseo.pot
git commit -m "docs(admin): retire General submenu; regen .pot for SEO Local"
```

---

## Self-Review (completed during planning)

- **Spec coverage:** 4 keys + sanitize email/url (Task 1); WebSite name+alternateName (Task 2); Org/Person email+url (Task 3); SEO Local tab + 7 fields + MediaField logo + explicit `seo-local` route + differentiated help (Task 4); retire General — Menu/MenuTest/App.js/delete (Task 5); `.pot` + CLAUDE.md/NOTES.md + integration + smoke (Task 6). Anti-cross test (LOW-2) in Task 2; Person `@type` kept simple (D2) by not touching it; image/logo asymmetry (LOW-3) untouched. Every acceptance criterion has a task.
- **Placeholder scan:** no TBD/TODO; every code step shows the code and the command with expected output (the docs step in Task 6 gives exact find/replace targets + grep helpers).
- **Type/símbolo consistency:** `local_website_name`/`local_website_alternate_name`/`local_url`/`local_email` produced in Task 1, read in Tasks 2/3 (PHP) and Task 4 (JS); `schema_site_type`/`schema_site_name`/`schema_logo` reused unchanged; `MediaField`/`SelectControl`/`TextControl` used in Task 4 (`TextControl` added to the import in Step 1); `renderPanel` `seo-local` branch added before the `MetaGlobalPanel` fallback.
- **Green-by-commit:** Task 1 adds defaults before Tasks 2/3 read them; SEO Local tab (Task 4) makes identity editable before General is retired (Task 5); deleting `General.js` and its `App.js` import happen in the same commit (no dangling import). Existing `SitePiecesTest`/`OptionsTest` stay green (empty defaults reproduce prior `@graph`); the integration `MenuTest` change is verified in Task 6 under wp-env.
- **Design audit incorporated:** MEDIUM-1 (explicit `seo-local` route, Task 4 Step 4), MEDIUM-2 (.pot + CLAUDE.md/NOTES.md, Task 6), LOW-1 (only `MenuTest` changes, Task 5; `MenuWiringTest` untouched), LOW-2 (anti-cross test + differentiated help, Tasks 2/4), LOW-3 (image/logo asymmetry left intact, noted).
