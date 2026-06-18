<?php
/**
 * Plugin Name:       OpenSEO
 * Plugin URI:        https://github.com/openseo/openseo
 * Description:       Open-source, AI-native SEO toolkit for WordPress. Built on the WordPress 7.0 Abilities API and AI Client.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            OpenSEO Contributors
 * Author URI:        https://github.com/openseo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       openseo
 * Domain Path:       /languages
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

// Guard against double-loading.
if ( defined( 'OPENSEO_VERSION' ) ) {
	return;
}

// Plugin constants.
define( 'OPENSEO_VERSION', '0.1.0' );
define( 'OPENSEO_PLUGIN_FILE', __FILE__ );
define( 'OPENSEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPENSEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OPENSEO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Load the Composer autoloader.
 *
 * In a development checkout this requires `composer install`. The distributed
 * build ships a production autoloader generated with `composer install --no-dev -o`.
 */
$openseo_autoloader = OPENSEO_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! is_readable( $openseo_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'OpenSEO could not start: the Composer autoloader is missing. Run "composer install" in the plugin directory.',
					'openseo'
				)
			);
		}
	);

	return;
}

require_once $openseo_autoloader;

/*
 * Lifecycle hooks must be registered at the top level of the main plugin file,
 * never inside another hook.
 */
register_activation_hook( __FILE__, array( Lifecycle\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lifecycle\Deactivator::class, 'deactivate' ) );

/*
 * Boot the plugin once all plugins are loaded so that integrations and the
 * Abilities API are available. No heavy work happens at file-load time.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
