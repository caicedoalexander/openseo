<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateContext;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class VariablesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array( 'title_separator' => '-' ) );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_the_date' )->justReturn( '' );
		Functions\when( 'get_the_modified_date' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn( array() );
		Functions\when( 'get_the_tags' )->justReturn( false );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
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

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_post( 42 );

		$this->assertSame(
			'Hello World - My Site',
			$variables->replace( '%title% %sep% %sitename%', $ctx )
		);
		$this->assertSame( 'A short summary.', $variables->replace( '%excerpt%', $ctx ) );
	}

	public function test_replaces_term_tokens(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );

		$term              = new WP_Term();
		$term->name        = 'News';
		$term->description = 'All the news.';

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_term( $term );

		$this->assertSame(
			'News - My Site',
			$variables->replace( '%term% %sep% %sitename%', $ctx )
		);
		$this->assertSame( 'All the news.', $variables->replace( '%term_description%', $ctx ) );
	}

	public function test_strips_separators_when_tokens_are_empty(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );

		$variables = new Variables( new Options() );

		// none() context → %title% empty → no dangling separator.
		$this->assertSame( '', $variables->replace( '%title% %sep%' ) );
	}
}
