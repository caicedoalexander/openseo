<?php
/**
 * Front-end SEO meta output.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Outputs SEO meta tags in the document head.
 *
 * Keeps a clear separation between resolving the description (data) and
 * printing it (escaped output) so it is easy to unit test the resolution logic.
 */
final class MetaTags implements Hookable {

	/**
	 * Build the meta tags module.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register the wp_head output hook.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
	}

	/**
	 * Print the meta description tag when enabled and non-empty.
	 */
	public function output_meta_description(): void {
		if ( ! $this->options->get( 'enable_meta_description' ) ) {
			return;
		}

		$description = $this->resolve_description();

		if ( '' === $description ) {
			return;
		}

		printf(
			'<meta name="description" content="%s" />' . "\n",
			esc_attr( $description )
		);
	}

	/**
	 * Resolve the best meta description for the current request.
	 */
	private function resolve_description(): string {
		if ( is_singular() ) {
			$excerpt = get_the_excerpt();

			if ( is_string( $excerpt ) && '' !== trim( $excerpt ) ) {
				return wp_strip_all_tags( $excerpt );
			}
		}

		$default = (string) $this->options->get( 'default_meta_description' );

		if ( '' !== $default ) {
			return $default;
		}

		return (string) get_bloginfo( 'description' );
	}
}
