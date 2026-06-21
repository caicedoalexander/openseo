<?php
/**
 * Immutable rendering context for template variables.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use WP_Term;

/**
 * Carries the primitives a template needs (title, excerpt, term name/description)
 * without retaining WP_Post/WP_Term, so Variables stays pure and unit-testable.
 * All WordPress reads happen in the factories.
 */
final class TemplateContext {

	/**
	 * Creates an immutable context with the given primitives.
	 *
	 * @param int    $post_id          Post ID (0 for non-post contexts).
	 * @param string $title            Post title.
	 * @param string $excerpt          Post excerpt.
	 * @param string $term_name        Term name.
	 * @param string $term_description Term description.
	 */
	private function __construct(
		public readonly int $post_id,
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $term_name,
		public readonly string $term_description,
	) {}

	/**
	 * Context for a singular post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function for_post( int $post_id ): self {
		return new self(
			$post_id,
			(string) get_the_title( $post_id ),
			wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ),
			'',
			'',
		);
	}

	/**
	 * Context for a taxonomy term archive.
	 *
	 * @param WP_Term $term Queried term.
	 */
	public static function for_term( WP_Term $term ): self {
		return new self(
			0,
			'',
			'',
			(string) $term->name,
			wp_strip_all_tags( (string) $term->description ),
		);
	}

	/**
	 * Empty context (no post, no term).
	 */
	public static function none(): self {
		return new self( 0, '', '', '', '' );
	}
}
