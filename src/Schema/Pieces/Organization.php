<?php
/**
 * Organization identity node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The site's Organization identity (publisher/author root).
 */
final class Organization implements Piece {

	/**
	 * Initializes the Organization piece with the settings accessor.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Whether the Organization node is needed (i.e. site type is not Person).
	 */
	public function is_needed(): bool {
		return 'Person' !== (string) $this->options->get( 'schema_site_type' );
	}

	/**
	 * Returns the @id of the Organization node.
	 */
	public function id(): string {
		return Ids::organization();
	}

	/**
	 * Returns the Organization node data array.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$name = (string) $this->options->get( 'schema_site_name' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$data = array(
			'@type' => 'Organization',
			'@id'   => $this->id(),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);

		$logo = (string) $this->options->get( 'schema_logo' );
		if ( '' !== $logo ) {
			// The logo is an inline ImageObject carrying its own @id, so the
			// `image` mirror references a node that actually exists in the graph.
			$data['logo']  = array(
				'@type' => 'ImageObject',
				'@id'   => $this->id() . 'Logo',
				'url'   => $logo,
			);
			$data['image'] = array( '@id' => $this->id() . 'Logo' );
		}

		return $data;
	}
}
