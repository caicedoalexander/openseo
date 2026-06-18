<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Sitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Settings\Options;
use OpenSEO\Sitemap\Sitemap;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Sitemap whose Options reads the given stored settings array.
	 *
	 * @param array<string, mixed> $settings Stored option array.
	 */
	private function sitemap_with( array $settings ): Sitemap {
		Functions\when( 'get_option' )->justReturn( $settings );

		return new Sitemap( new Options() );
	}

	public function test_is_enabled_off_when_master_toggle_disabled(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_enabled' => '' ) );

		$this->assertFalse( $sitemap->is_enabled( true ) );
	}

	public function test_is_enabled_respects_core_when_master_toggle_on(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_enabled' => '1' ) );

		$this->assertTrue( $sitemap->is_enabled( true ) );
		$this->assertFalse( $sitemap->is_enabled( false ) );
	}

	public function test_filter_provider_removes_users_when_authors_disabled(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_include_authors' => '' ) );

		$this->assertFalse( $sitemap->filter_provider( 'provider', 'users' ) );
		$this->assertSame( 'provider', $sitemap->filter_provider( 'provider', 'posts' ) );
	}

	public function test_filter_provider_keeps_users_when_authors_enabled(): void {
		$sitemap = $this->sitemap_with( array( 'sitemap_include_authors' => '1' ) );

		$this->assertSame( 'provider', $sitemap->filter_provider( 'provider', 'users' ) );
	}

	public function test_exclude_noindex_builds_or_clause(): void {
		$sitemap = $this->sitemap_with( array() );

		$args = $sitemap->exclude_noindex( array( 'post_type' => 'post' ) );

		$this->assertSame( 'post', $args['post_type'] );
		$this->assertSame( 'OR', $args['meta_query']['relation'] );
		$this->assertSame( '_openseo_robots_noindex', $args['meta_query'][0]['key'] );
		$this->assertSame( 'NOT EXISTS', $args['meta_query'][0]['compare'] );
		$this->assertSame( '1', $args['meta_query'][1]['value'] );
		$this->assertSame( '!=', $args['meta_query'][1]['compare'] );
	}

	public function test_exclude_noindex_preserves_existing_meta_query(): void {
		$sitemap  = $this->sitemap_with( array() );
		$existing = array( array( 'key' => 'other', 'value' => 'x' ) );

		$args = $sitemap->exclude_noindex( array( 'meta_query' => $existing ) );

		$this->assertSame( 'AND', $args['meta_query']['relation'] );
		$this->assertSame( $existing, $args['meta_query'][0] );
		$this->assertSame( 'OR', $args['meta_query'][1]['relation'] );
	}

	public function test_exclude_noindex_normalizes_non_array_args(): void {
		$sitemap = $this->sitemap_with( array() );

		$args = $sitemap->exclude_noindex( null );

		$this->assertArrayHasKey( 'meta_query', $args );
	}
}
