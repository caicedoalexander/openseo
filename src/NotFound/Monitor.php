<?php
/**
 * Logs front-end 404s when the monitor is enabled.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Records real 404s on template_redirect at priority 99 — after the Dispatcher
 * (priority 5) has already exited on any match, so only genuine 404s arrive.
 */
final class Monitor implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param LogRepositoryInterface $logs    404 log repository.
	 * @param Options                $options Plugin settings.
	 */
	public function __construct(
		private readonly LogRepositoryInterface $logs,
		private readonly Options $options,
	) {}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_log' ), 99 );
	}

	/**
	 * Log the current request if it is a 404 and the monitor is on.
	 */
	public function maybe_log(): void {
		if ( is_admin() || ! is_404() ) {
			return;
		}
		if ( '1' !== (string) $this->options->get( 'notfound_monitor_enabled' ) ) {
			return;
		}

		$url        = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $url ) {
			return;
		}

		$this->logs->record( $url, $referrer, $user_agent );
	}
}
