# Diseño — SEO Local (2a: identidad + unificación)

- **Fecha:** 2026-06-22
- **Estado:** Aprobado para planificación
- **Área:** Titles & Meta (admin React) + Schema (piezas de identidad)
- **Tipo:** Sub-proyecto 2a de la consolidación de Titles & Meta — primera de dos sub-fases de "SEO Local" (2a identidad → 2b LocalBusiness + shortcode)

## Contexto

Tras completar Meta Global (sub-proyecto 1), seguimos con **SEO Local** de Rank Math. Por su tamaño se
divide en dos sub-fases (decisión de brainstorming 2026-06-22):

- **2a (este spec):** la identidad básica de la captura de Rank Math — persona/empresa, nombre de la
  web, nombre alternativo, nombre de persona/organización, logo, URL, email — convertida en la **fuente
  única** de la identidad de schema, absorbiendo la vista *General* existente.
- **2b (futuro):** el subsistema LocalBusiness — dirección, horarios y teléfonos repetibles, tipo de
  negocio Schema.org, geo, info adicional, páginas About/Contact, Maps; shortcode de contacto; schema
  `LocalBusiness`/`Place` enriquecido.

### Estado verificado de OpenSEO

- **Menú propio "General"** (`assets/src/admin/views/General.js`, slug `openseo-general`) edita tres
  keys: `schema_site_type` (Organization|Person), `schema_site_name`, `schema_logo`.
- **`Schema\Pieces\WebSite`** (siempre presente): `name` = `get_bloginfo('name')` (hardcoded),
  `description` = bloginfo, `publisher` → Org/Person, `inLanguage`, `SearchAction`. **No** lee un nombre
  configurable ni `alternateName`.
- **`Schema\Pieces\Organization`** (needed si `schema_site_type` != Person) y **`Person`** (needed si ==
  Person): `name` (= `schema_site_name`, fallback bloginfo), `url` = `home_url('/')`,
  `logo`/`image` (= `schema_logo`). **No** emiten `email` ni una URL configurable.
- `Schema\Ids` centraliza los `@id`. `Schema\Graph` ensambla las piezas.

### Decisiones congeladas (brainstorming 2026-06-22)

1. **Alcance:** dos sub-fases; **2a identidad + unificación primero**, 2b LocalBusiness después (otro spec).
2. **Unificación:** las nuevas secciones son la fuente única; para 2a, la identidad se **absorbe** en
   SEO Local y se **retira el menú General**.
3. **D1** — Los 7 campos exactos de la captura básica de Rank Math.
4. **D2** — `Person` mantiene `@type: "Person"` (no el array `["Organization","Person"]` de Rank Math)
   en 2a. Revisitable en 2b.
5. **D3** — Control persona/organización = `SelectControl` (consistente con el admin), no un
   ToggleGroup experimental.
6. **D4** — Logo con el uploader `MediaField` (creado en Meta Global), no un campo de texto plano.
7. **D5** — `local_url` opcional (default `home_url`) y `local_email` en el nodo Org/Person.

## Objetivo

Traer la pantalla "SEO Local" básica de Rank Math a OpenSEO como una pestaña en Titles & Meta que es la
fuente única de la identidad de schema (persona/empresa, nombres, logo, URL, email), reflejada en los
nodos `WebSite`/`Organization`/`Person` del `@graph`, retirando el menú General.

## No-objetivos (van a 2b o quedan fuera)

- Dirección, horarios, teléfonos repetibles, tipo de negocio, price range, geo, info adicional, páginas
  About/Contact, Maps API → **2b**.
- Shortcode de contacto (`[openseo_contact_info]`) → **2b**.
- Schema `LocalBusiness`/`Place`, `contactPoint`, `openingHoursSpecification`, `PostalAddress`,
  `GeoCoordinates` → **2b**.
- `Person` con `@type` array `["Organization","Person"]` (D2).
- Migración de datos: ninguna. `schema_site_type/name/logo` conservan key y formato; las 4 keys nuevas
  arrancan vacías.

## Arquitectura

### 1. Modelo de datos (`Settings\Options`)

Cuatro keys nuevas en `defaults()`:

```php
'local_website_name'           => '',  // WebSite node name (fallback: bloginfo name)
'local_website_alternate_name' => '',  // WebSite alternateName (omitido si vacío)
'local_url'                    => '',  // Org/Person url override (fallback: home_url)
'local_email'                  => '',  // Org/Person email (omitido si vacío)
```

Reutiliza sin cambios: `schema_site_type`, `schema_site_name`, `schema_logo` (ya en defaults/sanitize).

Sanitización en `sanitize()`:
- `local_website_name`, `local_website_alternate_name` → se añaden al bucle de text fields existente
  (`sanitize_text_field( wp_unslash() )`).
- `local_url` → se añade al bucle de URLs (`esc_url_raw( wp_unslash() )`) junto a `og_default_image`/`schema_logo`.
- `local_email` → bloque propio: `$email = sanitize_email( wp_unslash( $input['local_email'] ) );
  $clean['local_email'] = is_email( $email ) ? $email : '';` (email inválido → cadena vacía; `is_email`
  devuelve `string|false`, se castea el resultado limpio).

### 2. Cableado del schema (PHP)

**`Schema\Pieces\WebSite::data()`** — nombre configurable + alternateName:
```php
$name = (string) $this->options->get( 'local_website_name' );
if ( '' === $name ) {
    $name = (string) get_bloginfo( 'name' );
}
// $data['name'] = $name; (en vez del bloginfo hardcoded)

$alternate = (string) $this->options->get( 'local_website_alternate_name' );
if ( '' !== $alternate ) {
    $data['alternateName'] = $alternate;  // añadido tras construir $data
}
```
(El método se reestructura: arma `$data` en una variable y añade `alternateName` condicionalmente, en
vez de devolver el array literal.)

**`Schema\Pieces\Organization::data()` y `Person::data()`** — email + url override:
```php
$url = (string) $this->options->get( 'local_url' );
if ( '' === $url ) {
    $url = home_url( '/' );
}
// 'url' => $url, (en vez de home_url('/') directo)

$email = (string) $this->options->get( 'local_email' );
if ( '' !== $email ) {
    $data['email'] = $email;  // añadido condicionalmente
}
```
Name y logo/image **no cambian** (siguen leyendo `schema_site_name`/`schema_logo`).

### 3. UI admin (React) — pestaña "SEO Local"

En `assets/src/admin/views/Titles.js`, el primer grupo de `VerticalTabs` pasa a tres tabs en el orden de
Rank Math: `meta-global`, **`seo-local`**, `homepage`.

**`SeoLocalPanel`** (nuevo) — 7 campos:
1. `SelectControl` "Site represents" → `schema_site_type` (Organization|Person). *(reutiliza)*
2. `TextControl` "Website name" (help: defaults to site name) → `local_website_name`.
3. `TextControl` "Alternate website name" → `local_website_alternate_name`.
4. `TextControl` "Person or Organization name" (help: defaults to site name) → `schema_site_name`. *(reutiliza)*
5. `MediaField` "Logo" (help: minimum 112×112px) → `schema_logo`. *(reutiliza; uploader de Meta Global)*
6. `TextControl` "URL" (help: defaults to site URL) → `local_url`.
7. `TextControl` "Email" → `local_email`.

`renderPanel` necesita una **rama condicional explícita** `if ( tab === 'seo-local' ) return
<SeoLocalPanel … />;` **antes** del `return <MetaGlobalPanel … />` final (que es el fallback). Sin esa
rama, el tab `seo-local` caería silenciosamente en `MetaGlobalPanel` (MEDIUM-1). El default activo sigue
siendo `meta-global`. Strings de UI en inglés (`__( …, 'openseo' )`).

> **Textos de ayuda diferenciados (LOW-2):** los campos 2 (`local_website_name`) y 4 (`schema_site_name`)
> editan nodos distintos del `@graph` (WebSite vs Org/Person). Sus `help` deben distinguirlos para no
> confundir: campo 2 → "Name of the WebSite node (defaults to site name)"; campo 4 → "Name of the
> Organization/Person entity (defaults to site name)".

### 4. Retiro de la vista General (unificación, decisión 2)

- **`Admin\Menu::pages()`**: se elimina la entrada `openseo-general` (slug + view `general`).
- **`assets/src/admin/App.js`**: se elimina el import de `General` y su rama de `view === 'general'`.
- **`assets/src/admin/views/General.js`**: se borra el archivo.
- **Sin migración de datos**: `schema_site_type/name/logo` siguen siendo las mismas keys, ahora editadas
  desde la pestaña SEO Local.
- **Tests de menú (LOW-1)**: solo **`MenuTest`** requiere ajuste —
  `test_registers_parent_and_all_submenus` lista `'openseo-general'` explícitamente y debe perderlo (un
  submenú menos: dashboard + titles/social/sitemaps/schema/redirects/404s/ai). **`MenuWiringTest` no
  cambia** (no referencia `openseo-general`; solo asevera el parent slug, la ruta REST y la ausencia de
  `SettingsPage`).
- **i18n + docs (MEDIUM-2)**: regenerar `languages/openseo.pot` (`wp i18n make-pot`, ruta destino del
  plugin) para retirar las cadenas de `views/General.js`, y actualizar el conteo/lista de submenús en
  `CLAUDE.md` (hoy "9 submenus … Dashboard · General · …") y `NOTES.md` → **8 submenús, sin "General"**,
  con "SEO Local" descrita como tab interna de Titles & Meta.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Settings\Options` (mod) | defaults + sanitize de las 4 keys nuevas (email/url incluidos) | helpers existentes |
| `Schema\Pieces\WebSite` (mod) | `name` configurable + `alternateName` | `Options` |
| `Schema\Pieces\Organization` (mod) | `email` + `url` override | `Options` |
| `Schema\Pieces\Person` (mod) | `email` + `url` override | `Options` |
| `Admin\Menu` (mod) | retirar la entrada `openseo-general` | — |
| `views/Titles.js` (mod) | tab `seo-local` + `SeoLocalPanel` | `MediaField`, `SettingsPanel` |
| `views/General.js` (borrado) | — | — |
| `App.js` (mod) | quitar la vista `general` | — |

## Manejo de errores y casos límite

- **Email inválido:** `sanitize_email` + `is_email` → cadena vacía; el nodo Org/Person no emite `email`.
- **URL vacía:** `local_url` `''` → el nodo usa `home_url('/')` (comportamiento actual preservado).
- **Nombre de web vacío:** `WebSite` cae a `get_bloginfo('name')` (sin regresión; los tests existentes
  con defaults vacíos siguen verdes).
- **Nombre alternativo vacío:** no se emite `alternateName` (clave omitida).
- **`schema_site_type` fuera de lista:** `sanitize()` ya lo normaliza a `Organization` (sin cambio).
- **Asimetría `logo`/`image` Person vs Organization (LOW-3, preexistente, intencional):** `Organization`
  emite `logo` (ImageObject con `@id`) + un `image` espejo por `@id`; `Person` emite solo `image`
  (ImageObject inline, sin `logo`) — correcto, porque `logo` es propiedad de Organization, no de Person en
  schema.org. 2a **no** toca esto; se documenta para que el plan no lo "unifique" por error.
- **Retiro de General sin romper navegación:** ningún otro módulo lee la vista `general`; el data-view
  desaparece junto al submenú, y `App.js` deja de mapearlo.
- **Seguridad/i18n:** sanitizar en entrada (email/url/text), escapar en salida (el schema va por
  `wp_json_encode` con `JSON_HEX_TAG`); cadenas por `__()`; el logo usa solo URLs de la biblioteca vía
  `MediaField` (`esc_url_raw`).

## Testing

**PHP unit (Brain Monkey):**
- `OptionsTest` (ampliar): defaults incluyen las 4 keys (`''`); sanitize de `local_website_name`/
  `local_website_alternate_name` (text), `local_url` (`esc_url_raw`), `local_email` (válido se conserva;
  inválido → `''`).
- `SitePiecesTest` (ampliar): `WebSite` usa `local_website_name` y cae a bloginfo cuando vacío; añade
  `alternateName` solo cuando está; `Organization`/`Person` añaden `email` solo cuando está y usan
  `local_url` (con fallback `home_url`). **Anti-cruce (LOW-2):** con `local_website_name='A'` y
  `schema_site_name='B'`, asevera `WebSite.name === 'A'` y `Organization.name === 'B'` (no se cruzan los
  nodos).
- `MenuTest` (ajustar): `test_registers_parent_and_all_submenus` deja de listar `openseo-general`; los
  demás (titles/social/sitemaps/schema/redirects/404s/ai/dashboard) se conservan. `MenuWiringTest` **no
  cambia**.

**JS:** no se añade test unitario nuevo (el panel es presentacional, reutiliza `MediaField` ya cubierto
por lint/build); se valida con `npm run lint:js` (sin imports sin usar tras borrar `General`) y
`npm run build`.

**Gates:** `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`, `npm run lint:js`,
`npm run test:js`, `npm run build` — todos verdes.

**Smoke test manual (wp-env):** en *OpenSEO → Titles & Meta → SEO Local* rellenar nombre alternativo,
URL y email; ver el código fuente → el `@graph` muestra `WebSite.alternateName` y `Organization.email`/
`url`; confirmar que el menú **General** ya no aparece y que la identidad se edita desde SEO Local.

## Criterios de aceptación

- Pestaña **"SEO Local"** (segunda, entre Meta Global y Homepage) con los 7 campos de identidad, el logo
  con uploader de medios y el selector persona/organización.
- Las 4 keys nuevas (`local_website_name`, `local_website_alternate_name`, `local_url`, `local_email`)
  se guardan y sanean (email inválido → vacío).
- `WebSite` emite el nombre configurable (fallback bloginfo) y `alternateName` cuando está; los nodos
  `Organization`/`Person` emiten `email` cuando está y respetan `local_url` (fallback `home_url`).
- El menú **General** se retira: su submenú, su vista React y `views/General.js` desaparecen; la
  identidad se edita solo desde SEO Local; sin pérdida de datos (mismas keys `schema_*`).
- Sin regresión: con defaults vacíos, `WebSite`/`Organization`/`Person` producen el mismo schema que
  antes; los tests de schema/options/menu siguen verdes (con los ajustes de menú).
- Gates verdes (lint/analyze/test:unit/lint:js/test:js/build).
