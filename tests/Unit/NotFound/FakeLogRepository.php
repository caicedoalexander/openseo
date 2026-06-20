<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\NotFound;

use OpenSEO\NotFound\LogRepositoryInterface;

/**
 * In-memory spy double for LogRepositoryInterface.
 * Shared by MonitorTest and PrunerTest.
 */
final class FakeLogRepository implements LogRepositoryInterface {

	/** @var array<int, array<string, string>> */
	public array $recorded = array();

	public int $pruned_days = 0;

	/**
	 * Record a 404 in memory instead of hitting the database.
	 *
	 * @param string $url        The requested URL path.
	 * @param string $referrer   HTTP Referer header value.
	 * @param string $user_agent HTTP User-Agent header value.
	 */
	public function record( string $url, string $referrer = '', string $user_agent = '' ): void {
		$this->recorded[] = array(
			'url'        => $url,
			'referrer'   => $referrer,
			'user_agent' => $user_agent,
		);
	}

	/**
	 * Capture the prune call without hitting the database.
	 *
	 * @param int $days Retention window in days.
	 */
	public function prune( int $days ): int {
		$this->pruned_days = $days;
		return 0;
	}
}
