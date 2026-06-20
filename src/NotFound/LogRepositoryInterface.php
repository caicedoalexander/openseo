<?php
/**
 * Contract for 404 log data access.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound;

/**
 * Defines the persistence operations Monitor and Pruner depend on.
 */
interface LogRepositoryInterface {

	/**
	 * Upsert a 404 hit, aggregating by URL.
	 *
	 * @param string $url        The requested URL path.
	 * @param string $referrer   HTTP Referer header value.
	 * @param string $user_agent HTTP User-Agent header value.
	 */
	public function record( string $url, string $referrer = '', string $user_agent = '' ): void;

	/**
	 * Delete rows whose last_seen is older than $days. Returns rows removed.
	 *
	 * @param int $days Retention window in days.
	 */
	public function prune( int $days ): int;
}
