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
 * Carries the primitives a template needs (title, excerpt, term name/description,
 * date, modified date, author, primary category/tag, and parent title)
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
	 * @param string $date             Published date.
	 * @param string $modified         Last-modified date.
	 * @param string $author           Post author display name.
	 * @param string $category         Primary category name.
	 * @param string $tag              Primary tag name.
	 * @param string $parent_title     Parent entry title.
	 */
	private function __construct(
		public readonly int $post_id,
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $term_name,
		public readonly string $term_description,
		public readonly string $date = '',
		public readonly string $modified = '',
		public readonly string $author = '',
		public readonly string $category = '',
		public readonly string $tag = '',
		public readonly string $parent_title = '',
	) {}

	/**
	 * Context for a singular post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function for_post( int $post_id ): self {
		$categories = get_the_category( $post_id );
		$tags       = get_the_tags( $post_id );
		$parent     = (int) wp_get_post_parent_id( $post_id );

		// get_post_field stub returns int|string|int[]; is_scalar narrows away
		// the array branch so the (int) cast is PHPStan-level-6 clean (M3).
		$author    = get_post_field( 'post_author', $post_id );
		$author_id = is_scalar( $author ) ? (int) $author : 0;

		return new self(
			$post_id,
			(string) get_the_title( $post_id ),
			wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ),
			'',
			'',
			(string) get_the_date( '', $post_id ),
			(string) get_the_modified_date( '', $post_id ),
			(string) get_the_author_meta( 'display_name', $author_id ),
			isset( $categories[0] ) ? (string) $categories[0]->name : '',
			is_array( $tags ) && isset( $tags[0] ) ? (string) $tags[0]->name : '',
			$parent > 0 ? (string) get_the_title( $parent ) : '',
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
