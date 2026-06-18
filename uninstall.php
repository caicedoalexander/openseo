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
delete_option( 'openseo_settings' );
delete_option( 'openseo_version' );
