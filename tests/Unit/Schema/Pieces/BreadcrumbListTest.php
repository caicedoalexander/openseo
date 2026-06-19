<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema\Pieces;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Breadcrumbs\TrailSource;
use OpenSEO\Schema\Pieces\BreadcrumbList;
use PHPUnit\Framework\TestCase;

final class BreadcrumbListTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A TrailSource double returning a fixed crumb list.
	 */
	private function trail( array $items ): TrailSource {
		return new class( $items ) implements TrailSource {
			/** @param array<int, array{name:string,url:string}> $items */
			public function __construct( private array $items ) {}
			public function items(): array {
				return $this->items;
			}
		};
	}

	public function test_not_needed_when_trail_too_short(): void {
		$piece = new BreadcrumbList( $this->trail( array( array( 'name' => 'Home', 'url' => 'https://example.com/' ) ) ) );

		$this->assertFalse( $piece->is_needed() );
	}

	public function test_builds_item_list_with_positions(): void {
		$piece = new BreadcrumbList(
			$this->trail(
				array(
					array( 'name' => 'Home', 'url' => 'https://example.com/' ),
					array( 'name' => 'My Post', 'url' => 'https://example.com/post/' ),
				)
			)
		);

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'BreadcrumbList', $data['@type'] );
		$this->assertSame( 'https://example.com/post/#breadcrumb', $data['@id'] );
		$this->assertCount( 2, $data['itemListElement'] );
		$this->assertSame( 1, $data['itemListElement'][0]['position'] );
		$this->assertSame( 'Home', $data['itemListElement'][0]['name'] );
		$this->assertSame( 'https://example.com/', $data['itemListElement'][0]['item'] );
		$this->assertSame( 2, $data['itemListElement'][1]['position'] );
	}

	public function test_omits_item_url_when_crumb_has_none(): void {
		$piece = new BreadcrumbList(
			$this->trail(
				array(
					array( 'name' => 'Home', 'url' => 'https://example.com/' ),
					array( 'name' => 'Search results', 'url' => '' ),
				)
			)
		);

		$this->assertArrayNotHasKey( 'item', $piece->data()['itemListElement'][1] );
	}
}
