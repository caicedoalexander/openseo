<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Matcher;
use OpenSEO\Redirects\Redirect;
use OpenSEO\Redirects\Ruleset;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase {

	private function ruleset( Redirect ...$rules ): Ruleset {
		$ruleset = new Ruleset();
		foreach ( $rules as $rule ) {
			$ruleset->add( $rule );
		}

		return $ruleset;
	}

	public function test_exact_match_wins(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset(
			new Redirect( 1, '/old', '/new', 301, false, true ),
			new Redirect( 2, '^/old$', '/regex', 302, true, true ),
		);

		$result = $matcher->match( $ruleset, '/old' );

		$this->assertSame( '/new', $result->target );
		$this->assertSame( 301, $result->status );
		$this->assertSame( 1, $result->id );
	}

	public function test_regex_match_substitutes_groups(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 5, '^/p/(\d+)$', '/post/$1', 301, true, true ) );

		$result = $matcher->match( $ruleset, '/p/42' );

		$this->assertSame( '/post/42', $result->target );
		$this->assertSame( 5, $result->id );
	}

	public function test_returns_null_when_no_match(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 1, '/old', '/new', 301, false, true ) );

		$this->assertNull( $matcher->match( $ruleset, '/nope' ) );
	}

	public function test_returns_null_on_self_loop(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 1, '/loop', '/loop', 301, false, true ) );

		$this->assertNull( $matcher->match( $ruleset, '/loop' ) );
	}
}
