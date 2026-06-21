<?php
/**
 * Resolves effective SEO values for the current request.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Meta\TemplateContext;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Settings\Options;
use WP_Term;

/**
 * SEO resolution cascade: per-entry override → content-type template → fallback.
 *
 * Returns '' whenever OpenSEO has no opinion, so callers can leave WordPress
 * defaults untouched instead of emitting empty tags.
 */
final class Resolver {

	/**
	 * Initializes the Resolver with settings, variables, defaults, and per-type templates.
	 *
	 * @param Options          $options        Settings accessor.
	 * @param Variables        $variables      Template variable replacer.
	 * @param TemplateDefaults $defaults       Default templates for each content surface.
	 * @param TypeTemplates    $type_templates Effective per-type singular templates.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly Variables $variables,
		private readonly TemplateDefaults $defaults,
		private readonly TypeTemplates $type_templates
	) {}

	/**
	 * Effective document title (empty = let WordPress decide).
	 */
	public function title(): string {
		if ( is_singular() ) {
			$id = get_queried_object_id();

			$override = (string) get_post_meta( $id, '_openseo_title', true );
			if ( '' !== $override ) {
				return $override;
			}

			$template = $this->type_templates->title_for( (string) get_post_type( $id ) );

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
		}

		if ( $this->is_taxonomy() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				$template = $this->type_template( 'taxonomies', $term->taxonomy, 'title' );
				if ( '' === $template ) {
					$template = $this->defaults->taxonomy_title();
				}

				return $this->variables->replace( $template, TemplateContext::for_term( $term ) );
			}
		}

		if ( is_front_page() ) {
			return $this->variables->replace( (string) $this->options->get( 'home_title' ) );
		}

		return '';
	}

	/**
	 * Effective meta description (empty = print nothing).
	 */
	public function description(): string {
		if ( is_singular() ) {
			$id = get_queried_object_id();

			$override = (string) get_post_meta( $id, '_openseo_description', true );
			if ( '' !== $override ) {
				return $override;
			}

			$template = $this->type_templates->description_for( (string) get_post_type( $id ) );

			return $this->variables->replace( $template, TemplateContext::for_post( $id ) );
		}

		if ( $this->is_taxonomy() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				$template = $this->type_template( 'taxonomies', $term->taxonomy, 'description' );
				if ( '' === $template ) {
					$template = $this->defaults->taxonomy_description();
				}

				return $this->variables->replace( $template, TemplateContext::for_term( $term ) );
			}
		}

		if ( is_front_page() ) {
			$home = (string) $this->options->get( 'home_description' );

			return '' !== $home ? $home : (string) get_bloginfo( 'description' );
		}

		return '';
	}

	/**
	 * Effective robots directive, e.g. "index, follow".
	 */
	public function robots(): string {
		$noindex  = false;
		$nofollow = false;

		if ( is_singular() ) {
			$id       = get_queried_object_id();
			$noindex  = '1' === (string) get_post_meta( $id, '_openseo_robots_noindex', true );
			$nofollow = '1' === (string) get_post_meta( $id, '_openseo_robots_nofollow', true );
		}

		return sprintf(
			'%s, %s',
			$noindex ? 'noindex' : 'index',
			$nofollow ? 'nofollow' : 'follow'
		);
	}

	/**
	 * Effective canonical URL (empty = let WordPress decide).
	 */
	public function canonical(): string {
		if ( ! is_singular() ) {
			return '';
		}

		$id       = get_queried_object_id();
		$override = (string) get_post_meta( $id, '_openseo_canonical', true );

		return '' !== $override ? $override : (string) get_permalink( $id );
	}

	/**
	 * Open Graph title: og override -> resolved title.
	 */
	public function social_title(): string {
		return $this->social_value( '_openseo_og_title', $this->title() );
	}

	/**
	 * Open Graph description: og override -> resolved description.
	 */
	public function social_description(): string {
		return $this->social_value( '_openseo_og_description', $this->description() );
	}

	/**
	 * Social image: og override -> featured image -> global default.
	 */
	public function social_image(): string {
		$override = $this->meta_value( '_openseo_og_image' );
		if ( '' !== $override ) {
			return $override;
		}

		if ( is_singular() ) {
			$featured = (string) get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
			if ( '' !== $featured ) {
				return $featured;
			}
		}

		return (string) $this->options->get( 'og_default_image' );
	}

	/**
	 * Twitter title: twitter override -> social title.
	 */
	public function twitter_title(): string {
		return $this->social_value( '_openseo_twitter_title', $this->social_title() );
	}

	/**
	 * Twitter description: twitter override -> social description.
	 */
	public function twitter_description(): string {
		return $this->social_value( '_openseo_twitter_description', $this->social_description() );
	}

	/**
	 * Twitter image: twitter override -> social image.
	 */
	public function twitter_image(): string {
		return $this->social_value( '_openseo_twitter_image', $this->social_image() );
	}

	/**
	 * Whether the current request is a public taxonomy archive.
	 */
	private function is_taxonomy(): bool {
		return is_category() || is_tag() || is_tax();
	}

	/**
	 * Stored template for an entity, or '' when none is configured.
	 *
	 * @param string $group 'post_types' or 'taxonomies'.
	 * @param string $slug  Post type or taxonomy slug.
	 * @param string $field 'title' or 'description'.
	 */
	private function type_template( string $group, string $slug, string $field ): string {
		$map = $this->options->get( $group );

		if ( ! is_array( $map ) ) {
			return '';
		}

		return (string) ( $map[ $slug ][ $field ] ?? '' );
	}

	/**
	 * A per-entry override value, or '' when absent / not singular.
	 *
	 * @param string $key Meta key to read.
	 */
	private function meta_value( string $key ): string {
		if ( ! is_singular() ) {
			return '';
		}

		return (string) get_post_meta( get_queried_object_id(), $key, true );
	}

	/**
	 * Return the per-entry override for $key, else the supplied fallback.
	 *
	 * @param string $key      Meta key to check for an override.
	 * @param string $fallback Value to use when no override is present.
	 */
	private function social_value( string $key, string $fallback ): string {
		$override = $this->meta_value( $key );

		return '' !== $override ? $override : $fallback;
	}
}
