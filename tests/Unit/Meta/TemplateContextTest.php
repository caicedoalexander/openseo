<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateContext;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class TemplateContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_the_date' )->justReturn( '' );
		Functions\when( 'get_the_modified_date' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn( array() );
		Functions\when( 'get_the_tags' )->justReturn( false );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'get_search_query' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_for_post_reads_primitives(): void {
		Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
		Functions\when( 'get_the_excerpt' )->justReturn( '<p>Summary.</p>' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( $s ) );

		$ctx = TemplateContext::for_post( 42 );

		$this->assertSame( 42, $ctx->post_id );
		$this->assertSame( 'Hello World', $ctx->title );
		$this->assertSame( 'Summary.', $ctx->excerpt );
		$this->assertSame( '', $ctx->term_name );
	}

	public function test_for_term_extracts_name_and_description(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$term              = new WP_Term();
		$term->name        = 'News';
		$term->description = 'All news.';

		$ctx = TemplateContext::for_term( $term );

		$this->assertSame( 0, $ctx->post_id );
		$this->assertSame( 'News', $ctx->term_name );
		$this->assertSame( 'All news.', $ctx->term_description );
		$this->assertSame( '', $ctx->title );
	}

	public function test_none_is_all_empty(): void {
		$ctx = TemplateContext::none();

		$this->assertSame( 0, $ctx->post_id );
		$this->assertSame( '', $ctx->title );
		$this->assertSame( '', $ctx->term_name );
		$this->assertSame( '', $ctx->excerpt );
		$this->assertSame( '', $ctx->term_description );
	}

	public function test_for_post_reads_enriched_primitives(): void {
		Functions\when( 'get_the_title' )->justReturn( 'Hello' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_the_date' )->justReturn( 'June 21, 2026' );
		Functions\when( 'get_the_modified_date' )->justReturn( 'June 22, 2026' );
		Functions\when( 'get_post_field' )->justReturn( 7 );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Doe' );
		$cat       = new WP_Term();
		$cat->name = 'News';
		$tag       = new WP_Term();
		$tag->name = 'Featured';
		Functions\when( 'get_the_category' )->justReturn( array( $cat ) );
		Functions\when( 'get_the_tags' )->justReturn( array( $tag ) );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );

		$ctx = TemplateContext::for_post( 42 );

		$this->assertSame( 'June 21, 2026', $ctx->date );
		$this->assertSame( 'June 22, 2026', $ctx->modified );
		$this->assertSame( 'Jane Doe', $ctx->author );
		$this->assertSame( 'News', $ctx->category );
		$this->assertSame( 'Featured', $ctx->tag );
		$this->assertSame( '', $ctx->parent_title );
	}

	public function test_for_post_resolves_parent_title_and_empty_terms(): void {
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_the_date' )->justReturn( '' );
		Functions\when( 'get_the_modified_date' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn( array() ); // [] empty shape
		Functions\when( 'get_the_tags' )->justReturn( false );       // false empty shape
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 3 );
		Functions\when( 'get_the_title' )->alias(
			static fn( $id ) => 3 === $id ? 'Parent Page' : 'Child'
		);

		$ctx = TemplateContext::for_post( 42 );

		$this->assertSame( '', $ctx->category );
		$this->assertSame( '', $ctx->tag );
		$this->assertSame( 'Parent Page', $ctx->parent_title );
	}

	public function test_for_author_reads_display_name(): void {
		Functions\when( 'get_the_author_meta' )->alias(
			static fn( $field, $id ) => 'display_name' === $field && 7 === $id ? 'Jane Doe' : ''
		);

		$ctx = TemplateContext::for_author( 7 );

		$this->assertSame( 'Jane Doe', $ctx->name );
		$this->assertSame( '', $ctx->search_query );
		$this->assertSame( '', $ctx->page );
	}

	public function test_for_search_reads_raw_query(): void {
		Functions\when( 'get_search_query' )->justReturn( 'tom & jerry' );

		$ctx = TemplateContext::for_search();

		$this->assertSame( 'tom & jerry', $ctx->search_query );
		$this->assertSame( '', $ctx->name );
	}

	public function test_page_label_is_empty_when_not_paginated(): void {
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$this->assertSame( '', TemplateContext::for_archive()->page );
	}

	public function test_page_label_uses_paged_and_total(): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( $key ) => 'paged' === $key ? 2 : 0
		);
		$wp_query                = new \stdClass();
		$wp_query->max_num_pages = 4;
		$GLOBALS['wp_query']     = $wp_query;

		$this->assertSame( 'Page 2 of 4', TemplateContext::for_archive()->page );

		unset( $GLOBALS['wp_query'] );
	}
}
