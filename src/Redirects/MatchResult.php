<?php
/**
 * Result of matching a request path against the ruleset.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Immutable outcome of a successful match.
 */
final class MatchResult {

	/**
	 * @param int    $id     Matched rule id.
	 * @param string $target Resolved redirect target.
	 * @param int    $status HTTP status code.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $target,
		public readonly int $status,
	) {}
}
