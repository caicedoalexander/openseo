<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Schema\Ids;
use PHPUnit\Framework\TestCase;

final class IdsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_site_level_ids_anchor_to_home(): void {
		$this->assertSame( 'https://example.com/#website', Ids::website() );
		$this->assertSame( 'https://example.com/#organization', Ids::organization() );
		$this->assertSame( 'https://example.com/#person', Ids::person() );
	}

	public function test_url_level_ids_append_fragments(): void {
		$url = 'https://example.com/post/';

		$this->assertSame( 'https://example.com/post/#webpage', Ids::webpage( $url ) );
		$this->assertSame( 'https://example.com/post/#article', Ids::article( $url ) );
		$this->assertSame( 'https://example.com/post/#breadcrumb', Ids::breadcrumb( $url ) );
	}

	public function test_current_url_is_home_on_front_page(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$this->assertSame( 'https://example.com/', Ids::current_url() );
	}

	public function test_current_url_is_permalink_on_singular(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );

		$this->assertSame( 'https://example.com/post/', Ids::current_url() );
	}
}
