<?php
/**
 * Robots meta tag presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs the robots meta tag.
 */
final class Robots implements Presenter {

	/**
	 * Initializes the presenter with the SEO resolver.
	 *
	 * @param Resolver $resolver SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	/**
	 * Print the robots meta tag.
	 */
	public function output(): void {
		printf( '<meta name="robots" content="%s" />' . "\n", esc_attr( $this->resolver->robots() ) );
	}
}
