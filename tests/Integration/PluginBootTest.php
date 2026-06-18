<?php
/**
 * Integration tests that boot the plugin inside WordPress.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use WP_UnitTestCase;

final class PluginBootTest extends WP_UnitTestCase {

	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'OPENSEO_VERSION' ) );
		$this->assertTrue( defined( 'OPENSEO_PLUGIN_FILE' ) );
	}

	public function test_singular_head_outputs_description_robots_canonical(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello',
				'post_excerpt' => 'A summary for search engines.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="description"', $output );
		$this->assertStringContainsString( 'A summary for search engines.', $output );
		$this->assertStringContainsString( '<meta name="robots" content="index, follow"', $output );
		$this->assertStringContainsString( 'rel="canonical"', $output );
		// Exactly one canonical — the core's rel_canonical must be removed.
		$this->assertSame( 1, substr_count( $output, 'rel="canonical"' ) );
	}

	public function test_noindex_override_is_reflected_in_head(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_openseo_robots_noindex', '1' );
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'content="noindex, follow"', $output );
	}

	public function test_singular_title_uses_template(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'My Post' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertStringContainsString( 'My Post', wp_get_document_title() );
	}

	public function test_per_entry_title_override_wins(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'My Post' ) );
		update_post_meta( $post_id, '_openseo_title', 'Overridden Title' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Overridden Title', wp_get_document_title() );
	}

	public function test_singular_head_outputs_open_graph_and_twitter(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Social Post',
				'post_excerpt' => 'Shareable summary.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'property="og:title"', $output );
		$this->assertStringContainsString( 'property="og:type"', $output );
		$this->assertStringContainsString( 'name="twitter:card"', $output );
		$this->assertStringContainsString( 'Social Post', $output );
	}
}
