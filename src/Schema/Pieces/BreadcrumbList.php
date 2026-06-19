<?php
/**
 * BreadcrumbList schema node.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema\Pieces;

use OpenSEO\Breadcrumbs\TrailSource;
use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;

/**
 * The BreadcrumbList node, built from the shared breadcrumb trail.
 */
final class BreadcrumbList implements Piece {

	/**
	 * Constructor.
	 *
	 * @param TrailSource $trail Shared breadcrumb trail source.
	 */
	public function __construct( private readonly TrailSource $trail ) {}

	/**
	 * Whether this piece applies to the current request.
	 */
	public function is_needed(): bool {
		return count( $this->trail->items() ) >= 2;
	}

	/**
	 * This piece's @id.
	 */
	public function id(): string {
		return Ids::breadcrumb( Ids::current_url() );
	}

	/**
	 * The BreadcrumbList node data.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$elements = array();
		$position = 1;

		foreach ( $this->trail->items() as $item ) {
			$element = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $item['name'],
			);

			if ( '' !== $item['url'] ) {
				$element['item'] = $item['url'];
			}

			$elements[] = $element;
			++$position;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $this->id(),
			'itemListElement' => $elements,
		);
	}
}
