<?php
/**
 * Deactivation routine.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Lifecycle;

/**
 * Runs once when the plugin is deactivated.
 *
 * Deactivation must be reversible: clear transient runtime state (scheduled
 * events, caches) but never delete user data. Data removal belongs in uninstall.
 */
final class Deactivator {

	/**
	 * Clear transient runtime state on deactivation.
	 */
	public static function deactivate(): void {
		// Clear any scheduled events the plugin may register in the future.
		wp_clear_scheduled_hook( 'openseo_daily_scan' );
	}
}
