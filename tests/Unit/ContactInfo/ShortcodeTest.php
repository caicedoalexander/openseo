<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\ContactInfo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\ContactInfo\Shortcode;
use PHPUnit\Framework\TestCase;

final class ShortcodeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_the_shortcode(): void {
		Functions\expect( 'add_shortcode' )
			->once()
			->with( 'openseo_contact_info', \Mockery::type( 'array' ) );

		( new Shortcode() )->register();
	}

	public function test_parse_sections_empty_is_all(): void {
		$this->assertSame( array(), Shortcode::parse_sections( '' ) );
	}

	public function test_parse_sections_splits_and_trims(): void {
		$this->assertSame(
			array( 'name', 'phone', 'address' ),
			Shortcode::parse_sections( 'name, phone , address' )
		);
	}

	public function test_parse_sections_drops_blanks(): void {
		$this->assertSame( array(), Shortcode::parse_sections( ' , ' ) );
	}

	public function test_render_returns_empty_when_no_data(): void {
		Functions\when( 'shortcode_atts' )->alias(
			static fn( $defaults, $atts ) => array_merge( $defaults, is_array( $atts ) ? $atts : array() )
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( '' );
		Functions\when( '__' )->alias( static fn( $text ) => $text );

		$this->assertSame( '', ( new Shortcode() )->render( array() ) );
	}
}
