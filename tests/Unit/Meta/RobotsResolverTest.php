<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use OpenSEO\Meta\RobotsResolver;
use PHPUnit\Framework\TestCase;

final class RobotsResolverTest extends TestCase {

	public function test_entry_on_wins_over_type_and_global(): void {
		$this->assertTrue( RobotsResolver::resolve( 'on', 'off', false ) );
	}

	public function test_entry_off_wins_and_forces_false(): void {
		$this->assertFalse( RobotsResolver::resolve( 'off', 'on', true ) );
	}

	public function test_legacy_1_is_treated_as_on(): void {
		$this->assertTrue( RobotsResolver::resolve( '1', '', false ) );
	}

	public function test_falls_through_entry_to_type(): void {
		$this->assertTrue( RobotsResolver::resolve( '', 'on', false ) );
		$this->assertFalse( RobotsResolver::resolve( '', 'off', true ) );
	}

	public function test_falls_through_to_global_when_all_inherit(): void {
		$this->assertTrue( RobotsResolver::resolve( '', '', true ) );
		$this->assertFalse( RobotsResolver::resolve( '', '', false ) );
	}
}
