<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\NotFound\LogRepository;
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

	public function test_prune_removes_old_rows(): void {
		global $wpdb;
		$this->logs->record( '/old' );
		// Backdate last_seen beyond the retention window.
		$wpdb->query( "UPDATE " . Schema::logs_table() . " SET last_seen = '2000-01-01 00:00:00'" );

		$this->assertSame( 1, $this->logs->prune( 30 ) );
		$this->assertSame( 0, $this->logs->count_all() );
	}
}
