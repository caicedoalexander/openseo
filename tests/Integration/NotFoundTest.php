<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\NotFound\LogRepository;
use OpenSEO\NotFound\Monitor;
use OpenSEO\NotFound\Pruner;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class NotFoundTest extends WP_UnitTestCase {

	private LogRepository $logs;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->logs = new LogRepository();
	}

	public function test_record_aggregates_by_url(): void {
		$this->logs->record( '/missing', 'https://ref', 'UA' );
		$first = $this->logs->all( 10, 0 )[0];

		$this->logs->record( '/missing', 'https://ref2', 'UA2' );
		$second = $this->logs->all( 10, 0 )[0];

		$this->assertCount( 1, $this->logs->all( 10, 0 ) );
		$this->assertSame( '2', $second['hits'] );
		$this->assertSame( '/missing', $second['url'] );
		// The upsert must NOT touch first_seen; it must update referrer/UA.
		$this->assertSame( $first['first_seen'], $second['first_seen'] );
		$this->assertSame( 'https://ref2', $second['referrer'] );
	}

	public function test_delete_removes_a_single_logged_url(): void {
		$this->logs->record( '/gone' );
		$id = (int) $this->logs->all( 10, 0 )[0]['id'];

		$this->assertTrue( $this->logs->delete( $id ) );
		$this->assertSame( 0, $this->logs->count_all() );
	}

	public function test_clear_empties_the_whole_log(): void {
		$this->logs->record( '/a' );
		$this->logs->record( '/b' );
		$this->assertSame( 2, $this->logs->count_all() );

		$this->logs->clear();

		$this->assertSame( 0, $this->logs->count_all() );
	}

	public function test_prune_removes_old_rows(): void {
		global $wpdb;
		$this->logs->record( '/old' );
		// Backdate last_seen beyond the retention window.
		$wpdb->query( "UPDATE " . Schema::logs_table() . " SET last_seen = '2000-01-01 00:00:00'" );

		$this->assertSame( 1, $this->logs->prune( 30 ) );
		$this->assertSame( 0, $this->logs->count_all() );
	}

	public function test_monitor_logs_404_when_enabled(): void {
		update_option( 'openseo_settings', array( 'notfound_monitor_enabled' => '1' ) );
		$logs    = new LogRepository();
		$monitor = new Monitor( $logs, new Options() );
		$this->go_to( home_url( '/definitely-not-a-real-page' ) );
		// go_to does not guarantee is_404() in the test environment — force it.
		$GLOBALS['wp_query']->set_404();
		$monitor->maybe_log();
		$this->assertSame( 1, $logs->count_all() );
	}

	public function test_monitor_skips_when_disabled(): void {
		update_option( 'openseo_settings', array( 'notfound_monitor_enabled' => '' ) );
		$logs    = new LogRepository();
		$monitor = new Monitor( $logs, new Options() );
		$this->go_to( home_url( '/another-missing-page' ) );
		$GLOBALS['wp_query']->set_404();
		$monitor->maybe_log();
		$this->assertSame( 0, $logs->count_all() );
	}

	public function test_pruner_schedules_daily_event(): void {
		wp_clear_scheduled_hook( 'openseo_404_prune' );
		$pruner = new Pruner( new LogRepository(), new Options() );
		$pruner->schedule();
		$this->assertNotFalse( wp_next_scheduled( 'openseo_404_prune' ) );
		wp_clear_scheduled_hook( 'openseo_404_prune' );
	}

	public function test_pruner_run_deletes_old_rows(): void {
		update_option( 'openseo_settings', array( 'notfound_retention_days' => '30' ) );
		global $wpdb;
		$logs = new LogRepository();
		$logs->record( '/old-404' );
		$wpdb->query( 'UPDATE ' . Schema::logs_table() . " SET last_seen = '2000-01-01 00:00:00'" );
		( new Pruner( $logs, new Options() ) )->run();
		$this->assertSame( 0, $logs->count_all() );
	}
}
