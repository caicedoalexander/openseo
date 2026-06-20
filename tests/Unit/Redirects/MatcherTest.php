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

	public function test_returns_null_when_internal_target_loops_via_trailing_slash(): void {
		// The source normalizes to '/x'; the target was stored verbatim as '/x/'.
		// Both address the same resource, so matching must not redirect (which
		// would loop: /x → /x/ → /x → /x/ …).
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 1, '/x', '/x/', 301, false, true ) );

		$this->assertNull( $matcher->match( $ruleset, '/x' ) );
	}

	public function test_returns_null_when_regex_target_loops_via_trailing_slash(): void {
		// A regex whose substituted target differs from the path only by a
		// trailing slash still loops back to the same resource.
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 1, '^(/loop)$', '$1/', 301, true, true ) );

		$this->assertNull( $matcher->match( $ruleset, '/loop' ) );
	}

	public function test_is_self_loop_normalizes_internal_targets_and_ignores_external(): void {
		$matcher = new Matcher();

		// Internal, root-relative targets that resolve to the same path loop.
		$this->assertTrue( $matcher->is_self_loop( '/x', '/x' ) );
		$this->assertTrue( $matcher->is_self_loop( '/x/', '/x' ) );

		// Different internal target, or external / protocol-relative target: no loop.
		$this->assertFalse( $matcher->is_self_loop( '/y', '/x' ) );
		$this->assertFalse( $matcher->is_self_loop( 'https://other.example/x', '/x' ) );
		$this->assertFalse( $matcher->is_self_loop( '//evil.example', '/x' ) );
	}

	public function test_is_self_loop_treats_query_or_fragment_only_targets_as_loops(): void {
		// The matcher strips query and fragment, so a target that only adds a
		// query or fragment to the same path would loop: the follow-up request
		// normalizes back to the path and re-matches the same rule. This locks in
		// that contract (it is not limited to trailing-slash differences).
		$matcher = new Matcher();

		$this->assertTrue( $matcher->is_self_loop( '/x?ref=1', '/x' ) );
		$this->assertTrue( $matcher->is_self_loop( '/x#section', '/x' ) );
	}
}
