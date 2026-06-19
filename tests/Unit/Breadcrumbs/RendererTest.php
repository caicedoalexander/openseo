<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Breadcrumbs\Renderer;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function items(): array {
		return array(
			array( 'name' => 'Home', 'url' => 'https://example.com/' ),
			array( 'name' => 'My Post', 'url' => 'https://example.com/post/' ),
		);
	}

	public function test_renders_links_and_marks_current(): void {
		$html = ( new Renderer( new Options() ) )->render( $this->items() );

		$this->assertStringContainsString( '<nav class="openseo-breadcrumbs"', $html );
		$this->assertStringContainsString( 'aria-label="Breadcrumb"', $html );
		$this->assertStringContainsString( '<a href="https://example.com/">Home</a>', $html );
		// Last crumb is not a link.
		$this->assertStringContainsString( '<span aria-current="page">My Post</span>', $html );
		// Default separator from the option default.
		$this->assertStringContainsString( '›', $html );
	}

	public function test_show_home_false_drops_the_home_crumb(): void {
		$html = ( new Renderer( new Options() ) )->render(
			$this->items(),
			array( 'show_home' => false )
		);

		$this->assertStringNotContainsString( '>Home<', $html );
	}

	public function test_custom_separator_and_alignment(): void {
		$html = ( new Renderer( new Options() ) )->render(
			$this->items(),
			array( 'separator' => '/', 'text_align' => 'center' )
		);

		$this->assertStringContainsString( '/', $html );
		$this->assertStringContainsString( 'text-align:center', $html );
	}

	public function test_empty_items_render_nothing(): void {
		$this->assertSame( '', ( new Renderer( new Options() ) )->render( array() ) );
	}
}
