<?php
/**
 * Redirects attachment pages to their parent post.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * When attachment redirection is enabled in Titles & Meta, send attachment
 * page requests to their parent post (301), or to the configured orphan URL /
 * homepage when there is no usable parent. Runs after the explicit redirect
 * engine (Dispatcher@5) so manual rules win, and before redirect_canonical@10.
 */
final class AttachmentRedirect implements Hookable {

	/**
	 * Initializes the module with settings.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Hook the redirect on template_redirect at priority 6.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 6 );
	}

	/**
	 * Perform the redirect when the current request is a redirectable attachment.
	 */
	public function maybe_redirect(): void {
		if ( ! $this->should_redirect() ) {
			return;
		}

		wp_safe_redirect( $this->target(), 301 );
		exit;
	}

	/**
	 * Whether the current request is an attachment page with redirection on.
	 * Pure decision (no side effects) so it is unit-testable without exit().
	 */
	public function should_redirect(): bool {
		return is_attachment() && '1' === (string) $this->options->get( 'attachment_redirect' );
	}

	/**
	 * Resolve the redirect destination: parent permalink, else the configured
	 * orphan URL, else the homepage. Guards against a parent that resolves back
	 * to the attachment's own URL (corrupt data) to avoid a redirect loop.
	 */
	public function target(): string {
		$id     = get_queried_object_id();
		$parent = (int) get_post_field( 'post_parent', $id );
		$url    = $parent > 0 ? (string) get_permalink( $parent ) : '';

		if ( '' === $url || (string) get_permalink( $id ) === $url ) {
			$orphan = (string) $this->options->get( 'attachment_redirect_orphan' );
			$url    = '' !== $orphan ? $orphan : home_url( '/' );
		}

		return $url;
	}
}
