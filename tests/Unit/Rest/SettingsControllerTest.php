<?php
/**
 * Unit tests for the settings REST controller permission gate.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Rest\SettingsController;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_check_permission_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( string $cap ): bool => 'manage_options' === $cap
		);

		$controller = new SettingsController( new Options() );

		$this->assertTrue( $controller->check_permission() );
	}

	public function test_check_permission_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$controller = new SettingsController( new Options() );

		$this->assertFalse( $controller->check_permission() );
	}
}
