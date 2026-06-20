<?php
/**
 * Matches a normalized path against a ruleset.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure matcher: exact rules first (O(1)), then regex rules in order. The first
 * regex hit wins; capture groups are substituted into the target.
 */
final class Matcher {

	/**
	 * Find the first matching rule for a path.
	 *
	 * @param Ruleset $ruleset Active rules to match against.
	 * @param string  $path    Normalized request path.
	 */
	public function match( Ruleset $ruleset, string $path ): ?MatchResult {
		$exact = $ruleset->exact( $path );
		if ( null !== $exact ) {
			return $this->result( $exact, $path, null );
		}

		foreach ( $ruleset->regex_rules() as $rule ) {
			$matches = Regex::match( $rule->source, $path );
			if ( null !== $matches ) {
				return $this->result( $rule, $path, $matches );
			}
		}

		return null;
	}

	/**
	 * Build a MatchResult, applying regex substitution and the self-loop guard.
	 *
	 * @param Redirect                $rule    The matched rule.
	 * @param string                  $path    Normalized request path.
	 * @param array<int, string>|null $matches Regex capture groups, or null for exact rules.
	 */
	private function result( Redirect $rule, string $path, ?array $matches ): ?MatchResult {
		$target = null === $matches
			? $rule->target
			: Regex::substitute( $rule->target, $matches );

		// Anti-loop: never redirect a path to itself.
		if ( $target === $path ) {
			return null;
		}

		return new MatchResult( $rule->id, $target, $rule->status );
	}
}
