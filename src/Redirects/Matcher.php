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
	 * Constructor.
	 *
	 * @param Normalizer $normalizer Normalizes internal targets for the anti-loop
	 *                               guard. Defaults to a root-relative normalizer,
	 *                               which is correct because targets are stored
	 *                               relative to the site root (post-subdirectory).
	 */
	public function __construct( private readonly Normalizer $normalizer = new Normalizer() ) {}

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
		if ( $this->is_self_loop( $target, $path ) ) {
			return null;
		}

		return new MatchResult( $rule->id, $target, $rule->status );
	}

	/**
	 * Whether redirecting $path to $target would loop back to $path.
	 *
	 * Internal, root-relative targets are normalized the same way the follow-up
	 * request will be, so a difference that is only a trailing slash (e.g.
	 * '/x' → '/x/') is recognized as the same resource instead of an infinite
	 * redirect. External and protocol-relative targets can never normalize to a
	 * local path, so they are never self-loops.
	 *
	 * @param string $target Resolved redirect target.
	 * @param string $path   Normalized request path.
	 */
	public function is_self_loop( string $target, string $path ): bool {
		if ( $target === $path ) {
			return true;
		}

		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			return $this->normalizer->normalize( $target ) === $path;
		}

		return false;
	}
}
