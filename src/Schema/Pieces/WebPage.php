<?php
/**
 * WebPage schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Meta\Resolver;
use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;

/**
 * The WebPage node for a singular entry or the front page.
 */
final class WebPage implements Piece {

	/**
	 * Initializes the WebPage piece with the shared SEO resolver.
	 *
	 * @param Resolver $resolver Shared SEO value resolver.
	 */
	public function __construct( private readonly Resolver $resolver ) {}

	/**
	 * Whether the WebPage node is needed (singular entry or front page).
	 */
	public function is_needed(): bool {
		return is_singular() || is_front_page();
	}

	/**
	 * Returns the @id of the WebPage node for the current URL.
	 */
	public function id(): string {
		return Ids::webpage( Ids::current_url() );
	}

	/**
	 * Returns the WebPage node data array.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$url = Ids::current_url();

		$data = array(
			'@type'      => 'WebPage',
			'@id'        => Ids::webpage( $url ),
			'url'        => $url,
			'name'       => $this->resolver->title(),
			'isPartOf'   => array( '@id' => Ids::website() ),
			'inLanguage' => (string) get_bloginfo( 'language' ),
		);

		// Reference the breadcrumb only on singular entries, where the trail
		// always has at least Home + self, so the @id resolves to a real node
		// (the front page emits no BreadcrumbList).
		if ( is_singular() ) {
			$data['breadcrumb'] = array( '@id' => Ids::breadcrumb( $url ) );

			$id                    = get_queried_object_id();
			$data['datePublished'] = (string) get_the_date( 'c', $id );
			$data['dateModified']  = (string) get_the_modified_date( 'c', $id );

			$image = $this->resolver->social_image();
			if ( '' !== $image ) {
				$data['primaryImageOfPage'] = array(
					'@type' => 'ImageObject',
					'url'   => $image,
				);
			}
		}

		return $data;
	}
}
