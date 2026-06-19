<?php
/**
 * Integration tests for the JSON-LD graph output.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class SchemaTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( Options::OPTION_KEY );
	}

	/**
	 * Extract the first ld+json graph from a wp_head dump.
	 *
	 * @param string $head Rendered head HTML.
	 * @return array<string, mixed>
	 */
	private function graph_from_head( string $head ): array {
		$this->assertMatchesRegularExpression( '#<script type="application/ld\+json">(.+?)</script>#s', $head );
		preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $head, $m );

		return json_decode( $m[1], true );
	}

	public function test_singular_post_emits_connected_graph(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Graph Post',
				'post_excerpt' => 'A summary.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$graph = $this->graph_from_head( (string) ob_get_clean() );

		$types = array_column( $graph['@graph'], '@type' );
		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'Organization', $types );
		$this->assertContains( 'WebPage', $types );
		$this->assertContains( 'Article', $types );
		$this->assertContains( 'BreadcrumbList', $types );
	}

	public function test_person_identity_replaces_organization(): void {
		update_option( Options::OPTION_KEY, array( 'schema_site_type' => 'Person' ) );
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$types = array_column( $this->graph_from_head( (string) ob_get_clean() )['@graph'], '@type' );

		$this->assertContains( 'Person', $types );
		$this->assertNotContains( 'Organization', $types );
	}

	public function test_none_type_suppresses_article(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_openseo_schema_type', 'none' );
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$types = array_column( $this->graph_from_head( (string) ob_get_clean() )['@graph'], '@type' );

		$this->assertContains( 'WebPage', $types );
		$this->assertNotContains( 'Article', $types );
	}
}
