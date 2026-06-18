<?php
/**
 * Canonical link presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs the canonical link tag.
 */
final class Canonical implements Presenter {

	/**
	 * Initializes the presenter with the SEO resolver.
	 *
	 * @param Resolver $resolver SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	/**
	 * Print the canonical link tag, or nothing when the URL is empty.
	 */
	public function output(): void {
		$value = $this->resolver->canonical();

		if ( '' === $value ) {
			return;
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $value ) );
	}
}
