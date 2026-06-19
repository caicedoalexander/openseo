<?php
/**
 * Integration tests for the breadcrumbs block.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use WP_Block_Type_Registry;
use WP_UnitTestCase;

final class BreadcrumbsBlockTest extends WP_UnitTestCase {

	public function test_block_is_registered(): void {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'openseo/breadcrumbs' )
		);
	}

	public function test_block_renders_a_nav_on_a_post(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Block Post' ) );
		$this->go_to( get_permalink( $post_id ) );

		$html = do_blocks( '<!-- wp:openseo/breadcrumbs /-->' );

		$this->assertStringContainsString( 'openseo-breadcrumbs', $html );
		$this->assertStringContainsString( 'Block Post', $html );
	}
}
