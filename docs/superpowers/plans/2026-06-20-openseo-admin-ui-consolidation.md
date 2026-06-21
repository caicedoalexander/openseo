# OpenSEO Admin UI Consolidation (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar la página de ajustes con 7 tabs y el gestor bajo *Herramientas* por un **menú top-level propio** con un submenú WP por sección, con las vistas de ajustes en **React** sobre un REST propio, reubicando Redirecciones/404 (PHP) bajo el mismo menú.

**Architecture:** PHP registra el menú (`Admin\Menu`, único registrador) y un controlador REST (`Rest\SettingsController`) que reutiliza `Options::sanitize()`. Las páginas de ajustes montan una app React (`assets/src/admin`) que lee el estado inicial de `window.openseoAdmin` y guarda por `apiFetch`. Los toggles de redirec./404 sobreviven como mini-form Settings API (`Settings\BehaviorSettings`) en sus páginas PHP reubicadas.

**Tech Stack:** PHP 8.1+ (PSR-4 `OpenSEO\` → `src/`), `@wordpress/scripts` (React via `@wordpress/element`), `@wordpress/components`, `@wordpress/api-fetch`, WordPress REST API, Settings API (solo toggles), PHPUnit (Brain Monkey unit + WP integration), Jest (wp-scripts).

**Spec:** `docs/superpowers/specs/2026-06-20-openseo-admin-ui-consolidation-design.md`

## Global Constraints

- **Versiones objetivo:** WordPress 7.0+ · PHP 8.1+.
- **Prefijos globales obligatorios** (enforced por PHPCS): `openseo` / `OpenSEO` / `OPENSEO`; text domain `openseo`. PSR-4 file naming.
- **Una sola option key:** `Options::OPTION_KEY = 'openseo_settings'`; toda escritura pasa por `Options::sanitize()`.
- **Seguridad no negociable:** sanitizar en entrada, escapar en salida; `current_user_can('manage_options')` + nonce en toda acción de estado; nunca procesar `$_POST`/`$_GET` completos (leer claves explícitas con `wp_unslash`).
- **Capability de todas las pantallas:** `manage_options`.
- **`declare( strict_types=1 );`** en todo PHP nuevo; tipos de parámetro y retorno explícitos (PHPStan nivel 6, `--memory-limit=1G`).
- **Gates verdes antes de cerrar:** `composer check` (PHPCS + PHPStan + PHPUnit unit), `npm run lint:js`, `npm run lint:css`, `npm run build`; integración vía wp-env.
- **Sin código en carga:** los módulos son `Contracts\Hookable` y se registran en `Plugin::modules()`.
- **REST fuera de `is_admin()`:** `rest_api_init` no corre en contexto admin; el controlador REST se registra en la lista de módulos siempre-activa, NO dentro del bloque `is_admin()`.

---

## Task 1: Controlador REST `openseo/v1/settings`

**Files:**
- Create: `src/Rest/SettingsController.php`
- Test (unit): `tests/Unit/Rest/SettingsControllerTest.php`
- Test (integración): `tests/Integration/RestSettingsTest.php`

**Interfaces:**
- Consumes: `OpenSEO\Settings\Options` (`all(): array<string,mixed>`, `sanitize(mixed): array<string,mixed>`, `Options::OPTION_KEY`).
- Produces: `OpenSEO\Rest\SettingsController` con `register(): void`, `register_routes(): void`, `check_permission(): bool`, `get_settings(): WP_REST_Response`, `update_settings(WP_REST_Request): WP_REST_Response`. Constantes `NAMESPACE = 'openseo/v1'`, `ROUTE = '/settings'`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Rest/SettingsControllerTest.php`:

```php
<?php
/**
 * Unit tests for the settings REST controller permission gate.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Rest\SettingsController;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_check_permission_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( string $cap ): bool => 'manage_options' === $cap
		);

		$controller = new SettingsController( new Options() );

		$this->assertTrue( $controller->check_permission() );
	}

	public function test_check_permission_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$controller = new SettingsController( new Options() );

		$this->assertFalse( $controller->check_permission() );
	}
}
```

- [ ] **Step 2: Run the unit test to verify it fails**

Run: `vendor/bin/phpunit --filter test_check_permission_requires_manage_options`
Expected: FAIL — `Class "OpenSEO\Rest\SettingsController" not found`.

- [ ] **Step 3: Write the controller**

Create `src/Rest/SettingsController.php`:

```php
<?php
/**
 * REST controller for the single OpenSEO settings option.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Rest;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes GET/POST for `openseo_settings`. Writes route through Options::sanitize(),
 * which merges over the stored values (partial updates preserve unsent keys) and
 * drops unknown keys. Auth: manage_options; the nonce is supplied by apiFetch's
 * automatic X-WP-Nonce middleware in wp-admin.
 */
final class SettingsController implements Hookable {

	public const REST_NAMESPACE = 'openseo/v1';

	public const ROUTE = '/settings';

	/**
	 * @param Options $options Typed settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register the REST routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the GET/POST routes for the settings option.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Capability gate for both routes.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return the full, merged settings array.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->options->all(), 200 );
	}

	/**
	 * Sanitize the (partial) body, persist, and return the merged result.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$input = $request->get_json_params();

		// Options::sanitize() wp_unslash()es internally to match the Settings API
		// ($_POST) path, where WP slashes input. REST JSON bodies are NOT slashed,
		// so slash first to keep wp_unslash() a no-op and preserve literal backslashes.
		$clean = $this->options->sanitize( wp_slash( is_array( $input ) ? $input : array() ) );

		update_option( Options::OPTION_KEY, $clean );

		return new WP_REST_Response( $this->options->all(), 200 );
	}
}
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `vendor/bin/phpunit --filter test_check_permission`
Expected: PASS (both permission tests).

- [ ] **Step 5: Write the failing integration test**

Create `tests/Integration/RestSettingsTest.php`:

```php
<?php
/**
 * Integration tests for the settings REST endpoint.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Rest\SettingsController;
use OpenSEO\Settings\Options;
use WP_REST_Request;
use WP_UnitTestCase;

final class RestSettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_action(
			'rest_api_init',
			static function (): void {
				( new SettingsController( new Options() ) )->register_routes();
			}
		);
	}

	public function test_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/openseo/v1/settings', $routes );
	}

	public function test_get_denied_for_anonymous(): void {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/openseo/v1/settings' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_partial_post_preserves_other_keys(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Options::OPTION_KEY, ( new Options() )->defaults() );

		$request = new WP_REST_Request( 'POST', '/openseo/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title_separator' => '|' ) ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '|', $data['title_separator'] );
		// Unsent key keeps its value instead of resetting.
		$this->assertSame( '%title% %sep% %sitename%', $data['title_template'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/openseo/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'bogus_key' => 'x' ) ) );

		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertArrayNotHasKey( 'bogus_key', $data );
	}

	public function test_empty_body_does_not_fatal(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request  = new WP_REST_Request( 'POST', '/openseo/v1/settings' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_backslash_values_are_preserved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/openseo/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		// JSON "a\\b" decodes to the PHP string a\b (one backslash).
		$request->set_body( wp_json_encode( array( 'title_separator' => 'a\\b' ) ) );

		$data = rest_get_server()->dispatch( $request )->get_data();

		// Without wp_slash() before sanitize(), wp_unslash() would strip the backslash.
		$this->assertSame( 'a\\b', $data['title_separator'] );
	}
}
```

- [ ] **Step 6: Run the integration test to verify it passes**

Run (wp-env must be up — `npm run env:start`):
`npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RestSettingsTest`
Expected: PASS (6 tests). If `401` differs in your WP build, accept `rest_authorization_required_code()`'s value, but on a fresh wp-env it is 401.

- [ ] **Step 7: Commit**

```bash
git add src/Rest/SettingsController.php tests/Unit/Rest/SettingsControllerTest.php tests/Integration/RestSettingsTest.php
git commit -m "feat(rest): add openseo/v1/settings controller (GET/POST)"
```

---

## Task 2: `Settings\BehaviorSettings` (toggles redirec./404 vía Settings API)

**Files:**
- Create: `src/Settings/BehaviorSettings.php`
- Test (integración): `tests/Integration/BehaviorSettingsTest.php`

**Interfaces:**
- Consumes: `Options` (`OPTION_GROUP`, `OPTION_KEY`, `defaults()`, `sanitize()`, `get()`).
- Produces: `OpenSEO\Settings\BehaviorSettings` con `register(): void`, `register_settings(): void`, `render_redirects_form(): void`, `render_notfound_form(): void`. Secciones `openseo_redirects` y `openseo_notfound`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/BehaviorSettingsTest.php`:

```php
<?php
/**
 * Integration tests for the redirect/404 behavior settings registration.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Settings\BehaviorSettings;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class BehaviorSettingsTest extends WP_UnitTestCase {

	public function test_option_is_registered_with_sanitizer(): void {
		( new BehaviorSettings( new Options() ) )->register_settings();

		$this->assertArrayHasKey( Options::OPTION_KEY, get_registered_settings() );
	}

	public function test_redirect_and_notfound_fields_register(): void {
		global $wp_settings_fields;

		( new BehaviorSettings( new Options() ) )->register_settings();

		$redirects = $wp_settings_fields['openseo_redirects']['openseo_redirects'] ?? array();
		$notfound  = $wp_settings_fields['openseo_notfound']['openseo_notfound'] ?? array();

		$this->assertArrayHasKey( 'redirects_auto_slug', $redirects );
		$this->assertArrayHasKey( 'redirects_default_status', $redirects );
		$this->assertArrayHasKey( 'notfound_monitor_enabled', $notfound );
		$this->assertArrayHasKey( 'notfound_retention_days', $notfound );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BehaviorSettingsTest`
Expected: FAIL — `Class "OpenSEO\Settings\BehaviorSettings" not found`.

- [ ] **Step 3: Write the class (field helpers ported from the deleted SettingsPage)**

Create `src/Settings/BehaviorSettings.php`:

```php
<?php
/**
 * Settings-API registration for the redirect/404 behavior toggles.
 *
 * These five keys live on the PHP Redirects/404 pages in Phase 1 (Phase 2 moves
 * them into React views). register_setting() keeps options.php working for them;
 * all writes still flow through Options::sanitize().
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Settings;

use OpenSEO\Contracts\Hookable;

/**
 * Registers the option + the redirect/404 toggle sections, and renders each
 * section as a small options.php form on its page.
 */
final class BehaviorSettings implements Hookable {

	/**
	 * @param Options $options Typed settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register settings on admin_init.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the option, the two sections, and their fields.
	 */
	public function register_settings(): void {
		register_setting(
			Options::OPTION_GROUP,
			Options::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->options, 'sanitize' ),
				'default'           => $this->options->defaults(),
			)
		);

		add_settings_section( 'openseo_redirects', '', '__return_false', 'openseo_redirects' );
		$this->add_checkbox_field( 'redirects_auto_slug', __( 'Auto-redirect on slug change', 'openseo' ), 'openseo_redirects' );
		$this->add_checkbox_field( 'redirects_track_hits', __( 'Track redirect hits', 'openseo' ), 'openseo_redirects' );
		$this->add_select_field(
			'redirects_default_status',
			__( 'Default redirect type', 'openseo' ),
			'openseo_redirects',
			array(
				'301' => __( '301 — Permanent', 'openseo' ),
				'302' => __( '302 — Temporary', 'openseo' ),
				'307' => __( '307 — Temporary (preserve method)', 'openseo' ),
			)
		);

		add_settings_section( 'openseo_notfound', '', '__return_false', 'openseo_notfound' );
		$this->add_checkbox_field( 'notfound_monitor_enabled', __( 'Enable 404 monitor', 'openseo' ), 'openseo_notfound' );
		$this->add_text_field( 'notfound_retention_days', __( '404 retention (days)', 'openseo' ), 'openseo_notfound' );
	}

	/**
	 * Render the redirects toggle form (options.php).
	 */
	public function render_redirects_form(): void {
		$this->render_form( 'openseo_redirects' );
	}

	/**
	 * Render the 404 toggle form (options.php).
	 */
	public function render_notfound_form(): void {
		$this->render_form( 'openseo_notfound' );
	}

	/**
	 * Render one section as a self-contained options.php form.
	 *
	 * @param string $section Section id (also the do_settings_sections page).
	 */
	private function render_form( string $section ): void {
		echo '<form action="options.php" method="post">';
		settings_fields( Options::OPTION_GROUP );
		do_settings_sections( $section );
		submit_button();
		echo '</form>';
	}

	/**
	 * Register one text field bound to a single option key.
	 *
	 * @param string $key     Option key.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 */
	private function add_text_field( string $key, string $label, string $section ): void {
		add_settings_field(
			$key,
			$label,
			function () use ( $key ): void {
				printf(
					'<input type="text" id="openseo_%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" />',
					esc_attr( $key ),
					esc_attr( Options::OPTION_KEY ),
					esc_attr( (string) $this->options->get( $key ) )
				);
			},
			$section,
			$section,
			array( 'label_for' => 'openseo_' . $key )
		);
	}

	/**
	 * Register one checkbox field (hidden companion guarantees the key is posted).
	 *
	 * @param string $key     Option key.
	 * @param string $label   Field label.
	 * @param string $section Section id.
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

	/**
	 * Register one select field bound to a single option key.
	 *
	 * @param string                   $key     Option key.
	 * @param string                   $label   Field label.
	 * @param string                   $section Section id.
	 * @param array<array-key, string> $choices value => label map.
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
						esc_attr( (string) $value ),
						selected( $current, (string) $value, false ),
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
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BehaviorSettingsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Settings/BehaviorSettings.php tests/Integration/BehaviorSettingsTest.php
git commit -m "feat(settings): add BehaviorSettings for redirect/404 toggles"
```

---

## Task 3: App React de admin (`assets/src/admin`)

**Files:**
- Create: `assets/src/admin/api.js`
- Create: `assets/src/admin/hooks/useSettings.js`
- Create (test): `assets/src/admin/hooks/useSettings.test.js`
- Create: `assets/src/admin/components/SaveBar.js`
- Create: `assets/src/admin/components/SettingsPanel.js`
- Create: `assets/src/admin/views/Dashboard.js`, `General.js`, `Titles.js`, `Social.js`, `Sitemaps.js`, `Schema.js`, `Ai.js`
- Create: `assets/src/admin/App.js`
- Modify (replace): `assets/src/admin/index.js`
- Modify (replace): `assets/src/admin/style.scss`

**Interfaces:**
- Consumes: `window.openseoAdmin` (`{ settings, connector:{available,url}, dashboard:{redirects,notfound} }`, provista por Task 5 Assets) y la ruta REST `openseo/v1/settings` (Task 1).
- Produces: bundle de salida `assets/build/admin-settings.js` montado en `#openseo-app[data-view]`. Export `settingsReducer(state, action)` (puro) y `useSettings(initial)`.

- [ ] **Step 1: Write the failing JS unit test for the reducer**

Create `assets/src/admin/hooks/useSettings.test.js`:

```js
import { settingsReducer } from './useSettings';

describe( 'settingsReducer', () => {
	const base = { values: { a: '1' }, dirty: false, saving: false, error: '' };

	it( 'updates a value and marks dirty on CHANGE', () => {
		const next = settingsReducer( base, { type: 'CHANGE', key: 'a', value: '2' } );
		expect( next.values.a ).toBe( '2' );
		expect( next.dirty ).toBe( true );
	} );

	it( 'sets saving on SAVING', () => {
		const next = settingsReducer( base, { type: 'SAVING' } );
		expect( next.saving ).toBe( true );
	} );

	it( 'replaces values and clears flags on SAVED', () => {
		const next = settingsReducer(
			{ values: {}, dirty: true, saving: true, error: 'x' },
			{ type: 'SAVED', values: { a: '9' } }
		);
		expect( next.values.a ).toBe( '9' );
		expect( next.dirty ).toBe( false );
		expect( next.saving ).toBe( false );
	} );

	it( 'records an error and stops saving on ERROR', () => {
		const next = settingsReducer( { ...base, saving: true }, { type: 'ERROR', error: 'boom' } );
		expect( next.error ).toBe( 'boom' );
		expect( next.saving ).toBe( false );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js -- --testPathPattern=useSettings`
Expected: FAIL — cannot find module `./useSettings`.

- [ ] **Step 3: Write `api.js`**

Create `assets/src/admin/api.js`:

```js
/**
 * REST client for the OpenSEO settings option.
 *
 * Relative paths let apiFetch's automatic root-URL + X-WP-Nonce middleware
 * (active in wp-admin via the wp-api-fetch dependency) handle auth.
 */
import apiFetch from '@wordpress/api-fetch';

export function getSettings() {
	return apiFetch( { path: '/openseo/v1/settings' } );
}

export function saveSettings( values ) {
	return apiFetch( { path: '/openseo/v1/settings', method: 'POST', data: values } );
}
```

- [ ] **Step 4: Write `hooks/useSettings.js`**

Create `assets/src/admin/hooks/useSettings.js`:

```js
/**
 * Settings state: local values, dirty tracking, and save via REST.
 */
import { useReducer, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { saveSettings } from '../api';

export function settingsReducer( state, action ) {
	switch ( action.type ) {
		case 'CHANGE':
			return {
				...state,
				values: { ...state.values, [ action.key ]: action.value },
				dirty: true,
				error: '',
			};
		case 'SAVING':
			return { ...state, saving: true, error: '' };
		case 'SAVED':
			return { values: action.values, dirty: false, saving: false, error: '' };
		case 'ERROR':
			return { ...state, saving: false, error: action.error };
		default:
			return state;
	}
}

export function useSettings( initial ) {
	const [ state, dispatch ] = useReducer( settingsReducer, {
		values: initial,
		dirty: false,
		saving: false,
		error: '',
	} );

	const change = useCallback(
		( key, value ) => dispatch( { type: 'CHANGE', key, value } ),
		[]
	);

	const save = useCallback( async () => {
		dispatch( { type: 'SAVING' } );
		try {
			const values = await saveSettings( state.values );
			dispatch( { type: 'SAVED', values } );
		} catch ( e ) {
			dispatch( {
				type: 'ERROR',
				error: e?.message || __( 'Could not save settings.', 'openseo' ),
			} );
		}
	}, [ state.values ] );

	return {
		values: state.values,
		dirty: state.dirty,
		saving: state.saving,
		error: state.error,
		change,
		save,
	};
}
```

- [ ] **Step 5: Run the reducer test to verify it passes**

Run: `npm run test:js -- --testPathPattern=useSettings`
Expected: PASS (4 tests).

- [ ] **Step 6: Write the shared components**

Create `assets/src/admin/components/SaveBar.js`:

```js
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function SaveBar( { dirty, saving, error, onSave } ) {
	return (
		<div className="openseo-savebar">
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			<Button
				variant="primary"
				isBusy={ saving }
				disabled={ ! dirty || saving }
				onClick={ onSave }
			>
				{ saving ? __( 'Saving…', 'openseo' ) : __( 'Save changes', 'openseo' ) }
			</Button>
		</div>
	);
}
```

Create `assets/src/admin/components/SettingsPanel.js`:

```js
import { useSettings } from '../hooks/useSettings';
import { SaveBar } from './SaveBar';

export function SettingsPanel( { children } ) {
	const settings = useSettings( window.openseoAdmin?.settings ?? {} );

	return (
		<div className="openseo-panel">
			<div className="openseo-panel__fields">
				{ children( settings ) }
			</div>
			<SaveBar
				dirty={ settings.dirty }
				saving={ settings.saving }
				error={ settings.error }
				onSave={ settings.save }
			/>
		</div>
	);
}
```

- [ ] **Step 7: Write the views**

Create `assets/src/admin/views/General.js`:

```js
import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function General() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<SelectControl
						label={ __( 'Site represents', 'openseo' ) }
						value={ values.schema_site_type }
						options={ [
							{ label: __( 'Organization', 'openseo' ), value: 'Organization' },
							{ label: __( 'Person', 'openseo' ), value: 'Person' },
						] }
						onChange={ ( v ) => change( 'schema_site_type', v ) }
					/>
					<TextControl
						label={ __( 'Name (defaults to site name)', 'openseo' ) }
						value={ values.schema_site_name }
						onChange={ ( v ) => change( 'schema_site_name', v ) }
					/>
					<TextControl
						label={ __( 'Logo / image URL', 'openseo' ) }
						value={ values.schema_logo }
						onChange={ ( v ) => change( 'schema_logo', v ) }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
```

Create `assets/src/admin/views/Titles.js`:

```js
import { TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

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
						label={ __( 'Default title template', 'openseo' ) }
						value={ values.title_template }
						onChange={ ( v ) => change( 'title_template', v ) }
					/>
					<TextareaControl
						label={ __( 'Default description template', 'openseo' ) }
						value={ values.description_template }
						onChange={ ( v ) => change( 'description_template', v ) }
					/>
					<TextControl
						label={ __( 'Homepage title', 'openseo' ) }
						value={ values.home_title }
						onChange={ ( v ) => change( 'home_title', v ) }
					/>
					<TextareaControl
						label={ __( 'Homepage description', 'openseo' ) }
						value={ values.home_description }
						onChange={ ( v ) => change( 'home_description', v ) }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
```

Create `assets/src/admin/views/Social.js`:

```js
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Social() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<TextControl
					label={ __( 'Default social image URL', 'openseo' ) }
					value={ values.og_default_image }
					onChange={ ( v ) => change( 'og_default_image', v ) }
				/>
			) }
		</SettingsPanel>
	);
}
```

Create `assets/src/admin/views/Sitemaps.js`:

```js
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Sitemaps() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<ToggleControl
						label={ __( 'Enable XML sitemap', 'openseo' ) }
						checked={ values.sitemap_enabled === '1' }
						onChange={ ( on ) => change( 'sitemap_enabled', on ? '1' : '' ) }
					/>
					<ToggleControl
						label={ __( 'Include author sitemap', 'openseo' ) }
						checked={ values.sitemap_include_authors === '1' }
						onChange={ ( on ) => change( 'sitemap_include_authors', on ? '1' : '' ) }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
```

Create `assets/src/admin/views/Schema.js`:

```js
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Schema() {
	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<TextControl
					label={ __( 'Breadcrumb separator', 'openseo' ) }
					value={ values.breadcrumb_separator }
					onChange={ ( v ) => change( 'breadcrumb_separator', v ) }
				/>
			) }
		</SettingsPanel>
	);
}
```

Create `assets/src/admin/views/Ai.js`:

```js
import { TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SettingsPanel } from '../components/SettingsPanel';

export function Ai() {
	const connector = window.openseoAdmin?.connector ?? { available: false, url: '' };

	return (
		<SettingsPanel>
			{ ( { values, change } ) => (
				<>
					<Notice
						status={ connector.available ? 'success' : 'warning' }
						isDismissible={ false }
					>
						{ connector.available
							? __(
									'An AI connector is configured. The editor can generate titles and descriptions.',
									'openseo'
							  )
							: (
									<>
										{ __( 'No AI connector is configured.', 'openseo' ) }{ ' ' }
										<a href={ connector.url }>
											{ __( 'Settings → Connectors', 'openseo' ) }
										</a>
									</>
							  ) }
					</Notice>
					<TextControl
						label={ __( 'AI model (optional override)', 'openseo' ) }
						value={ values.ai_model }
						onChange={ ( v ) => change( 'ai_model', v ) }
					/>
				</>
			) }
		</SettingsPanel>
	);
}
```

Create `assets/src/admin/views/Dashboard.js`:

```js
import { Card, CardHeader, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function Dashboard() {
	const d = window.openseoAdmin?.dashboard ?? { redirects: 0, notfound: 0 };
	const c = window.openseoAdmin?.connector ?? { available: false, url: '' };

	return (
		<div className="openseo-dashboard">
			<Card>
				<CardHeader>{ __( 'AI connector', 'openseo' ) }</CardHeader>
				<CardBody>
					{ c.available ? (
						__( 'Connected.', 'openseo' )
					) : (
						<>
							{ __( 'Not configured.', 'openseo' ) }{ ' ' }
							<a href={ c.url }>{ __( 'Connect', 'openseo' ) }</a>
						</>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardHeader>{ __( 'Active redirects', 'openseo' ) }</CardHeader>
				<CardBody>{ d.redirects }</CardBody>
			</Card>
			<Card>
				<CardHeader>{ __( 'Logged 404s', 'openseo' ) }</CardHeader>
				<CardBody>{ d.notfound }</CardBody>
			</Card>
		</div>
	);
}
```

- [ ] **Step 8: Write `App.js` (view registry) and replace `index.js`**

Create `assets/src/admin/App.js`:

```js
import { Dashboard } from './views/Dashboard';
import { General } from './views/General';
import { Titles } from './views/Titles';
import { Social } from './views/Social';
import { Sitemaps } from './views/Sitemaps';
import { Schema } from './views/Schema';
import { Ai } from './views/Ai';

const VIEWS = {
	dashboard: Dashboard,
	general: General,
	titles: Titles,
	social: Social,
	sitemaps: Sitemaps,
	schema: Schema,
	ai: Ai,
};

export function App( { view } ) {
	const View = VIEWS[ view ] ?? Dashboard;
	return <View />;
}
```

Replace the entire contents of `assets/src/admin/index.js`:

```js
/**
 * OpenSEO admin app entry. Mounts the React view named by the server-set
 * #openseo-app[data-view] node on each OpenSEO settings screen.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { App } from './App';

import './style.scss';

domReady( () => {
	const el = document.getElementById( 'openseo-app' );
	if ( ! el ) {
		return;
	}
	createRoot( el ).render( <App view={ el.dataset.view || 'dashboard' } /> );
} );
```

- [ ] **Step 9: Replace `style.scss`**

Replace the entire contents of `assets/src/admin/style.scss`:

```scss
.openseo-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px 0;
	margin-bottom: 16px;
	border-bottom: 1px solid #dcdcde;

	&__logo {
		color: #2271b1;
	}

	&__title {
		font-size: 18px;
		font-weight: 600;
	}

	&__version {
		color: #646970;
		font-size: 12px;
	}
}

.openseo-panel {
	max-width: 720px;
}

.openseo-savebar {
	margin-top: 16px;
}

.openseo-dashboard {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 16px;
	max-width: 960px;
}
```

- [ ] **Step 10: Build and verify the bundle compiles**

Run: `npm run build`
Expected: build succeeds; `assets/build/admin-settings.js`, `assets/build/admin-settings.css`, and `assets/build/admin-settings.asset.php` are (re)generated.

- [ ] **Step 11: Lint JS/CSS**

Run: `npm run lint:js` then `npm run lint:css`
Expected: no errors in `assets/src/admin/`.

- [ ] **Step 12: Commit**

```bash
git add assets/src/admin
git commit -m "feat(admin): add React settings app (views, useSettings, REST client)"
```

---

## Task 4: `Admin\Menu` + plantillas del shell

**Files:**
- Create: `src/Admin/Menu.php`
- Create: `templates/admin/header.php`
- Create: `templates/admin/app-page.php`
- Test (integración): `tests/Integration/MenuTest.php`

**Interfaces:**
- Consumes: `OPENSEO_PLUGIN_DIR`; un mapa `array<string, callable>` slug→render para páginas PHP (Task 5).
- Produces: `OpenSEO\Admin\Menu` con `register()`, `add_menu()`, `render_view(string)`, `screen_hooks(): array<int,string>`, `react_screen_hooks(): array<int,string>`, `dashboard_hook(): string`, const `PARENT_SLUG = 'openseo'`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/MenuTest.php`:

```php
<?php
/**
 * Integration tests for the OpenSEO top-level admin menu.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Admin\Menu;
use WP_UnitTestCase;

final class MenuTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	private function build(): Menu {
		return new Menu(
			array(
				'openseo-redirects' => '__return_true',
				'openseo-404s'      => '__return_true',
			)
		);
	}

	public function test_registers_parent_and_all_submenus(): void {
		global $submenu;

		$this->build()->add_menu();

		$this->assertArrayHasKey( Menu::PARENT_SLUG, $submenu );
		$slugs = wp_list_pluck( $submenu[ Menu::PARENT_SLUG ], 2 );

		foreach (
			array(
				'openseo',
				'openseo-general',
				'openseo-titles',
				'openseo-social',
				'openseo-sitemaps',
				'openseo-schema',
				'openseo-redirects',
				'openseo-404s',
				'openseo-ai',
			) as $slug
		) {
			$this->assertContains( $slug, $slugs );
		}
	}

	public function test_php_pages_are_excluded_from_react_hooks(): void {
		$menu = $this->build();
		$menu->add_menu();

		$this->assertNotEmpty( $menu->react_screen_hooks() );
		// Every screen has a hook; the two PHP pages are not React hooks.
		$this->assertGreaterThan(
			count( $menu->react_screen_hooks() ),
			count( $menu->screen_hooks() )
		);
	}

	public function test_dashboard_hook_is_the_top_level_hook(): void {
		$menu = $this->build();
		$menu->add_menu();

		// Asset gating + the dashboard counters bootstrap depend on this exact value.
		$this->assertSame( 'toplevel_page_openseo', $menu->dashboard_hook() );
		// The Dashboard submenu reuses the parent slug, so it is not double-counted.
		$this->assertSame(
			1,
			count( array_keys( $menu->react_screen_hooks(), 'toplevel_page_openseo', true ) )
		);
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MenuTest`
Expected: FAIL — `Class "OpenSEO\Admin\Menu" not found`.

- [ ] **Step 3: Write the templates**

Create `templates/admin/header.php`:

```php
<?php
/**
 * Shared branded header for OpenSEO admin pages.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="openseo-header">
	<span class="dashicons dashicons-search openseo-header__logo" aria-hidden="true"></span>
	<span class="openseo-header__title">OpenSEO</span>
	<span class="openseo-header__version"><?php echo esc_html( OPENSEO_VERSION ); ?></span>
</div>
```

Create `templates/admin/app-page.php`:

```php
<?php
/**
 * React mount page for OpenSEO settings views.
 *
 * @package OpenSEO
 *
 * @var string $openseo_view Server-set view id (closed list).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap openseo-admin">
	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/header.php'; ?>
	<h1 class="screen-reader-text"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<div id="openseo-app" data-view="<?php echo esc_attr( $openseo_view ); ?>"></div>
</div>
```

- [ ] **Step 4: Write `Admin\Menu`**

Create `src/Admin/Menu.php`:

```php
<?php
/**
 * OpenSEO top-level admin menu — the single registrar of all submenus.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Contracts\Hookable;

/**
 * Registers the top-level menu and every submenu in one ordered pass, so the
 * order is deterministic (add_submenu_page $position is unreliable across
 * separately-hooked classes). React pages render a mount node; PHP pages
 * (Redirects/404) render via callbacks injected by the composition root.
 */
final class Menu implements Hookable {

	public const PARENT_SLUG = 'openseo';

	private const CAP = 'manage_options';

	private const ICON = 'dashicons-search';

	/**
	 * All OpenSEO screen hook suffixes (React + PHP).
	 *
	 * @var array<int, string>
	 */
	private array $screen_hooks = array();

	/**
	 * React-only screen hook suffixes.
	 *
	 * @var array<int, string>
	 */
	private array $react_hooks = array();

	private string $dashboard_hook = '';

	/**
	 * @param array<string, callable> $php_pages Map of submenu slug => render callback.
	 */
	public function __construct( private readonly array $php_pages = array() ) {}

	/**
	 * Register the admin_menu hook.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Register the top-level menu and all submenus.
	 */
	public function add_menu(): void {
		$this->dashboard_hook = (string) add_menu_page(
			__( 'OpenSEO', 'openseo' ),
			__( 'OpenSEO', 'openseo' ),
			self::CAP,
			self::PARENT_SLUG,
			function (): void {
				$this->render_view( 'dashboard' );
			},
			self::ICON,
			'58.9'
		);

		$this->track( $this->dashboard_hook, true );

		foreach ( $this->pages() as $page ) {
			$is_react = isset( $page['view'] );
			$callback = $is_react
				? function () use ( $page ): void {
					$this->render_view( $page['view'] );
				}
				: ( $this->php_pages[ $page['slug'] ] ?? '__return_false' );

			$hook = (string) add_submenu_page(
				self::PARENT_SLUG,
				$page['title'],
				$page['title'],
				self::CAP,
				$page['slug'],
				$callback
			);

			$this->track( $hook, $is_react );
		}
	}

	/**
	 * Record a screen hook once. The Dashboard submenu reuses the parent slug, so
	 * its returned hook equals the top-level hook ('toplevel_page_openseo'); dedup
	 * avoids double-counting it in either list.
	 *
	 * @param string $hook     Hook suffix returned by add_*_page().
	 * @param bool   $is_react Whether the screen mounts the React app.
	 */
	private function track( string $hook, bool $is_react ): void {
		if ( '' === $hook ) {
			return;
		}
		if ( ! in_array( $hook, $this->screen_hooks, true ) ) {
			$this->screen_hooks[] = $hook;
		}
		if ( $is_react && ! in_array( $hook, $this->react_hooks, true ) ) {
			$this->react_hooks[] = $hook;
		}
	}

	/**
	 * Ordered page descriptors. React pages carry a 'view'; PHP pages do not.
	 *
	 * @return array<int, array{slug: string, title: string, view?: string}>
	 */
	private function pages(): array {
		return array(
			array(
				'slug'  => self::PARENT_SLUG,
				'title' => __( 'Dashboard', 'openseo' ),
				'view'  => 'dashboard',
			),
			array(
				'slug'  => 'openseo-general',
				'title' => __( 'General', 'openseo' ),
				'view'  => 'general',
			),
			array(
				'slug'  => 'openseo-titles',
				'title' => __( 'Titles & Meta', 'openseo' ),
				'view'  => 'titles',
			),
			array(
				'slug'  => 'openseo-social',
				'title' => __( 'Social', 'openseo' ),
				'view'  => 'social',
			),
			array(
				'slug'  => 'openseo-sitemaps',
				'title' => __( 'Sitemaps', 'openseo' ),
				'view'  => 'sitemaps',
			),
			array(
				'slug'  => 'openseo-schema',
				'title' => __( 'Schema', 'openseo' ),
				'view'  => 'schema',
			),
			array(
				'slug'  => 'openseo-redirects',
				'title' => __( 'Redirects', 'openseo' ),
			),
			array(
				'slug'  => 'openseo-404s',
				'title' => __( '404s', 'openseo' ),
			),
			array(
				'slug'  => 'openseo-ai',
				'title' => __( 'AI', 'openseo' ),
				'view'  => 'ai',
			),
		);
	}

	/**
	 * Render a React mount page.
	 *
	 * @param string $view View id passed to the React app.
	 */
	public function render_view( string $view ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$openseo_view = $view;
		require OPENSEO_PLUGIN_DIR . 'templates/admin/app-page.php';
	}

	/**
	 * All OpenSEO screen hook suffixes.
	 *
	 * @return array<int, string>
	 */
	public function screen_hooks(): array {
		return $this->screen_hooks;
	}

	/**
	 * React-only screen hook suffixes.
	 *
	 * @return array<int, string>
	 */
	public function react_screen_hooks(): array {
		return $this->react_hooks;
	}

	/**
	 * The dashboard (top-level) screen hook suffix.
	 */
	public function dashboard_hook(): string {
		return $this->dashboard_hook;
	}
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter MenuTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Menu.php templates/admin/header.php templates/admin/app-page.php tests/Integration/MenuTest.php
git commit -m "feat(admin): add top-level Menu and shared page shell"
```

---

## Task 5: Reubicar Redirec./404 + componer `Plugin`/`Assets` + retirar `SettingsPage`

> **COMMIT ATÓMICO (obligatorio).** Cambiar la firma de `RedirectsPage` (y su plantilla) sin
> recablear `Plugin::modules()` en el mismo commit deja el admin **fataleando** (`ArgumentCountError`
> en boot). Por eso esta tarea agrupa la reubicación, el wiring, `Assets` y el borrado de
> `SettingsPage` en **un único commit final** (Step 14). No commitees ni corras `composer check` /
> la suite de integración con el árbol a medias: las verificaciones van al final, tras el wiring.

**Files:**
- Modify: `src/NotFound/Admin/NotFoundListTable.php:89-104`
- Create: `tests/Integration/NotFoundLinkTest.php`
- Modify: `src/Redirects/Admin/RedirectsPage.php`
- Modify: `templates/admin/redirects-page.php`
- Create: `src/NotFound/Admin/NotFoundPage.php`
- Create: `templates/admin/notfound-page.php`
- Modify: `templates/admin/notfound-panel.php`
- Modify: `src/Admin/Assets.php`
- Modify: `src/Plugin.php`
- Create: `tests/Integration/MenuWiringTest.php`
- Delete: `src/Admin/SettingsPage.php`, `templates/admin/settings-page.php`, `tests/Integration/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Menu` (`PARENT_SLUG`, `screen_hooks()`, `react_screen_hooks()`, `dashboard_hook()`), `SettingsController`, `BehaviorSettings::render_redirects_form()/render_notfound_form()`, `Repository::count_active()`, `LogRepository::count_all()`, `Cache`, `Options`, `Connector`.
- Produces: `RedirectsPage(Repository, Cache, BehaviorSettings)` (sin menú, sin `$tab`); `OpenSEO\NotFound\Admin\NotFoundPage(LogRepository, Options, BehaviorSettings)` con `render(): void`; `Assets(Menu, Options, Repository, LogRepository)`; `Plugin::modules()` cableado completo (incl. `SettingsController` fuera de `is_admin()`).

- [ ] **Step 1: Fix the "create redirect" link in `NotFoundListTable`**

In `src/NotFound/Admin/NotFoundListTable.php`, replace the `column_url()` body (lines 89-104):

```php
	public function column_url( $item ): string {
		$create = add_query_arg(
			array(
				'page'   => 'openseo-redirects',
				'source' => rawurlencode( (string) $item['url'] ),
			),
			admin_url( 'admin.php' )
		);

		$actions = array(
			'create' => sprintf( '<a href="%s">%s</a>', esc_url( $create ), esc_html__( 'Create redirect', 'openseo' ) ),
		);

		return esc_html( (string) $item['url'] ) . $this->row_actions( $actions );
	}
```

(Changed: base `tools.php` → `admin.php`; removed `'tab' => 'redirects'`.)

- [ ] **Step 2: Write the failing integration test for the link + relocation**

Create `tests/Integration/NotFoundLinkTest.php`:

```php
<?php
/**
 * Integration tests: 404 → create-redirect link points under the OpenSEO menu.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\NotFound\Admin\NotFoundListTable;
use OpenSEO\NotFound\LogRepository;
use WP_UnitTestCase;

final class NotFoundLinkTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// WP_List_Table::__construct() calls get_current_screen(); give it one.
		set_current_screen( 'admin.php' );
	}

	public function test_create_redirect_link_uses_admin_php_without_tab(): void {
		$table = new NotFoundListTable( new LogRepository() );

		$html = $table->column_url( array( 'url' => '/missing-page' ) );

		$this->assertStringContainsString( 'admin.php?page=openseo-redirects', $html );
		$this->assertStringContainsString( 'source=', $html );
		$this->assertStringNotContainsString( 'tools.php', $html );
		$this->assertStringNotContainsString( 'tab=', $html );
	}
}
```

- [ ] **Step 3: Run it to verify it fails (before fix) / passes (after Step 1)**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NotFoundLinkTest`
Expected: PASS after Step 1 (the assertion encodes the desired post-fix state).

- [ ] **Step 4: Rewrite `RedirectsPage` (no menu, no `$tab`, inject BehaviorSettings)**

In `src/Redirects/Admin/RedirectsPage.php`:

Replace the imports + constructor + `register()` + `add_page()` block (lines 10-66) with:

```php
namespace OpenSEO\Redirects\Admin;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Normalizer;
use OpenSEO\Redirects\Regex;
use OpenSEO\Redirects\Repository;
use OpenSEO\Settings\BehaviorSettings;

/**
 * Handles redirect CRUD form submissions (nonce + capability) and renders the
 * redirects page. The submenu itself is registered by Admin\Menu.
 */
final class RedirectsPage implements Hookable {

	private const CAP = 'manage_options';

	/**
	 * @param Repository       $repo     Redirect rule repository.
	 * @param Cache            $cache    Redirect ruleset cache.
	 * @param BehaviorSettings $behavior Renders the redirect toggle form.
	 */
	public function __construct(
		private readonly Repository $repo,
		private readonly Cache $cache,
		private readonly BehaviorSettings $behavior,
	) {}

	/**
	 * Register the CRUD form handlers (no menu — Admin\Menu owns that).
	 */
	public function register(): void {
		add_action( 'admin_post_openseo_save_redirect', array( $this, 'handle_save' ) );
		add_action( 'admin_post_openseo_redirect_row_action', array( $this, 'handle_row_action' ) );
	}
```

Replace `render()` (lines 160-184) with:

```php
	/**
	 * Render the redirects page (toggle form + add form + list table).
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET prefill only; the save POST is nonce-protected.
		$prefill = isset( $_GET['source'] ) ? ( new Normalizer() )->normalize( sanitize_text_field( wp_unslash( $_GET['source'] ) ) ) : '';

		$openseo_repo     = $this->repo;
		$openseo_behavior = $this->behavior;

		require OPENSEO_PLUGIN_DIR . 'templates/admin/redirects-page.php';
	}
```

Update `redirect_back()` (line 220) target:

```php
		wp_safe_redirect( add_query_arg( 'openseo_msg', $flag, admin_url( 'admin.php?page=openseo-redirects' ) ) );
```

(Removed: `const SLUG`, `add_page()`, the `LogRepository`/`Options` imports + properties, and the `$this->not_found_log`/`$this->options` usages in `render()`. `creates_cycle()`, `handle_save()`, `handle_row_action()` are unchanged.)

- [ ] **Step 5: Rewrite `templates/admin/redirects-page.php` (no sub-tab nav, no `$tab`)**

Replace the entire file `templates/admin/redirects-page.php`:

```php
<?php
/**
 * Redirects manager page.
 *
 * @package OpenSEO
 *
 * @var \OpenSEO\Redirects\Repository      $openseo_repo     Injected by the page controller.
 * @var string                             $prefill          Pre-filled source path.
 * @var \OpenSEO\Settings\BehaviorSettings $openseo_behavior Renders the toggle form.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap openseo-admin">
	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/header.php'; ?>
	<h1><?php echo esc_html__( 'Redirects', 'openseo' ); ?></h1>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status flag.
	$openseo_msg = isset( $_GET['openseo_msg'] ) ? sanitize_key( wp_unslash( $_GET['openseo_msg'] ) ) : '';

	$openseo_notices = array(
		'saved'         => array( 'success', __( 'Redirect saved.', 'openseo' ) ),
		'invalid'       => array( 'error', __( 'Could not save: check the source, target, and type.', 'openseo' ) ),
		'invalid_regex' => array( 'error', __( 'Could not save: the regex pattern is invalid.', 'openseo' ) ),
		'cycle'         => array( 'error', __( 'Could not save: this would create a redirect loop with an existing rule.', 'openseo' ) ),
		'delete'        => array( 'success', __( 'Redirect deleted.', 'openseo' ) ),
		'enable'        => array( 'success', __( 'Redirect enabled.', 'openseo' ) ),
		'disable'       => array( 'success', __( 'Redirect disabled.', 'openseo' ) ),
	);

	if ( isset( $openseo_notices[ $openseo_msg ] ) ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $openseo_notices[ $openseo_msg ][0] ),
			esc_html( $openseo_notices[ $openseo_msg ][1] )
		);
	}
	?>

	<h2><?php echo esc_html__( 'Settings', 'openseo' ); ?></h2>
	<?php $openseo_behavior->render_redirects_form(); ?>

	<h2><?php echo esc_html__( 'Add redirect', 'openseo' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="openseo_save_redirect" />
		<?php wp_nonce_field( 'openseo_save_redirect' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="openseo_source"><?php echo esc_html__( 'Source path', 'openseo' ); ?></label></th>
				<td><input type="text" id="openseo_source" name="source_path" class="regular-text" value="<?php echo esc_attr( $prefill ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="openseo_target"><?php echo esc_html__( 'Target', 'openseo' ); ?></label></th>
				<td><input type="text" id="openseo_target" name="target" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="openseo_status"><?php echo esc_html__( 'Type', 'openseo' ); ?></label></th>
				<td>
					<select id="openseo_status" name="status_code">
						<option value="301">301</option>
						<option value="302">302</option>
						<option value="307">307</option>
						<option value="410">410</option>
					</select>
					<label><input type="checkbox" name="is_regex" value="1" /> <?php echo esc_html__( 'Regex', 'openseo' ); ?></label>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save redirect', 'openseo' ) ); ?>
	</form>

	<?php
	$openseo_table = new \OpenSEO\Redirects\Admin\RedirectsListTable( $openseo_repo );
	$openseo_table->prepare_items();
	?>
	<form method="get">
		<input type="hidden" name="page" value="openseo-redirects" />
		<?php
		$openseo_table->search_box( __( 'Search', 'openseo' ), 'openseo-redirect' );
		$openseo_table->display();
		?>
	</form>
</div>
```

- [ ] **Step 6: Create `NotFoundPage` + its template, and fix `notfound-panel.php`**

Create `src/NotFound/Admin/NotFoundPage.php`:

```php
<?php
/**
 * 404s page (OpenSEO → 404s). Submenu registered by Admin\Menu.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound\Admin;

use OpenSEO\NotFound\LogRepository;
use OpenSEO\Settings\BehaviorSettings;
use OpenSEO\Settings\Options;

/**
 * Renders the 404 monitor toggle form and the logged-404 list table.
 */
final class NotFoundPage {

	private const CAP = 'manage_options';

	/**
	 * @param LogRepository    $log      404 log data access.
	 * @param Options          $options  Settings (reads the monitor toggle).
	 * @param BehaviorSettings $behavior Renders the 404 toggle form.
	 */
	public function __construct(
		private readonly LogRepository $log,
		private readonly Options $options,
		private readonly BehaviorSettings $behavior,
	) {}

	/**
	 * Render the 404s page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$openseo_options        = $this->options;
		$openseo_behavior       = $this->behavior;
		$openseo_notfound_table = new NotFoundListTable( $this->log );
		$openseo_notfound_table->prepare_items();

		require OPENSEO_PLUGIN_DIR . 'templates/admin/notfound-page.php';
	}
}
```

Create `templates/admin/notfound-page.php`:

```php
<?php
/**
 * 404s page wrapper.
 *
 * @package OpenSEO
 *
 * @var \OpenSEO\NotFound\Admin\NotFoundListTable $openseo_notfound_table Injected by the page controller.
 * @var \OpenSEO\Settings\Options                 $openseo_options        Injected by the page controller.
 * @var \OpenSEO\Settings\BehaviorSettings        $openseo_behavior       Renders the toggle form.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap openseo-admin">
	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/header.php'; ?>
	<h1><?php echo esc_html__( '404s', 'openseo' ); ?></h1>

	<h2><?php echo esc_html__( 'Settings', 'openseo' ); ?></h2>
	<?php $openseo_behavior->render_notfound_form(); ?>

	<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/notfound-panel.php'; ?>
</div>
```

Replace the entire file `templates/admin/notfound-panel.php`:

```php
<?php
/**
 * 404 monitor list (body of the 404s page).
 *
 * @package OpenSEO
 *
 * @var \OpenSEO\NotFound\Admin\NotFoundListTable $openseo_notfound_table Injected by the page controller.
 * @var \OpenSEO\Settings\Options                 $openseo_options        Injected by the page controller.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<h2><?php echo esc_html__( 'Logged 404s', 'openseo' ); ?></h2>
<?php if ( '1' !== (string) $openseo_options->get( 'notfound_monitor_enabled' ) ) : ?>
	<p><?php echo esc_html__( 'The 404 monitor is off. Enable it in the settings above.', 'openseo' ); ?></p>
<?php endif; ?>
<form method="get">
	<input type="hidden" name="page" value="openseo-404s" />
	<?php $openseo_notfound_table->display(); ?>
</form>
```

(Changed: removed the broken `options-general.php?page=openseo&tab=redirects` link and the `<input name="tab">`; `page` value is now `openseo-404s`.)

- [ ] **Step 7: Rewrite `Admin\Assets`**

Replace the entire file `src/Admin/Assets.php`:

```php
<?php
/**
 * Admin asset loading for the OpenSEO menu screens.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Ai\Connector;
use OpenSEO\Contracts\Hookable;
use OpenSEO\NotFound\LogRepository;
use OpenSEO\Redirects\Repository;
use OpenSEO\Settings\Options;

/**
 * Enqueues the shared chrome CSS on every OpenSEO screen and the React app
 * (plus the window.openseoAdmin bootstrap) on React screens only. Screen
 * targeting uses the hook suffixes Menu captured at registration.
 */
final class Assets implements Hookable {

	private const HANDLE = 'openseo-admin-settings';

	/**
	 * @param Menu          $menu          Source of OpenSEO screen hook suffixes.
	 * @param Options       $options       Settings accessor (initial bootstrap state).
	 * @param Repository    $redirects     Redirect repository (dashboard count).
	 * @param LogRepository $not_found_log 404 log (dashboard count).
	 */
	public function __construct(
		private readonly Menu $menu,
		private readonly Options $options,
		private readonly Repository $redirects,
		private readonly LogRepository $not_found_log,
	) {}

	/**
	 * Register the enqueue hook.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue CSS on all OpenSEO screens, JS + bootstrap on React screens.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->menu->screen_hooks(), true ) ) {
			return;
		}

		$asset_path = OPENSEO_PLUGIN_DIR . 'assets/build/admin-settings.asset.php';

		if ( ! is_readable( $asset_path ) ) {
			return;
		}

		$asset   = require $asset_path;
		$version = $asset['version'] ?? OPENSEO_VERSION;

		$style_path = OPENSEO_PLUGIN_DIR . 'assets/build/admin-settings.css';
		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				self::HANDLE,
				OPENSEO_PLUGIN_URL . 'assets/build/admin-settings.css',
				array(),
				$version
			);
		}

		// PHP screens (Redirects/404) get the chrome CSS only.
		if ( ! in_array( $hook_suffix, $this->menu->react_screen_hooks(), true ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			OPENSEO_PLUGIN_URL . 'assets/build/admin-settings.js',
			$asset['dependencies'] ?? array(),
			$version,
			true
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.openseoAdmin = ' . wp_json_encode( $this->bootstrap( $hook_suffix ), JSON_HEX_TAG ) . ';',
			'before'
		);

		wp_set_script_translations( self::HANDLE, 'openseo' );
	}

	/**
	 * Build the bootstrap payload. Dashboard counts only on the dashboard screen.
	 *
	 * @param string $hook_suffix Current screen hook.
	 * @return array<string, mixed>
	 */
	private function bootstrap( string $hook_suffix ): array {
		$data = array(
			'settings'  => $this->options->all(),
			'connector' => array(
				'available' => Connector::is_text_generation_available(),
				'url'       => Connector::settings_url(),
			),
		);

		if ( $hook_suffix === $this->menu->dashboard_hook() ) {
			$data['dashboard'] = array(
				'redirects' => $this->redirects->count_active(),
				'notfound'  => $this->not_found_log->count_all(),
			);
		}

		return $data;
	}
}
```

- [ ] **Step 8: Rewrite `Plugin::modules()` wiring**

In `src/Plugin.php`:

Replace the `use` block for admin/rest classes — remove `use OpenSEO\Admin\SettingsPage;` and add:

```php
use OpenSEO\Admin\Menu;
use OpenSEO\NotFound\Admin\NotFoundPage;
use OpenSEO\Rest\SettingsController;
use OpenSEO\Settings\BehaviorSettings;
```

Add `SettingsController` to the always-on `$modules` array (REST runs outside `is_admin()`). Insert this line just before `if ( is_admin() ) {`:

```php
		$modules[] = new SettingsController( $options );
```

Replace the entire `if ( is_admin() ) { ... }` block with:

```php
		if ( is_admin() ) {
			$behavior       = new BehaviorSettings( $options );
			$redirects_page = new RedirectsPage( $redirects_repo, $redirects_cache, $behavior );
			$notfound_page  = new NotFoundPage( $not_found_log, $options, $behavior );

			$menu = new Menu(
				array(
					'openseo-redirects' => array( $redirects_page, 'render' ),
					'openseo-404s'      => array( $notfound_page, 'render' ),
				)
			);

			$modules[] = $menu;
			$modules[] = new AdminAssets( $menu, $options, $redirects_repo, $not_found_log );
			$modules[] = $behavior;
			$modules[] = new EditorPanel();
			$modules[] = $redirects_page;
		}
```

(`NotFoundPage` is a plain renderer — it has no hooks of its own, so it is constructed for the Menu callback but not added to `$modules`. The old `RedirectsPage( ..., $not_found_log )` 4-arg construction is gone.)

- [ ] **Step 9: Delete the retired Settings API page, template, and its test**

```bash
git rm src/Admin/SettingsPage.php templates/admin/settings-page.php tests/Integration/SettingsPageTest.php
```

- [ ] **Step 10: Write the wiring integration test**

Create `tests/Integration/MenuWiringTest.php`:

```php
<?php
/**
 * Integration test: the OpenSEO menu and REST route are wired by the plugin.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use WP_UnitTestCase;

final class MenuWiringTest extends WP_UnitTestCase {

	public function test_settings_route_is_live(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/openseo/v1/settings', $routes );
	}

	public function test_top_level_menu_is_registered(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		do_action( 'admin_menu' );

		global $admin_page_hooks;
		$this->assertArrayHasKey( 'openseo', $admin_page_hooks );
	}

	public function test_legacy_settings_page_class_is_gone(): void {
		$this->assertFalse( class_exists( '\OpenSEO\Admin\SettingsPage' ) );
	}
}
```

- [ ] **Step 11: Verify wiring, links, and no regression (PHP)**

Now that `Plugin` is rewired (nothing half-applied), run the new + relocated suites and static analysis:
- `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "MenuWiringTest|NotFoundLinkTest"`
  Expected: PASS (MenuWiringTest 3 tests, NotFoundLinkTest 1 test). The plugin boots via `tests/bootstrap-integration.php`, so `admin_menu`/`rest_api_init` reflect the real wiring.
- `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "NotFound|Redirects|Dispatcher|SlugWatcher"`
  Expected: PASS (engine/repo/monitor suites untouched; only admin screens moved).
- `composer analyze`
  Expected: no new errors (`RedirectsPage` no longer references removed props; `NotFoundPage`/`Assets` fully typed).

- [ ] **Step 12: Run the full PHP gate + JS build + lint**

Run: `composer check`, then `npm run lint:js`, `npm run lint:css`, `npm run build`
Expected: PHPCS clean, PHPStan clean, unit PHPUnit green; JS/CSS lint clean; build succeeds.

- [ ] **Step 13: Run the full integration suite**

Run: `npm run test:integration`
Expected: all green (including `RestSettingsTest`, `BehaviorSettingsTest`, `MenuTest`, `NotFoundLinkTest`, `MenuWiringTest`, and the untouched engine suites).

- [ ] **Step 14: Commit (atomic — relocation + wiring together)**

```bash
git add src/NotFound/Admin/NotFoundListTable.php tests/Integration/NotFoundLinkTest.php \
  src/Redirects/Admin/RedirectsPage.php templates/admin/redirects-page.php \
  src/NotFound/Admin/NotFoundPage.php templates/admin/notfound-page.php templates/admin/notfound-panel.php \
  src/Admin/Assets.php src/Plugin.php tests/Integration/MenuWiringTest.php
# Deletions (SettingsPage.php, settings-page.php, SettingsPageTest.php) were staged by `git rm` in Step 9.
git commit -m "feat(admin): top-level menu + REST settings; relocate Redirects/404; retire tabbed SettingsPage"
```

---

## Task 6: Documentación y verificación final

**Files:**
- Modify: `CLAUDE.md`
- Modify: `NOTES.md`

**Interfaces:** none (docs + verification only).

- [ ] **Step 1: Update `CLAUDE.md` architecture notes**

In `CLAUDE.md`, update the `Admin/SettingsPage.php` bullet under "Key modules" to describe the new surface. Replace the `Admin/SettingsPage.php` sentence with:

```markdown
- `Admin/Menu.php` — the single registrar of the **top-level OpenSEO menu** and all
  submenus (Dashboard · General · Titles & Meta · Social · Sitemaps · Schema ·
  Redirects · 404s · AI). React pages render `templates/admin/app-page.php` (a
  `#openseo-app[data-view]` mount + shared `templates/admin/header.php`); the React
  app lives in `assets/src/admin/` and reads/writes `openseo_settings` via the
  `Rest/SettingsController` route `openseo/v1/settings` (apiFetch, partial-merge
  through `Options::sanitize`). `Settings/BehaviorSettings` keeps the redirect/404
  behavior toggles on the (PHP) Redirects/404 pages via the Settings API. `Admin/Assets`
  enqueues the chrome CSS on every OpenSEO screen and the React bundle + a
  `window.openseoAdmin` bootstrap on React screens only.
```

- [ ] **Step 2: Add a Fase 6 section to `NOTES.md`**

Append to `NOTES.md` (after the Fase 5 section, before "## 6. WP-CLI"):

```markdown
### Consolidación de UI admin (Fase 6): qué cubre y cómo probar

La superficie de admin pasó de *Ajustes → OpenSEO* (7 tabs, Settings API) +
*Herramientas → OpenSEO Redirects* a un **menú propio** con un submenú por sección.
Las vistas de ajustes (Dashboard, General, Títulos, Social, Sitemaps, Schema, IA)
son **React** (`assets/src/admin/`) sobre el REST `openseo/v1/settings`
(`src/Rest/SettingsController.php`, reutiliza `Options::sanitize`). Redirecciones y
404 se reubicaron bajo el menú conservando su `WP_List_Table` (PHP); sus toggles
viven en un mini-form Settings API (`src/Settings/BehaviorSettings.php`).

CI ejercita: rutas REST (`RestSettingsTest`, merge parcial/claves desconocidas),
registro de menú (`MenuTest`, `MenuWiringTest`), secciones de toggles
(`BehaviorSettingsTest`) y el enlace 404→redirect (`NotFoundLinkTest`).

Smoke test manual: en wp-admin, abrir **OpenSEO** en el sidebar; cada submenú es su
propia URL (`admin.php?page=openseo-*`); cambiar un campo en *General* y Guardar →
recargar y confirmar persistencia; *404s* muestra el toggle del monitor arriba y el
log abajo.
```

- [ ] **Step 3: Final full verification**

Run, in order:
1. `composer check` — Expected: PHPCS + PHPStan + unit PHPUnit all green.
2. `npm run lint:js` — Expected: clean.
3. `npm run lint:css` — Expected: clean.
4. `npm run build` — Expected: succeeds.
5. `npm run test:integration` — Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md NOTES.md
git commit -m "docs: document admin UI consolidation (Fase 6)"
```

---

## Self-Review — Cobertura del spec

| Requisito del spec | Task |
|--------------------|------|
| Menú top-level + 9 submenús reales (§3) | Task 4 (+ wiring Task 5) |
| Registro centralizado de submenús (H1) | Task 4 (`Menu::pages()` un bucle) |
| Dashboard landing (reusa slug `openseo`) | Task 4 |
| Vistas de ajustes en React (§5) | Task 3 |
| REST `openseo/v1/settings` reutilizando `sanitize()` (§4) | Task 1 |
| REST fuera de `is_admin()` | Task 5 (always-on module) |
| `wp_slash()` antes de `sanitize()` en POST REST (C1) | Task 1 |
| Nonce vía middleware apiFetch (M5, ruta relativa) | Task 3 (`api.js`) |
| Bootstrap `window.openseoAdmin` sin nonce/root (§4) | Task 5 (`Assets::bootstrap`) |
| Contador Dashboard vía `count_active()`/`count_all()` (H3) | Task 5 |
| Toggles redirec./404 como Settings API en páginas PHP (M3) | Task 2 + Task 5 |
| Reubicar Redirec./404 bajo el menú (§6) | Task 5 |
| Quitar sub-tab nav y lógica `$tab` (M2) | Task 5 |
| Fix enlaces rotos `notfound-panel` + `NotFoundListTable` (H2) | Task 5 |
| Commit atómico relocation+wiring (H2 plan-review) | Task 5 (Step 14) |
| Gating de assets por hook-suffixes (§7) | Task 5 |
| Cabecera de marca compartida (§3) | Task 4 (`header.php`) |
| Eliminar `SettingsPage` + template + reescribir test (§8/§10) | Task 5 |
| Tests merge parcial / claves desconocidas / body vacío / backslash (M4/C1) | Task 1 |
| i18n `wp_set_script_translations` (L3) | Task 3/Task 5 |
| Docs CLAUDE.md/NOTES.md | Task 6 |

Fuera de alcance (Fase 2, no en este plan): Redirec./404 en React + REST CRUD; migrar toggles a REST.
