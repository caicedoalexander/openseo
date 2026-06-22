<?php
/**
 * WebSite schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The site-wide WebSite node: name, url, publisher, and a site SearchAction.
 */
final class WebSite implements Piece {

	/**
	 * Initializes the WebSite piece with the settings accessor.
	 *
	 * @param Options $options Settings accessor (provides the identity type).
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Whether the WebSite node is needed — always true.
	 */
	public function is_needed(): bool {
		return true;
	}

	/**
	 * Returns the @id of the WebSite node.
	 */
	public function id(): string {
		return Ids::website();
	}

	/**
	 * Returns the WebSite node data array.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$identity = 'Person' === (string) $this->options->get( 'schema_site_type' )
			? Ids::person()
			: Ids::organization();

		$name = (string) $this->options->get( 'local_website_name' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$data = array(
			'@type'           => 'WebSite',
			'@id'             => $this->id(),
			'url'             => home_url( '/' ),
			'name'            => $name,
			'description'     => (string) get_bloginfo( 'description' ),
			'publisher'       => array( '@id' => $identity ),
			'inLanguage'      => (string) get_bloginfo( 'language' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);

		$alternate = (string) $this->options->get( 'local_website_alternate_name' );
		if ( '' !== $alternate ) {
			$data['alternateName'] = $alternate;
		}

		return $data;
	}
}
