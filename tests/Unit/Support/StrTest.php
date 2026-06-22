<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Support;

use OpenSEO\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase {

	public function test_uppercases_first_letter_of_each_word(): void {
		$this->assertSame( 'Hello World', Str::mb_ucwords( 'hello world' ) );
	}

	public function test_is_multibyte_safe(): void {
		$this->assertSame( 'Café Del Mar', Str::mb_ucwords( 'café del mar' ) );
	}

	public function test_preserves_rest_of_word_uppercase(): void {
		// Only the first char is forced up; the rest is preserved (RM parity).
		$this->assertSame( 'IPhone', Str::mb_ucwords( 'iPhone' ) );
	}

	public function test_preserves_multiple_spaces(): void {
		$this->assertSame( 'A  B', Str::mb_ucwords( 'a  b' ) );
	}

	public function test_empty_string_stays_empty(): void {
		$this->assertSame( '', Str::mb_ucwords( '' ) );
	}
}
