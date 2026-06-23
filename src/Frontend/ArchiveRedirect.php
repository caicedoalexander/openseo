<?php
/**
 * Redirects disabled author/date archives to the homepage.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * When author or date archives are turned off in Titles & Meta, redirect those
 * requests to the homepage (301). Runs early on template_redirect so it wins
 * before core's redirect_canonical.
 */
final class ArchiveRedirect implements Hookable {

	/**
	 * Initializes the module with settings.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Hook the redirect early in template_redirect.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirect to the homepage when the current archive is disabled.
	 */
	public function maybe_redirect(): void {
		if ( $this->should_redirect() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Whether the current request is a disabled author/date archive. Pure
	 * decision (no side effects) so it is unit-testable without exit().
	 *
	 * @return bool True when the current request should be redirected.
	 */
	public function should_redirect(): bool {
		if ( is_author() && '1' !== (string) $this->options->get( 'author_archives' ) ) {
			return true;
		}

		if ( is_date() && '1' !== (string) $this->options->get( 'date_archives' ) ) {
			return true;
		}

		return false;
	}
}
