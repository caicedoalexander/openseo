<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Regex;
use PHPUnit\Framework\TestCase;

final class RegexTest extends TestCase {

	public function test_valid_pattern_passes(): void {
		$this->assertTrue( Regex::is_valid( '^/blog/(\d+)$' ) );
	}

	public function test_invalid_pattern_fails(): void {
		$this->assertFalse( Regex::is_valid( '^/blog/(\d+$' ) ); // Unbalanced paren.
	}

	public function test_overlong_pattern_is_invalid(): void {
		$this->assertFalse( Regex::is_valid( str_repeat( 'a', Regex::MAX_LENGTH + 1 ) ) );
	}

	public function test_match_returns_capture_groups(): void {
		$this->assertSame(
			array( '/blog/42', '42' ),
			Regex::match( '^/blog/(\d+)$', '/blog/42' )
		);
	}

	public function test_match_returns_null_on_no_match(): void {
		$this->assertNull( Regex::match( '^/blog/(\d+)$', '/about' ) );
	}

	public function test_substitute_replaces_numbered_groups(): void {
		$matches = array( '/blog/42', '42' );

		$this->assertSame( '/news/42', Regex::substitute( '/news/$1', $matches ) );
		$this->assertSame( '/news/42', Regex::substitute( '/news/${1}', $matches ) );
	}

	public function test_delimiter_in_pattern_is_escaped(): void {
		// A '#' inside the user pattern must be treated as a literal, never as
		// the delimiter (which would let the user inject flags). So 'foo#i'
		// matches the literal string "foo#i", not "foo" case-insensitively.
		$this->assertNull( Regex::match( 'foo#i', 'FOO' ) );
		$this->assertSame( array( 'foo#i' ), Regex::match( 'foo#i', 'foo#i' ) );
	}

	public function test_substitute_missing_group_yields_empty_string(): void {
		$matches = array( '/blog/42', '42' ); // Only groups 0 and 1 exist.

		$this->assertSame( '/news//item', Regex::substitute( '/news/$2/item', $matches ) );
	}
}
