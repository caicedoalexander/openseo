<?php
/**
 * Immutable redirect rule (matching-relevant fields only).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Value object for one redirect rule. Volatile counters (hits, last_accessed)
 * are intentionally excluded so the cached Ruleset never needs invalidating
 * when a hit is recorded.
 */
final class Redirect {

	/**
	 * Build an immutable redirect rule.
	 *
	 * @param int    $id       Row id (0 for an unsaved rule).
	 * @param string $source   Source path, or regex pattern when $is_regex.
	 * @param string $target   Redirect target (empty for a 410).
	 * @param int    $status   HTTP status code (301, 302, 307, 410).
	 * @param bool   $is_regex Whether $source is a regex pattern.
	 * @param bool   $enabled  Whether the rule is active.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $source,
		public readonly string $target,
		public readonly int $status,
		public readonly bool $is_regex,
		public readonly bool $enabled,
	) {}
}
