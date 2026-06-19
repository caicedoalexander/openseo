<?php
/**
 * Registers the dynamic breadcrumbs block.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Breadcrumbs;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * The openseo/breadcrumbs block. Server-rendered so it always reflects the live
 * page position, reusing the shared Trail + Renderer.
 */
final class Block implements Hookable {

	private const NAME   = 'openseo/breadcrumbs';
	private const HANDLE = 'openseo-breadcrumbs-editor';

	/**
	 * Constructor.
	 *
	 * @param Options $options Settings accessor (default separator).
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register the block on init (needed on front and editor requests).
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the editor script and the dynamic block type.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$asset_path = OPENSEO_PLUGIN_DIR . 'assets/build/breadcrumbs.asset.php';
		$asset      = is_readable( $asset_path ) ? require $asset_path : array();

		wp_register_script(
			self::HANDLE,
			OPENSEO_PLUGIN_URL . 'assets/build/breadcrumbs.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? OPENSEO_VERSION,
			true
		);

		register_block_type(
			self::NAME,
			array(
				'api_version'     => '3',
				'title'           => __( 'OpenSEO Breadcrumbs', 'openseo' ),
				'category'        => 'theme',
				'icon'            => 'networking',
				'editor_script'   => self::HANDLE,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'showHome'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'textAlign' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Server-render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$items = ( new Trail() )->items();

		if ( empty( $items ) ) {
			return '';
		}

		return ( new Renderer( $this->options ) )->render(
			$items,
			array(
				'show_home'  => (bool) ( $attributes['showHome'] ?? true ),
				'text_align' => (string) ( $attributes['textAlign'] ?? '' ),
			)
		);
	}
}
