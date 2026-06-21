# OpenSEO Fase 2 — Redirects/404 React + REST CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir las páginas de Redirecciones y 404 (PHP `WP_List_Table` + `admin-post` CRUD + toggles Settings API) en **vistas React** sobre un **REST CRUD propio**, con edición in-place y acciones masivas, retirando la Settings API del plugin.

**Architecture:** Dos controladores REST (`Rest\RedirectsController`, `Rest\NotFoundController`) bajo `openseo/v1`, una unidad de validación reutilizable `Redirects\RuleValidator` (sobre la interfaz `Redirects\RedirectLookup` para ser testeable), tablas React a medida (`DataTable` sobre `@wordpress/components`) y dos vistas (`Redirects`, `NotFound`). El motor (Dispatcher/Monitor/Pruner/SlugWatcher/Repository/Cache/Normalizer/Regex) no se toca.

**Tech Stack:** PHP 8.1+ (PSR-4 `OpenSEO\` → `src/`), WordPress REST API (`register_rest_route`, `WP_REST_Server` constants), `@wordpress/scripts` (React via `@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/url`), PHPUnit (Brain Monkey unit + WP integration), Jest.

**Spec:** `docs/superpowers/specs/2026-06-21-openseo-phase2-redirects-react-design.md`

## Global Constraints

- **Versiones objetivo:** WordPress 7.0+ · PHP 8.1+.
- **Prefijos** `openseo`/`OpenSEO`/`OPENSEO`; text domain `openseo` (PHPCS). PSR-4; `declare( strict_types=1 );` y tipos explícitos (PHPStan nivel 6, `composer analyze`).
- **Una option key** `openseo_settings`; los toggles ya viven ahí y se escriben por `openseo/v1/settings` (Fase 1).
- **Seguridad:** `current_user_can('manage_options')` en TODAS las rutas; nonce `X-WP-Nonce` vía `apiFetch`; sanitizar entrada, escapar salida; nunca procesar el cuerpo crudo entero; `<id>` saneado con `absint`.
- **REST fuera de `is_admin()`:** los controladores se registran en la lista siempre-activa de `Plugin::modules()` (igual que `SettingsController`).
- **Paginación:** envelope `{ items, total }` (no cabeceras `X-WP-Total`); `per_page` capado a 100, def. 20.
- **Verbos:** update con `WP_REST_Server::EDITABLE`, borrado con `WP_REST_Server::DELETABLE`.
- **Anti-bucle solo para reglas exactas** (no regex). `bulk` aplica todos los ids y hace **un solo** `Cache::flush()`.
- **Gates verdes antes de cerrar:** `composer check`, `npm run lint:js`/`lint:css`/`build`, integración wp-env.

---

## Task 1: `RedirectLookup` + `RuleValidator` (+ Repository implementa la interfaz)

**Files:**
- Create: `src/Redirects/RedirectLookup.php`
- Modify: `src/Redirects/Repository.php` (añadir `implements RedirectLookup`)
- Create: `src/Redirects/RuleValidator.php`
- Test: `tests/Unit/Redirects/RuleValidatorTest.php`

**Interfaces:**
- Consumes: `Redirects\Redirect` (DTO: `->id`, `->source`, `->target`, `->status`, `->is_regex`, `->enabled`), `Redirects\Normalizer` (`normalize(string): string`), `Redirects\Regex` (`is_valid(string): bool`).
- Produces: `interface RedirectLookup { find_active_by_source(string $path): ?Redirect; }`; `final class RuleValidator { __construct(RedirectLookup $lookup); validate(array $input, int $id = 0): array|WP_Error; }` returning `array{source_path:string,target:string,status_code:int,is_regex:bool,enabled:bool}` or `WP_Error` (codes `openseo_invalid`/`openseo_invalid_regex`/`openseo_cycle`, `data.status=400`).

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Redirects/RuleValidatorTest.php`:

```php
<?php
/**
 * Unit tests for the redirect rule validator (extracted from RedirectsPage).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Redirects\Redirect;
use OpenSEO\Redirects\RedirectLookup;
use OpenSEO\Redirects\RuleValidator;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class RuleValidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		// Real WP esc_url_raw returns '' for a bare relative path and echoes absolute URLs.
		Functions\when( 'esc_url_raw' )->alias(
			static fn( $url ) => str_contains( (string) $url, '://' ) ? (string) $url : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function validator( ?Redirect $back = null ): RuleValidator {
		$lookup = new class( $back ) implements RedirectLookup {
			public function __construct( private ?Redirect $back ) {}
			public function find_active_by_source( string $path ): ?Redirect {
				return $this->back;
			}
		};

		return new RuleValidator( $lookup );
	}

	public function test_normalizes_exact_source_and_returns_clean_data(): void {
		$clean = $this->validator()->validate(
			array(
				'source_path' => '/old/',
				'target'      => 'https://example.com/new',
				'status_code' => 301,
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( '/old', $clean['source_path'] ); // trailing slash stripped by Normalizer.
		$this->assertSame( 'https://example.com/new', $clean['target'] );
		$this->assertSame( 301, $clean['status_code'] );
		$this->assertFalse( $clean['is_regex'] );
		$this->assertTrue( $clean['enabled'] );
	}

	public function test_accepts_root_relative_target(): void {
		$clean = $this->validator()->validate(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( '/new', $clean['target'] );
	}

	public function test_invalid_status_returns_error(): void {
		$result = $this->validator()->validate(
			array(
				'source_path' => '/x',
				'target'      => 'https://e.com',
				'status_code' => 999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_invalid', $result->get_error_code() );
	}

	public function test_410_clears_target(): void {
		$clean = $this->validator()->validate(
			array(
				'source_path' => '/gone',
				'target'      => '',
				'status_code' => 410,
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( '', $clean['target'] );
		$this->assertSame( 410, $clean['status_code'] );
	}

	public function test_invalid_regex_returns_error(): void {
		$result = $this->validator()->validate(
			array(
				'source_path' => '(',           // unbalanced group → invalid pattern.
				'target'      => 'https://e.com',
				'status_code' => 301,
				'is_regex'    => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_invalid_regex', $result->get_error_code() );
	}

	public function test_detects_direct_cycle_for_exact_rules(): void {
		// Existing active rule: /new -> /old. New rule /old -> /new closes the loop.
		$back   = new Redirect( 7, '/new', '/old', 301, false, true );
		$result = $this->validator( $back )->validate(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_cycle', $result->get_error_code() );
	}

	public function test_editing_same_row_is_not_a_cycle(): void {
		$back   = new Redirect( 7, '/new', '/old', 301, false, true );
		$result = $this->validator( $back )->validate(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
			),
			7 // editing row 7 itself → excluded from the lookup.
		);

		$this->assertIsArray( $result );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter RuleValidatorTest`
Expected: FAIL — `Class "OpenSEO\Redirects\RedirectLookup" not found`.

- [ ] **Step 3: Create the `RedirectLookup` interface**

Create `src/Redirects/RedirectLookup.php`:

```php
<?php
/**
 * Lookup contract used by the rule validator (extracted so it is mockable;
 * Repository is final and hits $wpdb, so it cannot be doubled directly).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Minimal read contract over the redirects store.
 */
interface RedirectLookup {

	/**
	 * Find one active, exact (non-regex) rule by normalized source path.
	 *
	 * @param string $path Normalized source path.
	 */
	public function find_active_by_source( string $path ): ?Redirect;
}
```

- [ ] **Step 4: Make `Repository` implement the interface**

In `src/Redirects/Repository.php`, change the class declaration (line 23):

```php
final class Repository implements RedirectLookup {
```

(`find_active_by_source(string $path): ?Redirect` already exists with the matching signature; no other change.)

- [ ] **Step 5: Create the `RuleValidator`**

Create `src/Redirects/RuleValidator.php`:

```php
<?php
/**
 * Validates and normalizes a redirect rule before persistence.
 *
 * Extracts the logic that lived in RedirectsPage::handle_save so both the REST
 * create and update paths share one tested unit. Returns clean data or a
 * WP_Error (status 400) the controller serializes.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

use WP_Error;

/**
 * Pure-ish rule validator (WP-coupled via esc_url_raw/Normalizer/Regex/lookup,
 * but unit-testable by mocking the RedirectLookup interface).
 */
final class RuleValidator {

	private const STATUSES = array( 301, 302, 307, 410 );

	/**
	 * @param RedirectLookup $lookup Read contract for the anti-loop check.
	 */
	public function __construct( private readonly RedirectLookup $lookup ) {}

	/**
	 * Validate raw rule input.
	 *
	 * @param array<string, mixed> $input source_path, target, status_code, is_regex (+ enabled on edit).
	 * @param int                  $id     0 when creating; the row id when editing (excluded from the anti-loop).
	 * @return array{source_path:string,target:string,status_code:int,is_regex:bool,enabled:bool}|WP_Error
	 */
	public function validate( array $input, int $id = 0 ): array|WP_Error {
		$is_regex = ! empty( $input['is_regex'] );
		$source   = isset( $input['source_path'] ) ? sanitize_text_field( (string) $input['source_path'] ) : '';
		$raw      = isset( $input['target'] ) ? (string) $input['target'] : '';
		$target   = esc_url_raw( $raw, array( 'http', 'https' ) );
		$status   = isset( $input['status_code'] ) ? absint( $input['status_code'] ) : 301;

		// Accept a genuine root-relative path that esc_url_raw rejects (no scheme smuggling).
		if ( '' === $target && '' !== $raw
			&& str_starts_with( $raw, '/' ) && ! str_contains( $raw, '://' ) ) {
			$target = sanitize_text_field( $raw );
		}

		if ( $is_regex ) {
			if ( ! Regex::is_valid( $source ) ) {
				return new WP_Error( 'openseo_invalid_regex', __( 'The regex pattern is invalid.', 'openseo' ), array( 'status' => 400 ) );
			}
		} else {
			$source = ( new Normalizer() )->normalize( $source );
		}

		if ( '' === $source || ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'openseo_invalid', __( 'Check the source, target, and type.', 'openseo' ), array( 'status' => 400 ) );
		}
		if ( 410 !== $status && '' === $target ) {
			return new WP_Error( 'openseo_invalid', __( 'A target is required for this redirect type.', 'openseo' ), array( 'status' => 400 ) );
		}

		// Reject a direct 2-rule cycle (exact rules only; regex patterns are not normalized).
		if ( ! $is_regex && $this->creates_cycle( $id, $source, $target ) ) {
			return new WP_Error( 'openseo_cycle', __( 'This would create a redirect loop with an existing rule.', 'openseo' ), array( 'status' => 400 ) );
		}

		return array(
			'source_path' => $source,
			'target'      => 410 === $status ? '' : $target,
			'status_code' => $status,
			'is_regex'    => $is_regex,
			'enabled'     => array_key_exists( 'enabled', $input ) ? ! empty( $input['enabled'] ) : true,
		);
	}

	/**
	 * Whether saving source → target closes a direct loop with an existing
	 * active rule target → source. Only internal root-relative targets can cycle.
	 *
	 * @param int    $id     Row id being saved (0 for new), excluded from the lookup.
	 * @param string $source Normalized source path being saved.
	 * @param string $target Target being saved.
	 */
	private function creates_cycle( int $id, string $source, string $target ): bool {
		if ( ! str_starts_with( $target, '/' ) || str_starts_with( $target, '//' ) ) {
			return false;
		}

		$normalizer = new Normalizer();
		$back       = $this->lookup->find_active_by_source( $normalizer->normalize( $target ) );

		if ( null === $back || $back->id === $id ) {
			return false;
		}

		return $normalizer->normalize( $back->target ) === $source;
	}
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter RuleValidatorTest`
Expected: PASS (7 tests).

- [ ] **Step 7: Lint + analyze**

Run: `composer lint` then `composer analyze`
Expected: PHPCS clean; PHPStan level 6 no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Redirects/RedirectLookup.php src/Redirects/Repository.php src/Redirects/RuleValidator.php tests/Unit/Redirects/RuleValidatorTest.php
git commit -m "feat(redirects): add RedirectLookup + RuleValidator (extract handle_save logic)"
```

---

## Task 2: `Rest\RedirectsController` (CRUD + bulk)

**Files:**
- Create: `src/Rest/RedirectsController.php`
- Test: `tests/Integration/RedirectsRestTest.php`

**Interfaces:**
- Consumes: `Redirects\Repository` (`all(int,int,string): array`, `count_all(string): int`, `create(array): int`, `find(int): ?array`, `update(int,array): bool`, `delete(int): bool`, `set_enabled(int,bool): bool`), `Redirects\Cache` (`flush(): void`), `Redirects\RuleValidator` (`validate(array,int): array|WP_Error`).
- Produces: `final class RedirectsController implements Hookable { __construct(Repository, Cache, RuleValidator); register(): void; register_routes(): void; can_manage(): bool; index/create/update/delete/bulk(WP_REST_Request): WP_REST_Response|WP_Error; }`. Routes under `openseo/v1`: `GET|POST /redirects`, `POST /redirects/bulk`, `PUT|DELETE /redirects/(?P<id>\d+)`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/RedirectsRestTest.php`:

```php
<?php
/**
 * Integration tests for the redirects REST controller.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\RuleValidator;
use OpenSEO\Rest\RedirectsController;
use WP_REST_Request;
use WP_UnitTestCase;

final class RedirectsRestTest extends WP_UnitTestCase {

	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new Repository();
		$cache      = new Cache( $this->repo );
		$controller = new RedirectsController( $this->repo, $cache, new RuleValidator( $this->repo ) );
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function json( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( array() !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/openseo/v1/redirects', $routes );
		$this->assertArrayHasKey( '/openseo/v1/redirects/bulk', $routes );
		$this->assertArrayHasKey( '/openseo/v1/redirects/(?P<id>\d+)', $routes );
	}

	public function test_anonymous_is_denied(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->json( 'GET', '/openseo/v1/redirects' )->get_status() );
	}

	public function test_create_then_list_and_search(): void {
		$created = $this->json(
			'POST',
			'/openseo/v1/redirects',
			array( 'source_path' => '/from-a', 'target' => 'https://example.com/to', 'status_code' => 301 )
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( '/from-a', $created->get_data()['source_path'] );

		$list = $this->json( 'GET', '/openseo/v1/redirects' )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( '/from-a', $list['items'][0]['source_path'] );

		$search = $this->json( 'GET', '/openseo/v1/redirects?search=nomatch' )->get_data();
		$this->assertSame( 0, $search['total'] );
	}

	public function test_create_invalid_status_is_400(): void {
		$res = $this->json( 'POST', '/openseo/v1/redirects', array( 'source_path' => '/x', 'target' => 'https://e.com', 'status_code' => 999 ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'openseo_invalid', $res->get_data()['code'] );
	}

	public function test_create_cycle_is_400(): void {
		$this->json( 'POST', '/openseo/v1/redirects', array( 'source_path' => '/new', 'target' => '/old', 'status_code' => 301 ) );
		$res = $this->json( 'POST', '/openseo/v1/redirects', array( 'source_path' => '/old', 'target' => '/new', 'status_code' => 301 ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'openseo_cycle', $res->get_data()['code'] );
	}

	public function test_update_and_delete(): void {
		$id = $this->repo->create( array( 'source_path' => '/edit-me', 'target' => 'https://e.com/a', 'status_code' => 301, 'is_regex' => false, 'enabled' => true ) );

		$updated = $this->json( 'PUT', "/openseo/v1/redirects/{$id}", array( 'source_path' => '/edit-me', 'target' => 'https://e.com/b', 'status_code' => 302, 'enabled' => true ) );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 302, (int) $updated->get_data()['status_code'] );

		$deleted = $this->json( 'DELETE', "/openseo/v1/redirects/{$id}" );
		$this->assertSame( 200, $deleted->get_status() );
		$this->assertTrue( $deleted->get_data()['deleted'] );
		$this->assertNull( $this->repo->find( $id ) );
	}

	public function test_bulk_disable_then_delete(): void {
		$a = $this->repo->create( array( 'source_path' => '/a', 'target' => 'https://e.com/a', 'status_code' => 301, 'is_regex' => false, 'enabled' => true ) );
		$b = $this->repo->create( array( 'source_path' => '/b', 'target' => 'https://e.com/b', 'status_code' => 301, 'is_regex' => false, 'enabled' => true ) );

		$disabled = $this->json( 'POST', '/openseo/v1/redirects/bulk', array( 'action' => 'disable', 'ids' => array( $a, $b ) ) );
		$this->assertSame( 2, $disabled->get_data()['affected'] );
		$this->assertSame( 0, $this->repo->count_active() );

		$this->json( 'POST', '/openseo/v1/redirects/bulk', array( 'action' => 'delete', 'ids' => array( $a, $b ) ) );
		$this->assertSame( 0, $this->repo->count_all() );
	}

	public function test_bulk_rejects_unknown_action(): void {
		$res = $this->json( 'POST', '/openseo/v1/redirects/bulk', array( 'action' => 'nuke', 'ids' => array( 1 ) ) );
		$this->assertSame( 400, $res->get_status() );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run (wp-env up — `npm run env:start`):
`npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RedirectsRestTest`
Expected: FAIL — `Class "OpenSEO\Rest\RedirectsController" not found`.

- [ ] **Step 3: Create the controller**

Create `src/Rest/RedirectsController.php`:

```php
<?php
/**
 * REST controller for redirect rules (CRUD + bulk).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Rest;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\RuleValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes /openseo/v1/redirects. Writes flow through RuleValidator and flush the
 * ruleset cache. Auth: manage_options; nonce via apiFetch's X-WP-Nonce middleware.
 */
final class RedirectsController implements Hookable {

	public const REST_NAMESPACE = 'openseo/v1';

	/**
	 * @param Repository    $repo      Redirect rule repository.
	 * @param Cache         $cache     Redirect ruleset cache.
	 * @param RuleValidator $validator Shared create/update validator.
	 */
	public function __construct(
		private readonly Repository $repo,
		private readonly Cache $cache,
		private readonly RuleValidator $validator,
	) {}

	/**
	 * Register the REST routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the collection, bulk, and single-item routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
						'search'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);
	}

	/**
	 * Capability gate.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /redirects — paginated, optionally searched list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );
		$search   = (string) $request['search'];
		$offset   = ( $page - 1 ) * $per_page;

		return new WP_REST_Response(
			array(
				'items' => $this->repo->all( $per_page, $offset, $search ),
				'total' => $this->repo->count_all( $search ),
			),
			200
		);
	}

	/**
	 * POST /redirects — create a rule.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$clean = $this->validator->validate( (array) $request->get_json_params(), 0 );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$id = $this->repo->create( $clean );
		$this->cache->flush();

		return new WP_REST_Response( $this->repo->find( $id ), 201 );
	}

	/**
	 * PUT /redirects/<id> — edit a rule.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( null === $this->repo->find( $id ) ) {
			return new WP_Error( 'openseo_not_found', __( 'Redirect not found.', 'openseo' ), array( 'status' => 404 ) );
		}

		$clean = $this->validator->validate( (array) $request->get_json_params(), $id );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$this->repo->update( $id, $clean );
		$this->cache->flush();

		return new WP_REST_Response( $this->repo->find( $id ), 200 );
	}

	/**
	 * DELETE /redirects/<id> — delete a rule.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$this->repo->delete( (int) $request['id'] );
		$this->cache->flush();

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /redirects/bulk — enable/disable/delete a set of ids (one flush).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body   = (array) $request->get_json_params();
		$action = isset( $body['action'] ) ? (string) $body['action'] : '';
		$ids    = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_map( 'absint', $body['ids'] ) : array();
		$ids    = array_values( array_filter( $ids ) );

		if ( ! in_array( $action, array( 'enable', 'disable', 'delete' ), true ) || array() === $ids ) {
			return new WP_Error( 'openseo_invalid', __( 'Invalid bulk action.', 'openseo' ), array( 'status' => 400 ) );
		}

		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				$this->repo->delete( $id );
			} else {
				$this->repo->set_enabled( $id, 'enable' === $action );
			}
		}
		$this->cache->flush();

		return new WP_REST_Response( array( 'affected' => count( $ids ) ), 200 );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RedirectsRestTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Lint + analyze**

Run: `composer lint` then `composer analyze`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/RedirectsController.php tests/Integration/RedirectsRestTest.php
git commit -m "feat(rest): add openseo/v1/redirects controller (CRUD + bulk)"
```

---

## Task 3: `Rest\NotFoundController`

**Files:**
- Create: `src/Rest/NotFoundController.php`
- Test: `tests/Integration/NotFoundRestTest.php`

**Interfaces:**
- Consumes: `NotFound\LogRepository` (`all(int,int): array`, `count_all(): int`, `delete(int): bool`, `clear(): void`).
- Produces: `final class NotFoundController implements Hookable { __construct(LogRepository); register(): void; register_routes(): void; can_manage(): bool; index/delete/clear(WP_REST_Request): WP_REST_Response; }`. Routes: `GET|DELETE /notfound`, `DELETE /notfound/(?P<id>\d+)`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/NotFoundRestTest.php`:

```php
<?php
/**
 * Integration tests for the 404 log REST controller.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\NotFound\LogRepository;
use OpenSEO\Rest\NotFoundController;
use WP_REST_Request;
use WP_UnitTestCase;

final class NotFoundRestTest extends WP_UnitTestCase {

	private LogRepository $log;

	public function set_up(): void {
		parent::set_up();
		$this->log  = new LogRepository();
		$controller = new NotFoundController( $this->log );
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_routes_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/openseo/v1/notfound', $routes );
		$this->assertArrayHasKey( '/openseo/v1/notfound/(?P<id>\d+)', $routes );
	}

	public function test_anonymous_denied(): void {
		wp_set_current_user( 0 );
		$res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/openseo/v1/notfound' ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_list_and_clear(): void {
		$this->log->record( '/missing-1' );
		$this->log->record( '/missing-2' );

		$list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/openseo/v1/notfound' ) )->get_data();
		$this->assertSame( 2, $list['total'] );

		$cleared = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/openseo/v1/notfound' ) );
		$this->assertSame( 200, $cleared->get_status() );
		$this->assertTrue( $cleared->get_data()['cleared'] );
		$this->assertSame( 0, $this->log->count_all() );
	}

	public function test_delete_one(): void {
		$this->log->record( '/missing-3' );
		$rows = $this->log->all( 20, 0 );
		$id   = (int) $rows[0]['id'];

		$res = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', "/openseo/v1/notfound/{$id}" ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 0, $this->log->count_all() );
	}
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NotFoundRestTest`
Expected: FAIL — `Class "OpenSEO\Rest\NotFoundController" not found`.

- [ ] **Step 3: Create the controller**

Create `src/Rest/NotFoundController.php`:

```php
<?php
/**
 * REST controller for the 404 log (list, delete one, clear all).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Rest;

use OpenSEO\Contracts\Hookable;
use OpenSEO\NotFound\LogRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes /openseo/v1/notfound. Auth: manage_options; nonce via apiFetch.
 */
final class NotFoundController implements Hookable {

	public const REST_NAMESPACE = 'openseo/v1';

	/**
	 * @param LogRepository $log 404 log data access.
	 */
	public function __construct( private readonly LogRepository $log ) {}

	/**
	 * Register the REST routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the collection (list/clear) and single-item (delete) routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/notfound',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/notfound/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	/**
	 * Capability gate.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /notfound — paginated list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		return new WP_REST_Response(
			array(
				'items' => $this->log->all( $per_page, $offset ),
				'total' => $this->log->count_all(),
			),
			200
		);
	}

	/**
	 * DELETE /notfound/<id> — delete one logged URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$this->log->delete( (int) $request['id'] );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * DELETE /notfound — clear the whole log.
	 */
	public function clear(): WP_REST_Response {
		$this->log->clear();

		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run env:run -- tests-cli --env-cwd=wp-content/plugins/openseo vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NotFoundRestTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Lint + analyze**

Run: `composer lint` then `composer analyze`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/NotFoundController.php tests/Integration/NotFoundRestTest.php
git commit -m "feat(rest): add openseo/v1/notfound controller (list, delete, clear)"
```

---

## Task 4: React data layer — `api.js`, `listReducer`, hooks, `DataTable`

**Files:**
- Modify: `assets/src/admin/api.js`
- Create: `assets/src/admin/hooks/listReducer.js`
- Create: `assets/src/admin/hooks/listReducer.test.js`
- Create: `assets/src/admin/hooks/useRedirects.js`
- Create: `assets/src/admin/hooks/useNotfound.js`
- Create: `assets/src/admin/components/DataTable.js`

**Interfaces:**
- Consumes: REST routes from Tasks 2-3.
- Produces: `api.js` adds `getRedirects/createRedirect/updateRedirect/deleteRedirect/bulkRedirects/getNotfound/deleteNotfound/clearNotfound`. `listReducer(state, action)` (pure). `useRedirects()` → `{ items, total, loading, error, query, setQuery, save, remove, bulk, refresh }`. `useNotfound()` → `{ items, total, loading, error, query, setQuery, remove, clear, refresh }`. `DataTable` component (props per spec).

- [ ] **Step 1: Write the failing reducer test**

Create `assets/src/admin/hooks/listReducer.test.js`:

```js
import { listReducer } from './listReducer';

describe( 'listReducer', () => {
	const base = { items: [], total: 0, loading: false, error: '' };

	it( 'sets loading and clears error on LOADING', () => {
		const next = listReducer( { ...base, error: 'x' }, { type: 'LOADING' } );
		expect( next.loading ).toBe( true );
		expect( next.error ).toBe( '' );
	} );

	it( 'stores items + total and clears loading on LOADED', () => {
		const next = listReducer( { ...base, loading: true }, { type: 'LOADED', items: [ { id: 1 } ], total: 5 } );
		expect( next.items ).toHaveLength( 1 );
		expect( next.total ).toBe( 5 );
		expect( next.loading ).toBe( false );
	} );

	it( 'records error and stops loading on ERROR', () => {
		const next = listReducer( { ...base, loading: true }, { type: 'ERROR', error: 'boom' } );
		expect( next.error ).toBe( 'boom' );
		expect( next.loading ).toBe( false );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run test:js -- --testPathPattern=listReducer`
Expected: FAIL — cannot find module `./listReducer`.

- [ ] **Step 3: Create `listReducer.js`**

Create `assets/src/admin/hooks/listReducer.js`:

```js
/**
 * Pure reducer shared by useRedirects/useNotfound for async list state.
 */
export function listReducer( state, action ) {
	switch ( action.type ) {
		case 'LOADING':
			return { ...state, loading: true, error: '' };
		case 'LOADED':
			return { items: action.items, total: action.total, loading: false, error: '' };
		case 'ERROR':
			return { ...state, loading: false, error: action.error };
		default:
			return state;
	}
}

export const INITIAL_LIST = { items: [], total: 0, loading: true, error: '' };
```

- [ ] **Step 4: Run the reducer test to verify it passes**

Run: `npm run test:js -- --testPathPattern=listReducer`
Expected: PASS (3 tests).

- [ ] **Step 5: Add REST functions to `api.js`**

In `assets/src/admin/api.js`: add the `addQueryArgs` import **once**, on its own line right beside the existing `import apiFetch from '@wordpress/api-fetch';` at the top of the file (do NOT duplicate it). Keep the existing `getSettings`/`saveSettings`. Then append the new functions below them:

```js
// At the top, alongside the existing `import apiFetch from '@wordpress/api-fetch';`:
import { addQueryArgs } from '@wordpress/url';

// ... existing getSettings / saveSettings stay unchanged ...

export function getRedirects( { page = 1, perPage = 20, search = '' } = {} ) {
	return apiFetch( {
		path: addQueryArgs( '/openseo/v1/redirects', { page, per_page: perPage, search } ),
	} );
}

export function createRedirect( data ) {
	return apiFetch( { path: '/openseo/v1/redirects', method: 'POST', data } );
}

export function updateRedirect( id, data ) {
	return apiFetch( { path: `/openseo/v1/redirects/${ id }`, method: 'PUT', data } );
}

export function deleteRedirect( id ) {
	return apiFetch( { path: `/openseo/v1/redirects/${ id }`, method: 'DELETE' } );
}

export function bulkRedirects( action, ids ) {
	return apiFetch( { path: '/openseo/v1/redirects/bulk', method: 'POST', data: { action, ids } } );
}

export function getNotfound( { page = 1, perPage = 20 } = {} ) {
	return apiFetch( { path: addQueryArgs( '/openseo/v1/notfound', { page, per_page: perPage } ) } );
}

export function deleteNotfound( id ) {
	return apiFetch( { path: `/openseo/v1/notfound/${ id }`, method: 'DELETE' } );
}

export function clearNotfound() {
	return apiFetch( { path: '/openseo/v1/notfound', method: 'DELETE' } );
}
```

- [ ] **Step 6: Create `useRedirects.js`**

Create `assets/src/admin/hooks/useRedirects.js`:

```js
import { useReducer, useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { listReducer, INITIAL_LIST } from './listReducer';
import {
	getRedirects,
	createRedirect,
	updateRedirect,
	deleteRedirect,
	bulkRedirects,
} from '../api';

export function useRedirects() {
	const [ state, dispatch ] = useReducer( listReducer, INITIAL_LIST );
	const [ query, setQuery ] = useState( { page: 1, search: '' } );

	const load = useCallback( async ( q ) => {
		dispatch( { type: 'LOADING' } );
		try {
			const { items, total } = await getRedirects( { page: q.page, search: q.search } );
			dispatch( { type: 'LOADED', items, total } );
		} catch ( e ) {
			dispatch( { type: 'ERROR', error: e?.message || __( 'Could not load redirects.', 'openseo' ) } );
		}
	}, [] );

	useEffect( () => {
		load( query );
	}, [ query, load ] );

	const refresh = useCallback( () => load( query ), [ load, query ] );

	const save = useCallback(
		async ( data, id = 0 ) => {
			const saved = id ? await updateRedirect( id, data ) : await createRedirect( data );
			await load( query );
			return saved;
		},
		[ load, query ]
	);

	const remove = useCallback(
		async ( id ) => {
			await deleteRedirect( id );
			await load( query );
		},
		[ load, query ]
	);

	const bulk = useCallback(
		async ( action, ids ) => {
			await bulkRedirects( action, ids );
			await load( query );
		},
		[ load, query ]
	);

	return { ...state, query, setQuery, refresh, save, remove, bulk };
}
```

- [ ] **Step 7: Create `useNotfound.js`**

Create `assets/src/admin/hooks/useNotfound.js`:

```js
import { useReducer, useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { listReducer, INITIAL_LIST } from './listReducer';
import { getNotfound, deleteNotfound, clearNotfound } from '../api';

export function useNotfound() {
	const [ state, dispatch ] = useReducer( listReducer, INITIAL_LIST );
	const [ query, setQuery ] = useState( { page: 1 } );

	const load = useCallback( async ( q ) => {
		dispatch( { type: 'LOADING' } );
		try {
			const { items, total } = await getNotfound( { page: q.page } );
			dispatch( { type: 'LOADED', items, total } );
		} catch ( e ) {
			dispatch( { type: 'ERROR', error: e?.message || __( 'Could not load 404s.', 'openseo' ) } );
		}
	}, [] );

	useEffect( () => {
		load( query );
	}, [ query, load ] );

	const refresh = useCallback( () => load( query ), [ load, query ] );

	const remove = useCallback(
		async ( id ) => {
			await deleteNotfound( id );
			await load( query );
		},
		[ load, query ]
	);

	const clear = useCallback( async () => {
		await clearNotfound();
		await load( query );
	}, [ load, query ] );

	return { ...state, query, setQuery, refresh, remove, clear };
}
```

- [ ] **Step 8: Create `DataTable.js`**

Create `assets/src/admin/components/DataTable.js`:

```js
import {
	Button,
	CheckboxControl,
	SearchControl,
	Spinner,
	Flex,
	FlexItem,
	FlexBlock,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const PER_PAGE = 20;

export function DataTable( {
	columns,
	items,
	total,
	page,
	loading,
	searchable = false,
	search = '',
	onSearch,
	onPageChange,
	selectable = false,
	selected = [],
	onSelectionChange,
	rowActions,
	bulkActions = [],
	emptyLabel = __( 'Nothing here yet.', 'openseo' ),
} ) {
	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const allChecked = items.length > 0 && selected.length === items.length;

	const toggleAll = ( on ) =>
		onSelectionChange( on ? items.map( ( i ) => i.id ) : [] );

	const toggleOne = ( id, on ) =>
		onSelectionChange( on ? [ ...selected, id ] : selected.filter( ( s ) => s !== id ) );

	return (
		<div className="openseo-datatable">
			<Flex className="openseo-datatable__toolbar" justify="space-between">
				<FlexItem>
					{ selectable && selected.length > 0 ? (
						<Flex gap={ 2 }>
							{ bulkActions.map( ( a ) => (
								<Button
									key={ a.action }
									variant="secondary"
									isDestructive={ a.destructive }
									onClick={ () => a.onClick( selected ) }
								>
									{ a.label }
								</Button>
							) ) }
							<span>
								{ sprintf(
									/* translators: %d: number of selected rows. */
									__( '%d selected', 'openseo' ),
									selected.length
								) }
							</span>
						</Flex>
					) : null }
				</FlexItem>
				<FlexItem>
					{ searchable ? (
						<SearchControl
							value={ search }
							onChange={ onSearch }
							label={ __( 'Search', 'openseo' ) }
							__nextHasNoMarginBottom
						/>
					) : null }
				</FlexItem>
			</Flex>

			{ loading ? (
				<div className="openseo-datatable__loading">
					<Spinner />
				</div>
			) : (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							{ selectable ? (
								<td className="check-column">
									<CheckboxControl
										__nextHasNoMarginBottom
										checked={ allChecked }
										onChange={ toggleAll }
										label=""
									/>
								</td>
							) : null }
							{ columns.map( ( c ) => (
								<th key={ c.id }>{ c.label }</th>
							) ) }
							{ rowActions ? <th>{ __( 'Actions', 'openseo' ) }</th> : null }
						</tr>
					</thead>
					<tbody>
						{ items.length === 0 ? (
							<tr>
								<td colSpan={ columns.length + ( selectable ? 1 : 0 ) + ( rowActions ? 1 : 0 ) }>
									{ emptyLabel }
								</td>
							</tr>
						) : (
							items.map( ( item ) => (
								<tr key={ item.id }>
									{ selectable ? (
										<th scope="row" className="check-column">
											<CheckboxControl
												__nextHasNoMarginBottom
												checked={ selected.includes( item.id ) }
												onChange={ ( on ) => toggleOne( item.id, on ) }
												label=""
											/>
										</th>
									) : null }
									{ columns.map( ( c ) => (
										<td key={ c.id }>{ c.render ? c.render( item ) : item[ c.id ] }</td>
									) ) }
									{ rowActions ? <td>{ rowActions( item ) }</td> : null }
								</tr>
							) )
						) }
					</tbody>
				</table>
			) }

			{ totalPages > 1 ? (
				<Flex className="openseo-datatable__pager" justify="flex-end" gap={ 2 }>
					<FlexBlock />
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => onPageChange( page - 1 ) }
					>
						{ __( 'Previous', 'openseo' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'openseo' ),
							page,
							totalPages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => onPageChange( page + 1 ) }
					>
						{ __( 'Next', 'openseo' ) }
					</Button>
				</Flex>
			) : null }
		</div>
	);
}
```

- [ ] **Step 9: Build + lint**

Run: `npm run build` then `npm run lint:js` then `npm run test:js -- --testPathPattern=listReducer`
Expected: build succeeds; lint clean (fix Prettier formatting if flagged); reducer 3/3 still pass.

- [ ] **Step 10: Commit**

```bash
git add assets/src/admin/api.js assets/src/admin/hooks/listReducer.js assets/src/admin/hooks/listReducer.test.js assets/src/admin/hooks/useRedirects.js assets/src/admin/hooks/useNotfound.js assets/src/admin/components/DataTable.js
git commit -m "feat(admin): add REST client, list reducer/hooks, and DataTable component"
```

---

## Task 5: React views — `Redirects.js`, `NotFound.js`, registry, styles

**Files:**
- Create: `assets/src/admin/views/Redirects.js`
- Create: `assets/src/admin/views/NotFound.js`
- Modify: `assets/src/admin/App.js`
- Modify: `assets/src/admin/style.scss`

**Interfaces:**
- Consumes: `useRedirects`/`useNotfound` (Task 4), `DataTable` (Task 4), `useSettings` (Fase 1, for the toggles), `getQueryArg` from `@wordpress/url` (read `?source=`).
- Produces: `Redirects`/`NotFound` view components; registered in `App.js`'s `VIEWS`.

- [ ] **Step 1: Create `Redirects.js`**

Create `assets/src/admin/views/Redirects.js`:

```js
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Modal,
	TextControl,
	SelectControl,
	ToggleControl,
	Notice,
	Flex,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getQueryArg } from '@wordpress/url';
import { DataTable } from '../components/DataTable';
import { useRedirects } from '../hooks/useRedirects';
import { useSettings } from '../hooks/useSettings';

const STATUSES = [
	{ label: '301', value: '301' },
	{ label: '302', value: '302' },
	{ label: '307', value: '307' },
	{ label: '410', value: '410' },
];

const EMPTY_RULE = { id: 0, source_path: '', target: '', status_code: '301', is_regex: false, enabled: true };

// Strip a scheme-bearing or marked-up prefill before showing it (defense; the
// server validates again on save).
function safeSource( raw ) {
	const value = String( raw || '' );
	if ( value.includes( ':' ) || value.includes( '<' ) ) {
		return '';
	}
	return value;
}

export function Redirects() {
	const { items, total, loading, error, query, setQuery, save, remove, bulk } = useRedirects();
	const settings = useSettings( window.openseoAdmin?.settings ?? {} );
	const [ editing, setEditing ] = useState( null ); // null = modal closed
	const [ formError, setFormError ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );

	// Open the create modal pre-filled when arriving from a 404 row.
	useEffect( () => {
		const prefill = safeSource( getQueryArg( window.location.href, 'source' ) );
		if ( prefill ) {
			setEditing( { ...EMPTY_RULE, source_path: prefill } );
		}
	}, [] );

	const onSave = async () => {
		setFormError( '' );
		try {
			await save(
				{
					source_path: editing.source_path,
					target: editing.target,
					status_code: parseInt( editing.status_code, 10 ),
					is_regex: editing.is_regex,
					enabled: editing.enabled,
				},
				editing.id
			);
			setEditing( null );
		} catch ( e ) {
			setFormError( e?.message || __( 'Could not save the redirect.', 'openseo' ) );
		}
	};

	const columns = [
		{ id: 'source_path', label: __( 'Source', 'openseo' ), render: ( r ) => (
			<>
				{ r.source_path }{ ' ' }
				{ Number( r.is_regex ) === 1 ? <em>{ __( 'regex', 'openseo' ) }</em> : null }
			</>
		) },
		{ id: 'target', label: __( 'Target', 'openseo' ) },
		{ id: 'status_code', label: __( 'Type', 'openseo' ) },
		{ id: 'enabled', label: __( 'Status', 'openseo' ), render: ( r ) =>
			Number( r.enabled ) === 1 ? __( 'Enabled', 'openseo' ) : __( 'Disabled', 'openseo' ) },
		{ id: 'hits', label: __( 'Hits', 'openseo' ) },
	];

	const rowActions = ( r ) => (
		<Flex gap={ 1 } justify="flex-start">
			<Button variant="link" onClick={ () => setEditing( {
				id: Number( r.id ),
				source_path: r.source_path,
				target: r.target,
				status_code: String( r.status_code ),
				is_regex: Number( r.is_regex ) === 1,
				enabled: Number( r.enabled ) === 1,
			} ) }>{ __( 'Edit', 'openseo' ) }</Button>
			<Button variant="link" onClick={ () => bulk( Number( r.enabled ) === 1 ? 'disable' : 'enable', [ Number( r.id ) ] ) }>
				{ Number( r.enabled ) === 1 ? __( 'Disable', 'openseo' ) : __( 'Enable', 'openseo' ) }
			</Button>
			<Button variant="link" isDestructive onClick={ () => {
				// eslint-disable-next-line no-alert
				if ( window.confirm( __( 'Delete this redirect?', 'openseo' ) ) ) {
					remove( Number( r.id ) );
				}
			} }>{ __( 'Delete', 'openseo' ) }</Button>
		</Flex>
	);

	const bulkActions = [
		{ action: 'enable', label: __( 'Enable', 'openseo' ), onClick: ( ids ) => { bulk( 'enable', ids ); setSelected( [] ); } },
		{ action: 'disable', label: __( 'Disable', 'openseo' ), onClick: ( ids ) => { bulk( 'disable', ids ); setSelected( [] ); } },
		{ action: 'delete', label: __( 'Delete', 'openseo' ), destructive: true, onClick: ( ids ) => {
			// eslint-disable-next-line no-alert
			if ( window.confirm( __( 'Delete the selected redirects?', 'openseo' ) ) ) { bulk( 'delete', ids ); setSelected( [] ); }
		} },
	];

	return (
		<div className="openseo-panel">
			<h2>{ __( 'Settings', 'openseo' ) }</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Auto-redirect on slug change', 'openseo' ) }
				checked={ settings.values.redirects_auto_slug === '1' }
				onChange={ ( on ) => settings.change( 'redirects_auto_slug', on ? '1' : '' ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Track redirect hits', 'openseo' ) }
				checked={ settings.values.redirects_track_hits === '1' }
				onChange={ ( on ) => settings.change( 'redirects_track_hits', on ? '1' : '' ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Default redirect type', 'openseo' ) }
				value={ settings.values.redirects_default_status }
				options={ [
					{ label: '301', value: '301' },
					{ label: '302', value: '302' },
					{ label: '307', value: '307' },
				] }
				onChange={ ( v ) => settings.change( 'redirects_default_status', v ) }
			/>
			<Button variant="primary" isBusy={ settings.saving } disabled={ ! settings.dirty || settings.saving } onClick={ settings.save }>
				{ settings.saving ? __( 'Saving…', 'openseo' ) : __( 'Save settings', 'openseo' ) }
			</Button>

			<Flex justify="space-between" style={ { marginTop: '24px' } }>
				<h2>{ __( 'Redirects', 'openseo' ) }</h2>
				<Button variant="primary" onClick={ () => setEditing( { ...EMPTY_RULE } ) }>
					{ __( 'Add redirect', 'openseo' ) }
				</Button>
			</Flex>

			{ error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : null }

			<DataTable
				columns={ columns }
				items={ items }
				total={ total }
				page={ query.page }
				loading={ loading }
				searchable
				search={ query.search }
				onSearch={ ( s ) => setQuery( { page: 1, search: s } ) }
				onPageChange={ ( p ) => setQuery( { ...query, page: p } ) }
				selectable
				selected={ selected }
				onSelectionChange={ setSelected }
				rowActions={ rowActions }
				bulkActions={ bulkActions }
				emptyLabel={ __( 'No redirects yet.', 'openseo' ) }
			/>

			{ editing !== null && (
				<Modal title={ editing.id ? __( 'Edit redirect', 'openseo' ) : __( 'Add redirect', 'openseo' ) } onRequestClose={ () => setEditing( null ) }>
					{ formError ? <Notice status="error" isDismissible={ false }>{ formError }</Notice> : null }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Source path', 'openseo' ) }
						value={ editing.source_path }
						onChange={ ( v ) => setEditing( { ...editing, source_path: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Type', 'openseo' ) }
						value={ editing.status_code }
						options={ STATUSES }
						onChange={ ( v ) => setEditing( { ...editing, status_code: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Target', 'openseo' ) }
						value={ editing.target }
						disabled={ editing.status_code === '410' }
						onChange={ ( v ) => setEditing( { ...editing, target: v } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Regex source', 'openseo' ) }
						checked={ editing.is_regex }
						onChange={ ( on ) => setEditing( { ...editing, is_regex: on } ) }
					/>
					<Flex justify="flex-end" gap={ 2 } style={ { marginTop: '16px' } }>
						<Button variant="tertiary" onClick={ () => setEditing( null ) }>{ __( 'Cancel', 'openseo' ) }</Button>
						<Button variant="primary" onClick={ onSave }>{ __( 'Save redirect', 'openseo' ) }</Button>
					</Flex>
				</Modal>
			) }
		</div>
	);
}
```

- [ ] **Step 2: Create `NotFound.js`**

Create `assets/src/admin/views/NotFound.js`:

```js
import { Button, ToggleControl, TextControl, Notice, Flex } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { DataTable } from '../components/DataTable';
import { useNotfound } from '../hooks/useNotfound';
import { useSettings } from '../hooks/useSettings';

export function NotFound() {
	const { items, total, loading, error, query, setQuery, remove, clear } = useNotfound();
	const settings = useSettings( window.openseoAdmin?.settings ?? {} );

	const columns = [
		{ id: 'url', label: __( 'URL', 'openseo' ) },
		{ id: 'hits', label: __( 'Hits', 'openseo' ) },
		{ id: 'last_seen', label: __( 'Last seen', 'openseo' ) },
	];

	const rowActions = ( r ) => (
		<Flex gap={ 1 } justify="flex-start">
			<Button
				variant="link"
				href={ `admin.php?page=openseo-redirects&source=${ encodeURIComponent( r.url ) }` }
			>
				{ __( 'Create redirect', 'openseo' ) }
			</Button>
			<Button variant="link" isDestructive onClick={ () => remove( Number( r.id ) ) }>
				{ __( 'Delete', 'openseo' ) }
			</Button>
		</Flex>
	);

	return (
		<div className="openseo-panel">
			<h2>{ __( 'Settings', 'openseo' ) }</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Enable 404 monitor', 'openseo' ) }
				checked={ settings.values.notfound_monitor_enabled === '1' }
				onChange={ ( on ) => settings.change( 'notfound_monitor_enabled', on ? '1' : '' ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( '404 retention (days)', 'openseo' ) }
				value={ settings.values.notfound_retention_days }
				onChange={ ( v ) => settings.change( 'notfound_retention_days', v ) }
			/>
			<Button variant="primary" isBusy={ settings.saving } disabled={ ! settings.dirty || settings.saving } onClick={ settings.save }>
				{ settings.saving ? __( 'Saving…', 'openseo' ) : __( 'Save settings', 'openseo' ) }
			</Button>

			<Flex justify="space-between" style={ { marginTop: '24px' } }>
				<h2>{ __( 'Logged 404s', 'openseo' ) }</h2>
				<Button
					variant="secondary"
					isDestructive
					disabled={ total === 0 }
					onClick={ () => {
						// eslint-disable-next-line no-alert
						if ( window.confirm( __( 'Clear the entire 404 log?', 'openseo' ) ) ) {
							clear();
						}
					} }
				>
					{ __( 'Clear log', 'openseo' ) }
				</Button>
			</Flex>

			{ error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : null }

			<DataTable
				columns={ columns }
				items={ items }
				total={ total }
				page={ query.page }
				loading={ loading }
				onPageChange={ ( p ) => setQuery( { page: p } ) }
				rowActions={ rowActions }
				emptyLabel={ __( 'No 404s logged.', 'openseo' ) }
			/>
		</div>
	);
}
```

- [ ] **Step 3: Register the views in `App.js`**

Replace the contents of `assets/src/admin/App.js`:

```js
import { Dashboard } from './views/Dashboard';
import { General } from './views/General';
import { Titles } from './views/Titles';
import { Social } from './views/Social';
import { Sitemaps } from './views/Sitemaps';
import { Schema } from './views/Schema';
import { Ai } from './views/Ai';
import { Redirects } from './views/Redirects';
import { NotFound } from './views/NotFound';

const VIEWS = {
	dashboard: Dashboard,
	general: General,
	titles: Titles,
	social: Social,
	sitemaps: Sitemaps,
	schema: Schema,
	redirects: Redirects,
	notfound: NotFound,
	ai: Ai,
};

export function App( { view } ) {
	const View = VIEWS[ view ] ?? Dashboard;
	return <View />;
}
```

- [ ] **Step 4: Add table styles to `style.scss`**

Append to `assets/src/admin/style.scss`:

```scss
.openseo-datatable {
	margin-top: 12px;

	&__toolbar {
		margin-bottom: 12px;
	}

	&__loading {
		padding: 32px;
		text-align: center;
	}

	&__pager {
		margin-top: 12px;
	}
}
```

- [ ] **Step 5: Build + lint**

Run: `npm run build`, then `npm run lint:js`, then `npm run lint:css`
Expected: build succeeds; lint clean (apply Prettier fixes if flagged). The bundle now includes the Redirects/NotFound views.

- [ ] **Step 6: Commit**

```bash
git add assets/src/admin/views/Redirects.js assets/src/admin/views/NotFound.js assets/src/admin/App.js assets/src/admin/style.scss
git commit -m "feat(admin): add React Redirects + 404 views (CRUD, bulk, toggles)"
```

---

## Task 6: Rewire + cleanup (atomic) + docs + final verification

> **COMMIT ATÓMICO (obligatorio).** Cambiar `Menu`/`Plugin` y borrar las páginas/tablas PHP debe ir
> en UN commit, con las vistas React (Tasks 4-5) ya construidas. No commitees ni corras los gates con
> el árbol a medias: las verificaciones van al final (Steps 8-10), tras el recableo.

**Files:**
- Modify: `src/Admin/Menu.php` (drop `$php_pages`; add `view` to the two entries)
- Modify: `src/Plugin.php` (wire controllers; remove RedirectsPage/NotFoundPage/BehaviorSettings)
- Modify: `src/Admin/Assets.php` (drop the now-dead React-only early-return — all screens are React)
- Modify: `tests/Integration/MenuTest.php` (new `Menu()` signature; all-React assertions)
- Modify: `tests/Integration/MenuWiringTest.php` (C1: `new Menu()` — no constructor arg)
- Modify: `tests/Integration/RedirectsRestTest.php`, `tests/Integration/NotFoundRestTest.php` (H1: drop the now-redundant self `add_action`; the plugin registers the routes)
- Delete: `src/Redirects/Admin/RedirectsPage.php`, `src/Redirects/Admin/RedirectsListTable.php`, `src/NotFound/Admin/NotFoundPage.php`, `src/NotFound/Admin/NotFoundListTable.php`, `src/Settings/BehaviorSettings.php`, `templates/admin/redirects-page.php`, `templates/admin/notfound-page.php`, `templates/admin/notfound-panel.php`, `tests/Integration/BehaviorSettingsTest.php`, `tests/Integration/NotFoundLinkTest.php`
- Modify: `CLAUDE.md`, `NOTES.md`

**Interfaces:**
- Consumes: `Rest\RedirectsController`, `Rest\NotFoundController`, `Redirects\RuleValidator` (Tasks 1-3); React `redirects`/`notfound` views (Task 5).
- Produces: `Menu` with no constructor args (all 9 submenus are React); `Plugin::modules()` wiring the two controllers (always-on) and no longer the PHP pages.

- [ ] **Step 1: Give the two PHP submenus React views in `Menu::pages()`**

In `src/Admin/Menu.php`, add `'view'` to the redirects and 404 entries (lines 159-166):

```php
			array(
				'slug'  => 'openseo-redirects',
				'title' => __( 'Redirects', 'openseo' ),
				'view'  => 'redirects',
			),
			array(
				'slug'  => 'openseo-404s',
				'title' => __( '404s', 'openseo' ),
				'view'  => 'notfound',
			),
```

- [ ] **Step 2: Drop the `$php_pages` constructor + PHP-callback branch in `Menu`**

In `src/Admin/Menu.php`:

Replace the constructor (lines 49-54) with a no-arg one:

```php
	/**
	 * Constructor.
	 */
	public function __construct() {}
```

Replace the callback assignment inside `add_menu()` (the `$is_react`/`$callback` lines 82-87) with the always-React form:

```php
			foreach ( $this->pages() as $page ) {
				$hook = (string) add_submenu_page(
					self::PARENT_SLUG,
					$page['title'],
					$page['title'],
					self::CAP,
					$page['slug'],
					function () use ( $page ): void {
						$this->render_view( $page['view'] );
					}
				);

				$this->track( $hook, true );
			}
```

Update the `pages()` PHPDoc return type to `array<int, array{slug: string, title: string, view: string}>` (every entry now has `view`), and update the class docblock line about "PHP pages render via callbacks" to note all pages are React.

Verify nothing references the removed property: `grep -rn php_pages src/Admin/Menu.php` must return **0** matches.

- [ ] **Step 3: Rewire `Plugin::modules()`**

In `src/Plugin.php`:

Remove these three `use` imports **by symbol** (NOT by line number — they are scattered: `NotFoundPage` ~line 15, `BehaviorSettings` ~line 17, `RedirectsPage` ~line 41): `use OpenSEO\NotFound\Admin\NotFoundPage;`, `use OpenSEO\Settings\BehaviorSettings;`, `use OpenSEO\Redirects\Admin\RedirectsPage;`. Add:

```php
use OpenSEO\Rest\RedirectsController;
use OpenSEO\Rest\NotFoundController;
use OpenSEO\Redirects\RuleValidator;
```

Keep the existing `$modules[] = new SettingsController( $options );` line (~164). Replace it **and** the whole `if ( is_admin() ) { ... }` block that follows (~lines 164-183) with:

```php
		$modules[] = new SettingsController( $options );
		$modules[] = new RedirectsController( $redirects_repo, $redirects_cache, new RuleValidator( $redirects_repo ) );
		$modules[] = new NotFoundController( $not_found_log );

		if ( is_admin() ) {
			$menu = new Menu();

			$modules[] = $menu;
			$modules[] = new AdminAssets( $menu, $options, $redirects_repo, $not_found_log );
			$modules[] = new EditorPanel();
		}
```

(`BehaviorSettings`, `RedirectsPage`, `NotFoundPage` are gone; `RuleValidator` takes the repo as its `RedirectLookup`.)

- [ ] **Step 4: Delete the retired PHP surface + obsolete tests**

```bash
git rm src/Redirects/Admin/RedirectsPage.php src/Redirects/Admin/RedirectsListTable.php \
  src/NotFound/Admin/NotFoundPage.php src/NotFound/Admin/NotFoundListTable.php \
  src/Settings/BehaviorSettings.php \
  templates/admin/redirects-page.php templates/admin/notfound-page.php templates/admin/notfound-panel.php \
  tests/Integration/BehaviorSettingsTest.php tests/Integration/NotFoundLinkTest.php
```

- [ ] **Step 5: Update `MenuTest` for the all-React menu**

Replace the contents of `tests/Integration/MenuTest.php`:

```php
<?php
/**
 * Integration tests for the OpenSEO top-level admin menu (all-React).
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

	public function test_registers_parent_and_all_submenus(): void {
		global $submenu;

		( new Menu() )->add_menu();

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

	public function test_all_screens_are_react(): void {
		$menu = new Menu();
		$menu->add_menu();

		// Every OpenSEO screen now mounts the React app.
		$this->assertSame( $menu->screen_hooks(), $menu->react_screen_hooks() );
	}

	public function test_dashboard_hook_is_the_top_level_hook(): void {
		$menu = new Menu();
		$menu->add_menu();

		$this->assertSame( 'toplevel_page_openseo', $menu->dashboard_hook() );
	}
}
```

- [ ] **Step 6: Fix `MenuWiringTest` for the new `Menu()` signature (C1)**

In `tests/Integration/MenuWiringTest.php`, replace the `new Menu( array( ... ) )` construction with the no-arg constructor:

```php
		$menu = new Menu();
		$menu->add_menu();
```

(The rest — asserting `$admin_page_hooks` contains `openseo` — is unchanged.)

- [ ] **Step 7: Drop the redundant self-registration in the REST tests (H1)**

The plugin now registers `RedirectsController`/`NotFoundController` on `rest_api_init` (Step 3), and the integration bootstrap boots the plugin — so the routes are already live. Removing the tests' own `add_action` avoids double-registering the routes. In `tests/Integration/RedirectsRestTest.php`, change `set_up()` to keep only the repo + admin user:

```php
	public function set_up(): void {
		parent::set_up();
		$this->repo = new Repository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}
```

(Remove the `$cache`/`$controller`/`add_action` lines and the now-unused `Cache`/`RuleValidator`/`RedirectsController` imports.) In `tests/Integration/NotFoundRestTest.php`, likewise:

```php
	public function set_up(): void {
		parent::set_up();
		$this->log = new LogRepository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}
```

(Remove the `$controller`/`add_action` lines and the now-unused `NotFoundController` import.)

- [ ] **Step 8: Simplify `Admin\Assets` gating (M2)**

All OpenSEO screens are React now, so `screen_hooks()` ≡ `react_screen_hooks()` and the React-only early-return in `Assets::enqueue()` is dead code. In `src/Admin/Assets.php`, remove the block (current lines ~79-82):

```php
		// PHP screens (Redirects/404) get the chrome CSS only.
		if ( ! in_array( $hook_suffix, $this->menu->react_screen_hooks(), true ) ) {
			return;
		}
```

so the stylesheet, script, bootstrap, and `wp_set_script_translations` all enqueue together on every OpenSEO screen. Keep `screen_hooks()`/`react_screen_hooks()` on `Menu` (still used; now equal). `composer analyze` must stay clean.

- [ ] **Step 9: Update `CLAUDE.md`**

In `CLAUDE.md`, update the `Admin/Menu.php` "Key modules" bullet so it states all 9 submenus are React and Redirects/404 use REST controllers. Replace the sentence that says `Settings/BehaviorSettings` keeps the toggles on the (PHP) Redirects/404 pages with:

```markdown
  `Redirects/404` are now React views too: `Rest/RedirectsController` (`openseo/v1/redirects`,
  CRUD + bulk, validating through `Redirects/RuleValidator` over the `Redirects/RedirectLookup`
  interface) and `Rest/NotFoundController` (`openseo/v1/notfound`) back the `DataTable`-based
  `views/Redirects.js` / `views/NotFound.js`; the behavior toggles save via `openseo/v1/settings`.
  The tabbed Settings API surface is fully retired (no `BehaviorSettings`, no `WP_List_Table`).
```

Also update the `Redirects/` and `NotFound/` module bullets that mention "Admin surface … WP_List_Table" / "Admin/NotFoundListTable" to note the admin surface is now the React views + REST controllers (the engine/Repository/Monitor are unchanged).

- [ ] **Step 10: Add a Fase 7 note to `NOTES.md`**

Append to `NOTES.md` (after the Fase 6 section):

```markdown
### Redirec./404 a React + REST (Fase 7): qué cubre y cómo probar

Redirecciones y 404 pasaron de `WP_List_Table` (PHP) + `admin-post` CRUD + toggles Settings API a
**vistas React** sobre REST: `Rest\RedirectsController` (`openseo/v1/redirects`: GET/POST,
PUT/DELETE `/<id>`, POST `/bulk`) y `Rest\NotFoundController` (`openseo/v1/notfound`). La validación
(normalización/regex/target/whitelist/anti-bucle) vive en `Redirects\RuleValidator` sobre la interfaz
`Redirects\RedirectLookup` (testeable; `Repository` la implementa). La UI es un `DataTable` propio
(`assets/src/admin/components/DataTable.js`) con CRUD completo, edición en `Modal` y acciones masivas.
Los 5 toggles migraron a las vistas React (vía `openseo/v1/settings`); `Settings\BehaviorSettings` y
las páginas/tablas PHP se eliminaron. El motor (Dispatcher/Monitor/Pruner/SlugWatcher/Repository/
Cache) no cambió.

CI ejercita: `RuleValidatorTest` (unit), `RedirectsRestTest`/`NotFoundRestTest` (integración:
CRUD, bulk, búsqueda, anti-bucle 400, permisos 401), y el reducer JS `listReducer`.

Smoke test manual: *OpenSEO → Redirecciones* → "Add redirect" → crear `/old → /new` (301) → Guardar;
editar, desactivar y borrar; seleccionar varias y borrar en masa. *OpenSEO → 404s* → activar el
monitor, visitar una URL inexistente, "Create redirect" desde la fila, y "Clear log".
```

- [ ] **Step 11: Run the full PHP gates + build**

Run: `composer lint:fix` (PHPCBF reformats the inline `args` arrays to one-element-per-line — expected), then `composer check`, then `npm run lint:js`, `npm run lint:css`, `npm run build`
Expected: PHPCS clean, PHPStan level 6 no errors, unit PHPUnit green; JS/CSS lint clean; build succeeds. (No dangling references to the deleted classes; `Menu`/`Plugin` compile against the new wiring; `grep -rn "RedirectsPage\|NotFoundPage\|BehaviorSettings\|NotFoundListTable\|RedirectsListTable" src/ tests/` returns 0.)

- [ ] **Step 12: Run the full integration suite**

Run: `npm run test:integration`
Expected: all green — `RuleValidatorTest` (unit runs under `composer check`), `RedirectsRestTest`, `NotFoundRestTest`, `MenuTest` (all-React), `MenuWiringTest`, `RestSettingsTest`, and the untouched engine suites. `BehaviorSettingsTest`/`NotFoundLinkTest` are gone.

- [ ] **Step 13: Commit (atomic — rewire + cleanup + docs together)**

```bash
git add src/Admin/Menu.php src/Plugin.php src/Admin/Assets.php \
  tests/Integration/MenuTest.php tests/Integration/MenuWiringTest.php \
  tests/Integration/RedirectsRestTest.php tests/Integration/NotFoundRestTest.php \
  CLAUDE.md NOTES.md
# Deletions were staged by `git rm` in Step 4.
git commit -m "feat(admin): React Redirects/404 over REST; retire PHP list-tables + Settings API"
```

---

## Self-Review — Cobertura del spec

| Requisito del spec | Task |
|--------------------|------|
| `Redirects\RedirectLookup` interfaz + Repository implementa (H4) | Task 1 |
| `Redirects\RuleValidator` (extrae handle_save, anti-bucle solo exacto) | Task 1 |
| `Rest\RedirectsController` CRUD + bulk (EDITABLE/DELETABLE, envelope, per_page≤100) | Task 2 |
| Anti-bucle / regex / 410 / whitelist vía REST (400 con `code`) | Task 1 + Task 2 |
| `bulk` un solo `Cache::flush()` (M3) | Task 2 |
| `Rest\NotFoundController` (list/delete/clear) | Task 3 |
| Permisos `manage_options` (401/403) en todas las rutas | Task 2 + Task 3 |
| `api.js` REST helpers + `DataTable` + `useRedirects`/`useNotfound` + reducer puro | Task 4 |
| `DataTable` con `searchable` (404 sin búsqueda, M1) | Task 4 + Task 5 |
| Vistas `Redirects.js`/`NotFound.js` (CRUD, edición, masivas, vaciar log) | Task 5 |
| Migración de toggles a React (dos listas de estado distintas, M5) | Task 5 |
| "Crear redirect desde 404" + sanitización de `?source=` (H3) | Task 5 |
| `Menu` todas React (drop `$php_pages`, M2) + tests | Task 6 |
| `Plugin` wiring controladores fuera de `is_admin()` | Task 6 |
| Borrado de páginas/tablas PHP + `BehaviorSettings` + templates | Task 6 |
| Commit atómico del recableo | Task 6 (Step 10) |
| `Assets` sin cambios (todas React ⇒ gating ya correcto) | n/a (verificado: no requiere edición) |
| Docs CLAUDE.md/NOTES.md | Task 6 |

Fuera de alcance (Futuro): import/export CSV, constructor visual de regex, IndexNow, stats avanzadas.
