<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase {

	public function test_strips_query_string_and_trailing_slash(): void {
		$normalizer = new Normalizer();

		$this->assertSame( '/blog/post', $normalizer->normalize( '/blog/post/?utm=x' ) );
	}

	public function test_keeps_root_slash(): void {
		$normalizer = new Normalizer();

		$this->assertSame( '/', $normalizer->normalize( '/?ref=1' ) );
	}

	public function test_decodes_and_adds_leading_slash(): void {
		$normalizer = new Normalizer();

		$this->assertSame( '/a b', $normalizer->normalize( 'a%20b' ) );
	}

	public function test_removes_home_subdirectory(): void {
		$normalizer = new Normalizer( '/wp' );

		$this->assertSame( '/about', $normalizer->normalize( '/wp/about/' ) );
	}

	public function test_does_not_strip_partial_home_prefix(): void {
		$normalizer = new Normalizer( '/wp' );

		$this->assertSame( '/wpadmin/page', $normalizer->normalize( '/wpadmin/page' ) );
	}

	public function test_home_path_equal_to_request_yields_root(): void {
		$normalizer = new Normalizer( '/wp' );

		$this->assertSame( '/', $normalizer->normalize( '/wp/' ) );
	}

	public function test_collapses_multiple_trailing_slashes(): void {
		$normalizer = new Normalizer();

		$this->assertSame( '/a', $normalizer->normalize( '/a///' ) );
	}
}
