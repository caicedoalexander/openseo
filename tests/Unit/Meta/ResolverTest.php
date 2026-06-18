<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolver(): Resolver {
		$options = new Options();
		return new Resolver( $options, new Variables( $options ) );
	}

	public function test_title_prefers_per_entry_override_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_title' === $key ? 'Manual title' : ''
		);

		$this->assertSame( 'Manual title', $this->resolver()->title() );
	}

	public function test_title_falls_back_to_template_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );

		// Default template: "%title% %sep% %sitename%".
		$this->assertSame( 'Post Title - My Site', $this->resolver()->title() );
	}

	public function test_title_is_empty_on_unhandled_context(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertSame( '', $this->resolver()->title() );
	}

	public function test_robots_reflects_noindex_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_robots_noindex' === $key ? '1' : ''
		);

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_canonical_defaults_to_permalink_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );

		$this->assertSame( 'https://example.com/post/', $this->resolver()->canonical() );
	}
}
