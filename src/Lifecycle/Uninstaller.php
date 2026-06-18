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
	 * Delete all options created by the plugin.
	 */
	public static function uninstall(): void {
		delete_option( Options::OPTION_KEY );
		delete_option( 'openseo_version' );
	}
}
