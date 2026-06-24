<?php
/**
 * Eligible content types and taxonomies for SEO templates.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Settings;

/**
 * Single source of truth for which post types and taxonomies get SEO templates.
 * Used by Options::sanitize() (whitelist) and Admin\Assets (bootstrap) so the
 * editable set and the validated set never diverge. Criterion: public post
 * types and taxonomies (attachments included so their pages can be redirected
 * or templated from Titles & Meta).
 */
final class ContentTypes {

	/**
	 * Eligible post types as slug/label pairs.
	 *
	 * @return array<int, array{slug:string, label:string}>
	 */
	public function post_types(): array {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$out[] = array(
				'slug'  => (string) $type->name,
				'label' => (string) ( $type->labels->name ?? $type->name ),
			);
		}

		return $out;
	}

	/**
	 * Eligible taxonomies as slug/label pairs.
	 *
	 * @return array<int, array{slug:string, label:string}>
	 */
	public function taxonomies(): array {
		$out = array();

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[] = array(
				'slug'  => (string) $tax->name,
				'label' => (string) ( $tax->labels->name ?? $tax->name ),
			);
		}

		return $out;
	}

	/**
	 * Eligible post type slugs.
	 *
	 * @return array<int, string>
	 */
	public function post_type_slugs(): array {
		return array_column( $this->post_types(), 'slug' );
	}

	/**
	 * Eligible taxonomy slugs.
	 *
	 * @return array<int, string>
	 */
	public function taxonomy_slugs(): array {
		return array_column( $this->taxonomies(), 'slug' );
	}
}
