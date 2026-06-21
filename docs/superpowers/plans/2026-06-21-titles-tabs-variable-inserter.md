# UI con pestañas + inserter de variables (Titles & Meta) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganizar la vista admin "Titles & Meta" en pestañas verticales (General + una por tipo de contenido + una por taxonomía) y añadir en cada campo de template un dropdown buscable de variables, filtrado por contexto, que inserta tokens en la posición del cursor.

**Architecture:** Un catálogo de variables en PHP (`Meta\VariableCatalog`, con scope) se expone en `window.openseoAdmin.variables`; un test anti-drift impide que diverja de `Variables::replace`. La UI usa un `VerticalTabs` propio (ARIA tablist vertical), un `VariableInserter` (Dropdown + SearchControl) y un `TemplateField` con `<input>`/`<textarea>` nativo y `useRef` para insertar en el cursor. El guardado sigue por `openseo/v1/settings` sin cambios de modelo de datos.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, `@wordpress/scripts` (React/JS + Jest + SCSS), `@wordpress/components`/`element`/`compose`/`i18n`, WordPress 7.0.

## Global Constraints

- WordPress 7.0+, PHP 8.1+. `declare(strict_types=1);` en todo PHP nuevo. Text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS).
- Hooks de React desde `@wordpress/element` (`useState`/`useRef`/`useEffect`), NO desde `react`. `useInstanceId` desde `@wordpress/compose`.
- `TemplateField` usa `<input>`/`<textarea>` NATIVO (no `TextControl`/`TextareaControl`: su `ref` no apunta al DOM input — Gutenberg #28756). Clase `components-text-control__input` para heredar estilo WP.
- Inserción **en la posición del cursor** con guarda: la posición a restaurar se guarda en un `pendingCursorRef` SOLO en el handler de inserción; el `useEffect` aplica `setSelectionRange`+`focus()` solo cuando hay valor pendiente y lo limpia. El tecleo normal no lo toca.
- Variables filtradas por contexto: tipo → `global + singular`; taxonomía → `global + taxonomy`; General → solo `global`.
- `VerticalTabs`: `role="tablist"` + `aria-orientation="vertical"`; pestañas `role="tab"` + `aria-selected` + `id` + roving tabindex; panel `role="tabpanel"` + `aria-labelledby`; teclado ↑/↓ (envuelven) + Home/End. Encabezados de grupo NO son `tab`.
- Inserter: catálogo por **prop** (no leer `window` en el componente); búsqueda vacía → todas del scope; `Dropdown` render-prop (hereda `aria-expanded`); al insertar, cerrar y devolver el foco al input.
- Seguridad: bootstrap `wp_json_encode(..., JSON_HEX_TAG)`; render React auto-escapado; NINGÚN `dangerouslySetInnerHTML`. SCSS: no poner `overflow:hidden` en el ancestro del dropdown.
- Gates verdes por commit que toque su capa: `composer lint`, `composer analyze` (`--memory-limit=1G`), `composer test:unit`; JS `npm run lint:js`, `npm run test:js`, `npm run build` al tocar assets.
- Sin atribución en commits. Conventional commits.

---

## File Structure

**PHP (nuevos):** `src/Meta/VariableCatalog.php`.
**PHP (modificados):** `src/Admin/Assets.php` (dep + bootstrap `variables`), `src/Plugin.php` (inyectar `VariableCatalog`).
**JS (nuevos):** `assets/src/admin/variables.js`, `assets/src/admin/cursor.js`, `assets/src/admin/components/VariableInserter.js`, `assets/src/admin/components/TemplateField.js`, `assets/src/admin/components/VerticalTabs.js`.
**JS (modificados):** `assets/src/admin/views/Titles.js` (reescrito), `assets/src/admin/style.scss`.
**JS (eliminado):** `assets/src/admin/components/TemplateGroup.js`.
**Tests (nuevos):** `tests/Unit/Meta/VariableCatalogTest.php`, `assets/src/admin/variables.test.js`, `assets/src/admin/cursor.test.js`.

---

## Task 1: `Meta\VariableCatalog` + anti-drift test

**Files:**
- Create: `src/Meta/VariableCatalog.php`
- Test: `tests/Unit/Meta/VariableCatalogTest.php`

**Interfaces:**
- Consumes: `__()`; for the anti-drift test, `Meta\Variables` + `Meta\TemplateContext`.
- Produces: `OpenSEO\Meta\VariableCatalog::all(): array<int, array{token:string,label:string,description:string,scope:string}>` (scope ∈ `global|singular|taxonomy`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateContext;
use OpenSEO\Meta\Variables;
use OpenSEO\Meta\VariableCatalog;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class VariableCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_catalog_has_entries_with_required_keys_and_valid_scopes(): void {
		$all = ( new VariableCatalog() )->all();

		$this->assertNotEmpty( $all );
		foreach ( $all as $entry ) {
			$this->assertArrayHasKey( 'token', $entry );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertArrayHasKey( 'description', $entry );
			$this->assertArrayHasKey( 'scope', $entry );
			$this->assertContains( $entry['scope'], array( 'global', 'singular', 'taxonomy' ) );
			$this->assertSame( 1, preg_match( '/^%[a-z_]+%$/', $entry['token'] ) );
		}
	}

	public function test_every_catalog_token_is_replaced_by_variables(): void {
		// Anti-drift: a catalog token NOT handled by Variables::replace would be
		// left literal by strtr, so the output would still contain it.
		Functions\when( 'get_option' )->justReturn( array( 'title_separator' => '-' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( 'A Title' );
		Functions\when( 'get_the_excerpt' )->justReturn( 'An excerpt' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$variables = new Variables( new Options() );

		foreach ( ( new VariableCatalog() )->all() as $entry ) {
			$token   = $entry['token'];
			$context = $this->context_for_scope( $entry['scope'] );
			$output  = $variables->replace( $token, $context );

			$this->assertStringNotContainsString(
				$token,
				$output,
				"Catalog token {$token} is not expanded by Variables::replace"
			);
		}
	}

	private function context_for_scope( string $scope ): TemplateContext {
		if ( 'singular' === $scope ) {
			return TemplateContext::for_post( 1 );
		}
		if ( 'taxonomy' === $scope ) {
			$term              = new WP_Term();
			$term->name        = 'News';
			$term->description = 'All news.';
			return TemplateContext::for_term( $term );
		}
		return TemplateContext::none();
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter VariableCatalogTest`
Expected: FAIL — `Class "OpenSEO\Meta\VariableCatalog" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Catalog of template variables with metadata for the admin variable inserter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

/**
 * Lists the template tokens with a human label, a description, and a scope
 * (global | singular | taxonomy) so the editor UI can offer the right tokens
 * per context. The set of tokens must match what Variables::replace() expands;
 * a unit test enforces that invariant (anti-drift).
 */
final class VariableCatalog {

	/**
	 * All known variables.
	 *
	 * @return array<int, array{token:string, label:string, description:string, scope:string}>
	 */
	public function all(): array {
		return array(
			array(
				'token'       => '%sitename%',
				'label'       => __( 'Site title', 'openseo' ),
				'description' => __( "Your site's name", 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%tagline%',
				'label'       => __( 'Tagline', 'openseo' ),
				'description' => __( "Your site's tagline", 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%sep%',
				'label'       => __( 'Separator', 'openseo' ),
				'description' => __( 'The separator character', 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%currentyear%',
				'label'       => __( 'Current year', 'openseo' ),
				'description' => __( 'The current year', 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%title%',
				'label'       => __( 'Title', 'openseo' ),
				'description' => __( 'The entry title', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%excerpt%',
				'label'       => __( 'Excerpt', 'openseo' ),
				'description' => __( 'The entry excerpt', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%term%',
				'label'       => __( 'Term name', 'openseo' ),
				'description' => __( 'The taxonomy term name', 'openseo' ),
				'scope'       => 'taxonomy',
			),
			array(
				'token'       => '%term_description%',
				'label'       => __( 'Term description', 'openseo' ),
				'description' => __( 'The taxonomy term description', 'openseo' ),
				'scope'       => 'taxonomy',
			),
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter VariableCatalogTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Meta/VariableCatalog.php tests/Unit/Meta/VariableCatalogTest.php
git commit -m "feat(meta): add VariableCatalog with anti-drift test"
```

---

## Task 2: Bootstrap `variables` (`Admin\Assets` + `Plugin`)

**Files:**
- Modify: `src/Admin/Assets.php` (constructor dep + bootstrap key)
- Modify: `src/Plugin.php` (pass `new VariableCatalog()` to `AdminAssets`)

**Interfaces:**
- Consumes: `VariableCatalog::all()` (Task 1).
- Produces: `window.openseoAdmin.variables` = the catalog array.

> No unit test for `Assets` (enqueue class, untestable — consistent with the codebase). Gate is lint + analyze + the full unit suite staying green.

- [ ] **Step 1: Add the constructor dependency**

In `src/Admin/Assets.php`, add the import below `use OpenSEO\Settings\Options;`:

```php
use OpenSEO\Meta\VariableCatalog;
```

Add a 7th promoted param to the constructor (after `$defaults`):

```php
		private readonly TemplateDefaults $defaults,
		private readonly VariableCatalog $variable_catalog,
	) {}
```

Update the constructor docblock with a line for `$variable_catalog`:

```php
	 * @param VariableCatalog  $variable_catalog Template variables catalog (inserter).
```

- [ ] **Step 2: Add `variables` to the bootstrap payload**

In `bootstrap()`, add a `variables` key to the `$data` array (after the `contentTypes` entry):

```php
			'variables'    => $this->variable_catalog->all(),
```

- [ ] **Step 3: Pass the dependency in `Plugin.php`**

In `src/Plugin.php`, add the import below `use OpenSEO\Meta\TypeTemplates;`:

```php
use OpenSEO\Meta\VariableCatalog;
```

Change the `AdminAssets` construction (the line `$modules[] = new AdminAssets( $menu, $options, $redirects_repo, $not_found_log, new ContentTypes(), new TemplateDefaults() );`) to append the catalog:

```php
			$modules[] = new AdminAssets( $menu, $options, $redirects_repo, $not_found_log, new ContentTypes(), new TemplateDefaults(), new VariableCatalog() );
```

- [ ] **Step 4: Verify gates**

Run: `composer lint`
Expected: No PHPCS violations.

Run: `composer analyze`
Expected: No errors.

Run: `composer test:unit`
Expected: Whole suite green.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Assets.php src/Plugin.php
git commit -m "feat(admin): expose variable catalog in the admin bootstrap"
```

---

## Task 3: `variables.js` — scope filter + query filter (pure)

**Files:**
- Create: `assets/src/admin/variables.js`
- Test: `assets/src/admin/variables.test.js`

**Interfaces:**
- Produces: `variablesForScope(catalog, scope)` (global + scope, in catalog order); `filterVariables(list, query)` (case-insensitive match on label/token/description; empty query → all).

- [ ] **Step 1: Write the failing test**

```js
import { variablesForScope, filterVariables } from './variables';

const catalog = [
	{ token: '%sitename%', label: 'Site title', description: "Your site's name", scope: 'global' },
	{ token: '%title%', label: 'Title', description: 'The entry title', scope: 'singular' },
	{ token: '%term%', label: 'Term name', description: 'The taxonomy term name', scope: 'taxonomy' },
];

describe( 'variablesForScope', () => {
	it( 'returns global + singular for the singular scope, in order', () => {
		const r = variablesForScope( catalog, 'singular' );
		expect( r.map( ( v ) => v.token ) ).toEqual( [ '%sitename%', '%title%' ] );
	} );

	it( 'returns global + taxonomy for the taxonomy scope', () => {
		const r = variablesForScope( catalog, 'taxonomy' );
		expect( r.map( ( v ) => v.token ) ).toEqual( [ '%sitename%', '%term%' ] );
	} );

	it( 'returns only global for the global scope', () => {
		const r = variablesForScope( catalog, 'global' );
		expect( r.map( ( v ) => v.token ) ).toEqual( [ '%sitename%' ] );
	} );

	it( 'tolerates a missing catalog', () => {
		expect( variablesForScope( undefined, 'global' ) ).toEqual( [] );
	} );
} );

describe( 'filterVariables', () => {
	it( 'returns the whole list for an empty query', () => {
		expect( filterVariables( catalog, '' ) ).toHaveLength( 3 );
	} );

	it( 'matches label, token, or description case-insensitively', () => {
		expect( filterVariables( catalog, 'TERM' ).map( ( v ) => v.token ) ).toEqual( [ '%term%' ] );
		expect( filterVariables( catalog, '%title%' ).map( ( v ) => v.token ) ).toEqual( [ '%title%' ] );
		expect( filterVariables( catalog, "your site" ).map( ( v ) => v.token ) ).toEqual( [ '%sitename%' ] );
	} );

	it( 'returns empty when nothing matches', () => {
		expect( filterVariables( catalog, 'zzz' ) ).toEqual( [] );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- variables.test.js`
Expected: FAIL — cannot find module `./variables`.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Pure helpers for the admin variable inserter.
 */

/**
 * Variables applicable to a scope: every 'global' plus those of the given scope.
 *
 * @param {Array}  catalog Variable catalog ({ token, label, description, scope }).
 * @param {string} scope   'global' | 'singular' | 'taxonomy'.
 * @return {Array} Filtered list, in catalog order.
 */
export function variablesForScope( catalog, scope ) {
	return ( catalog ?? [] ).filter(
		( v ) => v.scope === 'global' || v.scope === scope
	);
}

/**
 * Case-insensitive search over label/token/description. Empty query → all.
 *
 * @param {Array}  list  Variables to filter.
 * @param {string} query Search text.
 * @return {Array} Matching variables.
 */
export function filterVariables( list, query ) {
	const q = String( query ?? '' ).trim().toLowerCase();
	if ( ! q ) {
		return list;
	}
	return list.filter(
		( v ) =>
			v.label.toLowerCase().includes( q ) ||
			v.token.toLowerCase().includes( q ) ||
			v.description.toLowerCase().includes( q )
	);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- variables.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/variables.js assets/src/admin/variables.test.js
git commit -m "feat(admin): add variablesForScope + filterVariables helpers"
```

---

## Task 4: `cursor.js` — `insertAtCursor` (pure)

**Files:**
- Create: `assets/src/admin/cursor.js`
- Test: `assets/src/admin/cursor.test.js`

**Interfaces:**
- Produces: `insertAtCursor(value, token, start, end)` → `{ value, cursor }` — inserts `token` replacing `[start,end)`, returns the new cursor (`start + token.length`).

- [ ] **Step 1: Write the failing test**

```js
import { insertAtCursor } from './cursor';

describe( 'insertAtCursor', () => {
	it( 'inserts at the caret position', () => {
		const r = insertAtCursor( 'ab', '%x%', 1, 1 );
		expect( r.value ).toBe( 'a%x%b' );
		expect( r.cursor ).toBe( 4 );
	} );

	it( 'appends at the end', () => {
		const r = insertAtCursor( 'ab', '%x%', 2, 2 );
		expect( r.value ).toBe( 'ab%x%' );
		expect( r.cursor ).toBe( 5 );
	} );

	it( 'replaces a selection', () => {
		const r = insertAtCursor( 'abcd', '%x%', 1, 3 );
		expect( r.value ).toBe( 'a%x%d' );
		expect( r.cursor ).toBe( 4 );
	} );

	it( 'clamps out-of-range positions and appends', () => {
		const r = insertAtCursor( 'ab', '%x%', null, null );
		expect( r.value ).toBe( 'ab%x%' );
		expect( r.cursor ).toBe( 5 );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- cursor.test.js`
Expected: FAIL — cannot find module `./cursor`.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Insert a token into a string at the caret/selection, returning the new value
 * and caret position. Pure — no DOM access.
 *
 * @param {string} value Current field value.
 * @param {string} token Token to insert.
 * @param {number} start Selection start (defaults to end of value).
 * @param {number} end   Selection end (defaults to start).
 * @return {{ value: string, cursor: number }}
 */
export function insertAtCursor( value, token, start, end ) {
	const len = value.length;
	const s = Math.max( 0, Math.min( start ?? len, len ) );
	const e = Math.max( s, Math.min( end ?? s, len ) );
	const next = value.slice( 0, s ) + token + value.slice( e );
	return { value: next, cursor: s + token.length };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- cursor.test.js`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/cursor.js assets/src/admin/cursor.test.js
git commit -m "feat(admin): add insertAtCursor helper"
```

---

## Task 5: `VariableInserter` component

**Files:**
- Create: `assets/src/admin/components/VariableInserter.js`

**Interfaces:**
- Consumes: `variablesForScope`, `filterVariables` (Task 3); `@wordpress/components` `Button`/`Dropdown`/`SearchControl`; `@wordpress/element` `useState`; `@wordpress/i18n` `__`.
- Produces: `VariableInserter({ catalog, scope, onInsert })`.

- [ ] **Step 1: Write the component**

```jsx
import { Button, Dropdown, SearchControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { variablesForScope, filterVariables } from '../variables';

export function VariableInserter( { catalog, scope, onInsert } ) {
	const [ query, setQuery ] = useState( '' );
	const scoped = variablesForScope( catalog, scope );
	const list = filterVariables( scoped, query );

	return (
		<Dropdown
			className="openseo-var-inserter"
			popoverProps={ { placement: 'bottom-end' } }
			onClose={ () => setQuery( '' ) }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="secondary"
					size="small"
					icon="insert"
					label={ __( 'Insert variable', 'openseo' ) }
					aria-expanded={ isOpen }
					onClick={ onToggle }
				/>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="openseo-var-inserter__panel">
					<SearchControl
						__nextHasNoMarginBottom
						value={ query }
						onChange={ setQuery }
						label={ __( 'Search variables', 'openseo' ) }
					/>
					{ scoped.length === 0 && (
						<p className="openseo-var-inserter__empty">
							{ __( 'No variables', 'openseo' ) }
						</p>
					) }
					{ scoped.length > 0 && list.length === 0 && (
						<p className="openseo-var-inserter__empty">
							{ __( 'No results', 'openseo' ) }
						</p>
					) }
					<ul className="openseo-var-inserter__list">
						{ list.map( ( v ) => (
							<li key={ v.token }>
								<Button
									variant="tertiary"
									className="openseo-var-inserter__item"
									onClick={ () => {
										onInsert( v.token );
										onClose();
									} }
								>
									<span className="openseo-var-inserter__token">
										{ v.token }
									</span>
									<span className="openseo-var-inserter__label">
										{ v.label }
									</span>
								</Button>
							</li>
						) ) }
					</ul>
				</div>
			) }
		/>
	);
}
```

> `Dropdown` focuses the first tabbable (the `SearchControl`) on open and closes on Esc by default; `aria-expanded` is wired on the toggle. Variables are `<button>` (keyboard-native).

- [ ] **Step 2: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/VariableInserter.js
git commit -m "feat(admin): add VariableInserter dropdown"
```

---

## Task 6: `TemplateField` component (native input + cursor insertion)

**Files:**
- Create: `assets/src/admin/components/TemplateField.js`

**Interfaces:**
- Consumes: `VariableInserter` (Task 5); `insertAtCursor` (Task 4); `@wordpress/element` `useRef`/`useEffect`; `@wordpress/compose` `useInstanceId`.
- Produces: `TemplateField({ label, value, placeholder, multiline, scope, catalog, onChange })` — `onChange(value)` is presentational; insertion happens at the caret.

- [ ] **Step 1: Write the component**

```jsx
import { useRef, useEffect } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { VariableInserter } from './VariableInserter';
import { insertAtCursor } from '../cursor';

export function TemplateField( {
	label,
	value,
	placeholder,
	multiline,
	scope,
	catalog,
	onChange,
} ) {
	const inputRef = useRef( null );
	const pendingCursor = useRef( null );
	const instanceId = useInstanceId( TemplateField );
	const fieldId = `openseo-tf-${ instanceId }`;

	useEffect( () => {
		if ( pendingCursor.current !== null && inputRef.current ) {
			const pos = pendingCursor.current;
			inputRef.current.focus();
			inputRef.current.setSelectionRange( pos, pos );
			pendingCursor.current = null;
		}
	} );

	const handleInsert = ( token ) => {
		const el = inputRef.current;
		const start = el ? el.selectionStart : value.length;
		const end = el ? el.selectionEnd : value.length;
		const result = insertAtCursor( value, token, start, end );
		pendingCursor.current = result.cursor;
		onChange( result.value );
	};

	const sharedProps = {
		id: fieldId,
		ref: inputRef,
		className: 'components-text-control__input',
		value,
		placeholder,
		onChange: ( e ) => onChange( e.target.value ),
	};

	return (
		<div className="openseo-template-field">
			<div className="openseo-template-field__header">
				<label
					htmlFor={ fieldId }
					className="openseo-template-field__label"
				>
					{ label }
				</label>
				<VariableInserter
					catalog={ catalog }
					scope={ scope }
					onInsert={ handleInsert }
				/>
			</div>
			{ multiline ? (
				<textarea { ...sharedProps } rows={ 3 } />
			) : (
				<input type="text" { ...sharedProps } />
			) }
		</div>
	);
}
```

> The `useEffect` runs every render but only restores the caret when `pendingCursor.current` is set (i.e. right after an insertion), so normal typing is unaffected.

- [ ] **Step 2: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/TemplateField.js
git commit -m "feat(admin): add TemplateField with caret-position variable insertion"
```

---

## Task 7: `VerticalTabs` component (ARIA tablist + keyboard)

**Files:**
- Create: `assets/src/admin/components/VerticalTabs.js`

**Interfaces:**
- Consumes: `@wordpress/element` `useRef`.
- Produces: `VerticalTabs({ groups, active, onSelect, children })` where `groups` is `[{ label?, tabs:[{name,title}] }]` and `children(active)` renders the panel.

- [ ] **Step 1: Write the component**

```jsx
import { useRef } from '@wordpress/element';

export function VerticalTabs( { groups, active, onSelect, children } ) {
	const allTabs = groups.flatMap( ( g ) => g.tabs );
	const tabRefs = useRef( {} );

	const onKeyDown = ( e ) => {
		const idx = allTabs.findIndex( ( t ) => t.name === active );
		if ( idx < 0 ) {
			return;
		}
		const count = allTabs.length;
		let next = null;
		if ( e.key === 'ArrowDown' ) {
			next = ( idx + 1 ) % count;
		} else if ( e.key === 'ArrowUp' ) {
			next = ( idx - 1 + count ) % count;
		} else if ( e.key === 'Home' ) {
			next = 0;
		} else if ( e.key === 'End' ) {
			next = count - 1;
		}
		if ( next !== null ) {
			e.preventDefault();
			const name = allTabs[ next ].name;
			onSelect( name );
			tabRefs.current[ name ]?.focus();
		}
	};

	return (
		<div className="openseo-vtabs">
			<div
				className="openseo-vtabs__nav"
				role="tablist"
				aria-orientation="vertical"
				onKeyDown={ onKeyDown }
			>
				{ groups.map( ( group ) => (
					<div
						key={ group.label ?? 'general' }
						className="openseo-vtabs__group"
					>
						{ group.label && (
							<div
								className="openseo-vtabs__group-label"
								role="presentation"
							>
								{ group.label }
							</div>
						) }
						{ group.tabs.map( ( tab ) => {
							const selected = tab.name === active;
							return (
								<button
									key={ tab.name }
									ref={ ( el ) => {
										tabRefs.current[ tab.name ] = el;
									} }
									type="button"
									role="tab"
									id={ `openseo-tab-${ tab.name }` }
									aria-selected={ selected }
									aria-controls={ `openseo-panel-${ tab.name }` }
									tabIndex={ selected ? 0 : -1 }
									className={ `openseo-vtabs__tab${
										selected ? ' is-active' : ''
									}` }
									onClick={ () => onSelect( tab.name ) }
								>
									{ tab.title }
								</button>
							);
						} ) }
					</div>
				) ) }
			</div>
			<div
				className="openseo-vtabs__panel"
				role="tabpanel"
				id={ `openseo-panel-${ active }` }
				aria-labelledby={ `openseo-tab-${ active }` }
				tabIndex={ 0 }
			>
				{ children( active ) }
			</div>
		</div>
	);
}
```

- [ ] **Step 2: Lint**

Run: `npm run lint:js`
Expected: No ESLint errors.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/VerticalTabs.js
git commit -m "feat(admin): add accessible VerticalTabs component"
```

---

## Task 8: Reescribir `views/Titles.js` + estilos + eliminar `TemplateGroup`

**Files:**
- Modify: `assets/src/admin/views/Titles.js` (full rewrite)
- Modify: `assets/src/admin/style.scss` (append styles)
- Delete: `assets/src/admin/components/TemplateGroup.js`

**Interfaces:**
- Consumes: `VerticalTabs` (Task 7), `TemplateField` (Task 6), `setTemplateField` (existing `templateFields.js`), `SettingsPanel` (existing), `window.openseoAdmin.contentTypes`/`variables` (Task 2).

- [ ] **Step 1: Rewrite `views/Titles.js`**

Replace the entire contents of `assets/src/admin/views/Titles.js` with:

```jsx
import { TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';
import { VerticalTabs } from '../components/VerticalTabs';
import { TemplateField } from '../components/TemplateField';
import { setTemplateField } from '../templateFields';

const bootstrap = window.openseoAdmin ?? {};
const contentTypes = bootstrap.contentTypes ?? { postTypes: [], taxonomies: [] };
const catalog = bootstrap.variables ?? [];

const GROUPS = [
	{ tabs: [ { name: 'general', title: __( 'General', 'openseo' ) } ] },
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

function GeneralPanel( { values, change } ) {
	return (
		<>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Title separator', 'openseo' ) }
				value={ values.title_separator }
				onChange={ ( v ) => change( 'title_separator', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage title', 'openseo' ) }
				value={ values.home_title }
				scope="global"
				catalog={ catalog }
				onChange={ ( v ) => change( 'home_title', v ) }
			/>
			<TemplateField
				label={ __( 'Homepage description', 'openseo' ) }
				value={ values.home_description }
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
					change( mapKey, setTemplateField( map, type.slug, 'title', v ) )
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
	return <GeneralPanel values={ values } change={ change } />;
}

export function Titles() {
	const [ active, setActive ] = useState( 'general' );
	const current = TAB_NAMES.includes( active ) ? active : 'general';

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

- [ ] **Step 2: Delete the obsolete `TemplateGroup`**

Run: `git rm assets/src/admin/components/TemplateGroup.js`
(Its only importer was `Titles.js`, now rewritten.)

- [ ] **Step 3: Append styles to `assets/src/admin/style.scss`**

Append:

```scss
.openseo-vtabs {
	display: flex;
	gap: 24px;
	align-items: flex-start;

	&__nav {
		flex: 0 0 200px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__group-label {
		margin: 12px 0 4px;
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		color: #757575;
	}

	&__tab {
		text-align: left;
		padding: 8px 12px;
		border: 0;
		border-radius: 4px;
		background: transparent;
		cursor: pointer;
		color: #1e1e1e;

		&:hover {
			background: #f0f0f0;
		}

		&.is-active {
			background: #e6f0f7;
			box-shadow: inset 3px 0 0 #3858e9;
			font-weight: 600;
		}
	}

	&__panel {
		flex: 1 1 auto;
		min-width: 0;
		/* No overflow:hidden here — the inserter Popover must escape this box. */
	}
}

.openseo-template-field {
	margin-bottom: 16px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		margin-bottom: 4px;
	}

	&__label {
		font-weight: 600;
	}
}

.openseo-var-inserter__panel {
	width: 280px;
	max-height: 320px;
	overflow-y: auto;
	padding: 8px;
}

.openseo-var-inserter__list {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.openseo-var-inserter__item {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 100%;
	height: auto;
	padding: 6px 8px;
}

.openseo-var-inserter__token {
	font-family: monospace;
	color: #3858e9;
}

.openseo-var-inserter__label {
	font-size: 11px;
	color: #757575;
}

.openseo-var-inserter__empty {
	margin: 8px 0 0;
	color: #757575;
}
```

- [ ] **Step 4: Lint, test, build**

Run: `npm run lint:js`
Expected: No ESLint errors.

Run: `npm run test:js`
Expected: All suites pass (variables, cursor, plus existing).

Run: `npm run build`
Expected: Build succeeds (no missing-import error from the deleted `TemplateGroup`).

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/views/Titles.js assets/src/admin/style.scss
git commit -m "feat(admin): tabbed Titles & Meta view with variable inserter"
```

---

## Task 9: Verificación final completa

**Files:** none (verification only).

- [ ] **Step 1: PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green (incl. `VariableCatalogTest`).

- [ ] **Step 2: JS gates**

Run: `npm run lint:js`
Expected: No errors.

Run: `npm run test:js`
Expected: All suites pass (incl. `variables`, `cursor`).

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Regenerate the translation template (`.pot`)**

This task added new translatable strings (catalog labels in PHP + UI strings in JS), so refresh the
`.pot` per the spec (requires wp-env running; not a CI gate):

```bash
npm run env:start
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo languages/openseo.pot
```
Commit the updated `languages/openseo.pot` if it changed:
```bash
git add languages/openseo.pot
git commit -m "chore(i18n): regenerate .pot for tabbed Titles & Meta strings"
```
(If wp-env is unavailable in this environment, note it as a deferred follow-up rather than skipping silently.)

- [ ] **Step 4: Manual smoke test (wp-env, recommended)**

```bash
npm run env:start
```
In wp-admin → **OpenSEO → Titles & Meta**: vertical tabs (General + content types + taxonomies); switch tabs with click and ↑/↓/Home/End; on a content-type tab, open the variable inserter, search, and insert a token at the caret (mid-text); confirm taxonomy tabs offer `%term%`/`%term_description%` and General only globals; save and reload → values persist.

- [ ] **Step 5: Final commit (only if build artifacts/fixes remain)**

```bash
git add -A
git commit -m "chore(admin): final verification for tabbed Titles & Meta"
```

(Skip if nothing to commit.)

---

## Self-Review (completed during planning)

- **Spec coverage:** catálogo + scope (Task 1), bootstrap (Task 2), filtros puros (Task 3), inserción en cursor pura (Task 4), inserter con ARIA/foco (Task 5), TemplateField input nativo + guarda de cursor (Task 6), VerticalTabs ARIA/teclado (Task 7), reescritura por pestañas + eliminación de TemplateGroup + estilos (Task 8), anti-drift (Task 1). Todos los criterios de aceptación tienen tarea.
- **Placeholder scan:** sin TBD/TODO; cada paso de código muestra el código y el comando con salida esperada.
- **Type/símbolo consistency:** `variablesForScope`/`filterVariables` (3) usados en `VariableInserter` (5); `insertAtCursor` (4) en `TemplateField` (6); `VariableCatalog::all` (1) en `Assets` (2); `VerticalTabs`/`TemplateField`/`setTemplateField` en `Titles.js` (8); bootstrap key `variables` producida en Task 2, consumida en Task 8.
- **Verde por commit:** los componentes nuevos (Tasks 5-7) no se importan hasta Task 8, así que no rompen el build intermedio; `TemplateGroup` se elimina en el mismo commit (Task 8) que reescribe su único consumidor (`Titles.js`). El cambio de constructor de `Assets` (Task 2) actualiza su único call site (`Plugin.php`); no hay test de `Assets` que migrar.
- **Auditoría del diseño incorporada:** input nativo + `useRef` (H1), ARIA/teclado de tabs (H2) e inserter (H3), guarda `pendingCursorRef` (M1), sin overflow en el ancestro del popover (M2/§style), anti-drift por "no contiene token" (M3), General incondicional + guard `current` (M4), catálogo por prop (L4), búsqueda vacía → todas (L2), `onChange(value)` presentacional (L1).
- **Auditoría del plan (wp-plan-reviewer):** C1 ("Plugin.php:179 pasa 4 args") = **falso positivo**, verificado contra el código real — la línea pasa 6 args (`…, new ContentTypes(), new TemplateDefaults()`), justo lo que Task 2 Step 3 busca para reemplazar por 7. Incorporados: M1 (regenerar `languages/openseo.pot` en Task 9), L1 (`__nextHasNoMarginBottom` en el `TextControl` de Title separator). El resto fueron confirmaciones sin acción.
