# Diseño — Titles & Meta: páginas especiales (Inicio · Autores · Otras páginas)

- **Fecha:** 2026-06-22
- **Estado:** Aprobado para planificación
- **Área:** Resolución SEO de superficies no-singulares (frontend) + admin React
- **Tipo:** Sub-proyecto de la consolidación de Titles & Meta (inspirado en Rank Math)

## Contexto

Hoy `Meta\Resolver` solo resuelve **tres superficies**: singular, taxonomía y portada de últimas
entradas (`is_front_page()`). El título de portada (`home_title`) y su descripción (`home_description`)
ya existen como claves en `openseo_settings`. No hay resolución para **archivos de autor**, **resultados
de búsqueda**, **404**, ni controles de **paginación** o **contenido protegido por contraseña**. Tampoco
hay robots/OpenGraph propios para la portada, ni forma de desactivar los archivos de autor o de fecha.

Las tres pantallas de Rank Math que consolidamos —**Página de inicio**, **Autores** y **Otras
páginas**— se mapean sobre piezas que ya existen: el cascadeo del `Resolver`, el `TemplateField` con
inserter de variables, los `RobotsFields`/checkboxes y los sanitizadores de `Options`. El grueso del
trabajo son **ramas nuevas en el `Resolver`**, **claves nuevas en `Options`**, **tres paneles React** y
**tres tokens nuevos**. El único módulo nuevo es un `Hookable` de redirección (`template_redirect`).

### Decisiones congeladas (brainstorming 2026-06-22)

1. **Alcance "núcleo" para Autores:** título/descripción/robots de autor + toggle activar/desactivar
   archivos de autor. **Se difieren** las dos piezas pesadas: la base de URL `/author/` (rewrite +
   flush) y la caja SEO por-perfil de usuario.
2. **Sin robots avanzados por superficie** (max-snippet/max-video-preview/max-image-preview): portada y
   autor heredan el `advanced_robots` global. Se mantiene la simplicidad del `Resolver`.
3. **"Compartición mejorada en Slack" descartada** (YAGNI; específica de Slack).
4. **Enfoque de arquitectura:** extender `Resolver` en sitio con helpers privados pequeños (una rama por
   superficie), no una clase `SpecialPagesResolver` aparte. `robots()` se descompone para no exceder 50
   líneas.

## Objetivo

Que OpenSEO emita título, descripción, robots y OpenGraph correctos en la portada de entradas, los
archivos de autor, los resultados de búsqueda y el 404; que permita noindex granular para paginación,
búsqueda y contenido protegido por contraseña; y que permita desactivar (redirigiendo a portada) los
archivos de autor y de fecha. Todo configurable desde tres sub-pestañas React de Titles & Meta.

## No-objetivos

- Base de URL de autor configurable (`/author/` → otro slug) y su rewrite/flush. **Diferido.**
- Caja SEO por-perfil de usuario (override de título/desc/robots por autor individual). **Diferido.**
- Robots avanzados numéricos por superficie (portada/autor) — heredan el global.
- Compartición mejorada en Slack.
- `og:url`/canonical para archivos no-singulares (hoy `canonical()` devuelve `''` fuera de singular; se
  mantiene — los archivos no emiten `og:url`). Posible mejora futura.
- Plantilla de título para archivos de fecha (Rank Math solo ofrece el toggle de desactivado; no hay
  campo de título de fecha).
- **Página de entradas/blog con portada estática (corrige H2):** cuando el sitio usa una página estática
  como portada, WordPress crea una **página de entradas** separada (`is_home() && ! is_front_page()`,
  p.ej. `/blog/`). Esa superficie es un **archivo**: no es `is_singular()` (luego su meta `_openseo_*` no
  se lee), no es `is_front_page()` (luego `home_*` no aplica) y no es autor/búsqueda/404/taxonomía, así
  que cae al `return ''` del `Resolver` → defaults de archivo (`index, follow`, sin título OpenSEO). Se
  **difiere** su SEO propio a una fase posterior; aquí se documenta como hueco consciente, no silencioso.
  (Implementación futura: rama `is_home() && ! is_front_page()` leyendo el meta de la página
  `get_option('page_for_posts')`.)

## Arquitectura

### 1. Modelo de datos — claves nuevas en `Options::defaults()`

**Página de inicio**
```php
'home_robots_custom'  => '',          // toggle: usar robots propios de la portada
'home_robots'         => array(),     // mapa directiva => '1' (absoluto cuando custom on)
'home_og_title'       => '',
'home_og_description' => '',
'home_og_image'       => '',
```

**Autores**
```php
'author_archives'      => '1',                         // '' = desactivar (redirige a portada)
'author_title'         => '%name% %sep% %sitename%',
'author_description'   => '',
'author_robots_custom' => '',
'author_robots'        => array(),
```

**Otras páginas**
```php
'date_archives'               => '1',                          // '' = desactivar (redirige a portada)
'title_404'                   => 'Page Not Found %sep% %sitename%',
'search_title'                => '%search_query% %sep% %sitename%',
'noindex_search'              => '1',
'noindex_paginated'           => '',
'noindex_paginated_singular'  => '',
'noindex_password_protected'  => '',
```

> **Sin migración:** `Options::all()` mezcla `defaults()` sobre lo guardado, así que las instalaciones
> existentes heredan estos valores al leerse (la clave ausente toma el default). Los defaults de
> plantilla (`author_title`, `title_404`, `search_title`) se siembran como **valor por defecto** (patrón
> de `home_title`), no como placeholder; si el usuario los vacía, el `Resolver` cae a un método de
> `TemplateDefaults` para no emitir un título vacío.

`home_robots`/`author_robots` son mapas **planos** `directiva => '1'` (checkboxes absolutos, no
tri-estado), con las 5 directivas estándar (`noindex, nofollow, noarchive, nosnippet, noimageindex`). No
incluyen `noindex_empty_terms` (eso es solo de taxonomías).

### 2. `Resolver` — ramas nuevas

**`resolve_title()`** — orden de evaluación (las query-conditions de WP son mutuamente excluyentes):
```
singular → taxonomía → is_front_page() → is_author() → is_search() → is_404()
```
- `is_front_page()`: ya existe (`home_title`). **Nota:** se alcanza solo en la portada de *últimas
  entradas*; con página estática, `is_singular()` gana antes y se usa el meta de esa página — idéntico a
  Rank Math.
- `is_author()`: `home`-style → `variables->replace( author_title ?: defaults->author_title(),
  TemplateContext::for_author( get_queried_object_id() ) )`.
- `is_search()`: `variables->replace( search_title ?: defaults->search_title(),
  TemplateContext::for_search() )`.
- `is_404()`: `variables->replace( title_404 ?: defaults->not_found_title() )` (sin contexto, pero
  `%page%` no aplica en 404).

**`description()`** — añade `is_author()` → `author_description` (vacío permitido → sin tag). Búsqueda y
404 no emiten descripción (devuelven `''`).

**`robots()`** — se **descompone** para respetar el límite de 50 líneas:
- `base_robots()`: la cascada actual (singular/taxonomía/global) **más** las ramas nuevas de superficie:
  - Portada de entradas (`is_front_page() && ! is_singular()`) con `home_robots_custom === '1'` → las 5
    directivas salen del mapa `home_robots` (absoluto; lo ausente = index/follow).
  - `is_author()` con `author_robots_custom === '1'` → las 5 del mapa `author_robots`.
  - `is_search()` con `noindex_search === '1'` → `noindex` forzado (follow).
- `special_noindex()`: capa de **overlay** que cruza todas las superficies y, si aplica, **fuerza
  `noindex = true`**:
  - `noindex_paginated` y `is_paged()` (paginación de **listados**: archivos y portada de entradas,
    `$paged > 1`).
  - `noindex_paginated_singular` y singular multipágina (`is_singular()` y `(int) get_query_var('page')
    > 1`).
  - `noindex_password_protected` y `is_singular()` y `post_password_required()`.
- **Orden (corrige M2):** el overlay muta `$effective['noindex'] = true` **antes** del ensamblado del
  string — exactamente el mismo mecanismo que el `$force_noindex_empty` actual (que ya muta
  `$effective['noindex']` justo antes de `robots_parts()`). Así, la regla existente "omitir
  `advanced_robots` cuando `noindex` o `nosnippet`" se respeta **automáticamente**: un noindex forzado por
  paginación/contraseña suprime los `max-snippet`/`max-image-preview` globales sin lógica extra.
- El ensamblado del string (index/follow + extras + advanced robots cuando no es noindex/nosnippet) no
  cambia respecto a hoy; los `advanced_robots` siguen siendo los **globales**.

> **Cambio observable (intencional):** robots/título/descripción empiezan a actuar en autor, búsqueda y
> 404 donde antes el `Resolver` devolvía `''`/`index, follow`. Coherente con la dirección de la fase.

**OpenGraph de portada** — `social_title()`, `social_description()`, `social_image()` añaden la rama
**portada de entradas** (`is_front_page() && ! is_singular()`):
- `social_title()` → `home_og_title` ?: título resuelto.
- `social_description()` → `home_og_description` ?: descripción resuelta.
- `social_image()` → `home_og_image` ?: `og_default_image` global.

El helper `meta_value()` actual solo lee post-meta (singular); para la portada de entradas no hay post,
así que estas ramas leen las **opciones** `home_og_*` directamente antes del fallback. Twitter hereda
sin cambios (ya cae sobre los valores OG).

**OG por superficie (corrige M1)** — qué emite `OpenGraph::output()` en cada caso (verificado contra
`OpenGraph.php` + `Resolver::social_*`):

| Superficie | `og:title` | `og:description` | `og:image` | `og:url` |
|---|---|---|---|---|
| Portada de entradas | `home_og_title` ?: título resuelto | `home_og_description` ?: descr resuelta | `home_og_image` ?: default | — (canonical `''`) |
| Autor | título resuelto (autor) | `author_description` (omitido si `''`) | `og_default_image` | — |
| Búsqueda / 404 | título resuelto | — (omitido, `description()` = `''`) | `og_default_image` | — |

> `og:url` se omite en todas las no-singulares porque `canonical()` devuelve `''` ahí (No-objetivo
> declarado). Los campos OG **propios** existen solo para la portada; autor/búsqueda/404 reutilizan los
> valores resueltos sin campos nuevos.

### 3. Tokens nuevos — `Variables` + `VariableCatalog` + `TemplateContext`

| Token | Scope | Valor |
|---|---|---|
| `%name%` | `author` | nombre para mostrar del autor (**crudo**; ver "Escape del título") |
| `%search_query%` | `search` | término buscado (**crudo**; ver "Escape del título") |
| `%page%` | `global` | "Page X of Y" en paginadas; `''` si no |

- `Variables::replace()` añade las tres entradas al array de reemplazos (`%name%` → `$context->name`,
  `%search_query%` → `$context->search_query`, `%page%` → `$context->page`).
- `TemplateContext` gana los campos `readonly string $name`, `$search_query`, `$page` (todos default
  `''`) y dos factories nuevas:
  - `for_author( int $author_id )`: `name = get_the_author_meta('display_name', $author_id)` (**crudo**),
    `page =` string de paginación.
  - `for_search()`: `search_query = get_search_query( false )` (forma **cruda** vía `get_query_var('s')`),
    `page =` string de paginación.
  - El string `%page%` ("Page X of Y") se calcula en la factory (contexto WP, con `__()`) para que
    `Variables` siga sin tocar i18n directamente. Se rellena cuando `$paged >= 2`; vacío si no.

#### Escape del título (corrige C1)

El pipeline tiene una **asimetría de escape** que el diseño debe respetar: los presentadores
`Description`, `Robots`, `OpenGraph` y `Twitter` escapan su propia salida (`esc_attr`/`esc_url`), pero
**`Title` no escapa nada** — `pre_get_document_title` devuelve el string tal cual y `wp_get_document_title()`
del core **tampoco lo re-escapa**, así que el `<title>` es el único sink sin escape del pipeline. Por
eso los tokens dinámicos derivados de entrada del usuario (`%search_query%` vía `?s=`, `%name%` editable)
deben mantenerse **crudos** en `TemplateContext` (un solo lugar de la verdad) y escaparse **en el punto
de salida de cada presentador**:

- `Title::filter_title()` aplica `esc_html()` al título resuelto antes de devolverlo (cambio de una
  línea). Esto neutraliza el XSS reflejado por `?s=<script>` y muestra el término legible (no entidades).
- `OpenGraph`/`Twitter` siguen escapando con `esc_attr` su **propia copia cruda** del título resuelto —
  sin doble escape, porque `TemplateContext` lleva el valor crudo (no pre-escapado como haría
  `get_search_query(true)`).

> Por qué **no** `get_search_query( true )`: aplica `esc_attr` (contexto de **atributo**), no `esc_html`
> (contexto de **texto** entre `<title>…</title>`); dejaría entidades visibles en el `<title>` y un
> **doble escape** en `og:title` (`&amp;amp;`). El término crudo + escape en cada sink es lo correcto.
- `variablesForScope` (JS, ya existente) filtra por `v.scope === 'global' || v.scope === scope`, así que
  funciona con cualquier scope nuevo sin cambios: los campos de autor (scope `author`) y búsqueda (scope
  `search`) muestran sus tokens propios + los globales (incluido `%page%`). El `VariableInserter` pasa el
  `scope` como prop literal, sin enum cerrado que rechace los nuevos. **Pendiente menor (H1):** ampliar el
  JSDoc de `variablesForScope` (`@param scope 'global' | 'singular' | 'taxonomy' | 'author' | 'search'`)
  para evitar drift de documentación.
- **Anti-drift:** el test que exige que el catálogo coincida con `Variables::replace()` se actualiza con
  los tres tokens nuevos.

### 4. Módulo nuevo — `Frontend\ArchiveRedirect` (Hookable)

```php
final class ArchiveRedirect implements Hookable {
    public function __construct( private readonly Options $options ) {}
    public function register(): void {
        add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
    }
    public function maybe_redirect(): void {
        if ( is_author() && '1' !== (string) $this->options->get( 'author_archives' ) ) {
            wp_safe_redirect( home_url( '/' ), 301 ); exit;
        }
        if ( is_date() && '1' !== (string) $this->options->get( 'date_archives' ) ) {
            wp_safe_redirect( home_url( '/' ), 301 ); exit;
        }
    }
}
```
- Prioridad **1** (antes de `redirect_canonical`@10 y del resto de la pipeline de plantilla).
- Se registra en la lista **always-on** de `Plugin::modules()` (es comportamiento de front-end, como los
  presentadores del head; `template_redirect` no se dispara en admin).
- `wp_safe_redirect` evita open-redirect (destino interno fijo = portada). No hay riesgo de bucle: la
  portada no es archivo de autor ni de fecha.
- **(L3) Coste por request:** los conditional tags (`is_author()`/`is_date()`) se evalúan **antes** de
  leer `Options` gracias al short-circuit `&&`, así que en una página normal (la mayoría) **no** se lee
  ninguna opción. `Options::get()` recae sobre `get_option` (cacheado por el object cache de WP tras la
  primera lectura del request). Coste despreciable.

### 5. Sanitize — `Options::sanitize()`

Se añaden las claves nuevas a los grupos ya existentes:
- **Texto** (`sanitize_text_field`): `home_og_title`, `author_title`, `title_404`, `search_title`.
- **Textarea** (`sanitize_textarea_field`): `home_og_description`, `author_description`. (Hoy
  `home_description` usa `sanitize_text_field`; para los multilínea nuevos se usa
  `sanitize_textarea_field`, que es lo correcto para descripciones.)
- **Checkbox** (`'1'`/`''`): `home_robots_custom`, `author_robots_custom`, `author_archives`,
  `date_archives`, `noindex_search`, `noindex_paginated`, `noindex_paginated_singular`,
  `noindex_password_protected`. Se suman al loop de checkboxes existente (cada uno con su campo hidden
  companion en el form React, igual que el resto de toggles).
- **URL** (`esc_url_raw`): `home_og_image` (se suma al loop de URLs existente).
- **Mapas de robots** (`home_robots`, `author_robots`): loop nuevo que, por cada directiva conocida
  (`noindex, nofollow, noarchive, nosnippet, noimageindex`), guarda `'1'` cuando el input es `'1'`;
  ignora claves desconocidas. Mismo patrón que el sanitize del `robots` global pero sin
  `noindex_empty_terms`. **(L2)** El mapa **persiste aunque el `*_robots_custom` esté off**; el `Resolver`
  solo lo lee cuando el toggle custom es `'1'`, así que conservar el mapa apagado es inocuo y preserva la
  selección del usuario entre activaciones.

### 6. `TemplateDefaults` — fallbacks últimos

Métodos nuevos (literales puros, sin WordPress, como los actuales): `author_title()` →
`'%name% %sep% %sitename%'`, `search_title()` → `'%search_query% %sep% %sitename%'`,
`not_found_title()` → `'Page Not Found %sep% %sitename%'`. Son el fallback que usa el `Resolver` cuando
la opción correspondiente está vacía (el usuario la borró). Los defaults sembrados en `Options` y estos
métodos coinciden, manteniendo una sola fuente de verdad conceptual.

### 7. UI React — tres paneles en `views/Titles.js`

Tabs nuevos en el grupo superior de `GROUPS`, tras `homepage`: **`authors`** y **`other-pages`**. Orden
final del grupo: Meta Global · SEO Local · Homepage · Authors · Other pages (+ Content types/Taxonomies).

> La pestaña "Meta social" de Rank Math **no** aplica: OpenSEO ya tiene **Social** como submenú propio
> (`views/Social.js`). Los campos OpenGraph de la portada van **dentro** del tab Homepage, tal como la
> captura de Rank Math los muestra en la pantalla de inicio.

- **`HomepagePanel`** (ampliar el existente): mantiene los dos `TemplateField` (título/descr) y añade:
  - `ToggleControl` "Custom homepage robots" (`home_robots_custom`) que revela 5 `CheckboxControl`
    (`home_robots`), reutilizando el patrón de robots de `MetaGlobalPanel`.
  - 3 campos OG: `TemplateField` (`home_og_title`, scope `global`), `TemplateField` multilínea
    (`home_og_description`), `MediaField` (`home_og_image`).
- **`AuthorsPanel`** (nuevo): `ToggleControl` archivos de autor (`author_archives`); `TemplateField`
  título (`author_title`, scope `author`) y descripción multilínea (`author_description`, scope
  `author`); `ToggleControl` robots custom (`author_robots_custom`) + 5 `CheckboxControl`
  (`author_robots`).
- **`OtherPagesPanel`** (nuevo): `ToggleControl` archivos por fecha (`date_archives`); `TemplateField`
  404 (`title_404`) y búsqueda (`search_title`, scope `search`); 4 `ToggleControl` de noindex
  (`noindex_search`, `noindex_paginated`, `noindex_paginated_singular`, `noindex_password_protected`).
- `renderPanel()` enruta `authors` → `AuthorsPanel` y `other-pages` → `OtherPagesPanel`.
- **Componentes:** se reúsan `TemplateField`, `MediaField`, `CheckboxControl`, `ToggleControl`,
  `SettingsPanel`, `VerticalTabs`. El bloque de 5 `CheckboxControl` de robots hoy vive **inline** en
  `MetaGlobalPanel` (no es un componente). Para no triplicarlo (MetaGlobal + Homepage + Authors), **(L1)**
  se extrae un componente pequeño `RobotsCheckboxes({ map, onChange })` que rinde los 5 checkboxes sobre
  un mapa `directiva => '1'`, y los tres paneles lo consumen. Es la única pieza React verdaderamente nueva
  y mantiene DRY.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Settings\Options` (mod) | defaults + sanitize de las ~17 claves nuevas | helpers existentes |
| `Meta\Resolver` (mod) | ramas título/descr/robots/OG para autor/búsqueda/404/portada + overlay noindex | `Variables`, `TemplateContext`, `TemplateDefaults`, `Options`, conditional tags |
| `Frontend\Head\Title` (mod) | aplicar `esc_html()` al título resuelto (único sink sin escape — C1) | `Resolver` |
| `Meta\TemplateContext` (mod) | factories `for_author`/`for_search` + campos `name`/`search_query`/`page` | WP reads en factories |
| `Meta\Variables` (mod) | reemplazo de `%name%`/`%search_query%`/`%page%` | `TemplateContext` |
| `Meta\VariableCatalog` (mod) | 3 tokens nuevos (scopes `author`/`search`/`global`) | — |
| `Meta\TemplateDefaults` (mod) | `author_title()`/`search_title()`/`not_found_title()` (puros) | — |
| `Frontend\ArchiveRedirect` (nuevo) | redirige autor/fecha desactivados a portada (301) | `Options`, conditional tags |
| `Plugin` (mod) | registra `ArchiveRedirect` en módulos always-on | — |
| `components/RobotsCheckboxes` (nuevo) | 5 checkboxes sobre un mapa `directiva => '1'` (L1, DRY) | `robots.js` (`ROBOTS_DIRECTIVES`) |
| `views/Titles.js` (mod) | `HomepagePanel` ampliado + `AuthorsPanel`/`OtherPagesPanel` + ruteo | `TemplateField`, `MediaField`, `RobotsCheckboxes`, controles WP |

## Manejo de errores y casos límite

- **Portada estática vs. últimas entradas:** `is_singular()` se evalúa primero; la página estática usa
  su propio meta. `home_*` (título/robots/OG) solo aplican a la portada de entradas
  (`is_front_page() && ! is_singular()`).
- **Plantilla vacía:** si `author_title`/`search_title`/`title_404` se vacían, el `Resolver` cae al
  método de `TemplateDefaults` para no emitir título vacío.
- **`%page%` sin paginación / 404:** la factory deja `page = ''`; el `Variables` colapsa el token vacío y
  recorta separadores colgantes (lógica ya existente).
- **Autor inexistente / id 0:** `get_the_author_meta` devuelve `''`; el título cae al resto del template.
- **Robots custom on con mapa vacío:** sale `index, follow` (ninguna directiva activa) — comportamiento
  esperado de "custom pero todo permitido".
- **Overlay noindex:** un noindex forzado por paginación/contraseña gana sobre el `base_robots()` aunque
  la superficie dijera index.
- **Redirección:** `wp_safe_redirect` + `exit`; destino interno fijo; sin bucles.
- **Seguridad/i18n:** sanitize en entrada, escape en salida; `wp_unslash` por clave explícita en
  sanitize; cadenas por `__()`; text domain `openseo`. **Escape (C1):** `%search_query%`/`%name%` viajan
  **crudos** en `TemplateContext`; `Title::filter_title()` aplica `esc_html()` (el `<title>` del core no
  escapa) y `OpenGraph`/`Twitter`/`Description` escapan su propia copia (`esc_attr`/`esc_url`) — sin doble
  escape. Esto cierra el XSS reflejado por `?s=` y los display names con `<`.

## Testing

**PHP unit (Brain Monkey):**
- `OptionsTest` (ampliar): sanitize de cada clave nueva — checkboxes `'1'`/`''`; texto/textarea;
  `home_og_image` por `esc_url_raw`; mapas `home_robots`/`author_robots` (whitelist de directivas,
  ignora desconocidas); defaults presentes en `defaults()`.
- `ResolverTest` (ampliar): título autor/búsqueda/404 (mock `is_author`/`is_search`/`is_404` +
  contextos); descripción autor; robots — portada custom, autor custom, `noindex_search`, y overlay
  (`is_paged`, singular multipágina, `post_password_required`); OG de portada (`home_og_*` con y sin
  fallback). Confirmar que los tests existentes (singular/taxonomía/front-page) **siguen verdes**.
- `VariablesTest`/anti-drift del catálogo (ampliar): `%name%`/`%search_query%`/`%page%` se expanden y el
  catálogo coincide con `Variables::replace()`.
- `TemplateContextTest` (si existe / añadir): `for_author`/`for_search` rellenan los campos esperados.
- `ArchiveRedirectTest` (nuevo): redirige cuando el toggle está off y la condición se cumple; **no**
  redirige cuando está on o la condición no aplica (mock de `wp_safe_redirect`/conditional tags).
- `TitleTest` (ampliar/añadir, **C1**): `filter_title()` aplica `esc_html()` al título resuelto; una
  búsqueda `?s=<script>` produce un título sin `<script>` ejecutable (entidades); un `%name%` con `<` en
  el display name sale escapado. Regresión de XSS reflejado.

**JS (Jest):** los tres paneles son presentacionales y reúsan helpers ya cubiertos
(`setTemplateField`/`setRobotsField`); no se añaden helpers puros nuevos. Se corre `npm run lint:js`
(gate del proyecto: Prettier/jsdoc/jsx-a11y) además de `npm run test:js`.

**Integración (wp-env, opcional / smoke manual):**
- Portada de entradas: `home_og_*` aparecen en el `<head>`; `home_robots_custom` tiñe el `<meta
  robots>`.
- `/author/<x>`: título/descr/robots propios; con `author_archives` off, 301 a portada.
- Búsqueda: título con `%search_query%`; `noindex_search` → `noindex`.
- 404: título `title_404`.
- Archivo de fecha con `date_archives` off → 301 a portada.

## Criterios de aceptación

- Portada de últimas entradas: robots propios (toggle + checkboxes) y OG propio (título/descr/imagen),
  con fallback al resuelto / `og_default_image`.
- Archivos de autor: título y descripción por plantilla (con `%name%`/`%page%`), robots propios, y toggle
  de activación que (off) redirige a portada con 301.
- Resultados de búsqueda: título por plantilla (`%search_query%`/`%page%`) y noindex configurable (on por
  defecto).
- 404: título por plantilla.
- Noindex granular: paginación de archivos, paginación de singulares multipágina, y contenido protegido
  por contraseña.
- Archivos de fecha: toggle de activación que (off) redirige a portada con 301.
- Tres sub-pestañas React (Homepage ampliada, Authors, Other pages) persisten vía `openseo/v1/settings`.
- Sin regresión en singular/taxonomía/front-page existentes.
- Gates verdes: `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`,
  `npm run lint:js`, `npm run test:js`, `npm run build`. `languages/openseo.pot` regenerado.
