<?php
/**
 * Lookup contract used by the rule validator (extracted so it is mockable;
 * Repository is final and hits $wpdb, so it cannot be doubled directly).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Minimal read contract over the redirects store.
 */
interface RedirectLookup {

	/**
	 * Find one active, exact (non-regex) rule by normalized source path.
	 *
	 * @param string $path Normalized source path.
	 */
	public function find_active_by_source( string $path ): ?Redirect;
}
