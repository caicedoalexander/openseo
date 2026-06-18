<?php
/**
 * Admin asset loading.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Contracts\Hookable;

/**
 * Enqueues the compiled admin bundle on the OpenSEO settings screen only.
 *
 * The bundle and its dependency metadata are produced by @wordpress/scripts
 * into assets/build/. We read the generated *.asset.php for the dependency
 * array and cache-busting version.
 */
final class Assets implements Hookable {

	private const HANDLE = 'openseo-admin-settings';

	private const SCREEN_HOOK = 'settings_page_openseo';

	/**
	 * Register admin asset hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the admin script and style on the settings screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( self::SCREEN_HOOK !== $hook_suffix ) {
			return;
		}

		$asset_path = OPENSEO_PLUGIN_DIR . 'assets/build/admin-settings.asset.php';

		if ( ! is_readable( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			self::HANDLE,
			OPENSEO_PLUGIN_URL . 'assets/build/admin-settings.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? OPENSEO_VERSION,
			true
		);

		$style_path = OPENSEO_PLUGIN_DIR . 'assets/build/admin-settings.css';

		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				self::HANDLE,
				OPENSEO_PLUGIN_URL . 'assets/build/admin-settings.css',
				array(),
				$asset['version'] ?? OPENSEO_VERSION
			);
		}
	}
}
