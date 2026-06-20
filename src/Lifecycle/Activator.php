<?php
/**
 * Activation routine.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Lifecycle;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Settings\Options;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Seed defaults and store the installed version.
	 *
	 * Existing configuration is never overwritten so reactivation is safe.
	 */
	public static function activate(): void {
		if ( false === get_option( Options::OPTION_KEY, false ) ) {
			// Autoload: this small option is read on every front-end request
			// (wp_head presenters, the Resolver), so eager loading is correct.
			add_option( Options::OPTION_KEY, ( new Options() )->defaults(), '', true );
		}

		Schema::install();

		update_option( 'openseo_version', OPENSEO_VERSION );
	}
}
