<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateContext;
use OpenSEO\Meta\Variables;
use OpenSEO\Meta\VariableCatalog;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class VariableCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_catalog_has_entries_with_required_keys_and_valid_scopes(): void {
		$all = ( new VariableCatalog() )->all();

		$this->assertNotEmpty( $all );
		foreach ( $all as $entry ) {
			$this->assertArrayHasKey( 'token', $entry );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertArrayHasKey( 'description', $entry );
			$this->assertArrayHasKey( 'scope', $entry );
			$this->assertContains( $entry['scope'], array( 'global', 'singular', 'taxonomy' ) );
			$this->assertSame( 1, preg_match( '/^%[a-z_]+%$/', $entry['token'] ) );
		}
	}

	public function test_every_catalog_token_is_replaced_by_variables(): void {
		// Anti-drift: a catalog token NOT handled by Variables::replace would be
		// left literal by strtr, so the output would still contain it.
		Functions\when( 'get_option' )->justReturn( array( 'title_separator' => '-' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( 'A Title' );
		Functions\when( 'get_the_excerpt' )->justReturn( 'An excerpt' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_the_date' )->justReturn( '' );
		Functions\when( 'get_the_modified_date' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn( array() );
		Functions\when( 'get_the_tags' )->justReturn( false );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );

		$variables = new Variables( new Options() );

		foreach ( ( new VariableCatalog() )->all() as $entry ) {
			$token   = $entry['token'];
			$context = $this->context_for_scope( $entry['scope'] );
			$output  = $variables->replace( $token, $context );

			$this->assertStringNotContainsString(
				$token,
				$output,
				"Catalog token {$token} is not expanded by Variables::replace"
			);
		}
	}

	public function test_catalog_includes_enriched_tokens(): void {
		$tokens = array_column( ( new VariableCatalog() )->all(), 'token' );

		foreach ( array( '%date%', '%modified%', '%author%', '%category%', '%tag%', '%parent_title%' ) as $expected ) {
			$this->assertContains( $expected, $tokens );
		}
	}

	private function context_for_scope( string $scope ): TemplateContext {
		if ( 'singular' === $scope ) {
			return TemplateContext::for_post( 1 );
		}
		if ( 'taxonomy' === $scope ) {
			$term              = new WP_Term();
			$term->name        = 'News';
			$term->description = 'All news.';
			return TemplateContext::for_term( $term );
		}
		return TemplateContext::none();
	}
}
