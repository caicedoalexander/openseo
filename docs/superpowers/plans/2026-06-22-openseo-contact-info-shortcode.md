# SEO Local 2b-ii (shortcode de contacto) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar la información de contacto/negocio (nombre, descripción, email, teléfonos, dirección, horarios, enlace de mapa) en cualquier página vía el shortcode `[openseo_contact_info]`, con HTML semántico, escapado y filtrable por `show`.

**Architecture:** Un `ContactInfo\Renderer` puro (lee `Options`, usa `Schema\LocalChoices` para etiquetas i18n) devuelve HTML totalmente escapado — espejo del patrón `Breadcrumbs\Renderer`. Un `ContactInfo\Shortcode` (`Hookable`) registra `[openseo_contact_info]`, parsea `show`/`class` y **devuelve** (no imprime) el HTML del Renderer. Se registra en `Plugin::modules()` fuera de `is_admin()`. Estrictamente aditivo.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, WordPress 7.0 (Shortcode API), wp-env.

## Global Constraints

- WP 7.0+, PHP 8.1+. `declare(strict_types=1);` en PHP. Text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS). PSR-4 file naming. Namespace nuevo `OpenSEO\ContactInfo`.
- Secciones (orden canónico): `name`, `description`, `email`, `phone`, `address`, `hours`, `map`. Cada una se omite si su dato está vacío. Sin datos → `render` devuelve `''`.
- Fuentes (`Options`): `name` = `schema_site_name` (fallback `get_bloginfo('name')`) — **espeja la identidad Organization/Person, NO `local_website_name`** (D-name). `url` = `local_url` (fallback `home_url('/')`). `description`=`local_description`; `email`=`local_email`; phone principal=`local_phone`; adicionales=`local_phone_numbers` (`[{type,number}]`); `address`=`local_address` (grupo street/locality/region/postal_code/country); `hours`=`local_opening_hours` (`[{day,opens,closes}]`); `geo`=`local_geo` (`"lat,lng"`).
- Etiquetas i18n vía `Schema\LocalChoices::phone_types()` (tipo de teléfono) y `days()` (día), indexadas a un mapa value→label.
- Escapado: `esc_html` para texto; `esc_url` para `mailto:`/`tel:`/mapa; `esc_attr` para `class`. **El esquema `mailto:`/`tel:` se concatena en PHP antes de `esc_url`** (`esc_url('mailto:'.$email)`), nunca del dato. El `tel:` se limpia con `preg_replace('/[^0-9+]/','',$phone)` (texto visible conserva el original). En `hours`, `esc_html` se aplica a día/opens/closes **por separado** y el en-dash (`&#8211;`) es un **literal** entre valores ya escapados (NO `esc_html` global de la fila).
- Map = `https://www.google.com/maps/search/?api=1&query=<rawurlencode(geo|dirección)>`, `target="_blank" rel="noopener"`; sin iframe/API key. Usa geo si está, si no la dirección; se omite si no hay ninguno.
- `show=""` (o sin atributo) = todas; `show="a,b"` filtra a esas (intersección con las conocidas, orden canónico); `class="…"` añade clase a la raíz `openseo-contact-info`.
- Shortcode **devuelve** string (no `echo`). Registrado en `Plugin::modules()` **fuera de `is_admin()`** (frontend). El caller del Renderer hace `echo`/return con `phpcs:ignore WordPress.Security.EscapeOutput` (Renderer devuelve HTML ya escapado).
- Sin CSS de frontend encolado, sin filtro de extensibilidad, sin alias Yoast, sin combinar días, sin info-adicional (todos fuera de alcance, ver spec No-objetivos).
- PHPStan nivel 6 sin baseline (lee `Options::get()` mixed con guards `is_array`/casts `(string)`). Seguridad: solo lectura/display; sin SQL, sin nonce, sin estado.
- Gates por commit: `composer lint`, `composer analyze` (`--memory-limit=1G`), `composer test:unit`. Integración wp-env + `.pot` en la verificación final. Conventional commits, SIN atribución.

---

## File Structure

**PHP (nuevos):** `src/ContactInfo/Renderer.php`, `src/ContactInfo/Shortcode.php`.
**PHP (modificados):** `src/Plugin.php` (registrar el módulo Shortcode).
**Tests (nuevos):** `tests/Unit/ContactInfo/RendererTest.php`, `tests/Unit/ContactInfo/ShortcodeTest.php`.
**Docs:** `languages/openseo.pot` (cadena nueva "View on map").

---

## Task 1: `ContactInfo\Renderer`

**Files:**
- Create: `src/ContactInfo/Renderer.php`
- Test: `tests/Unit/ContactInfo/RendererTest.php`

**Interfaces:**
- Consumes: `Options` (las claves de contacto), `Schema\LocalChoices::days()/phone_types()`.
- Produces: `Renderer::render( array $sections = array(), string $extra_class = '' ): string` — HTML escapado de la tarjeta; `''` cuando ninguna sección produce contenido.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\ContactInfo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\ContactInfo\Renderer;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( array $stored, array $sections = array(), string $class = '' ): string {
		Functions\when( 'get_option' )->justReturn( $stored );
		return ( new Renderer( new Options() ) )->render( $sections, $class );
	}

	public function test_empty_options_render_nothing(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		$this->assertSame( '', $this->render( array() ) );
	}

	public function test_name_links_to_url(): void {
		$html = $this->render(
			array( 'schema_site_name' => 'Acme', 'local_url' => 'https://acme.test' ),
			array( 'name' )
		);
		$this->assertStringContainsString( '<div class="openseo-contact-info">', $html );
		$this->assertStringContainsString( '<a href="https://acme.test">Acme</a>', $html );
	}

	public function test_email_is_a_mailto_link(): void {
		$html = $this->render( array( 'local_email' => 'hi@acme.test' ), array( 'email' ) );
		$this->assertStringContainsString( 'href="mailto:hi@acme.test"', $html );
	}

	public function test_phone_primary_and_additional_with_type_label(): void {
		$html = $this->render(
			array(
				'local_phone'         => '+1 (555) 010-0',
				'local_phone_numbers' => array( array( 'type' => 'sales', 'number' => '+1-555-0199' ) ),
			),
			array( 'phone' )
		);
		$this->assertStringContainsString( 'href="tel:+15550100"', $html ); // cleaned
		$this->assertStringContainsString( '+1 (555) 010-0', $html );       // visible original
		$this->assertStringContainsString( 'Sales', $html );                 // type label
		$this->assertStringContainsString( 'href="tel:+15550199"', $html );
	}

	public function test_address_joins_non_empty_parts(): void {
		$html = $this->render(
			array( 'local_address' => array( 'street' => 'Main St', 'locality' => 'NYC', 'region' => '', 'postal_code' => '10001', 'country' => 'US' ) ),
			array( 'address' )
		);
		$this->assertStringContainsString( '<address class="openseo-contact-info__address">Main St, NYC, 10001, US</address>', $html );
	}

	public function test_hours_use_day_label_and_literal_endash(): void {
		$html = $this->render(
			array( 'local_opening_hours' => array( array( 'day' => 'Monday', 'opens' => '09:00', 'closes' => '17:00' ) ) ),
			array( 'hours' )
		);
		$this->assertStringContainsString( '<li>Monday: 09:00&#8211;17:00</li>', $html );
	}

	public function test_map_from_geo_then_address(): void {
		// esc_url is mocked as returnArg here, so the literal `&` survives. In
		// real WP, esc_url encodes `&` to `&#038;` in the attribute (the browser
		// decodes it back) — that is correct output, not a bug.
		$geo = $this->render( array( 'local_geo' => '40.7128,-74.006' ), array( 'map' ) );
		$this->assertStringContainsString( 'maps/search/?api=1&query=40.7128%2C-74.006', $geo );
		$this->assertStringContainsString( 'rel="noopener"', $geo );

		$addr = $this->render(
			array( 'local_address' => array( 'street' => 'Main St', 'locality' => 'NYC', 'region' => '', 'postal_code' => '', 'country' => '' ) ),
			array( 'map' )
		);
		$this->assertStringContainsString( 'query=Main%20St%2C%20NYC', $addr );
	}

	public function test_show_filters_sections(): void {
		$html = $this->render(
			array( 'schema_site_name' => 'Acme', 'local_email' => 'hi@acme.test', 'local_description' => 'About' ),
			array( 'name', 'email' )
		);
		$this->assertStringContainsString( 'Acme', $html );
		$this->assertStringContainsString( 'mailto:hi@acme.test', $html );
		$this->assertStringNotContainsString( 'About', $html ); // description filtered out
	}

	public function test_extra_class_and_escaping(): void {
		$html = $this->render( array( 'schema_site_name' => '<b>"X"</b>' ), array( 'name' ), 'my-card' );
		$this->assertStringContainsString( 'class="openseo-contact-info my-card"', $html );
		$this->assertStringContainsString( '&lt;b&gt;&quot;X&quot;&lt;/b&gt;', $html );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter RendererTest tests/Unit/ContactInfo`
Expected: FAIL — `Class "OpenSEO\ContactInfo\Renderer" not found`.

- [ ] **Step 3: Write the implementation**

```php
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

	private function description(): string {
		$desc = (string) $this->options->get( 'local_description' );
		return '' === $desc
			? ''
			: '<div class="openseo-contact-info__description">' . esc_html( $desc ) . '</div>';
	}

	private function email(): string {
		$email = (string) $this->options->get( 'local_email' );
		if ( '' === $email ) {
			return '';
		}
		return '<div class="openseo-contact-info__email"><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a></div>';
	}

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
			$list  .= '<li>' . esc_html( $label ) . ': ' . esc_html( $opens ) . '&#8211;' . esc_html( $closes ) . '</li>';
		}

		return '' === $list
			? ''
			: '<div class="openseo-contact-info__hours"><ul>' . $list . '</ul></div>';
	}

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
```

- [ ] **Step 4: Run tests + analysis + lint**

Run: `vendor/bin/phpunit --filter RendererTest tests/Unit/ContactInfo`
Expected: PASS (9 tests).

Run: `composer analyze` → No errors. Run: `composer lint` → clean.

- [ ] **Step 5: Commit**

```bash
git add src/ContactInfo/Renderer.php tests/Unit/ContactInfo/RendererTest.php
git commit -m "feat(contact): contact-info card Renderer (escaped sections)"
```

---

## Task 2: `ContactInfo\Shortcode`

**Files:**
- Create: `src/ContactInfo/Shortcode.php`
- Test: `tests/Unit/ContactInfo/ShortcodeTest.php`

**Interfaces:**
- Consumes: `Renderer` (Task 1), `Contracts\Hookable`, WP `add_shortcode`/`shortcode_atts`.
- Produces: `Shortcode` implements `Hookable`; `register()` adds the `openseo_contact_info` shortcode; `render($atts): string`; `Shortcode::parse_sections(string $show): array<int,string>` (pure).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\ContactInfo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\ContactInfo\Shortcode;
use PHPUnit\Framework\TestCase;

final class ShortcodeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_the_shortcode(): void {
		Functions\expect( 'add_shortcode' )
			->once()
			->with( 'openseo_contact_info', \Mockery::type( 'array' ) );

		( new Shortcode() )->register();
	}

	public function test_parse_sections_empty_is_all(): void {
		$this->assertSame( array(), Shortcode::parse_sections( '' ) );
	}

	public function test_parse_sections_splits_and_trims(): void {
		$this->assertSame(
			array( 'name', 'phone', 'address' ),
			Shortcode::parse_sections( 'name, phone , address' )
		);
	}

	public function test_parse_sections_drops_blanks(): void {
		$this->assertSame( array(), Shortcode::parse_sections( ' , ' ) );
	}

	public function test_render_returns_empty_when_no_data(): void {
		Functions\when( 'shortcode_atts' )->alias(
			static fn( $defaults, $atts ) => array_merge( $defaults, is_array( $atts ) ? $atts : array() )
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( '' );

		$this->assertSame( '', ( new Shortcode() )->render( array() ) );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ShortcodeTest tests/Unit/ContactInfo`
Expected: FAIL — `Class "OpenSEO\ContactInfo\Shortcode" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Registers the [openseo_contact_info] shortcode.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\ContactInfo;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Front-end shortcode that renders the contact card. The callback RETURNS the
 * Renderer's (already-escaped) HTML — shortcodes return, they do not echo.
 */
final class Shortcode implements Hookable {

	/**
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode( 'openseo_contact_info', array( $this, 'render' ) );
	}

	/**
	 * Render callback.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes ('' when none).
	 * @return string Escaped HTML, or '' when nothing is configured.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'show'  => '',
				'class' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'openseo_contact_info'
		);

		return ( new Renderer( new Options() ) )->render(
			self::parse_sections( (string) $atts['show'] ),
			(string) $atts['class']
		);
	}

	/**
	 * Parse the `show` attribute into a list of section keys ('' = all).
	 *
	 * @param string $show Comma-separated section keys.
	 * @return array<int, string>
	 */
	public static function parse_sections( string $show ): array {
		if ( '' === $show ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $show ) ) ) );
	}
}
```

- [ ] **Step 4: Run tests + analysis + lint**

Run: `vendor/bin/phpunit --filter ShortcodeTest tests/Unit/ContactInfo`
Expected: PASS (5 tests).

Run: `composer analyze` → No errors. Run: `composer lint` → clean.

- [ ] **Step 5: Commit**

```bash
git add src/ContactInfo/Shortcode.php tests/Unit/ContactInfo/ShortcodeTest.php
git commit -m "feat(contact): [openseo_contact_info] shortcode (show/class)"
```

---

## Task 3: Wiring en `Plugin` + verificación final

**Files:**
- Modify: `src/Plugin.php`
- Modify: `languages/openseo.pot`

**Interfaces:**
- Consumes: `ContactInfo\Shortcode` (Task 2).

- [ ] **Step 1: Add the import**

In `src/Plugin.php`, add below the other `use` statements (e.g. near the `Breadcrumbs` imports):

```php
use OpenSEO\ContactInfo\Shortcode as ContactInfoShortcode;
```

- [ ] **Step 2: Register the module**

In `src/Plugin.php` `modules()`, in the `$modules = array( … )` literal, add `new ContactInfoShortcode()` right after the `new BreadcrumbsBlock( $options ),` line (so it sits among the always-on front-end modules, NOT inside the `is_admin()` block):

```php
			$graph,
			new BreadcrumbsBlock( $options ),
			new ContactInfoShortcode(),
		);
```

- [ ] **Step 3: PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green (incl. `RendererTest`, `ShortcodeTest`).

- [ ] **Step 4: Integration suite (wp-env) — confirms boot + shortcode registration**

```bash
npm run env:start
npm run test:integration
```
Expected: the plugin boots with the new module (no fatal); existing integration tests stay green. (If wp-env/Docker is unavailable, note it as a deferred follow-up — do not skip silently.)

- [ ] **Step 5: Regenerate the `.pot`**

```bash
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo wp-content/plugins/openseo/languages/openseo.pot
```
(Destination MUST be the plugin-relative path.) Verify the new string landed:
```bash
grep -c "View on map" languages/openseo.pot   # expected: 1
```

- [ ] **Step 6: Manual smoke test (wp-env)**

With a LocalBusiness configured (from 2b-i), create a page containing `[openseo_contact_info]`, view it → the card shows name/email/phone/address/hours and a "View on map" link. Try `[openseo_contact_info show="name,phone,map"]` → only those sections. The map link opens Google Maps at the coordinates. View source → all values are escaped (the `&` in the map URL appears as `&#038;` — that is correct `esc_url` output, not a bug). (If wp-env unavailable, defer — don't skip silently.)

- [ ] **Step 7: Commit**

```bash
git add src/Plugin.php languages/openseo.pot
git commit -m "feat(contact): register contact-info shortcode module; regen .pot"
```

---

## Self-Review (completed during planning)

- **Spec coverage:** `Renderer` con las 7 secciones, orden canónico, omisión por vacío, escaping (mailto/tel scheme-en-PHP, en-dash literal en hours), map geo→dirección, `show` filtrado, `class` (Task 1); `Shortcode` registra + parsea `show`/`class` + devuelve (Task 2); wiring fuera de `is_admin()` + `.pot` + smoke (Task 3). D-name (espeja identidad, no local_website_name) en `Renderer::name`. Cada criterio de aceptación tiene tarea.
- **Placeholder scan:** sin TBD/TODO; cada paso de código muestra el código y el comando con salida esperada.
- **Type/símbolo consistency:** `Renderer::render(array $sections, string $extra_class): string` (1) usado por `Shortcode::render` (2); `Shortcode::parse_sections(string): array` (2) testeado y usado por su propio `render`; `ContactInfoShortcode` (alias de `ContactInfo\Shortcode`) registrado en `Plugin::modules()` (3); las claves `local_*`/`schema_site_name` leídas coinciden con `Options`; `LocalChoices::days()/phone_types()` consumidas vía `labels()`.
- **Green-by-commit:** Task 1 (Renderer) es autónoma; Task 2 (Shortcode) depende de Renderer (ya commiteado); Task 3 registra el módulo (depende de Shortcode) y verifica boot. Cada commit deja sus gates verdes. No hay símbolo usado antes de su tarea.
- **Design audit incorporado:** MEDIUM-1 (esquema mailto/tel concatenado en PHP antes de esc_url — `email()`/`tel_link()`), MEDIUM-2 (esc_html separado + en-dash literal en `hours()`), MEDIUM-3 (D-name: `name()` usa schema_site_name, no local_website_name). LOW (sin alias Yoast / sin combinar días / sin filtro) reflejados como no-objetivos del spec; no se implementan.
