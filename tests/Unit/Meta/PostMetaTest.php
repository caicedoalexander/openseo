<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\PostMeta;
use PHPUnit\Framework\TestCase;

final class PostMetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schema_type_accepts_whitelisted_value(): void {
		$meta = new PostMeta();

		$this->assertSame( 'BlogPosting', $meta->sanitize_value( 'BlogPosting', '_openseo_schema_type' ) );
		$this->assertSame( 'none', $meta->sanitize_value( 'none', '_openseo_schema_type' ) );
	}

	public function test_schema_type_rejects_unknown_value(): void {
		$meta = new PostMeta();

		// Off-list values collapse to '' (Default), never stored verbatim.
		$this->assertSame( '', $meta->sanitize_value( 'FAQPage', '_openseo_schema_type' ) );
		$this->assertSame( '', $meta->sanitize_value( '<script>', '_openseo_schema_type' ) );
	}

	public function test_other_keys_still_sanitize_as_before(): void {
		$meta = new PostMeta();

		$this->assertSame( 'https://e.com/i.png', $meta->sanitize_value( 'https://e.com/i.png', '_openseo_og_image' ) );
		$this->assertSame( 'Plain title', $meta->sanitize_value( 'Plain title', '_openseo_title' ) );
	}
}
