<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Redirect;
use OpenSEO\Redirects\Ruleset;
use PHPUnit\Framework\TestCase;

final class RulesetTest extends TestCase {

	public function test_indexes_exact_and_regex_rules(): void {
		$ruleset = new Ruleset();
		$ruleset->add( new Redirect( 1, '/old', '/new', 301, false, true ) );
		$ruleset->add( new Redirect( 2, '^/p/(\d+)$', '/post/$1', 301, true, true ) );

		$this->assertSame( 1, $ruleset->exact( '/old' )->id );
		$this->assertNull( $ruleset->exact( '/missing' ) );
		$this->assertCount( 1, $ruleset->regex_rules() );
		$this->assertSame( 2, $ruleset->count() );
	}

	public function test_ignores_disabled_rules(): void {
		$ruleset = new Ruleset();
		$ruleset->add( new Redirect( 1, '/old', '/new', 301, false, false ) );

		$this->assertNull( $ruleset->exact( '/old' ) );
		$this->assertSame( 0, $ruleset->count() );
	}

	public function test_preserves_regex_insertion_order(): void {
		$ruleset = new Ruleset();
		$ruleset->add( new Redirect( 1, 'a', '/a', 301, true, true ) );
		$ruleset->add( new Redirect( 2, 'b', '/b', 301, true, true ) );

		$rules = $ruleset->regex_rules();
		$this->assertSame( 1, $rules[0]->id );
		$this->assertSame( 2, $rules[1]->id );
	}
}
