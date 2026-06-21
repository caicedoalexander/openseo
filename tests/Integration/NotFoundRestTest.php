<?php
/**
 * Integration tests for the 404 log REST controller.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\NotFound\LogRepository;
use OpenSEO\Rest\NotFoundController;
use WP_REST_Request;
use WP_UnitTestCase;

final class NotFoundRestTest extends WP_UnitTestCase {

	private LogRepository $log;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
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
