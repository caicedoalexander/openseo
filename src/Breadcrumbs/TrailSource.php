<?php
/**
 * Supplies an ordered breadcrumb trail.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Breadcrumbs;

/**
 * Abstraction over the trail builder so consumers (the BreadcrumbList schema
 * piece in particular) can depend on the contract, letting tests substitute a
 * fixed trail without un-finalizing the concrete Trail.
 */
interface TrailSource {

	/**
	 * Ordered crumbs from Home to the current location.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	public function items(): array;
}
