<?php
/**
 * Unit tests for the document title presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend\Head;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\Head\Title;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->alias(
			static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'get_search_query' )->justReturn( '' );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolver(): Resolver {
		$options  = new Options();
		$defaults = new TemplateDefaults();
		return new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) );
	}

	public function test_escapes_resolved_search_title(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_search_query' )->justReturn( '<script>alert(1)</script>' );

		$result = ( new Title( $this->resolver() ) )->filter_title( 'WP fallback' );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	public function test_returns_wp_title_when_resolver_empty(): void {
		// All conditionals false → resolver returns '' → keep WP's title.
		$this->assertSame( 'WP fallback', ( new Title( $this->resolver() ) )->filter_title( 'WP fallback' ) );
	}
}
