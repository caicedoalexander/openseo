<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\NotFound;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\NotFound\Pruner;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class PrunerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $settings Stored option overrides.
	 */
	private function pruner_with( array $settings, FakeLogRepository $logs ): Pruner {
		Functions\when( 'get_option' )->justReturn( $settings );

		return new Pruner( $logs, new Options() );
	}

	// -----------------------------------------------------------------------
	// register()
	// -----------------------------------------------------------------------

	public function test_register_hooks_init_and_cron_hook(): void {
		$captured = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $cb, int $priority = 10 ) use ( &$captured ): void {
				$captured[] = $hook;
			}
		);

		$logs   = new FakeLogRepository();
		$pruner = new Pruner( $logs, new Options() );
		$pruner->register();

		$this->assertContains( 'init', $captured );
		$this->assertContains( 'openseo_404_prune', $captured );
	}

	// -----------------------------------------------------------------------
	// schedule()
	// -----------------------------------------------------------------------

	public function test_schedule_adds_event_when_not_yet_scheduled(): void {
		$scheduled = false;
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function () use ( &$scheduled ): void {
				$scheduled = true;
			}
		);

		$logs   = new FakeLogRepository();
		$pruner = $this->pruner_with( array(), $logs );
		$pruner->schedule();

		$this->assertTrue( $scheduled );
	}

	public function test_schedule_skips_when_already_scheduled(): void {
		$called = false;
		Functions\when( 'wp_next_scheduled' )->justReturn( 999999 );
		Functions\when( 'wp_schedule_event' )->alias(
			static function () use ( &$called ): void {
				$called = true;
			}
		);

		$logs   = new FakeLogRepository();
		$pruner = $this->pruner_with( array(), $logs );
		$pruner->schedule();

		$this->assertFalse( $called );
	}

	// -----------------------------------------------------------------------
	// run()
	// -----------------------------------------------------------------------

	public function test_run_prunes_with_configured_days(): void {
		$logs   = new FakeLogRepository();
		$pruner = $this->pruner_with( array( 'notfound_retention_days' => '60' ), $logs );
		$pruner->run();

		$this->assertSame( 60, $logs->pruned_days );
	}

	public function test_run_clamps_days_to_minimum_one(): void {
		$logs   = new FakeLogRepository();
		$pruner = $this->pruner_with( array( 'notfound_retention_days' => '0' ), $logs );
		$pruner->run();

		$this->assertSame( 1, $logs->pruned_days );
	}

	public function test_run_clamps_negative_days_to_one(): void {
		$logs   = new FakeLogRepository();
		$pruner = $this->pruner_with( array( 'notfound_retention_days' => '-5' ), $logs );
		$pruner->run();

		$this->assertSame( 1, $logs->pruned_days );
	}

	public function test_run_uses_default_retention_when_setting_absent(): void {
		// Options::defaults() sets 'notfound_retention_days' => '30'.
		$logs   = new FakeLogRepository();
		$pruner = $this->pruner_with( array(), $logs );
		$pruner->run();

		$this->assertSame( 30, $logs->pruned_days );
	}
}
