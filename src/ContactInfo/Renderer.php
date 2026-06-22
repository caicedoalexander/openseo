<?php
/**
 * Renders the contact-info card as escaped HTML.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\ContactInfo;

use OpenSEO\Schema\LocalChoices;
use OpenSEO\Settings\Options;

/**
 * Turns the stored Local SEO data into an accessible, fully-escaped contact
 * card. Every value is escaped here, so callers can echo/return the result
 * directly. Mirrors the Breadcrumbs\Renderer pattern.
 */
final class Renderer {

	private const SECTIONS = array( 'name', 'description', 'email', 'phone', 'address', 'hours', 'map' );

	/**
	 * Constructor.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Render the contact card.
	 *
	 * @param array<int, string> $sections    Requested sections (empty = all).
	 * @param string             $extra_class Additional root class.
	 * @return string Fully-escaped HTML, or '' when nothing is configured.
	 */
	public function render( array $sections = array(), string $extra_class = '' ): string {
		$wanted = array() === $sections
			? self::SECTIONS
			: array_values( array_intersect( self::SECTIONS, $sections ) );

		$parts = array();
		foreach ( $wanted as $section ) {
			$html = $this->section( $section );
			if ( '' !== $html ) {
				$parts[] = $html;
			}
		}

		if ( array() === $parts ) {
			return '';
		}

		$class = 'openseo-contact-info';
		if ( '' !== $extra_class ) {
			$class .= ' ' . $extra_class;
		}

		return '<div class="' . esc_attr( $class ) . '">' . implode( '', $parts ) . '</div>';
	}

	/**
	 * Render one section by key.
	 *
	 * @param string $section Section key.
	 */
	private function section( string $section ): string {
		return match ( $section ) {
			'name'        => $this->name(),
			'description' => $this->description(),
			'email'       => $this->email(),
			'phone'       => $this->phone(),
			'address'     => $this->address(),
			'hours'       => $this->hours(),
			'map'         => $this->map(),
			default       => '',
		};
	}

	/**
	 * Render the business name section.
	 *
	 * @return string Escaped HTML or ''.
	 */
	private function name(): string {
		$name = (string) $this->options->get( 'schema_site_name' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}
		if ( '' === $name ) {
			return '';
		}

		$url = (string) $this->options->get( 'local_url' );
		if ( '' === $url ) {
			$url = (string) home_url( '/' );
		}

		$inner = '' !== $url
			? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>'
			: esc_html( $name );

		return '<div class="openseo-contact-info__name">' . $inner . '</div>';
	}

	/**
	 * Render the description section.
	 *
	 * @return string Escaped HTML or ''.
	 */
	private function description(): string {
		$desc = (string) $this->options->get( 'local_description' );
		return '' === $desc
			? ''
			: '<div class="openseo-contact-info__description">' . esc_html( $desc ) . '</div>';
	}

	/**
	 * Render the email section.
	 *
	 * @return string Escaped HTML or ''.
	 */
	private function email(): string {
		$email = (string) $this->options->get( 'local_email' );
		if ( '' === $email ) {
			return '';
		}
		return '<div class="openseo-contact-info__email"><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a></div>';
	}

	/**
	 * Render the phone section (primary + additional numbers).
	 *
	 * @return string Escaped HTML or ''.
	 */
	private function phone(): string {
		$primary = (string) $this->options->get( 'local_phone' );
		$rows    = $this->options->get( 'local_phone_numbers' );
		$rows    = is_array( $rows ) ? $rows : array();

		$head = '' !== $primary ? $this->tel_link( $primary ) : '';

		$types = $this->labels( LocalChoices::phone_types() );
		$list  = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$number = (string) ( $row['number'] ?? '' );
			if ( '' === $number ) {
				continue;
			}
			$type  = (string) ( $row['type'] ?? '' );
			$label = '' !== $type && isset( $types[ $type ] )
				? '<span class="openseo-contact-info__phone-type">' . esc_html( $types[ $type ] ) . '</span> '
				: '';
			$list .= '<li>' . $label . $this->tel_link( $number ) . '</li>';
		}
		if ( '' !== $list ) {
			$list = '<ul class="openseo-contact-info__phones">' . $list . '</ul>';
		}

		if ( '' === $head && '' === $list ) {
			return '';
		}

		return '<div class="openseo-contact-info__phone">' . $head . $list . '</div>';
	}

	/**
	 * Build a tel: link with a cleaned URI and the original visible text.
	 *
	 * @param string $phone Phone number.
	 */
	private function tel_link( string $phone ): string {
		$clean = (string) preg_replace( '/[^0-9+]/', '', $phone );
		return '<a href="' . esc_url( 'tel:' . $clean ) . '">' . esc_html( $phone ) . '</a>';
	}

	/**
	 * Render the address section.
	 *
	 * @return string Escaped HTML or ''.
	 */
	private function address(): string {
		$parts = $this->address_parts();
		if ( array() === $parts ) {
			return '';
		}
		$escaped = array_map( static fn( string $p ): string => esc_html( $p ), $parts );
		return '<address class="openseo-contact-info__address">' . implode( ', ', $escaped ) . '</address>';
	}

	/**
	 * Non-empty address parts in canonical order.
	 *
	 * @return array<int, string>
	 */
	private function address_parts(): array {
		$address = $this->options->get( 'local_address' );
		$address = is_array( $address ) ? $address : array();
		$parts   = array();
		foreach ( array( 'street', 'locality', 'region', 'postal_code', 'country' ) as $key ) {
			$value = (string) ( $address[ $key ] ?? '' );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}
		return $parts;
	}

	/**
	 * Render the opening hours section.
	 *
	 * @return string Escaped HTML or ''.
	 */
	private function hours(): string {
		$rows = $this->options->get( 'local_opening_hours' );
		$rows = is_array( $rows ) ? $rows : array();
		$days = $this->labels( LocalChoices::days() );

		$list = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$day    = (string) ( $row['day'] ?? '' );
			$opens  = (string) ( $row['opens'] ?? '' );
			$closes = (string) ( $row['closes'] ?? '' );
			$label  = $days[ $day ] ?? $day;
			// Escape each value separately; the en-dash separator is a controlled literal.
			$list .= '<li>' . esc_html( $label ) . ': ' . esc_html( $opens ) . '&#8211;' . esc_html( $closes ) . '</li>';
		}

		return '' === $list
			? ''
			: '<div class="openseo-contact-info__hours"><ul>' . $list . '</ul></div>';
	}

	/**
	 * Render the map link section.
	 *
	 * @return string Escaped HTML or ''.
	 */
	private function map(): string {
		$geo   = (string) $this->options->get( 'local_geo' );
		$query = '' !== $geo ? $geo : implode( ', ', $this->address_parts() );
		if ( '' === $query ) {
			return '';
		}
		$url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
		return '<div class="openseo-contact-info__map"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on map', 'openseo' ) . '</a></div>';
	}

	/**
	 * Index a LocalChoices choice list to a value => label map.
	 *
	 * @param array<int, array{value:string,label:string}> $choices Choices.
	 * @return array<string, string>
	 */
	private function labels( array $choices ): array {
		$map = array();
		foreach ( $choices as $choice ) {
			$map[ $choice['value'] ] = $choice['label'];
		}
		return $map;
	}
}
