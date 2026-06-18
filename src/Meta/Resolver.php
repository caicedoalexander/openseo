<?php
/**
 * Resolves effective SEO values for the current request.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Settings\Options;

/**
 * SEO resolution cascade: per-entry override → content-type template → fallback.
 *
 * Returns '' whenever OpenSEO has no opinion, so callers can leave WordPress
 * defaults untouched instead of emitting empty tags.
 */
final class Resolver {

	/**
	 * Initializes the Resolver with settings and template variable replacer.
	 *
	 * @param Options   $options   Settings accessor.
	 * @param Variables $variables Template variable replacer.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly Variables $variables
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

			return $this->variables->replace( (string) $this->options->get( 'title_template' ), $id );
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

			return $this->variables->replace( (string) $this->options->get( 'description_template' ), $id );
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
