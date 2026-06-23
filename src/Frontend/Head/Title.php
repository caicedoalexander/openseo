<?php
/**
 * Controls the document title.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Meta\Resolver;

/**
 * Short-circuits wp_title with OpenSEO's resolved title when it has one.
 */
final class Title implements Hookable {

	/**
	 * Initializes the filter with the SEO resolver.
	 *
	 * @param Resolver $resolver SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	/**
	 * Hooks into pre_get_document_title to override the document title.
	 */
	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ) );
	}

	/**
	 * Returns the resolved title when non-empty, else the unchanged WordPress title.
	 *
	 * @param string $title Title WordPress would otherwise use.
	 */
	public function filter_title( string $title ): string {
		$resolved = $this->resolver->title();

		return '' !== $resolved ? esc_html( $resolved ) : $title;
	}
}
