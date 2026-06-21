<?php
/**
 * Integration tests for the redirects REST controller.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Repository;
use WP_REST_Request;
use WP_UnitTestCase;

final class RedirectsRestTest extends WP_UnitTestCase {

	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new Repository();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function json( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$parts  = explode( '?', $route, 2 );
		$path   = $parts[0];
		$query  = isset( $parts[1] ) ? $parts[1] : '';
		$params = array();
		if ( '' !== $query ) {
			parse_str( $query, $params );
		}
		$request = new WP_REST_Request( $method, $path );
		if ( array() !== $params ) {
			$request->set_query_params( $params );
		}
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
