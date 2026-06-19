<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use WP_UnitTestCase;

final class MetaRegistrationTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		( new \OpenSEO\Meta\PostMeta() )->register_meta();
	}

    public function test_seo_meta_keys_are_registered_for_posts(): void {
        $registered = get_registered_meta_keys( 'post', 'post' );

        $this->assertArrayHasKey( '_openseo_title', $registered );
        $this->assertArrayHasKey( '_openseo_description', $registered );
        $this->assertArrayHasKey( '_openseo_canonical', $registered );
        $this->assertTrue( $registered['_openseo_title']['show_in_rest'] );
    }

    public function test_meta_round_trips_through_storage(): void {
        $post_id = self::factory()->post->create();

        update_post_meta( $post_id, '_openseo_title', 'Custom title' );

        $this->assertSame( 'Custom title', get_post_meta( $post_id, '_openseo_title', true ) );
    }

    public function test_meta_round_trips_through_rest_like_the_editor(): void {
        $editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor_id );
        $post_id = self::factory()->post->create( array( 'post_author' => $editor_id ) );

        // Mirror what useEntityProp does: PUT the post with a meta payload.
        $request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
        $request->set_body_params(
            array( 'meta' => array( '_openseo_title' => 'Via REST' ) )
        );
        $response = rest_do_request( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'Via REST', get_post_meta( $post_id, '_openseo_title', true ) );
    }

	public function test_schema_type_meta_is_registered(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayHasKey( '_openseo_schema_type', $registered );
		$this->assertTrue( $registered['_openseo_schema_type']['show_in_rest'] );
	}
}
