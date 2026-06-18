<?php
/**
 * Orchestrates <head> presenters on wp_head.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Contracts\Hookable;

/**
 * Runs each registered presenter when WordPress prints the document head.
 */
final class HeadPrinter implements Hookable {

	/**
	 * Build the head printer with an ordered list of presenters.
	 *
	 * @param Presenter[] $presenters Ordered presenters to output.
	 */
	public function __construct( private readonly array $presenters ) {}

	/**
	 * Print OpenSEO's head tags early in wp_head.
	 */
	public function register(): void {
		// OpenSEO emits its own canonical; drop the core's to avoid duplicates.
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( $this, 'print_head' ), 1 );
	}

	/**
	 * Output each presenter in order.
	 */
	public function print_head(): void {
		foreach ( $this->presenters as $presenter ) {
			$presenter->output();
		}
	}
}
