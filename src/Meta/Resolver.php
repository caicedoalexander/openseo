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
use OpenSEO\Meta\RobotsResolver;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Settings\Options;
use OpenSEO\Support\Str;
use WP_Term;

/**
 * SEO resolution cascade: per-entry override → content-type template → fallback.
 *
 * Returns '' whenever OpenSEO has no opinion, so callers can leave WordPress
 * defaults untouched instead of emitting empty tags.
 */
final class Resolver {

	private const DIRECTIVES = array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' );

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
	 * Effective document title (empty = let WordPress decide), with optional
	 * global capitalization applied.
	 */
	public function title(): string {
		return $this->capitalize( $this->resolve_title() );
	}

	/**
	 * Apply the global "capitalize titles" setting when enabled. Empty stays empty.
	 *
	 * @param string $title Resolved title.
	 */
	private function capitalize( string $title ): string {
		if ( '' === $title || '1' !== (string) $this->options->get( 'capitalize_titles' ) ) {
			return $title;
		}

		return Str::mb_ucwords( $title );
	}

	/**
	 * Resolve the raw effective title before capitalization.
	 */
	private function resolve_title(): string {
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
			return $this->variables->replace( (string) $this->options->get( 'home_title' ), TemplateContext::for_archive() );
		}

		if ( is_author() ) {
			$template = (string) $this->options->get( 'author_title' );
			if ( '' === $template ) {
				$template = $this->defaults->author_title();
			}

			return $this->variables->replace( $template, TemplateContext::for_author( get_queried_object_id() ) );
		}

		if ( is_search() ) {
			$template = (string) $this->options->get( 'search_title' );
			if ( '' === $template ) {
				$template = $this->defaults->search_title();
			}

			return $this->variables->replace( $template, TemplateContext::for_search() );
		}

		if ( is_404() ) {
			$template = (string) $this->options->get( 'title_404' );
			if ( '' === $template ) {
				$template = $this->defaults->not_found_title();
			}

			return $this->variables->replace( $template );
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

		if ( is_author() ) {
			return (string) $this->options->get( 'author_description' );
		}

		return '';
	}

	/**
	 * Effective robots directive, e.g. "index, follow".
	 */
	public function robots(): string {
		$effective            = $this->effective_robots();
		$effective['noindex'] = $effective['noindex'] || $this->force_noindex();

		$parts = $this->robots_parts( $effective );

		if ( ! $effective['noindex'] && ! $effective['nosnippet'] ) {
			$parts = array_merge( $parts, $this->advanced_robots_parts() );
		}

		return implode( ', ', $parts );
	}

	/**
	 * The five effective robots booleans for the current surface, before the
	 * cross-surface noindex overlay. Custom home/author maps are absolute;
	 * every other surface uses the entry → type → global cascade.
	 *
	 * @return array<string, bool>
	 */
	private function effective_robots(): array {
		$global_map = $this->options->get( 'robots' );
		$global_map = is_array( $global_map ) ? $global_map : array();
		$global     = static fn( string $d ): bool => '1' === (string) ( $global_map[ $d ] ?? '' );

		$custom = $this->custom_surface_map();
		if ( null !== $custom ) {
			$effective = array();
			foreach ( self::DIRECTIVES as $d ) {
				$effective[ $d ] = '1' === (string) ( $custom[ $d ] ?? '' );
			}

			return $effective;
		}

		$type_robots         = array();
		$entry               = array();
		$force_noindex_empty = false;

		if ( is_singular() ) {
			$id          = get_queried_object_id();
			$type        = (string) get_post_type( $id );
			$map         = $this->options->get( 'post_types' );
			$type_robots = is_array( $map ) && is_array( $map[ $type ]['robots'] ?? null ) ? $map[ $type ]['robots'] : array();
			$entry       = array(
				'noindex'  => (string) get_post_meta( $id, '_openseo_robots_noindex', true ),
				'nofollow' => (string) get_post_meta( $id, '_openseo_robots_nofollow', true ),
			);
		} elseif ( $this->is_taxonomy() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$map         = $this->options->get( 'taxonomies' );
				$type_robots = is_array( $map ) && is_array( $map[ $term->taxonomy ]['robots'] ?? null ) ? $map[ $term->taxonomy ]['robots'] : array();
				if ( $global( 'noindex_empty_terms' ) && 0 === (int) $term->count ) {
					$force_noindex_empty = true;
				}
			}
		}

		$effective = array();
		foreach ( self::DIRECTIVES as $d ) {
			$entry_val       = ( 'noindex' === $d || 'nofollow' === $d ) ? (string) ( $entry[ $d ] ?? '' ) : '';
			$type_val        = (string) ( $type_robots[ $d ] ?? '' );
			$effective[ $d ] = RobotsResolver::resolve( $entry_val, $type_val, $global( $d ) );
		}

		if ( $force_noindex_empty ) {
			$effective['noindex'] = true;
		}

		return $effective;
	}

	/**
	 * Absolute robots map for surfaces that define their own (posts homepage,
	 * author archives) when their "custom robots" toggle is on; null otherwise.
	 *
	 * @return array<string, string>|null
	 */
	private function custom_surface_map(): ?array {
		if ( is_front_page() && ! is_singular() && '1' === (string) $this->options->get( 'home_robots_custom' ) ) {
			$map = $this->options->get( 'home_robots' );

			return is_array( $map ) ? $map : array();
		}

		if ( is_author() && '1' === (string) $this->options->get( 'author_robots_custom' ) ) {
			$map = $this->options->get( 'author_robots' );

			return is_array( $map ) ? $map : array();
		}

		return null;
	}

	/**
	 * Cross-surface reasons to force noindex regardless of the resolved
	 * cascade: search results, paginated listings/singulars, and
	 * password-protected posts.
	 */
	private function force_noindex(): bool {
		if ( is_search() && '1' === (string) $this->options->get( 'noindex_search' ) ) {
			return true;
		}

		if ( '1' === (string) $this->options->get( 'noindex_paginated' ) && is_paged() ) {
			return true;
		}

		if ( '1' === (string) $this->options->get( 'noindex_paginated_singular' ) && is_singular() && (int) get_query_var( 'page' ) > 1 ) {
			return true;
		}

		if ( '1' === (string) $this->options->get( 'noindex_password_protected' ) && is_singular() && post_password_required() ) {
			return true;
		}

		return false;
	}

	/**
	 * Effective directive list (index/follow + any extra booleans), without advanced robots.
	 *
	 * @param array<string, bool> $e Effective directives.
	 * @return array<int, string>
	 */
	private function robots_parts( array $e ): array {
		$parts = array(
			$e['noindex'] ? 'noindex' : 'index',
			$e['nofollow'] ? 'nofollow' : 'follow',
		);
		if ( $e['noarchive'] ) {
			$parts[] = 'noarchive';
		}
		if ( $e['nosnippet'] ) {
			$parts[] = 'nosnippet';
		}
		if ( $e['noimageindex'] ) {
			$parts[] = 'noimageindex';
		}

		return $parts;
	}

	/**
	 * Advanced robots directives from the global setting, e.g. "max-snippet:-1".
	 * The caller skips these when the page is noindex or nosnippet.
	 *
	 * @return array<int, string>
	 */
	private function advanced_robots_parts(): array {
		$adv = $this->options->get( 'advanced_robots' );
		$adv = is_array( $adv ) ? $adv : array();

		$blocks = array(
			'max-snippet'       => array( 'max_snippet', 'length', '-1' ),
			'max-video-preview' => array( 'max_video_preview', 'length', '-1' ),
			'max-image-preview' => array( 'max_image_preview', 'value', 'large' ),
		);

		$parts = array();
		foreach ( $blocks as $directive => $meta ) {
			[ $key, $field, $default ] = $meta;
			$block                     = is_array( $adv[ $key ] ?? null ) ? $adv[ $key ] : array();
			if ( '1' === (string) ( $block['enabled'] ?? '' ) ) {
				$parts[] = $directive . ':' . (string) ( $block[ $field ] ?? $default );
			}
		}

		return $parts;
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
	 * Whether the current request is the posts homepage (latest-posts front
	 * page, not a static front page — which resolves as a singular instead).
	 */
	private function is_posts_homepage(): bool {
		return is_front_page() && ! is_singular();
	}

	/**
	 * Open Graph title: homepage og -> home og title; singular: og override -> resolved title.
	 */
	public function social_title(): string {
		if ( $this->is_posts_homepage() ) {
			$home = (string) $this->options->get( 'home_og_title' );

			return '' !== $home ? $home : $this->title();
		}

		return $this->social_value( '_openseo_og_title', $this->title() );
	}

	/**
	 * Open Graph description: homepage og -> home og description; singular: og override -> resolved description.
	 */
	public function social_description(): string {
		if ( $this->is_posts_homepage() ) {
			$home = (string) $this->options->get( 'home_og_description' );

			return '' !== $home ? $home : $this->description();
		}

		return $this->social_value( '_openseo_og_description', $this->description() );
	}

	/**
	 * Social image: homepage og -> home og image; singular: og override -> featured image -> per-type default -> global default.
	 */
	public function social_image(): string {
		if ( $this->is_posts_homepage() ) {
			$home = (string) $this->options->get( 'home_og_image' );

			return '' !== $home ? $home : (string) $this->options->get( 'og_default_image' );
		}

		$override = $this->meta_value( '_openseo_og_image' );
		if ( '' !== $override ) {
			return $override;
		}

		if ( is_singular() ) {
			$id       = get_queried_object_id();
			$featured = (string) get_the_post_thumbnail_url( $id, 'full' );
			if ( '' !== $featured ) {
				return $featured;
			}

			$type_image = $this->type_templates->og_image_for( (string) get_post_type( $id ) );
			if ( '' !== $type_image ) {
				return $type_image;
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
	 * Effective Twitter card type from the global setting (revalidated).
	 */
	public function twitter_card(): string {
		$type = (string) $this->options->get( 'twitter_card_type' );

		return in_array( $type, array( 'summary', 'summary_large_image' ), true )
			? $type
			: 'summary_large_image';
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
