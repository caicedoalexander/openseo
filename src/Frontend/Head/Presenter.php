<?php
/**
 * A unit of <head> output.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

/**
 * Renders one slice of the document head as escaped output.
 */
interface Presenter {

	/**
	 * Echo the presenter's tag(s), already escaped. Print nothing when empty.
	 */
	public function output(): void;
}
