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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_for_post_reads_primitives(): void {
		Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
		Functions\when( 'get_the_excerpt' )->justReturn( '<p>Summary.</p>' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$ctx = TemplateContext::for_post( 42 );

		$this->assertSame( 42, $ctx->post_id );
		$this->assertSame( 'Hello World', $ctx->title );
		$this->assertSame( '<p>Summary.</p>', $ctx->excerpt );
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
	}
}
