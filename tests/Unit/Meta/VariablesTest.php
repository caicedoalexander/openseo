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
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_search_query' )->justReturn( '' );
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

	public function test_replaces_enriched_post_tokens(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_the_date' )->justReturn( 'June 21, 2026' );
		Functions\when( 'get_the_modified_date' )->justReturn( 'June 22, 2026' );
		Functions\when( 'get_post_field' )->justReturn( 7 );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );
		$cat       = new WP_Term();
		$cat->name = 'News';
		Functions\when( 'get_the_category' )->justReturn( array( $cat ) );
		Functions\when( 'get_the_tags' )->justReturn( false );

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_post( 42 );

		$this->assertSame( 'June 21, 2026', $variables->replace( '%date%', $ctx ) );
		$this->assertSame( 'June 22, 2026', $variables->replace( '%modified%', $ctx ) );
		$this->assertSame( 'Jane Doe', $variables->replace( '%author%', $ctx ) );
		$this->assertSame( 'News', $variables->replace( '%category%', $ctx ) );
		$this->assertSame( '', $variables->replace( '%tag%', $ctx ) );
		// parent_title: wp_get_post_parent_id defaults to 0 in setUp → '' passthrough.
		$this->assertSame( '', $variables->replace( '%parent_title%', $ctx ) );
	}

	public function test_replaces_author_name_token(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_author( 7 );

		$this->assertSame( 'Jane Doe - My Site', $variables->replace( '%name% %sep% %sitename%', $ctx ) );
	}

	public function test_replaces_search_query_token_raw(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_search_query' )->justReturn( 'tom & jerry' );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_search();

		$this->assertSame( 'tom & jerry', $variables->replace( '%search_query%', $ctx ) );
	}

	public function test_replaces_page_token(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_query_var' )->alias( static fn( $k ) => 'paged' === $k ? 3 : 0 );
		$wp_query                = new \stdClass();
		$wp_query->max_num_pages = 5;
		$GLOBALS['wp_query']     = $wp_query;

		$variables = new Variables( new Options() );
		$ctx       = TemplateContext::for_archive();

		$this->assertSame( 'Page 3 of 5', $variables->replace( '%page%', $ctx ) );

		unset( $GLOBALS['wp_query'] );
	}
}
