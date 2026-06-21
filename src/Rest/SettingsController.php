<?php
/**
 * REST controller for the single OpenSEO settings option.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Rest;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes GET/POST for `openseo_settings`. Writes route through Options::sanitize(),
 * which merges over the stored values (partial updates preserve unsent keys) and
 * drops unknown keys. Auth: manage_options; the nonce is supplied by apiFetch's
 * automatic X-WP-Nonce middleware in wp-admin.
 */
final class SettingsController implements Hookable {

	public const REST_NAMESPACE = 'openseo/v1';

	public const ROUTE = '/settings';

	/**
	 * Constructor.
	 *
	 * @param Options $options Typed settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register the REST routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the GET/POST routes for the settings option.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Capability gate for both routes.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return the full, merged settings array.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->options->all(), 200 );
	}

	/**
	 * Sanitize the (partial) body, persist, and return the merged result.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$input = $request->get_json_params();

		// Options::sanitize() wp_unslash()es internally to match the Settings API
		// ($_POST) path, where WP slashes input. REST JSON bodies are NOT slashed,
		// so slash first to keep wp_unslash() a no-op and preserve literal backslashes.
		$clean = $this->options->sanitize( wp_slash( is_array( $input ) ? $input : array() ) );

		update_option( Options::OPTION_KEY, $clean );

		return new WP_REST_Response( $this->options->all(), 200 );
	}
}
