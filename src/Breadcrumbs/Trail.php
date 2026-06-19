<?php
/**
 * Builds the breadcrumb hierarchy for the current request.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Breadcrumbs;

/**
 * Single source of truth for the breadcrumb trail. Returns data only — the
 * theme function, the block, and the BreadcrumbList schema piece all consume it.
 */
final class Trail implements TrailSource {

	/**
	 * Ordered crumbs from Home to the current location.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	public function items(): array {
		if ( is_front_page() ) {
			return array();
		}

		$items = array(
			array(
				'name' => __( 'Home', 'openseo' ),
				'url'  => home_url( '/' ),
			),
		);

		if ( is_singular() ) {
			return array_merge( $items, $this->singular_items() );
		}

		if ( is_category() || is_tag() || is_tax() || is_author() ) {
			$object = get_queried_object();
			$name   = '';
			if ( is_object( $object ) ) {
				// WP_Term carries `name`; WP_User (author archives) carries `display_name`.
				if ( isset( $object->display_name ) ) {
					$name = (string) $object->display_name;
				} elseif ( isset( $object->name ) ) {
					$name = (string) $object->name;
				}
			}
			$items[] = array(
				'name' => $name,
				'url'  => '',
			);
			return $items;
		}

		if ( is_search() ) {
			$items[] = array(
				'name' => __( 'Search results', 'openseo' ),
				'url'  => '',
			);

			return $items;
		}

		if ( is_404() ) {
			$items[] = array(
				'name' => __( 'Not found', 'openseo' ),
				'url'  => '',
			);
			return $items;
		}

		return $items;
	}

	/**
	 * Crumbs for a singular entry: ancestors (pages) or primary category (posts),
	 * then the entry itself.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function singular_items(): array {
		$id    = get_queried_object_id();
		$items = array();

		if ( 'page' === get_post_type( $id ) ) {
			foreach ( array_reverse( get_post_ancestors( $id ) ) as $ancestor ) {
				$items[] = array(
					'name' => (string) get_the_title( $ancestor ),
					'url'  => (string) get_permalink( $ancestor ),
				);
			}
		} else {
			$categories = get_the_category( $id );
			if ( ! empty( $categories ) ) {
				$primary = $categories[0];
				$items[] = array(
					'name' => (string) $primary->name,
					'url'  => (string) get_category_link( $primary->term_id ),
				);
			}
		}

		$items[] = array(
			'name' => (string) get_the_title( $id ),
			'url'  => (string) get_permalink( $id ),
		);

		return $items;
	}
}
