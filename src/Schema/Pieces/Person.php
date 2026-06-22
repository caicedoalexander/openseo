<?php
/**
 * Person identity node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;

/**
 * The site's Person identity (for single-author / personal sites).
 */
final class Person implements Piece {

	/**
	 * Initializes the Person piece with the settings accessor.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Whether the Person node is needed (i.e. site type is Person).
	 */
	public function is_needed(): bool {
		return 'Person' === (string) $this->options->get( 'schema_site_type' );
	}

	/**
	 * Returns the @id of the Person node.
	 */
	public function id(): string {
		return Ids::person();
	}

	/**
	 * Returns the Person node data array.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$name = (string) $this->options->get( 'schema_site_name' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$url = (string) $this->options->get( 'local_url' );
		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		$data = array(
			'@type' => 'Person',
			'@id'   => $this->id(),
			'name'  => $name,
			'url'   => $url,
		);

		$email = (string) $this->options->get( 'local_email' );
		if ( '' !== $email ) {
			$data['email'] = $email;
		}

		$logo = (string) $this->options->get( 'schema_logo' );
		if ( '' !== $logo ) {
			$data['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		return $data;
	}
}
