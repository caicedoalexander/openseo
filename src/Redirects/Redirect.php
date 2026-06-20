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

	public function __construct(
		public readonly int $id,
		public readonly string $source,
		public readonly string $target,
		public readonly int $status,
		public readonly bool $is_regex,
		public readonly bool $enabled,
	) {}
}
