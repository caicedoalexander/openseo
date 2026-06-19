<?php
/**
 * One node of the JSON-LD @graph.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

/**
 * A self-contained schema.org node. Pieces are independent and composable: the
 * Graph asks each one whether it applies, then collects its data.
 */
interface Piece {

	/**
	 * Whether this node applies to the current request.
	 */
	public function is_needed(): bool;

	/**
	 * This node's @id, so other pieces can reference it.
	 */
	public function id(): string;

	/**
	 * The node as an associative array (with @type, @id, and refs by @id).
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array;
}
