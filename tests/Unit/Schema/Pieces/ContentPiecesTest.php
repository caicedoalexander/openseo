<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema\Pieces;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\Variables;
use OpenSEO\Schema\Pieces\Article;
use OpenSEO\Schema\Pieces\WebPage;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class ContentPiecesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'en-US' );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );
		Functions\when( 'get_the_date' )->justReturn( '2026-06-01T10:00:00+00:00' );
		Functions\when( 'get_the_modified_date' )->justReturn( '2026-06-02T10:00:00+00:00' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane' );
		Functions\when( 'get_the_author' )->justReturn( 'Jane' );
		Functions\when( 'get_author_posts_url' )->justReturn( 'https://example.com/author/jane/' );
		Functions\when( 'get_post_field' )->justReturn( 7 );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $str ) => strip_tags( $str ) );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolver(): Resolver {
		$options = new Options();

		return new Resolver( $options, new Variables( $options ), new TemplateDefaults() );
	}

	public function test_webpage_needed_on_singular_and_references_website(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );

		$piece = new WebPage( $this->resolver() );

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'WebPage', $data['@type'] );
		$this->assertSame( 'https://example.com/post/#webpage', $data['@id'] );
		$this->assertSame( 'https://example.com/#website', $data['isPartOf']['@id'] );
		$this->assertSame( 'https://example.com/post/#breadcrumb', $data['breadcrumb']['@id'] );
	}

	public function test_webpage_not_needed_off_singular_and_front(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertFalse( ( new WebPage( $this->resolver() ) )->is_needed() );
	}

	public function test_article_needed_for_default_post(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );

		$piece = new Article( $this->resolver(), new Options() );

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'Article', $data['@type'] );
		$this->assertSame( 'https://example.com/post/#article', $data['@id'] );
		$this->assertSame( 'https://example.com/post/#webpage', $data['isPartOf']['@id'] );
		$this->assertSame( 'Person', $data['author']['@type'] );
		$this->assertSame( 'https://example.com/#organization', $data['publisher']['@id'] );
	}

	public function test_article_honors_explicit_type_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_schema_type' === $key ? 'NewsArticle' : ''
		);

		$piece = new Article( $this->resolver(), new Options() );

		$this->assertSame( 'NewsArticle', $piece->data()['@type'] );
	}

	public function test_article_suppressed_for_none_and_webpage_types(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );

		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_schema_type' === $key ? 'none' : ''
		);
		$this->assertFalse( ( new Article( $this->resolver(), new Options() ) )->is_needed() );

		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_schema_type' === $key ? 'WebPage' : ''
		);
		$this->assertFalse( ( new Article( $this->resolver(), new Options() ) )->is_needed() );
	}

	public function test_article_suppressed_for_pages_by_default(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'page' );

		$this->assertFalse( ( new Article( $this->resolver(), new Options() ) )->is_needed() );
	}

	public function test_webpage_front_page_needed_but_has_no_singular_fields(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );

		$piece = new WebPage( $this->resolver() );

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertArrayNotHasKey( 'breadcrumb', $data );
		$this->assertArrayNotHasKey( 'datePublished', $data );
		$this->assertArrayNotHasKey( 'dateModified', $data );
	}

	public function test_article_not_needed_when_not_singular(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertFalse( ( new Article( $this->resolver(), new Options() ) )->is_needed() );
	}
}
