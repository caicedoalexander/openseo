<?php
/**
 * Uninstall handler for OpenSEO.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

// Bail unless WordPress is performing an uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$openseo_autoloader = __DIR__ . '/vendor/autoload.php';

if ( is_readable( $openseo_autoloader ) ) {
	require_once $openseo_autoloader;
	\OpenSEO\Lifecycle\Uninstaller::uninstall();

	return;
}

// Fallback cleanup if the autoloader is unavailable at uninstall time.
// Mirrors Uninstaller::uninstall() without relying on the Composer autoloader,
// so plugin data is never left orphaned in the database.
global $wpdb;

// Table names are built from $wpdb->prefix (not user input); interpolation is safe.
$openseo_redirects = $wpdb->prefix . 'openseo_redirects';
$openseo_logs      = $wpdb->prefix . 'openseo_404_logs';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$openseo_redirects}" );
$wpdb->query( "DROP TABLE IF EXISTS {$openseo_logs}" );
// phpcs:enable

delete_option( 'openseo_db_version' );
delete_option( 'openseo_settings' );
delete_option( 'openseo_version' );

delete_transient( 'openseo_redirects_ruleset' );
delete_transient( 'openseo_redirects_count' );
