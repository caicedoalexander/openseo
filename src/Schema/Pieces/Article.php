<?php
/**
 * Article schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The Article node for a singular post, with the @type chosen per entry.
 */
final class Article implements Piece {

	private const ARTICLE_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle' );

	/**
	 * Initializes the Article piece with the shared resolver, settings, and type templates.
	 *
	 * @param Resolver      $resolver       Shared SEO value resolver.
	 * @param Options       $options        Settings accessor (identity type for publisher).
	 * @param TypeTemplates $type_templates Per-content-type schema defaults.
	 */
	public function __construct(
		private readonly Resolver $resolver,
		private readonly Options $options,
		private readonly TypeTemplates $type_templates
	) {}

	/**
	 * Whether the Article node is needed for the current request.
	 */
	public function is_needed(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		return in_array( $this->effective_schema_type(), self::ARTICLE_TYPES, true );
	}

	/**
	 * Returns the @id of the Article node for the current URL.
	 */
	public function id(): string {
		return Ids::article( Ids::current_url() );
	}

	/**
	 * Returns the Article node data array.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$id  = get_queried_object_id();
		$url = Ids::current_url();

		$identity = 'Person' === (string) $this->options->get( 'schema_site_type' )
			? Ids::person()
			: Ids::organization();

		$author_id = (int) get_post_field( 'post_author', $id );

		$data = array(
			'@type'            => $this->type(),
			'@id'              => Ids::article( $url ),
			'headline'         => $this->resolver->title(),
			'isPartOf'         => array( '@id' => Ids::webpage( $url ) ),
			'mainEntityOfPage' => array( '@id' => Ids::webpage( $url ) ),
			'datePublished'    => (string) get_the_date( 'c', $id ),
			'dateModified'     => (string) get_the_modified_date( 'c', $id ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => (string) get_the_author_meta( 'display_name', $author_id ),
				'url'   => (string) get_author_posts_url( $author_id ),
			),
			'publisher'        => array( '@id' => $identity ),
		);

		$image = $this->resolver->social_image();
		if ( '' !== $image ) {
			$data['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image,
			);
		}

		return $data;
	}

	/**
	 * Resolve the effective schema type: per-entry override → per-type default →
	 * automatic default. Shared by is_needed() and type() to avoid drift.
	 */
	private function effective_schema_type(): string {
		$id       = get_queried_object_id();
		$override = (string) get_post_meta( $id, '_openseo_schema_type', true );

		if ( '' !== $override ) {
			return $override;
		}

		return $this->type_templates->schema_type_for( (string) get_post_type( $id ) );
	}

	/**
	 * The Article @type to emit (only reached when is_needed() is true).
	 */
	private function type(): string {
		$effective = $this->effective_schema_type();

		return in_array( $effective, self::ARTICLE_TYPES, true ) ? $effective : 'Article';
	}
}
