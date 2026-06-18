<?php
/**
 * Loads the OpenSEO block-editor panel.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin\Editor;

use OpenSEO\Contracts\Hookable;

/**
 * Enqueues the compiled editor bundle so the SEO document panel appears.
 */
final class EditorPanel implements Hookable {

	private const HANDLE = 'openseo-editor';

	/**
	 * Register block-editor asset hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the editor script when the block editor loads.
	 */
	public function enqueue(): void {
		$asset_path = OPENSEO_PLUGIN_DIR . 'assets/build/editor.asset.php';

		if ( ! is_readable( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			self::HANDLE,
			OPENSEO_PLUGIN_URL . 'assets/build/editor.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? OPENSEO_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'openseo' );
	}
}
