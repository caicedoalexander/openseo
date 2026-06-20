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
}
