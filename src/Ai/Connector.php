<?php
/**
 * Asks the WordPress AI Client whether text generation is available.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Ai;

/**
 * Single source of truth for "is an AI connector ready?".
 *
 * Uses the AI Client's feature detection, which does not call the provider's
 * API, so it is cheap to call on every editor load.
 */
final class Connector {

	/**
	 * Whether a configured connector can generate text right now.
	 */
	public static function is_text_generation_available(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		return (bool) wp_ai_client_prompt( '' )->is_supported_for_text_generation();
	}

	/**
	 * Admin URL of the Settings → Connectors screen.
	 *
	 * Single source of truth so the editor panel and the settings page link to
	 * the same place. Confirm the page slug against WP 7.0 in wp-env (Task 0).
	 */
	public static function settings_url(): string {
		return admin_url( 'options-general.php?page=connectors' );
	}
}
