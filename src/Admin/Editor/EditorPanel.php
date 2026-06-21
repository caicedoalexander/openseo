<?php
/**
 * Loads the OpenSEO block-editor panel.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin\Editor;

use OpenSEO\Ai\Connector;
use OpenSEO\Contracts\Hookable;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Settings\Options;

/**
 * Enqueues the compiled editor bundle so the SEO document panel appears.
 */
final class EditorPanel implements Hookable {

	private const HANDLE = 'openseo-editor';

	/**
	 * Constructor.
	 *
	 * @param Options       $options        Settings accessor (separator).
	 * @param TypeTemplates $type_templates Effective per-type templates.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly TypeTemplates $type_templates
	) {}

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

		$style_path = OPENSEO_PLUGIN_DIR . 'assets/build/style-editor.css';
		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				self::HANDLE,
				OPENSEO_PLUGIN_URL . 'assets/build/style-editor.css',
				array(),
				$asset['version'] ?? OPENSEO_VERSION
			);
		}

		$post_type = $this->current_post_type();

		wp_add_inline_script(
			self::HANDLE,
			'window.openseoEditor = ' . wp_json_encode(
				array(
					'aiAvailable'         => Connector::is_text_generation_available(),
					'connectorsUrl'       => Connector::settings_url(),
					'titleTemplate'       => $this->type_templates->title_for( $post_type ),
					'descriptionTemplate' => $this->type_templates->description_for( $post_type ),
					'separator'           => (string) $this->options->get( 'title_separator' ),
					'siteName'            => (string) get_bloginfo( 'name' ),
					'tagline'             => (string) get_bloginfo( 'description' ),
					'siteUrl'             => (string) home_url( '/' ),
					'siteIcon'            => (string) get_site_icon_url(),
				),
				JSON_HEX_TAG
			) . ';',
			'before'
		);

		wp_set_script_translations( self::HANDLE, 'openseo' );
	}

	/**
	 * Best-effort current post type on the editor screen, defaulting to 'post'.
	 */
	private function current_post_type(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen && '' !== (string) $screen->post_type ) {
			return (string) $screen->post_type;
		}

		if ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$type = (string) get_post_type( $GLOBALS['post'] );
			if ( '' !== $type ) {
				return $type;
			}
		}

		return 'post';
	}
}
