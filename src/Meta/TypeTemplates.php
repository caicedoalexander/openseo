<?php
/**
 * Effective title/description template for a post type.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Settings\Options;

/**
 * Resolves the effective singular template for a post type: the stored
 * per-type template, or the singular default when none is set. Single source
 * of "effective per-type template" shared by the frontend Resolver and the
 * editor SERP preview so the two never diverge.
 */
final class TypeTemplates {

	/**
	 * Constructor.
	 *
	 * @param Options          $options  Settings accessor.
	 * @param TemplateDefaults $defaults Per-surface defaults.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly TemplateDefaults $defaults
	) {}

	/**
	 * Effective title template for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	public function title_for( string $post_type ): string {
		$stored = $this->stored( $post_type, 'title' );

		return '' !== $stored ? $stored : $this->defaults->singular_title();
	}

	/**
	 * Effective description template for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	public function description_for( string $post_type ): string {
		$stored = $this->stored( $post_type, 'description' );

		return '' !== $stored ? $stored : $this->defaults->singular_description();
	}

	/**
	 * Stored per-type template field, or '' when absent.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $field     'title' or 'description'.
	 */
	private function stored( string $post_type, string $field ): string {
		$map = $this->options->get( 'post_types' );

		if ( ! is_array( $map ) ) {
			return '';
		}

		return (string) ( $map[ $post_type ][ $field ] ?? '' );
	}
}
