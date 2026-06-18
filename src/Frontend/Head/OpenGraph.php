<?php
/**
 * Open Graph meta presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs Open Graph tags for social sharing.
 */
final class OpenGraph implements Presenter {

	/**
	 * Initializes the presenter with the SEO resolver.
	 *
	 * @param Resolver $resolver SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	/**
	 * Print Open Graph meta tags, skipping any with empty values.
	 */
	public function output(): void {
		$tags = array(
			'og:type'        => is_singular() ? 'article' : 'website',
			'og:title'       => $this->resolver->social_title(),
			'og:description' => $this->resolver->social_description(),
			'og:url'         => $this->resolver->canonical(),
			'og:image'       => $this->resolver->social_image(),
		);

		foreach ( $tags as $property => $value ) {
			if ( '' === $value ) {
				continue;
			}

			// URL-valued properties get esc_url (validates the protocol);
			// text-valued ones get esc_attr.
			$is_url = in_array( $property, array( 'og:url', 'og:image' ), true );

			printf(
				'<meta property="%s" content="%s" />' . "\n",
				esc_attr( $property ),
				$is_url ? esc_url( $value ) : esc_attr( $value )
			);
		}
	}
}
