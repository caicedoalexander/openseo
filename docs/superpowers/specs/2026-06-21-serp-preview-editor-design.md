# Diseño — SERP/snippet preview en vivo en el editor

- **Fecha:** 2026-06-21
- **Estado:** Aprobado para planificación
- **Área:** Editor (panel SEO de Gutenberg)
- **Tipo:** Sub-proyecto 2 de la consolidación de Titles & Meta

## Contexto

El panel SEO del editor (`assets/src/editor/`) ya tiene una pestaña *General* con SEO title,
meta description, contadores planos (`N/60`, `N/160`), botones "Generate with AI" y un
**snippet preview básico** (`buildSnippetPreview`): junta `title + sep + siteName` y trunca la
descripción a 160. Tiene tres limitaciones clave frente a un "preview del navegador" real:

1. El separador está **hardcodeado a `-`** (no usa `title_separator`).
2. El preview **solo refleja el override tecleado**; con los campos vacíos (el caso normal) no
   resuelve el template del tipo de contenido — muestra el sitio a secas, lo cual es engañoso.
3. Sin **favicon/URL/breadcrumb**, sin **toggle escritorio/móvil**, sin **barras de longitud con
   color** (solo el contador plano), sin **badge noindex**.

Este sub-proyecto consume el cimiento de templates por tipo (sub-proyecto 1) para mostrar un
SERP preview fiel y en vivo.

### Decisiones congeladas (brainstorming 2026-06-21)

1. **Resolución en vivo en cliente:** el preview se calcula en el navegador, espejando la cascada
   del `Resolver`. El template efectivo del tipo se calcula en PHP y se pasa al editor; en JS solo
   se expanden tokens.
2. **Alcance visual completo** estilo Google/Rank Math: favicon + URL/breadcrumb + título +
   descripción resueltos + toggle Escritorio/Móvil + barras de longitud con color + badge noindex.
   Reemplaza el preview básico de la pestaña *General*.
3. **Sin permalink editable** (el slug se sigue editando en el panel nativo de WordPress).

## Objetivo

Que el editor muestre un preview de resultado de búsqueda fiel y en vivo: el título y la
descripción que **realmente** emitirá el frontend (override por entrada, o el template del tipo
con tokens expandidos), con chrome visual de navegador, indicadores de longitud y aviso de noindex.

## No-objetivos

- Permalink/slug editable desde el preview.
- Estrellas de rating / rich results en el preview.
- Medición exacta por píxeles (se usan límites por caracteres).
- Preview de redes sociales (Open Graph/Twitter) — es otra superficie.
- Resolución vía servidor (REST) — se descartó en favor de cliente en vivo.

## Arquitectura

### 1. Flujo de datos — resolución en vivo (espejo de la cascada PHP)

```
título efectivo = override _openseo_title (vivo)        || expand(titleTemplate, tokensVivos)
desc. efectiva  = override _openseo_description (vivo)   || expand(descriptionTemplate, tokensVivos)
```

- `titleTemplate` / `descriptionTemplate` = templates **efectivos del tipo del post actual**,
  calculados en **PHP** (`post_types[tipo] ?: TemplateDefaults::singular_*`) y pasados al editor.
  La cascada por tipo + default vive solo en PHP; en JS solo se **expanden tokens**.
- Tokens expandidos en JS leyendo el editor store en vivo:
  - `%title%` → `getEditedPostAttribute('title')`
  - `%excerpt%` → `getEditedPostAttribute('excerpt')`; si vacío, **aproximación best-effort**
    derivada del contenido (`getEditedPostContent()` → quitar comentarios de bloque `<!--…-->`,
    quitar tags, colapsar espacios, recortar). Ver nota de paridad de excerpt abajo.
  - `%sitename%`, `%tagline%`, `%sep%` → del bootstrap
  - `%currentyear%` → año **UTC** (`String(new Date().getUTCFullYear())`) para coincidir con
    `gmdate('Y')` de `Variables.php`.
- El override `_openseo_*` es un meta vivo del store; template y tokens también se resuelven en
  vivo. **Sin ida y vuelta al servidor.**

> **Paridad de `%excerpt%` (corrige H3):** en el frontend `TemplateContext::for_post` usa
> `wp_strip_all_tags(get_the_excerpt())`, que cuando no hay excerpt manual corre
> `wp_trim_excerpt()` (shortcodes/bloques + strip + 55 palabras, filtrable). Replicar eso en JS
> desde el markup de bloques serializado es inviable. Por tanto, **cuando el excerpt manual está
> vacío, el `%excerpt%` del preview es una estimación de cortesía**, no paridad byte-a-byte; el
> valor real lo calcula el servidor al guardar. La promesa "coincide con el frontend" aplica al
> título y a la descripción **con override o con excerpt manual**; el excerpt derivado es best-effort.

> Nota de paridad: el set de tokens del editor son los **singulares** (`%title%`, `%excerpt%`,
> `%sitename%`, `%tagline%`, `%sep%`, `%currentyear%`). `%term%`/`%term_description%` no aplican
> en el editor de una entrada. La expansión JS es una réplica acotada de `Variables.php`, fijada
> con tests, incluida la limpieza de separadores colgantes.

### 2. Datos nuevos en el bootstrap (`EditorPanel.php` → `window.openseoEditor`)

Se añaden:

```php
'titleTemplate'       => $type_templates->title_for( $post_type ),
'descriptionTemplate' => $type_templates->description_for( $post_type ),
'separator'           => (string) $options->get( 'title_separator' ),
'siteName'            => (string) get_bloginfo( 'name' ),
'tagline'             => (string) get_bloginfo( 'description' ),
'siteUrl'             => (string) home_url( '/' ),
'siteIcon'            => (string) get_site_icon_url(),   // '' si no hay
```

`EditorPanel` recibe `Options` + `Meta\TypeTemplates` inyectados (hoy se construye sin deps en
`Plugin.php`). **`TypeTemplates` se construye UNA vez en `Plugin::modules()`** y se inyecta tanto
en el `Resolver` como en `EditorPanel` (una sola fuente de defaults — corrige M3).

**Resolución del post type (corrige M2):** cadena explícita
`get_current_screen()?->post_type` → si vacío, `isset($GLOBALS['post']) ? get_post_type($GLOBALS['post']) : ''`
→ si vacío, `'post'`. Si el tipo no se determina, `TypeTemplates` cae a los defaults singulares
(nunca vacío). En el editor de entradas estándar (caso 99%) `get_current_screen()->post_type` es fiable.

**Retiro de fuente inconsistente (M4):** `GeneralTab` deja de leer `select('core').getSite()?.title`
para el nombre del sitio y usa `window.openseoEditor.siteName` (= `get_bloginfo('name')`, la misma
fuente que `Variables.php`), eliminando la divergencia actual.

### 3. Helper PHP compartido — `Meta\TypeTemplates` (DRY preview ↔ frontend)

Nueva clase que centraliza "template efectivo por tipo":

```php
final class TypeTemplates {
    public function __construct(Options $options, TemplateDefaults $defaults);
    public function title_for(string $post_type): string;        // post_types[type].title ?: singular_title()
    public function description_for(string $post_type): string;  // post_types[type].description ?: singular_description()
}
```

`Meta\Resolver` pasa a usar `TypeTemplates` **solo en la rama `is_singular()`** de `title()` y
`description()`, de modo que el preview del editor y el `<title>`/meta del frontend salen de la
**misma** fuente. Es un refactor pequeño y dirigido del código que tocamos.

> **Alcance del refactor (corrige H1):** hoy `Resolver::type_template($group, $slug, $field)` es un
> método privado **genérico** usado por las 4 ramas (post types **y** taxonomías). `TypeTemplates`
> cubre **solo** post types con la semántica idéntica `post_types[type][field] ?: default` (el
> fallback al default solo cuando el stored es `''`). El refactor sustituye **únicamente** las 2
> llamadas singulares (`title()`/`description()` post type) por `TypeTemplates`; las 2 llamadas de
> taxonomía siguen usando el método privado `type_template('taxonomies', …)` **sin cambios**. Los
> tests de taxonomía existentes (`test_title_resolves_taxonomy_with_default`,
> `test_description_resolves_taxonomy_template`) deben permanecer verdes tal cual — no se "amplían",
> se conservan como prueba de no-regresión.

### 4. Componentes React (`assets/src/editor/components/`)

- **`SerpPreview`** — tarjeta del resultado: fila de URL (favicon + breadcrumb derivado del
  permalink en vivo, con fallback a `siteUrl`), título (azul, truncado a su límite), descripción
  (gris, truncada), y aviso/badge **Noindex** cuando `_openseo_robots_noindex='1'`. Props:
  valores ya resueltos + `device` + `isNoindex` + `siteUrl`/`siteIcon`. Aplica clase
  `is-desktop` / `is-mobile`. Presentacional puro.
- **`PreviewDevices`** — toggle Escritorio/Móvil (dos botones); estado local en `GeneralTab`.
- **`LengthIndicator`** — barra de color + contador `N / max`. Reemplaza los `help={N/60}` planos
  de título y descripción.

### 5. Módulos puros JS (testeables sin React)

- **`preview.js`** (reescrito):
  - `expandTokens(template, tokens)` — **port literal de `Variables::replace`** (corrige H2), en
    este orden exacto: (1) sustituir todos los `%token%`; (2) colapsar espacios `\s+` → `' '` y
    `trim`; (3) recortar el separador colgante tratándolo como **cadena completa escapada en regex**
    (patrón `^(?:SEP\s*)+|(?:\s*SEP)+$`, igual que `preg_quote` en PHP — **no** `String.trim` ni un
    charset); (4) `trim` final. Debe replicar separadores multi-carácter y con metacaracteres.
  - `resolveSnippet({ override, template, tokens })` — `override` si no vacío, si no
    `expandTokens(template, tokens)`. (El override **no** expande tokens, igual que el `Resolver`.)
  - `truncate(text, max)` — recorte con elipsis **solo para el render del card**; la elipsis **no**
    cuenta para el `LengthIndicator` (corrige L1: el contador mide el texto resuelto sin elipsis).
  - Se **retira** `buildSnippetPreview` (y se reescribe `preview.test.js`).
- **`length.js`** (nuevo): `lengthState(len, { min, max, hardMax })` →
  `{ count, status: 'ok'|'warn'|'over', percent }`.
  - Título: `min 30, max 60, hardMax 70` → `ok` 30–60, `warn` 1–29 o 61–70, `over` 0 o >70.
  - Descripción: `min 120, max 160, hardMax 180` → `ok` 120–160, `warn` fuera cercano, `over` 0 o >180.

### 6. CSS

Nuevo `assets/src/editor/editor.scss`, importado desde `editor/index.js` (webpack lo extrae a
`style-editor.css`), encolado en `EditorPanel` con `wp_enqueue_style`. Contiene el card del SERP,
el responsive escritorio/móvil y los colores de las barras.

> Nota de implementación (corrige M1): `style-editor.css` **no existe hoy** simplemente porque el
> entry `editor` no importa ningún SCSS. El mecanismo de extracción de `@wordpress/scripts`
> (`MiniCssExtractPlugin`, nombre `[name]`) ya funciona para `admin` (`assets/src/admin/index.js`
> hace `import './style.scss'` → `style-admin-settings.css`). Por tanto **no hay que tocar
> `webpack.config.js`**: basta crear `assets/src/editor/editor.scss` e importarlo desde
> `editor/index.js`, luego `npm run build` y confirmar que aparece `assets/build/style-editor.css`.
> `EditorPanel` encola el CSS solo si `is_readable` (patrón defensivo de `Admin\Assets::enqueue`).
> Verificar también que `.distignore` no excluya `style-editor.css` del ZIP (igual que el resto de
> `assets/build/`).

### 7. GeneralTab (editor/index.js)

- Lee tokens en vivo del store (`getEditedPostAttribute` title/excerpt, `getEditedPostContent`).
- Estado local `device` (desktop/mobile) + `PreviewDevices`.
- Resuelve título/descripción con `resolveSnippet` usando override (meta vivo) + template/sep/site
  del bootstrap.
- Sustituye el `help` plano por `LengthIndicator` en título y descripción.
- Renderiza `SerpPreview` con los valores resueltos, `device`, `isNoindex`, `siteUrl`/`siteIcon`.
  `isNoindex` se lee **en vivo** con `useMeta('_openseo_robots_noindex') === '1'` (mismo
  `useEntityProp('postType', …, 'meta')` que usa `AdvancedTab`, así que activar el toggle en
  *Advanced* actualiza el badge en *General* sin recargar — corrige M5). El bootstrap no aporta noindex.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Meta\TypeTemplates` (nuevo) | Template efectivo por post type (stored ?: default) | `Options`, `TemplateDefaults` |
| `Meta\Resolver` (mod) | Usa `TypeTemplates` en la rama singular | `TypeTemplates` |
| `Admin\Editor\EditorPanel` (mod) | Bootstrap con templates/sep/site/icon; encola CSS | `Options`, `TypeTemplates` |
| `Plugin` (mod) | Inyecta deps en `EditorPanel` | — |
| `preview.js` (mod) | `expandTokens`/`resolveSnippet`/`truncate` (puro) | — |
| `length.js` (nuevo) | `lengthState` (puro) | — |
| `components/SerpPreview` (nuevo) | Tarjeta visual del resultado | `truncate` |
| `components/PreviewDevices` (nuevo) | Toggle escritorio/móvil | — |
| `components/LengthIndicator` (nuevo) | Barra + contador | `length.js` |
| `editor/index.js` (mod) | GeneralTab: tokens vivos + estado device + composición | todos los anteriores |
| `editor.scss` (nuevo) | Estilos del card/responsive/colores | — |

## Manejo de errores y casos límite

- **Tipo de post no determinable / no elegible:** templates caen a los defaults singulares (nunca vacío).
- **`siteIcon` vacío:** la fila de URL omite el favicon (o muestra un placeholder neutro), sin romper layout.
- **Permalink no disponible (borrador nuevo):** breadcrumb cae a `siteUrl` del bootstrap.
- **Excerpt vacío:** `%excerpt%` se deriva del contenido (strip + recorte); si tampoco hay contenido, queda ''.
- **Separador colgante por token vacío:** lo limpia `expandTokens` (igual que `Variables.php`).
- **`style-editor.css` ausente:** `EditorPanel` encola el CSS solo si el archivo es legible (no fataliza).
- **Override con tokens literales:** el override se usa tal cual (no se expanden tokens en el override), igual que el `Resolver` (que tampoco expande el override).
- **XSS:** todos los componentes renderizan strings vía React (auto-escapado); **ningún** componente usa `dangerouslySetInnerHTML`. El strip de tags/markup en la derivación del excerpt es por calidad visual, no seguridad. El bootstrap usa `wp_json_encode` + `JSON_HEX_TAG` (patrón existente).

## Testing

**Unit JS (Jest):**
- `preview.test.js` (reescrito): `resolveSnippet` (override gana; expande template cuando vacío;
  separador colgante limpio), `expandTokens` (todos los tokens singulares), `truncate`.
- `length.test.js` (nuevo): `lengthState` para título y descripción en los tres estados (ok/warn/over),
  bordes (0, min, max, hardMax).

**Unit PHP (Brain Monkey):**
- `TypeTemplatesTest` (nuevo): `title_for`/`description_for` → stored gana, default fallback, tipo desconocido → default.
- `ResolverTest` (ampliar/mantener verde): la rama singular sigue resolviendo igual tras usar `TypeTemplates`.

**Visual/manual (wp-env):** card del SERP, toggle escritorio/móvil, barras de color y badge noindex
en el editor de una entrada; verificar que con los campos vacíos el preview muestra el template
resuelto y que al teclear un override gana en vivo.

## Criterios de aceptación

- Con SEO title/description vacíos, el preview muestra el **template del tipo resuelto** con tokens
  en vivo (título del post, sitename, separador real, excerpt); al teclear un override, este gana en vivo.
- El separador del preview es el `title_separator` configurado (no `-` hardcodeado).
- El preview muestra favicon + breadcrumb (permalink/siteUrl), y alterna Escritorio/Móvil.
- Las barras de longitud de título y descripción cambian de color por rango; el contador muestra `N / max`.
- Con noindex activo, el preview muestra el aviso/badge correspondiente.
- El template efectivo del preview coincide con el que emite el frontend (misma fuente `TypeTemplates`)
  para título y descripción **con override o con excerpt manual**; el `%excerpt%` derivado del
  contenido es una aproximación best-effort (no paridad con `get_the_excerpt()` — ver §1).
- Gates verdes: `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`,
  `npm run lint:js`, `npm run test:js`, `npm run build`.
