<?php
/**
 * Unit tests for the Twitter Card presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend\Head;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\Head\Twitter;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class TwitterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'post_password_required' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'get_search_query' )->justReturn( '' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
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

	public function test_card_uses_configured_type(): void {
		Functions\when( 'get_option' )->justReturn( array( 'twitter_card_type' => 'summary' ) );

		ob_start();
		( new Twitter( $this->resolver() ) )->output();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="twitter:card" content="summary"', $output );
	}

	public function test_card_defaults_to_summary_large_image(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		ob_start();
		( new Twitter( $this->resolver() ) )->output();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image"', $output );
	}
}
