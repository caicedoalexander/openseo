<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Breadcrumbs\Trail;
use PHPUnit\Framework\TestCase;

final class TrailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		// Default every context to false; each test flips the ones it needs.
		foreach ( array( 'is_front_page', 'is_singular', 'is_category', 'is_tag', 'is_tax', 'is_author', 'is_search', 'is_404' ) as $cond ) {
			Functions\when( $cond )->justReturn( false );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_front_page_has_no_trail(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$this->assertSame( array(), ( new Trail() )->items() );
	}

	public function test_post_trail_includes_home_category_and_self(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_the_title' )->justReturn( 'My Post' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/my-post/' );

		$cat            = new \stdClass();
		$cat->name      = 'News';
		$cat->term_id   = 9;
		Functions\when( 'get_the_category' )->justReturn( array( $cat ) );
		Functions\when( 'get_category_link' )->justReturn( 'https://example.com/cat/news/' );

		$items = ( new Trail() )->items();

		$this->assertCount( 3, $items );
		$this->assertSame( 'Home', $items[0]['name'] );
		$this->assertSame( 'News', $items[1]['name'] );
		$this->assertSame( 'https://example.com/cat/news/', $items[1]['url'] );
		$this->assertSame( 'My Post', $items[2]['name'] );
		$this->assertSame( 'https://example.com/my-post/', $items[2]['url'] );
	}

	public function test_page_trail_includes_ancestors(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 12 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post_ancestors' )->justReturn( array( 3 ) ); // parent id
		Functions\when( 'get_the_title' )->alias(
			static fn( $id = 0 ) => 3 === $id ? 'Parent' : 'Child'
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( $id = 0 ) => 3 === $id
				? 'https://example.com/parent/'
				: 'https://example.com/parent/child/'
		);

		$items = ( new Trail() )->items();

		$this->assertSame( array( 'Home', 'Parent', 'Child' ), array_column( $items, 'name' ) );
	}

	public function test_category_archive_trail(): void {
		Functions\when( 'is_category' )->justReturn( true );
		$term       = new \stdClass();
		$term->name = 'Travel';
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$items = ( new Trail() )->items();

		$this->assertSame( array( 'Home', 'Travel' ), array_column( $items, 'name' ) );
	}

	public function test_author_archive_uses_display_name(): void {
		Functions\when( 'is_author' )->justReturn( true );
		$user               = new \stdClass();
		$user->display_name = 'Jane Doe';
		Functions\when( 'get_queried_object' )->justReturn( $user );

		$items = ( new Trail() )->items();

		$this->assertSame( array( 'Home', 'Jane Doe' ), array_column( $items, 'name' ) );
		$this->assertSame( '', $items[1]['url'] );
	}

	public function test_search_trail(): void {
		Functions\when( 'is_search' )->justReturn( true );

		$items = ( new Trail() )->items();

		$this->assertSame( array( 'Home', 'Search results' ), array_column( $items, 'name' ) );
	}

	public function test_404_trail(): void {
		Functions\when( 'is_404' )->justReturn( true );

		$items = ( new Trail() )->items();

		$this->assertSame( array( 'Home', 'Not found' ), array_column( $items, 'name' ) );
	}
}
