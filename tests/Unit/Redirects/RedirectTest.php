<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\MatchResult;
use OpenSEO\Redirects\Redirect;
use PHPUnit\Framework\TestCase;

final class RedirectTest extends TestCase {

	public function test_redirect_exposes_readonly_fields(): void {
		$redirect = new Redirect( 7, '/old', '/new', 301, false, true );

		$this->assertSame( 7, $redirect->id );
		$this->assertSame( '/old', $redirect->source );
		$this->assertSame( '/new', $redirect->target );
		$this->assertSame( 301, $redirect->status );
		$this->assertFalse( $redirect->is_regex );
		$this->assertTrue( $redirect->enabled );
	}

	public function test_match_result_exposes_readonly_fields(): void {
		$result = new MatchResult( 7, '/new', 301 );

		$this->assertSame( 7, $result->id );
		$this->assertSame( '/new', $result->target );
		$this->assertSame( 301, $result->status );
	}
}
