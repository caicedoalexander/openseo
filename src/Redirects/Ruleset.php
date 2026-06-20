<?php
/**
 * In-memory set of active redirect rules.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure container: O(1) exact-source map plus an ordered regex list. Disabled
 * rules are never added, so the Matcher never has to re-check enablement.
 */
final class Ruleset {

	/**
	 * Exact-source map for O(1) lookups.
	 *
	 * @var array<string, Redirect>
	 */
	private array $exact = array();

	/**
	 * Ordered regex rules in insertion order.
	 *
	 * @var Redirect[]
	 */
	private array $regex = array();

	/**
	 * Add a rule (no-op if disabled).
	 *
	 * @param Redirect $rule The redirect rule to add.
	 */
	public function add( Redirect $rule ): void {
		if ( ! $rule->enabled ) {
			return;
		}

		if ( $rule->is_regex ) {
			$this->regex[] = $rule;

			return;
		}

		$this->exact[ $rule->source ] = $rule;
	}

	/**
	 * Exact rule for a path, or null.
	 *
	 * @param string $path The source path to look up.
	 */
	public function exact( string $path ): ?Redirect {
		return $this->exact[ $path ] ?? null;
	}

	/**
	 * Ordered regex rules.
	 *
	 * @return Redirect[]
	 */
	public function regex_rules(): array {
		return $this->regex;
	}

	/**
	 * Total active rule count.
	 */
	public function count(): int {
		return count( $this->exact ) + count( $this->regex );
	}
}
