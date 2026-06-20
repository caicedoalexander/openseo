<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\NotFound;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\NotFound\Monitor;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class MonitorTest extends TestCase {

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
	private function monitor_with( array $settings, FakeLogRepository $logs ): Monitor {
		Functions\when( 'get_option' )->justReturn( $settings );

		return new Monitor( $logs, new Options() );
	}

	// -----------------------------------------------------------------------
	// register()
	// -----------------------------------------------------------------------

	public function test_register_hooks_template_redirect_at_priority_99(): void {
		$captured = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $cb, int $priority = 10 ) use ( &$captured ): void {
				$captured[] = array( $hook, $priority );
			}
		);

		$logs    = new FakeLogRepository();
		$monitor = new Monitor( $logs, new Options() );
		$monitor->register();

		$this->assertContains( array( 'template_redirect', 99 ), $captured );
	}

	// -----------------------------------------------------------------------
	// maybe_log() — early-return guards
	// -----------------------------------------------------------------------

	public function test_skips_when_is_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$logs    = new FakeLogRepository();
		$monitor = $this->monitor_with( array( 'notfound_monitor_enabled' => '1' ), $logs );
		$monitor->maybe_log();

		$this->assertCount( 0, $logs->recorded );
	}

	public function test_skips_when_not_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );

		$logs    = new FakeLogRepository();
		$monitor = $this->monitor_with( array( 'notfound_monitor_enabled' => '1' ), $logs );
		$monitor->maybe_log();

		$this->assertCount( 0, $logs->recorded );
	}

	public function test_skips_when_monitor_disabled(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );

		$logs    = new FakeLogRepository();
		$monitor = $this->monitor_with( array( 'notfound_monitor_enabled' => '0' ), $logs );
		$monitor->maybe_log();

		$this->assertCount( 0, $logs->recorded );
	}

	public function test_skips_when_monitor_setting_absent(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );

		$logs    = new FakeLogRepository();
		$monitor = $this->monitor_with( array(), $logs );
		$monitor->maybe_log();

		$this->assertCount( 0, $logs->recorded );
	}

	public function test_skips_when_url_is_empty_after_sanitize(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );
		// esc_url_raw of an empty/invalid URI returns ''.
		Functions\when( 'esc_url_raw' )->justReturn( '' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_SERVER['REQUEST_URI'] = '';
		$logs                   = new FakeLogRepository();
		$monitor                = $this->monitor_with( array( 'notfound_monitor_enabled' => '1' ), $logs );
		$monitor->maybe_log();
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertCount( 0, $logs->recorded );
	}

	// -----------------------------------------------------------------------
	// maybe_log() — happy path
	// -----------------------------------------------------------------------

	public function test_records_404_with_url_referrer_and_user_agent(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => 'sanitized:' . $v );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => 'clean:' . $v );

		$_SERVER['REQUEST_URI']     = '/missing-page';
		$_SERVER['HTTP_REFERER']    = 'https://example.com';
		$_SERVER['HTTP_USER_AGENT'] = 'TestBot/1.0';

		$logs    = new FakeLogRepository();
		$monitor = $this->monitor_with( array( 'notfound_monitor_enabled' => '1' ), $logs );
		$monitor->maybe_log();

		unset( $_SERVER['REQUEST_URI'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_USER_AGENT'] );

		$this->assertCount( 1, $logs->recorded );
		$this->assertSame( 'sanitized:/missing-page', $logs->recorded[0]['url'] );
		$this->assertSame( 'sanitized:https://example.com', $logs->recorded[0]['referrer'] );
		$this->assertSame( 'clean:TestBot/1.0', $logs->recorded[0]['user_agent'] );
	}

	public function test_records_with_empty_referrer_and_user_agent_when_headers_absent(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_SERVER['REQUEST_URI'] = '/gone';
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_USER_AGENT'] );

		$logs    = new FakeLogRepository();
		$monitor = $this->monitor_with( array( 'notfound_monitor_enabled' => '1' ), $logs );
		$monitor->maybe_log();

		unset( $_SERVER['REQUEST_URI'] );

		$this->assertCount( 1, $logs->recorded );
		$this->assertSame( '', $logs->recorded[0]['referrer'] );
		$this->assertSame( '', $logs->recorded[0]['user_agent'] );
	}
}
