<?php
/**
 * Uninstall routine.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Lifecycle;

use OpenSEO\Settings\Options;

/**
 * Removes all data created by the plugin.
 *
 * Called from uninstall.php only, which itself guards on WP_UNINSTALL_PLUGIN.
 */
final class Uninstaller {

	/**
	 * Delete all options and tables created by the plugin.
	 *
	 * Keep in sync with the autoloader-less fallback in uninstall.php, which
	 * replicates this cleanup inline for the case where the Composer autoloader
	 * (and therefore this class) is unavailable at uninstall time.
	 */
	public static function uninstall(): void {
		global $wpdb;

		// Table names are built from $wpdb->prefix (not user input); interpolation is safe.
		$redirects = $wpdb->prefix . 'openseo_redirects';
		$logs      = $wpdb->prefix . 'openseo_404_logs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$redirects}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$logs}" );
		// phpcs:enable

		delete_option( 'openseo_db_version' );
		delete_option( Options::OPTION_KEY );
		delete_option( 'openseo_version' );
	}
}
