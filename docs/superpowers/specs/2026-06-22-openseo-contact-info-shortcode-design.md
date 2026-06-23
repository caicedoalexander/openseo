# Diseño — SEO Local (2b-ii: shortcode de contacto)

- **Fecha:** 2026-06-22
- **Estado:** Aprobado para planificación
- **Área:** Frontend (shortcode) + Settings (lectura)
- **Tipo:** Sub-proyecto 2b-ii de la consolidación de Titles & Meta — segunda y última sub-fase de "SEO Local 2b" (2b-i campos+schema → 2b-ii shortcode)

## Contexto

2b-i añadió los campos LocalBusiness (dirección, horarios, teléfonos, geo, descripción, …) y su JSON-LD.
2b-ii expone esa información en la **página** mediante el shortcode `[openseo_contact_info]` (paridad con
`[rank_math_contact_info]`), una tarjeta de contacto con `show`/`class`.

### Estado verificado de OpenSEO

- No existe ningún shortcode (`add_shortcode` no aparece en `src/`). El patrón a seguir es el de
  breadcrumbs: `Breadcrumbs\Renderer` produce HTML **totalmente escapado** (`esc_html`/`esc_url`/
  `esc_attr`) y el caller hace `echo` con un `phpcs:ignore WordPress.Security.EscapeOutput`. La función
  de tema `openseo_breadcrumbs()` vive en `src/template-functions.php`.
- Los datos están en `Options` (claves de 2a/2b-i): `schema_site_name`, `local_url`, `local_email`,
  `local_phone`, `local_description`, `local_address` (grupo), `local_opening_hours`,
  `local_phone_numbers`, `local_geo`.
- `Schema\LocalChoices::days()`/`phone_types()` dan las etiquetas i18n (value→label) para mostrar días y
  tipos de teléfono de forma legible.
- `Plugin::modules()` construye los módulos; los always-on (no admin) se registran fuera de
  `is_admin()`.

### Decisiones congeladas (brainstorming 2026-06-22)

1. **Secciones:** tarjeta completa — `name`, `description`, `email`, `phone` (principal + adicionales),
   `address`, `hours`, `map`.
2. **Superficie:** solo el shortcode `[openseo_contact_info]` (con `show` y `class`); sin función de
   tema ni bloque en 2b-ii (el `Renderer` queda reutilizable si se quieren después).
3. **D1** — Orden **canónico** de secciones, filtrado por `show` (no en el orden de `show`).
4. **D2** — Mapa = enlace a Google Maps (`…/maps/search/?api=1&query=…` de `local_geo`, fallback a la
   dirección), `target="_blank" rel="noopener"`; **sin iframe ni API key** (consistente con 2b-i).
5. **D3** — **Sin CSS de frontend encolado**: HTML semántico con clases (el tema lo estiliza), como
   breadcrumbs.
6. **D4** — Reutilizar las etiquetas de `LocalChoices` (días, tipos de teléfono) para texto
   i18n-extraíble.
7. **D5** — Shortcode registrado siempre (frontend), no tras `is_admin()`.

## Objetivo

Mostrar la información de contacto/negocio configurada (nombre, descripción, email, teléfonos,
dirección, horarios, enlace a mapa) en cualquier página mediante `[openseo_contact_info]`, con HTML
semántico, escapado y filtrable por `show`.

## No-objetivos

- Función de tema `openseo_contact_info()` y bloque Gutenberg (diferidos; el `Renderer` los habilita
  trivialmente después).
- Embed de Google Maps / API key (descartado en 2b-i; solo enlace).
- CSS de frontend propio (el tema estiliza las clases).
- Info-adicional (legalName/VAT…) en la tarjeta (es ficha-empresa, no contacto; YAGNI).
- Microdata/RDFa en el HTML del shortcode (el JSON-LD de 2b-i ya cubre el structured data).
- **(LOW-2)** Alias de compatibilidad Yoast (`wpseo_address`/`wpseo_map`/`wpseo_opening_hours`) — decisión
  consciente, no olvido.
- **(LOW-2)** Combinar días con el mismo horario ("Lun, Mar: 9–17"): en 2b-ii se lista **una fila por
  día**; combinarlos es un follow-up.
- Filtro `apply_filters('openseo_contact_info_html', …)` para extensibilidad (LOW-3): follow-up
  opcional; `Breadcrumbs\Renderer` tampoco lo expone.

## Arquitectura

### 1. `ContactInfo\Renderer` (nuevo, puro/escapado)

`render( array $sections = array(), string $extra_class = '' ): string` — `$sections` vacío = todas;
si no vacío, solo esas (intersección con las conocidas, en **orden canónico**). Devuelve `''` si
ninguna sección produce contenido. Lee de `Options`; usa `LocalChoices` para etiquetas. **Escapa cada
valor** (el caller puede hacer `echo` directo).

Constante de orden canónico: `name, description, email, phone, address, hours, map`.

Secciones (cada una se omite si su dato está vacío):
- **name** — `schema_site_name` (fallback `get_bloginfo('name')`); si `local_url` (fallback
  `home_url('/')`) está, envuelto en `<a href>`; si no, texto plano. **(MEDIUM-3, decisión D-name):** el
  `name` **espeja la identidad de `Organization`/`Person`** (mismo `schema_site_name`→`get_bloginfo` que
  esas piezas), **no** `local_website_name` (que es el nombre del nodo WebSite). El plan NO debe sustituir
  por `local_website_name`.
- **description** — `local_description`.
- **email** — `local_email` como `<a href="mailto:…">`.
- **phone** — `local_phone` principal como `<a href="tel:…">`; luego una lista de `local_phone_numbers`:
  por fila, etiqueta de tipo (de `LocalChoices::phone_types()`, omitida si `type` vacío) + número como
  `tel:`.
- **address** — `<address>` con las partes no vacías de `local_address`
  (`street, locality, region, postal_code, country`) unidas por `, `.
- **hours** — `<ul>` de `local_opening_hours`: por fila, `"<día>: <opens>–<closes>"` (día traducido vía
  `LocalChoices::days()`). **(MEDIUM-2):** `esc_html` se aplica a `<día>`, `<opens>` y `<closes>`
  **por separado**; el separador en-dash se inserta como **literal** (`–` / `&#8211;`) entre los valores
  ya escapados — NO se hace un `esc_html` global de la fila (re-escaparía el `&` de la entidad a
  `&amp;#8211;`), exactamente como `Breadcrumbs\Renderer` trata su separador.
- **map** — `<a target="_blank" rel="noopener">` a
  `https://www.google.com/maps/search/?api=1&query=<rawurlencode(geo|dirección)>`; usa `local_geo` si
  está, si no la dirección formateada; se omite si no hay ni geo ni dirección.

**Escapado:** `esc_html` para texto; `esc_url` para `mailto:`/`tel:`/el enlace de mapa (`mailto` y `tel`
están en los protocolos permitidos por defecto de `esc_url`); el número del `tel:` se limpia con
`preg_replace('/[^0-9+]/', '', $phone)` antes de construir el URI (el texto visible conserva el original
vía `esc_html`); `esc_attr` para la `class`. **(MEDIUM-1, invariante):** el esquema (`mailto:` / `tel:`)
se **concatena en PHP** antes de pasar a `esc_url` (`esc_url( 'mailto:' . $email )`,
`esc_url( 'tel:' . $clean )`); nunca proviene del dato. El plan NO debe hacer `esc_url($email)` "a secas"
(perdería el esquema y podría devolver `''`).

### 2. `ContactInfo\Shortcode` (nuevo, `Hookable`) + wiring

```php
public function register(): void {
    add_shortcode( 'openseo_contact_info', array( $this, 'render' ) );
}

/**
 * @param array<string,string>|string $atts Shortcode attributes.
 */
public function render( $atts ): string {
    $atts = shortcode_atts(
        array( 'show' => '', 'class' => '' ),
        is_array( $atts ) ? $atts : array(),
        'openseo_contact_info'
    );
    $sections = '' === $atts['show']
        ? array()
        : array_filter( array_map( 'trim', explode( ',', $atts['show'] ) ) );

    return ( new Renderer( new Options() ) )->render( $sections, (string) $atts['class'] );
}
```

`Plugin::modules()` añade `new ContactInfo\Shortcode()` a la lista **fuera** de `is_admin()` (always-on),
junto a los demás módulos de frontend; `boot()` llama `register()`.

### 3. Estructura de salida (semántica, clasificada, escapada)

```html
<div class="openseo-contact-info {extra}">
  <div class="openseo-contact-info__name"><a href="{url}">{name}</a></div>
  <div class="openseo-contact-info__description">{description}</div>
  <div class="openseo-contact-info__email"><a href="mailto:{email}">{email}</a></div>
  <div class="openseo-contact-info__phone">
    <a href="tel:{clean}">{phone}</a>
    <ul class="openseo-contact-info__phones">
      <li><span class="openseo-contact-info__phone-type">{type label}</span> <a href="tel:{clean}">{number}</a></li>
    </ul>
  </div>
  <address class="openseo-contact-info__address">{parts joined}</address>
  <div class="openseo-contact-info__hours">
    <ul><li>{day}: {opens}&#8211;{closes}</li></ul>
  </div>
  <div class="openseo-contact-info__map"><a href="{maps url}" target="_blank" rel="noopener">{__('View on map')}</a></div>
</div>
```

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `ContactInfo\Renderer` (nuevo) | HTML escapado de la tarjeta desde `Options` | `Options`, `LocalChoices` |
| `ContactInfo\Shortcode` (nuevo) | registra `[openseo_contact_info]`, parsea `show`/`class`, delega | `Renderer`, WP `add_shortcode`/`shortcode_atts` |
| `Plugin` (mod) | registra el módulo Shortcode (frontend) | `ContactInfo\Shortcode` |

## Manejo de errores y casos límite

- **Sin datos configurados:** ninguna sección produce contenido → `render` devuelve `''` (el shortcode
  no imprime nada).
- **`show` con claves desconocidas o vacías:** se ignoran (intersección con las conocidas); `show=""` =
  todas.
- **Solo algunas secciones con datos:** las vacías se omiten; el contenedor solo envuelve lo que hay.
- **Person (sin campos LocalBusiness):** address/hours/map suelen estar vacíos → se omiten; name/email/
  phone se muestran si están.
- **`local_geo` vacío pero con dirección:** el mapa usa la dirección; si tampoco hay dirección, se omite
  el mapa.
- **`tel:` con caracteres no telefónicos:** el URI se limpia (`[^0-9+]`); el texto visible conserva el
  formato original.
- **Seguridad:** todo escapado en salida (`esc_html`/`esc_url`/`esc_attr`); `esc_url` valida el
  protocolo de `mailto:`/`tel:`/`https:`. El shortcode no procesa input de usuario más allá de sus
  atributos (saneados por `shortcode_atts` + el split de `show`). Sin SQL, sin nonce (es solo lectura/
  display). i18n: cadenas fijas (`View on map`) y etiquetas vía `LocalChoices`.
- **PHPStan nivel 6:** `Options::get()` (`mixed`) leído con guards `is_array`/casts `(string)`;
  `LocalChoices::days()`/`phone_types()` devuelven `array<int,array{value,label}>`, se indexan a un
  mapa value→label con guard.

## Testing

**PHP unit (Brain Monkey):**
- `ContactInfo\RendererTest`: cada sección renderiza con datos (name con/sin url, email mailto, phone
  principal + adicionales con etiqueta de tipo, address formateada, hours con día traducido, map de geo
  y map de dirección); cada sección se omite si su dato está vacío; `show=['name','phone']` solo
  renderiza esas; sin ningún dato → `''`; escaping (un valor con `"`/`<` sale escapado); el `tel:` se
  limpia. Mockea `esc_html`/`esc_url`/`esc_attr`/`__`/`get_bloginfo`/`home_url` y `get_option`.
- `ContactInfo\ShortcodeTest`: `register()` llama `add_shortcode('openseo_contact_info', …)` (assert vía
  Brain Monkey expectation); `render(['show'=>'name,phone','class'=>'x'])` parsea a
  `sections=['name','phone']` y delega al Renderer con la clase (verificable construyendo un caso real o
  asertando el HTML resultante). `render('')` (atts vacío/string) no peta.

**Gates:** `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`. (Sin JS — no se toca el
bundle.) Si se regeneran cadenas, `.pot`.

**Smoke test manual (wp-env):** con un LocalBusiness configurado (de 2b-i), crear una página con
`[openseo_contact_info]` → ver nombre/email/teléfono/dirección/horarios/enlace de mapa; probar
`[openseo_contact_info show="name,phone,map"]` → solo esas; el enlace de mapa abre Google Maps con las
coordenadas.

## Criterios de aceptación

- `[openseo_contact_info]` renderiza una tarjeta con nombre, descripción, email, teléfono(s), dirección,
  horarios y enlace de mapa, con cada sección omitida cuando no hay datos.
- `show="…"` filtra las secciones (orden canónico); `class="…"` añade una clase a la raíz.
- El enlace de mapa apunta a Google Maps con las coordenadas (o la dirección), `target="_blank"
  rel="noopener"`, sin iframe.
- Todo el HTML va escapado; sin datos → no se imprime nada.
- El shortcode funciona en el frontend (registrado fuera de `is_admin()`).
- Gates verdes (lint/analyze/test:unit).
