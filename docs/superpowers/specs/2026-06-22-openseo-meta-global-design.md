# Diseño — Meta Global

- **Fecha:** 2026-06-22
- **Estado:** Aprobado para planificación
- **Área:** Titles & Meta (frontend presenters + admin React)
- **Tipo:** Sub-proyecto de la consolidación de Titles & Meta — **1 de 2** (Meta Global → luego SEO Local)

## Contexto

El usuario quiere paridad con las dos primeras pestañas de "Títulos y metadatos SEO" de Rank Math:
**Meta Global** y **SEO Local**. Se decidió (brainstorming 2026-06-22) abordarlas como **dos
sub-proyectos**, empezando por Meta Global (más pequeño: en su mayoría cablea ajustes a presenters que
ya existen), e integrarlas como **sub-pestañas dentro de Titles & Meta** (espejo de la IA de Rank Math),
con las nuevas secciones como **fuente única** para los ajustes que hoy están dispersos.

Tras verificar el cableado real de OpenSEO, buena parte de "Meta Global" **ya existe**:

| Campo (Rank Math "Meta global") | Estado en OpenSEO | Acción |
|---|---|---|
| Carácter separador (`-` `–` `—` `»` `\|` `•`) | ✅ `title_separator`, aplicado en `Meta\Variables::replace` (`%sep%`). UI = `TextControl` libre | Cambiar UI a selector de caracteres (sin cambio PHP) |
| Robots meta global (noindex/nofollow/noarchive/nosnippet/noimageindex) | ✅ sub-array `robots`, aplicado en `Meta\Resolver::robots()` (sub-proyecto 4) | **Reubicar** UI a la pestaña Meta Global |
| Noindex de archivos de etiqueta/categoría vacíos | ✅ `robots.noindex_empty_terms`, aplicado | **Reubicar** UI |
| Miniatura de OpenGraph | ✅ `og_default_image` (vista Social, URL en texto plano), fallback en `Resolver::social_image()` | **Unificar** en Meta Global + uploader de medios real |
| Metadatos avanzados para robots (max-snippet / max-video-preview / max-image-preview) | ❌ no existe (excluido explícitamente en sub-proyecto 4) | **Nuevo**: keys + wiring en `Resolver::robots()` |
| Capitalizar los títulos | ❌ no existe | **Nuevo**: key + wiring en `Resolver::title()` |
| Tipo de tarjeta de Twitter | ❌ hardcoded en `Frontend\Head\Twitter::output()` (summary/large según haya imagen) | **Nuevo**: key + wiring |
| Rewrite Titles (condicional al tema) | ❌ | **Excluido** — OpenSEO usa `pre_get_document_title`, no lo necesita |

### Decisiones congeladas (brainstorming 2026-06-22)

1. **Orden:** dos sub-proyectos; **Meta Global primero**, SEO Local después (otro spec).
2. **IA:** sub-pestañas dentro de la vista `views/Titles.js` (que ya usa `VerticalTabs`), espejo de Rank Math.
3. **Unificación:** las nuevas secciones son la fuente única (para Meta Global el punto de unificación es
   `og_default_image`, hoy editado desde la vista Social).
4. **D1** — Renombrar la pestaña interna "General" de Titles → **"Página de inicio"** (solo
   `home_title`/`home_description`) y crear **"Meta Global"** como primera pestaña. El separador, el
   robots global y el noindex de vacíos **se mueven** de la pestaña General a Meta Global.
5. **D2** — `og_default_image` se edita **solo** desde Meta Global; la vista Social muestra un aviso que
   apunta allí (la futura "Meta Social" será otro sub-proyecto).
6. **D3** — Se mantiene el modelo de robots de OpenSEO (sin checkbox redundante "Index"; `index` =
   ausencia de `noindex`), presentándolo como Rank Math.
7. **D4** — Advanced robots y Twitter card type **solo globales** por ahora (sin override por entrada; eso
   sería otra fase).
8. **D5** — `rewrite_title` excluido.

## Objetivo

Completar la pantalla "Meta Global" de Rank Math en OpenSEO: separador (selector de caracteres),
capitalización de títulos, robots global + **metadatos avanzados de robots**, noindex de archivos
vacíos, miniatura OpenGraph por defecto (con uploader real) y tipo de tarjeta de Twitter; todo
reflejado en los presenters del `wp_head` y en el título del documento.

## No-objetivos

- **SEO Local** (sub-proyecto 2): identidad persona/empresa, dirección, horarios, schema LocalBusiness,
  shortcode de contacto. Spec aparte.
- **Meta Social** completa (Facebook app id, OG type/site_name, Twitter username, etc.). Solo se toca el
  `og_default_image` para unificarlo; el resto de la vista Social no se amplía.
- Override **por entrada** de advanced robots y de Twitter card (solo global por ahora — D4).
- `rewrite_title` (D5).
- Migración de datos: no hay. Las keys reutilizadas (`og_default_image`, `title_separator`, `robots`)
  conservan nombre y formato; las 3 keys nuevas arrancan con sus defaults.

## Arquitectura

### 1. Modelo de datos (`Settings\Options`)

Tres keys nuevas en `defaults()`:

```php
'capitalize_titles' => '',                  // '1' | ''  (toggle)
'twitter_card_type' => 'summary_large_image', // 'summary_large_image' | 'summary'
'advanced_robots'   => array(
    'max_snippet'       => array( 'enabled' => '', 'length' => '-1' ),
    'max_video_preview' => array( 'enabled' => '', 'length' => '-1' ),
    'max_image_preview' => array( 'enabled' => '', 'value'  => 'large' ),
),
```

Sanitización en `sanitize()`:

- **`capitalize_titles`** → se añade al bucle de checkboxes existente (`'1'` / `''`).
- **`twitter_card_type`** → whitelist `['summary_large_image','summary']`; fuera de lista → default
  `'summary_large_image'`.
- **`advanced_robots`** (si `isset($input['advanced_robots'])`) → se reconstruye sub-clave por sub-clave
  (ignora claves desconocidas):
  - `max_snippet.enabled`, `max_video_preview.enabled`, `max_image_preview.enabled` → `'1'`/`''`.
  - `max_snippet.length`, `max_video_preview.length` → entero (se permite `-1`); se guarda como string.
    `intval(wp_unslash(...))`, clamp mínimo `-1`.
  - `max_image_preview.value` → whitelist `['large','standard','none']`, default `'large'`.

> **Convención de booleanos (M2):** `advanced_robots.*.enabled` y `capitalize_titles` usan `'1'/''`
> (igual que el robots **global** y el resto de checkboxes de `Options`), **NO** la tri-estado
> `'on'/'off'/''` del robots **por tipo/entrada** (`sanitize_template_map` + `RobotsResolver`). Es
> correcto porque advanced robots es solo global (D4): no hay herencia que requiera "heredar". No
> mezclar ambos formatos.

> El separador (`title_separator`), el robots global (`robots`) y el `og_default_image` **no cambian**
> en `Options`: ya están en `defaults()`/`sanitize()` (sub-proyectos previos). Solo cambia **dónde** se
> editan en la UI.

### 2. Cableado frontend (PHP)

**a) Capitalización — `Meta\Resolver::title()` + `Support\Str` (nuevo, puro).**

Nueva clase pura testeable `OpenSEO\Support\Str` (dir nuevo `src/Support/`):

```php
public static function mb_ucwords( string $str ): string {
    // Mayúscula inicial de cada palabra preservando el resto (multibyte-safe),
    // conservando los espacios originales (split con captura de delimitadores).
}
```

`Resolver::title()` se refactoriza: el cuerpo actual pasa a un privado `resolve_title(): string`, y
`title()` envuelve: `return $this->capitalize( $this->resolve_title() );`. El privado
`capitalize(string $title)` aplica `Support\Str::mb_ucwords()` **solo** si `capitalize_titles === '1'`
y el título no está vacío. Como `social_title()` cae a `title()`, la capitalización se propaga a OG /
Twitter title por cascada (comportamiento aceptado en el brainstorming).

**b) Metadatos avanzados de robots — `Meta\Resolver::robots()`.**

`robots_string()` se renombra a `robots_parts()` y devuelve un `array<int,string>` de directivas
(en vez de un string ya unido). `robots()` arma:

```
$parts = $this->robots_parts( $effective );           // index/follow + noarchive/nosnippet/noimageindex
if ( ! $effective['noindex'] && ! $effective['nosnippet'] ) {
    $parts = array_merge( $parts, $this->advanced_robots_parts() );
}
return implode( ', ', $parts );
```

`robots_parts(array $e): array<int,string>` **conserva el parámetro `$effective`** (no pasa a
no-args): es la versión renombrada de `robots_string()` que ahora devuelve el array en vez del string.

`advanced_robots_parts(): array<int,string>` lee `Options::get('advanced_robots')` y por cada bloque
habilitado (`enabled === '1'`) emite:
- `max-snippet:{int length}`  (p. ej. `max-snippet:-1`)
- `max-video-preview:{int length}`
- `max-image-preview:{value}`  (`large` | `standard` | `none`)

El "bail" si `noindex` o `nosnippet` replica a Rank Math (con noindex/nosnippet, los avanzados no
aportan). `Frontend\Head\Robots` no cambia.

> **Lectura tipada para PHPStan nivel 6 (M1):** `Options::get('advanced_robots')` devuelve `mixed`, y el
> array es anidado con sub-claves heterogéneas (`length` vs `value`). Para no propagar `mixed` ni
> requerir baseline, `advanced_robots_parts()` itera sobre un **mapa fijo de salida** y castea cada
> lectura a tipo concreto, sin acceder a offsets de `mixed` sin guard:
> ```php
> $adv = $this->options->get( 'advanced_robots' );
> $adv = is_array( $adv ) ? $adv : array();
> $blocks = array(
>     'max-snippet'       => array( 'max_snippet', 'length', '-1' ),
>     'max-video-preview' => array( 'max_video_preview', 'length', '-1' ),
>     'max-image-preview' => array( 'max_image_preview', 'value', 'large' ),
> );
> foreach ( $blocks as $directive => [ $key, $field, $default ] ) {
>     $block   = is_array( $adv[ $key ] ?? null ) ? $adv[ $key ] : array();
>     $enabled = '1' === (string) ( $block['enabled'] ?? '' );
>     if ( $enabled ) {
>         $parts[] = $directive . ':' . (string) ( $block[ $field ] ?? $default );
>     }
> }
> ```
> Cada acceso queda detrás de `is_array`/`(string)`, así PHPStan ve tipos concretos. No se usa un
> shape `string|array` ambiguo.

**c) Tipo de tarjeta de Twitter — `Meta\Resolver::twitter_card()` (nuevo) + `Frontend\Head\Twitter`.**

`Resolver::twitter_card(): string` devuelve el valor configurado, validado a
`['summary_large_image','summary']` (default `summary_large_image`). `Twitter::output()` deja de
calcular el tipo según la imagen y usa `'twitter:card' => $this->resolver->twitter_card()`. El resto de
tags (title/description/image) no cambian; se sigue omitiendo cualquier tag con valor vacío. El
`twitter:card` siempre se emite (nunca vacío).

> **Cambio observable (intencional):** páginas sin imagen de Twitter pasarán a declarar
> `summary_large_image` por defecto (antes degradaban a `summary`). Es el comportamiento de Rank Math
> y de la nueva opción global. Sigue siendo configurable a `summary`.

**d) Separador / OG default:** sin cambios PHP (ya cableados en `Variables::replace` y
`Resolver::social_image()`).

### 3. UI admin (React) — `assets/src/admin/`

**Reestructura de `views/Titles.js`:**

- `GROUPS` arranca con dos tabs nuevas en el primer grupo:
  `{ name: 'meta-global', title: __('Meta Global') }` y `{ name: 'homepage', title: __('Página de inicio') }`.
  (SEO Local se insertará entre ellas en el sub-proyecto 2.)
- Pestaña activa por defecto: `'meta-global'`.
- **`MetaGlobalPanel`** (nuevo): separador (`SeparatorField`), `ToggleControl` capitalizar,
  checkboxes robots global + `ToggleControl` noindex de vacíos (**movidos** desde el actual
  `GeneralPanel`), `AdvancedRobotsField`, miniatura OG (`MediaField` sobre `og_default_image`),
  `SelectControl` Twitter card type.
- **`HomepagePanel`** (el actual `GeneralPanel`, renombrado): solo `home_title` / `home_description`
  (`TemplateField`). Pierde separador y robots (que se van a Meta Global).
- `renderPanel` enruta `'meta-global'` → `MetaGlobalPanel`; `'homepage'` (o legacy `'general'`) →
  `HomepagePanel`.

**Componentes nuevos reutilizables (`components/`):**

- **`MediaField`** — uploader de medios. Usa **solo `MediaUpload` de `@wordpress/media-utils`** (NO
  `MediaUploadCheck`: ese símbolo se exporta desde `@wordpress/block-editor` y depende del store
  `core/block-editor`, que no está disponible de forma fiable en una página admin React fuera del
  editor de bloques — Gutenberg #40698). El guard de capacidad lo da la página entera (gateada por
  `manage_options`) más el `upload_files` del usuario; no se necesita `MediaUploadCheck`. Props:
  `label`, `value` (URL), `onChange(url)`. Render-prop de `MediaUpload`: botón "Seleccionar imagen" /
  "Reemplazar" + botón "Quitar"; muestra preview si hay valor. `onSelect={ (media) => onChange(media.url) }`,
  `allowedTypes={ ['image'] }`. Guarda **solo la URL** (la key `og_default_image` es URL; `esc_url_raw`
  ya la sanea). Reutilizable por el logo de SEO Local después.
- **`SeparatorField`** — grupo de botones con los 6 caracteres de Rank Math
  (`['-','–','—','»','|','•']`, almacenados como carácter UTF-8 literal) + un `TextControl` "personalizado"
  que preserva la capacidad de valor libre actual. Bindea `title_separator`. El botón activo se marca
  con `isPressed`.
- **`AdvancedRobotsField`** — 3 filas: cada una un `CheckboxControl` (`enabled`) + control de valor
  (`TextControl` numérico para snippet y video length; `SelectControl` Large/Standard/None para image
  preview). Escribe en `advanced_robots` con actualización inmutable.

**Helper JS puro (`assets/src/admin/advancedRobots.js`, testeable):**

```js
// Actualización inmutable de una sub-clave de advanced_robots.
export function setAdvancedRobots( map, block, field, value ) { … }
export const MAX_IMAGE_PREVIEW_OPTIONS = [ Large, Standard, None ];   // con __()
export const SEPARATOR_PRESETS = [ '-', '–', '—', '»', '|', '•' ];
```

**`views/Social.js`:** se reemplaza el `TextControl` por un `Notice` informativo (status `info`,
`isDismissible={false}`) que indica **en texto** que la imagen social por defecto se gestiona ahora en
*OpenSEO → Titles & Meta → pestaña Meta Global*. No se intenta deep-link a la sub-pestaña: las pestañas
de `VerticalTabs` son estado interno (`useState`), no URLs propias (M4). Importante: `Social.js`
**deja de usar `SettingsPanel`** y renderiza el `Notice` directo — sin campos editables no debe quedar
un `SaveBar` huérfano/activo al pie. No edita `og_default_image` (fuente única en Meta Global — D2).

### 4. Enqueue de medios — `Admin\Assets`

`Admin\Assets::enqueue()` llama `wp_enqueue_media()` en las pantallas de OpenSEO para que el modal de
la biblioteca de medios esté disponible (plantillas Backbone + `wp.media`). La dependencia
`@wordpress/media-utils` la detecta `@wordpress/scripts` desde el `import` y la añade al
`*.asset.php` (handle `wp-media-utils`); `wp_enqueue_media()` es necesario adicionalmente para registrar
las plantillas del modal.

### 5. Bootstrap

No se necesitan datos nuevos del servidor: los enums (caracteres de separador, opciones de image
preview, tipos de tarjeta) viven como constantes en JS. `window.openseoAdmin.settings` ya incluye las
keys nuevas vía `Options::all()`.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Support\Str` (nuevo) | `mb_ucwords()` puro multibyte | — |
| `Meta\Resolver` (mod) | `title()` con capitalización; `robots()` con advanced parts; `twitter_card()` | `Support\Str`, `Options` |
| `Frontend\Head\Twitter` (mod) | `twitter:card` desde `Resolver::twitter_card()` | `Resolver` |
| `Settings\Options` (mod) | defaults + sanitize de `capitalize_titles`, `twitter_card_type`, `advanced_robots` | helpers existentes |
| `Admin\Assets` (mod) | `wp_enqueue_media()` en pantallas OpenSEO | — |
| `components/MediaField` (nuevo) | uploader de medios (URL) | `@wordpress/media-utils` |
| `components/SeparatorField` (nuevo) | selector de carácter separador + custom | `advancedRobots.js` (presets) |
| `components/AdvancedRobotsField` (nuevo) | 3 filas checkbox+valor de advanced robots | `advancedRobots.js` |
| `advancedRobots.js` (nuevo) | `setAdvancedRobots`, constantes de enums/presets (puro) | — |
| `views/Titles.js` (mod) | tabs Meta Global / Página de inicio; paneles | componentes nuevos |
| `views/Social.js` (mod) | aviso → Meta Global | — |

## Manejo de errores y casos límite

- **Capitalizar con título vacío:** `capitalize()` devuelve `''` sin tocar (no rompe la cascada
  "vacío = WordPress decide").
- **`mb_ucwords` con multibyte/acentos/whitespace múltiple:** preserva el resto de cada palabra y los
  separadores originales; no colapsa espacios (eso ya lo hace `Variables::replace` antes).
- **Capitalización de marcas/acrónimos (L3):** `capitalize()` actúa sobre el título **ya ensamblado**
  (incluye `%sitename%` y demás tokens resueltos), así que fuerza la inicial de cada palabra incluido el
  nombre del sitio; un acrónimo tipo "iPhone" verá su inicial subida ("IPhone"), preservando el resto.
  Es **paridad con Rank Math** (mismo comportamiento de su `Str::mb_ucwords`) y queda **aceptado**; quien
  no lo quiera, no activa el toggle.
- **Advanced robots con noindex o nosnippet efectivos:** no se emiten (bail), aunque estén habilitados.
- **`max-*-preview` length `-1`:** se emite literal (`max-snippet:-1` = sin límite), igual que Rank Math.
- **`advanced_robots` con sub-claves desconocidas o tipos raros:** sanitize las descarta / normaliza al
  default; el Resolver lee con guards `is_array`.
- **`twitter_card_type` inválido:** sanitize → `summary_large_image`; `Resolver::twitter_card()`
  revalida por si el option se tocó por fuera.
- **`summary_large_image` sin `twitter:image` (M3):** al desacoplar card de presencia de imagen, una
  página sin imagen declarará `summary_large_image` igualmente y se omitirá el tag `twitter:image`
  (vacío). El validador de X degrada a `summary` en su render. Es **paridad con Rank Math** y queda
  aceptado (D4): el tipo es global y configurable a `summary` si se prefiere.
- **Separador custom vacío:** `title_separator` `''` ya lo maneja `Variables::replace` (la rama de
  strip de separadores se salta si está vacío).
- **OG image URL inválida:** `esc_url_raw` la limpia; `MediaField` solo entrega URLs de la biblioteca.
- **Sin `wp_enqueue_media`:** el botón del uploader no abriría el modal — por eso el enqueue es parte
  del alcance.
- **Seguridad/i18n:** sanitize en entrada, escape en salida (presenters ya escapan); todas las cadenas
  por `__()`; sin `dangerouslySetInnerHTML`; el uploader usa solo URLs de attachments.

## Testing

**PHP unit (Brain Monkey):**
- `Support\StrTest` (nuevo): `mb_ucwords` — ASCII, acentos/UTF-8, múltiples espacios preservados,
  cadena vacía, palabra con mayúsculas internas preservadas.
- `ResolverTest` (ampliar):
  - `title()` capitaliza cuando `capitalize_titles='1'` y respeta el original cuando `''`; vacío → vacío.
  - `robots()` anexa `max-snippet`/`max-video-preview`/`max-image-preview` cuando están habilitados y el
    resultado es index/sin nosnippet; default (todo disabled) → string sin avanzados (no rompe tests
    existentes).
  - **(L2)** caso explícito de no-regresión: advanced robots **habilitados** + `nosnippet` efectivo
    activo simultáneamente → el bail gana y **NO** se anexan avanzados (verifica el orden: bail antes de
    `array_merge`). Ídem con `noindex` efectivo + advanced habilitados.
  - `twitter_card()` devuelve el valor configurado y revalida fuera de lista.
- `OptionsTest` (ampliar): sanitize de `capitalize_titles` (checkbox), `twitter_card_type` (whitelist),
  `advanced_robots` (enabled `'1'/''`, length entero con `-1`, value whitelist, claves desconocidas
  descartadas), y **(L4)** un `title_separator` multibyte (p. ej. `—`, `»`, `•`) sobrevive
  `sanitize_text_field` sin mutilarse.

**JS (Jest):**
- `advancedRobots.test.js`: `setAdvancedRobots` (merge inmutable, no muta, preserva otros bloques);
  forma de `SEPARATOR_PRESETS` / `MAX_IMAGE_PREVIEW_OPTIONS`.

**Gates:** `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`, `npm run lint:js`,
`npm run test:js`, `npm run build` — todos verdes.

**Smoke test manual (wp-env):** en *OpenSEO → Titles & Meta → Meta Global*: cambiar separador y ver el
título; activar capitalizar y confirmar título capitalizado en el front; habilitar max-image-preview y
ver `max-image-preview:large` en `<meta robots>`; subir una imagen OG por defecto y verificar
`og:image` de fallback; cambiar Twitter card a `summary` y ver `twitter:card` en el front.

## Criterios de aceptación

- Pestaña **"Meta Global"** (primera) en Titles & Meta con: selector de separador, capitalizar títulos,
  robots global + noindex de vacíos (reubicados), metadatos avanzados de robots, miniatura OpenGraph con
  uploader real, y tipo de tarjeta de Twitter.
- Pestaña actual renombrada a **"Página de inicio"** con solo título/descripción del home.
- `capitalize_titles` capitaliza el título resuelto (documento + cascada social) cuando está activo.
- El `<meta robots>` incluye `max-snippet` / `max-video-preview` / `max-image-preview` cuando están
  habilitados y el robots efectivo lo permite (no noindex/nosnippet).
- `twitter:card` refleja `twitter_card_type` (default `summary_large_image`, configurable a `summary`).
- La miniatura OpenGraph por defecto se gestiona **solo** desde Meta Global (uploader de medios); la
  vista Social remite allí.
- Sin regresión: separador, robots global y OG fallback siguen funcionando con sus keys actuales; los
  tests existentes de `Resolver`/`Twitter`/`Options` siguen verdes.
- Gates verdes (lint/analyze/test:unit/lint:js/test:js/build).
