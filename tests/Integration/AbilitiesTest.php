<?php
/**
 * Integration tests for the AI Abilities — REST exposure and no-connector paths.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Ai\Abilities;
use OpenSEO\Settings\Options;
use WP_Error;
use WP_UnitTestCase;

final class AbilitiesTest extends WP_UnitTestCase {

	public function test_generate_meta_description_without_connector_errors(): void {
		$post_id = self::factory()->post->create();

		$ability = new Abilities( new Options() );
		$result  = $ability->generate_meta_description( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_no_connector', $result->get_error_code() );
	}

	public function test_generate_title_with_invalid_post_errors(): void {
		$ability = new Abilities( new Options() );
		$result  = $ability->generate_title( array( 'post_id' => 0 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_invalid_post', $result->get_error_code() );
	}

	public function test_suggest_schema_type_without_connector_errors(): void {
		$post_id = self::factory()->post->create();

		$ability = new Abilities( new Options() );
		$result  = $ability->suggest_schema_type( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_no_connector', $result->get_error_code() );
	}

	public function test_suggest_schema_type_ability_is_exposed_over_rest(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create( array( 'post_author' => $admin ) );

		// The WP Abilities API REST route is /wp-abilities/v1/abilities/<name>/run
		// (the namespace segment is literally "abilities", not the category slug) —
		// matching the existing generate-* test and the editor's apiFetch path.
		$request = new \WP_REST_Request(
			'POST',
			'/wp-abilities/v1/abilities/openseo/suggest-schema-type/run'
		);
		$request->set_body_params( array( 'input' => array( 'post_id' => $post_id ) ) );
		$response = rest_do_request( $request );

		$this->assertNotSame( 404, $response->get_status() );
		$this->assertNotSame( 'rest_no_route', $response->get_data()['code'] ?? '' );
	}

	public function test_meta_description_ability_is_exposed_over_rest(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create( array( 'post_author' => $admin ) );

		// executeAbility() in the editor POSTs here. The route only exists when
		// the ability registers meta.show_in_rest => true (regression guard for
		// the editor's core flow). Without a connector the ability still returns
		// openseo_no_connector — an error, but NOT rest_no_route / 404.
		// Note: the WP Abilities API uses /wp-abilities/v1/abilities/<name>/run
		// (the namespace segment is "abilities", not the category slug).
		$request = new \WP_REST_Request(
			'POST',
			'/wp-abilities/v1/abilities/openseo/generate-meta-description/run'
		);
		$request->set_body_params( array( 'input' => array( 'post_id' => $post_id ) ) );
		$response = rest_do_request( $request );

		$this->assertNotSame( 404, $response->get_status() );
		$this->assertNotSame( 'rest_no_route', $response->get_data()['code'] ?? '' );
	}
}
