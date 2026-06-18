<?php
/**
 * Meta description presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs the meta description tag.
 */
final class Description implements Presenter {

	/**
	 * Initializes the presenter with the SEO resolver.
	 *
	 * @param Resolver $resolver SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	/**
	 * Print the meta description tag, or nothing when the description is empty.
	 */
	public function output(): void {
		$value = $this->resolver->description();

		if ( '' === $value ) {
			return;
		}

		printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $value ) );
	}
}
