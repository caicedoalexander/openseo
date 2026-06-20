<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use WP_UnitTestCase;

final class DbSchemaTest extends WP_UnitTestCase {

	public function test_install_creates_both_tables(): void {
		global $wpdb;

		Schema::install();

		$redirects = Schema::redirects_table();
		$logs      = Schema::logs_table();

		$this->assertSame( $redirects, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redirects ) ) );
		$this->assertSame( $logs, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs ) ) );
		$this->assertSame( Schema::VERSION, Schema::current_version() );
	}

	public function test_install_is_idempotent(): void {
		global $wpdb;

		Schema::install();
		Schema::install(); // Second run must not error.

		$redirects = Schema::redirects_table();
		$logs      = Schema::logs_table();

		$this->assertSame( $redirects, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redirects ) ) );
		$this->assertSame( $logs, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs ) ) );
		$this->assertSame( '', (string) $wpdb->last_error );
		$this->assertSame( Schema::VERSION, Schema::current_version() );
	}
}
