<?php
/**
 * Hookable contract.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Contracts;

/**
 * A module that registers its own WordPress hooks.
 */
interface Hookable {

	/**
	 * Register the module's actions and filters.
	 */
	public function register(): void;
}
