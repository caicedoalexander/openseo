<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class VariablesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array( 'title_separator' => '-' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_replaces_site_tokens(): void {
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'My Site' : 'My tagline'
		);

		$variables = new Variables( new Options() );

		$this->assertSame(
			'My Site - My tagline',
			$variables->replace( '%sitename% %sep% %tagline%' )
		);
	}

	public function test_replaces_post_tokens(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
		Functions\when( 'get_the_excerpt' )->justReturn( 'A short summary.' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$variables = new Variables( new Options() );

		$this->assertSame(
			'Hello World - My Site',
			$variables->replace( '%title% %sep% %sitename%', 42 )
		);
		$this->assertSame( 'A short summary.', $variables->replace( '%excerpt%', 42 ) );
	}

	public function test_strips_separators_when_tokens_are_empty(): void {
		// Mock the full set of functions Variables touches when $post_id > 0,
		// otherwise Brain Monkey fatals on the first unmocked call.
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$variables = new Variables( new Options() );

		// Empty %title% leaves no double spaces or dangling separator.
		$this->assertSame( '', $variables->replace( '%title% %sep%', 7 ) );
	}
}
