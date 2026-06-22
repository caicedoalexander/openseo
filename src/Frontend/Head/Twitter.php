<?php
/**
 * Twitter Card meta presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs Twitter Card tags.
 */
final class Twitter implements Presenter {

	/**
	 * Initializes the presenter with the SEO resolver.
	 *
	 * @param Resolver $resolver SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	/**
	 * Print Twitter Card meta tags, skipping any with empty values.
	 */
	public function output(): void {
		$image = $this->resolver->twitter_image();

		$tags = array(
			'twitter:card'        => $this->resolver->twitter_card(),
			'twitter:title'       => $this->resolver->twitter_title(),
			'twitter:description' => $this->resolver->twitter_description(),
			'twitter:image'       => $image,
		);

		foreach ( $tags as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			printf(
				'<meta name="%s" content="%s" />' . "\n",
				esc_attr( $name ),
				'twitter:image' === $name ? esc_url( $value ) : esc_attr( $value )
			);
		}
	}
}
