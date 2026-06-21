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
		$this->assertSame( '%sitename% %sep% %tagline%', $data['home_title'] );
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
