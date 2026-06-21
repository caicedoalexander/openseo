<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Settings\ContentTypes;
use PHPUnit\Framework\TestCase;

final class ContentTypesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fake_type( string $name, string $label ): object {
		$labels       = new \stdClass();
		$labels->name = $label;
		$type         = new \stdClass();
		$type->name   = $name;
		$type->labels = $labels;
		return $type;
	}

	public function test_post_types_exclude_attachment(): void {
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => $this->fake_type( 'post', 'Posts' ),
				'page'       => $this->fake_type( 'page', 'Pages' ),
				'attachment' => $this->fake_type( 'attachment', 'Media' ),
			)
		);

		$slugs = ( new ContentTypes() )->post_type_slugs();

		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
		$this->assertNotContains( 'attachment', $slugs );
	}

	public function test_taxonomies_map_slug_and_label(): void {
		Functions\when( 'get_taxonomies' )->justReturn(
			array( 'category' => $this->fake_type( 'category', 'Categories' ) )
		);

		$taxes = ( new ContentTypes() )->taxonomies();

		$this->assertSame(
			array( array( 'slug' => 'category', 'label' => 'Categories' ) ),
			$taxes
		);
	}
}
