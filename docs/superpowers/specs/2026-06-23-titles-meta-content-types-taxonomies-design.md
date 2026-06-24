# Diseño — Titles & Meta: Tipos de contenido y Taxonomías (Adjuntos + defaults por tipo)

- **Fecha:** 2026-06-23
- **Estado:** Aprobado para planificación
- **Área:** Resolución SEO por tipo de contenido / taxonomía (frontend) + admin React + editor
- **Tipo:** Sub-proyecto de la consolidación de Titles & Meta (inspirado en Rank Math)

## Contexto

Las pestañas **Content types** (Entradas, Páginas) y **Taxonomies** (Categorías, Etiquetas) **ya
existen** en `views/Titles.js`: el grupo se genera dinámicamente desde
`window.openseoAdmin.contentTypes` y el `TypePanel` (Titles.js:386) pinta, por cada tipo/taxonomía,
**Título**, **Descripción** y **Robots** (tri-estado vía `RobotsFields`). Toda la fontanería de backend
ya soporta esto: el mapa anidado `post_types[slug]`/`taxonomies[slug]` en `Options`, el cascadeo del
`Resolver` (`type_template()`), `TypeTemplates` (efectivo = guardado o default), `sanitize_template_map()`
y el bootstrap de `Admin\Assets`.

Frente a la captura de Rank Math (Tipos de contenido: Entradas · Páginas · **Adjuntos**; Taxonomías:
Categorías · Etiquetas) hay **dos huecos**:

1. **Adjuntos no aparece.** `Settings\ContentTypes` excluye `attachment` a propósito
   (`EXCLUDED_POST_TYPES = array('attachment')`), así que no hay pestaña ni plantilla para adjuntos.
2. **Los paneles existentes son pobres.** Rank Math ofrece por tipo, además de título/descr/robots, un
   **tipo de schema por defecto** y una **imagen social por defecto**. Hoy en OpenSEO el `@type` de
   `Schema\Pieces\Article` se resuelve **solo por entrada** (`_openseo_schema_type`) con un fallback
   **hardcoded** ("Article únicamente para el post type `post`"), y la imagen social no tiene capa de
   tipo (`Resolver::social_image()` va: override de entrada → imagen destacada → default global).

Las **taxonomías quedan lean**: OpenSEO no tiene SEO por término (`get_term_meta`/`register_term_meta`
no se usan en ningún sitio), así que los toggles de Rank Math "meta box por taxonomía" no aplican, y el
"noindex de términos vacíos" **ya existe global** (`MetaGlobalPanel`, `robots.noindex_empty_terms`). No
hay nada rico coherente que añadir a las taxonomías en esta fase.

El grueso del trabajo son: **dos campos nuevos en el mapa `post_types`**, **dos claves top-level** para
el comportamiento de adjuntos, **un módulo `Hookable`** de redirección, **ramas nuevas en dos cascadas**
del `Resolver`/`Article`, y **enriquecer el `TypePanel`** + un panel dedicado para Adjuntos.

### Decisiones congeladas (brainstorming 2026-06-23)

1. **Alcance:** tres adiciones, todas en tipos de contenido: (A) Adjuntos + redirect a la entrada padre,
   (B) tipo de schema por defecto por tipo, (C) imagen social/OG por defecto por tipo.
2. **Taxonomías sin cambios** (no hay SEO por término → nada rico coherente).
3. **Descartado por YAGNI:** toggle "mostrar controles SEO" por tipo (se solapa con el caso de Adjuntos,
   que ya neutraliza su SEO vía redirect; nicho para el resto); plantillas OG **título/descripción** por
   tipo (los templates normales de título/descr ya alimentan social como fallback); robots **avanzados**
   por tipo (heredan el global); **títulos de archivo** por tipo (casi solo aplica a CPTs).
4. **`attachment_redirect` por defecto ON.** Es el default de Rank Math y la práctica SEO correcta
   (páginas de adjunto = contenido pobre). OpenSEO es pre-release, así que el cambio de comportamiento es
   aceptable. (Decisión confirmada por el usuario.)
5. **Arquitectura:** extender el mapa `post_types` (no claves nuevas dispersas) y extender los
   cascadeos en sitio; un módulo frontend nuevo y pequeño para el redirect, hermano del
   `Frontend\ArchiveRedirect` existente.

## Objetivo

Que la barra lateral de Titles & Meta muestre **Adjuntos** junto a Entradas y Páginas; que los adjuntos
puedan **redirigir a su entrada padre** (con fallback configurable para huérfanos); que cada **tipo de
contenido** tenga un **tipo de schema por defecto** (consumido por la pieza `Article` y reflejado como
sugerencia "Automático" en el editor) y una **imagen social por defecto** (nueva capa de la cascada
social); todo configurable desde el `TypePanel` enriquecido y persistido vía `openseo/v1/settings`. Las
taxonomías conservan su panel actual (título/descr/robots) sin cambios.

## No-objetivos

- **SEO por término** (caja en el editor de términos, overrides por categoría/etiqueta individual).
  **Diferido** — requiere `register_term_meta` + UI nueva; fuera de alcance.
- **Campos ricos en taxonomías** (schema/social/meta-box por taxonomía). Sin fontanería de término →
  diferido.
- **Toggle "mostrar controles SEO" por tipo** (visibilidad del panel del editor). Descartado (YAGNI).
- **Plantillas OG título/descripción por tipo**; **robots avanzados por tipo**; **títulos de archivo de
  CPT** (`has_archive`). Diferidos.
- **Destino de redirect de adjuntos externo.** El fallback de huérfanos usa `wp_safe_redirect` (mismo
  host); una URL externa se ignora y cae a la portada. Aceptado.
- **Migración / re-seed.** `Options::all()` mezcla `defaults()` sobre lo guardado; las claves nuevas
  toman su default al leerse. Sin paso de migración.

## Arquitectura

### 1. Modelo de datos — `Options::defaults()` y `ContentTypes`

**Extender cada entrada del mapa `post_types[slug]`** con dos campos **opcionales** (las entradas de
`taxonomies[slug]` **no** cambian):

```
post_types[slug] = {
  title, description, robots?,   // ya existe
  schema_type?,   // '' = automático | 'Article' | 'BlogPosting' | 'NewsArticle' | 'WebPage' | 'none'
  og_image?,      // URL absoluta (esc_url_raw)
}
```

El conjunto de valores válidos de `schema_type` reutiliza `PostMeta::SCHEMA_TYPES` (única fuente de
verdad; `''` = automático). Ambos campos son **opcionales y omitidos cuando están vacíos**, para mantener
el mapa lean (igual que `robots`).

**Claves top-level nuevas** (comportamiento de sitio para adjuntos; **no** son plantilla, por eso van
fuera del mapa):

```php
'attachment_redirect'        => '1',   // ON por defecto (decisión congelada #4)
'attachment_redirect_orphan' => '',    // URL para adjuntos sin padre; '' = home_url('/')
```

**`Settings\ContentTypes`:** eliminar `attachment` de `EXCLUDED_POST_TYPES` (quedará
`EXCLUDED_POST_TYPES = array()`, o se elimina la lista y el filtro). Efecto en cascada automático:
`attachment` pasa a ser tipo elegible → aparece en el bootstrap (`contentTypes.postTypes`), en el
whitelist de `sanitize_template_map()` y como pestaña en `Titles.js`. Actualizar el comentario de la
clase (ya no "minus attachment").

> **Nota:** `Meta\PostMeta` ya registra el meta `_openseo_*` para adjuntos (son `public` +
> `show_in_rest`), así que no hay cambios ahí. El panel del editor (`EditorPanel`) se engancha a
> `enqueue_block_editor_assets`, que **no** corre en la biblioteca de medios; los adjuntos se configuran
> solo por plantilla o por redirect, no con la caja del editor. Es coherente.

### 2. Cascada del `@type` de schema — `Article` + `TypeTemplates` + `TemplateDefaults`

Hoy `Schema\Pieces\Article::is_needed()`/`type()` solo miran el override por entrada con un fallback
hardcoded. Se introduce el **nivel de tipo** entre el override y el comportamiento por defecto:

- **`TemplateDefaults::schema_type( string $post_type ): string`** (literal puro, sin WP): el default
  automático por tipo. `'post' => 'Article'`, `'page' => 'WebPage'`, resto `=> 'none'`. Este mapeo
  **preserva el comportamiento actual** (hoy solo `post` emite Article; `page`→WebPage y el resto→none
  significan "sin nodo Article", idéntico a hoy) y a la vez lo hace configurable.
- **`TypeTemplates::schema_type_for( string $post_type ): string`** (efectivo = guardado o default):
  devuelve `post_types[slug].schema_type` si no está vacío; si no, `TemplateDefaults::schema_type()`.
  Siempre devuelve un tipo concreto (`Article`/`BlogPosting`/`NewsArticle`/`WebPage`/`none`), nunca `''`.
  Mismo patrón que `title_for()`/`description_for()`.

**`Article::is_needed()`** (orden):
1. Override por entrada `_openseo_schema_type`: si es un Article-type (`Article`/`BlogPosting`/
   `NewsArticle`) → **necesario**; si es `none`/`WebPage` → **no**; si es `''` → seguir.
2. `TypeTemplates::schema_type_for( get_post_type() )`: si es Article-type → necesario; si
   `none`/`WebPage` → no.

**`Article::type()`**: el override Article-type si existe; si no, el efectivo de tipo si es Article-type;
si no, `'Article'`.

**DRY (M3):** `is_needed()` y `type()` comparten exactamente la resolución override→tipo→default, así que
se extrae un método privado `effective_schema_type(): string` en `Article` que ambos consumen, evitando
duplicar la cascada.

**Cambio de constructor (M2):** hoy `Article::__construct( Resolver, Options )` (Article.php:30) y se
construye en `Plugin.php:146`. `Article` gana un tercer parámetro `TypeTemplates $type_templates`;
`Plugin::modules()` lo pasa (la variable `$type_templates` ya existe en ese método, Plugin.php:131). Hay
que actualizar `ArticleTest`/`ContentPiecesTest` que instancian la pieza con dos argumentos.

El default sembrado en el `TypePanel` (placeholder "Automático") y el del editor salen del **mismo**
`schema_type_for()`, evitando drift entre admin, editor y frontend.

### 3. Cascada de imagen social — `Resolver::social_image()` + `TypeTemplates`

- **`TypeTemplates::og_image_for( string $post_type ): string`**: devuelve `post_types[slug].og_image`
  guardado o `''` (sin default automático; cae al global). No es "efectivo con default" como las
  plantillas, porque su fallback es el global, no un literal.
- **`Resolver::social_image()`** (singular) inserta la capa de tipo **entre** la imagen destacada y el
  default global:

```
_openseo_og_image (entrada)  →  imagen destacada  →  post_types[slug].og_image (NUEVO)  →  og_default_image (global)
```

**Anclaje exacto (M1):** la capa se inserta **dentro** del bloque `if ( is_singular() )` actual
(Resolver.php:411-416), **tras** la comprobación de imagen destacada y **antes** del `return` del default
global, leyendo `$this->type_templates->og_image_for( get_post_type( get_queried_object_id() ) )`.
`Resolver` ya tiene `TypeTemplates` inyectado (Resolver.php:42), así que no hay cambio de constructor. La
rama `is_posts_homepage()` (`home_og_image`, Resolver.php:400-404) **queda intacta** y no gana capa de
tipo. La lógica de Twitter (que hereda de la social) **no cambia**. `social_title()` y
`social_description()` **no** ganan capa de tipo (decisión #3: las plantillas normales ya alimentan
social).

### 4. Módulo nuevo — `Frontend\AttachmentRedirect` (Hookable)

Hermano de `Frontend\ArchiveRedirect`. Estructura paralela:

```php
final class AttachmentRedirect implements Hookable {
    public function __construct( private readonly Options $options ) {}

    public function register(): void {
        add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 6 );
    }

    public function maybe_redirect(): void {
        if ( ! is_attachment() || '1' !== (string) $this->options->get( 'attachment_redirect' ) ) {
            return;
        }
        $id     = get_queried_object_id();
        $parent = (int) get_post_field( 'post_parent', $id );
        $target = $parent > 0 ? (string) get_permalink( $parent ) : '';

        // Anti-identidad (H1): datos corruptos (padre = el propio adjunto, o permalink que
        // resuelve a la URL del adjunto) → tratar como huérfano para no buclear.
        if ( '' === $target || $target === (string) get_permalink( $id ) ) {
            $orphan = (string) $this->options->get( 'attachment_redirect_orphan' );
            $target = '' !== $orphan ? $orphan : home_url( '/' );
        }

        wp_safe_redirect( $target, 301 );
        exit;
    }
}
```

- **Prioridad 6:** después del motor de redirecciones explícitas (`Redirects\Dispatcher`@5, para que una
  regla manual sobre la URL del adjunto **gane**) y antes de `redirect_canonical`@10. Diverge a propósito
  del `ArchiveRedirect`@1 (que tiene destino interno fijo y no compite con reglas de usuario).
- **Registro:** lista **always-on** de `Plugin::modules()` (comportamiento de frontend;
  `template_redirect` no corre en admin).
- **Seguridad:** `wp_safe_redirect` (evita open-redirect; permalink del padre y home son mismo host). Un
  `attachment_redirect_orphan` externo lo bloquea `wp_safe_redirect` y cae a home (No-objetivo).
- **Anti-bucle (H1):** además del corte por `is_attachment()` (el padre/portada no son adjuntos), la
  guarda anti-identidad cubre el caso patológico de un padre cuyo permalink resuelve a la propia URL del
  adjunto.
- **Alcance del redirect (H1, decisión):** se aplica a **todos los visitantes, incluidos los
  autenticados**. Los adjuntos no tienen flujo de borrador/preview (la biblioteca de medios no usa el
  block editor), así que no hay un caso de "previsualizar" que proteger; mantenerlo uniforme es más
  simple y coincide con Rank Math.
- **Caché del 301 (H1):** un 301 es cacheado por el navegador; si el admin **desactiva** el toggle
  después, los visitantes con caché pueden seguir redirigidos un tiempo. Es el comportamiento SEO
  estándar (Rank Math también usa 301); se documenta como consecuencia esperada del default-ON.
- **Coste por request:** `is_attachment()` corta antes de leer `Options` por el `&&` short-circuit; en
  páginas normales no se lee ninguna opción.

### 5. Sanitize — `Options::sanitize()`

- **`sanitize_template_map()`** gana un parámetro `bool $allow_rich` (true solo para `post_types`):
  - Cuando `$allow_rich`, además de title/description/robots procesa **`schema_type`** (whitelist contra
    `PostMeta::SCHEMA_TYPES`; valor inválido → se ignora, conserva el actual) y **`og_image`**
    (`esc_url_raw( wp_unslash(...) )`).
  - **Formato real de robots (C1):** ojo, el mapa `robots` por-tipo se almacena en **tri-estado
    `'on'/'off'`** (no `'1'`; ver Options.php:299-309), y "robots vacío" se evalúa con `empty($robots)`.
    La condición de unset, ya con las cinco variables saneadas, es exactamente:
    `'' === $title && '' === $description && empty($robots) && '' === $schema_type && '' === $og_image`.
  - **Contrato de borrado (C2):** `schema_type`/`og_image` se incluyen en `$entry` **solo cuando no están
    vacíos** (mapa lean). El front (`setTemplateField`, ya genérico — acepta cualquier campo) **siempre
    envía la clave**, así que al elegir "Automático" (`schema_type=''`) o quitar la imagen (`og_image=''`)
    el front manda `''` y el back lo **omite** del `$entry` en vez de persistir un string vacío. Esto
    mantiene la idempotencia y el mapa lean sin lógica JS de borrado.
  - La llamada de `taxonomies` pasa `$allow_rich = false` → su forma actual no cambia.
  - **Docblocks (L1):** actualizar la docstring y los array-shape `@param $current` / `@return` de
    `sanitize_template_map()` (Options.php:267-278) de `{title,description,robots?}` a
    `{title,description,robots?,schema_type?:string,og_image?:string}`, para PHPStan nivel 6.
- **Checkbox** (`'1'`/`''`, loop existente): `attachment_redirect`. El `ToggleControl` de React envía
  `'1'`/`''` directo vía `change('attachment_redirect', on ? '1' : '')` (L2: **no** hay "hidden companion
  field" — eso era del Settings API ya retirado).
- **URL** (`esc_url_raw`, loop existente): `attachment_redirect_orphan`.

### 6. UI React — `views/Titles.js`

- **`TypePanel`** (enriquecer; afecta a Entradas, Páginas y CPTs): debajo de los `RobotsFields`, y **solo
  cuando `mapKey === 'post_types'`**, añadir:
  - `SelectControl` **"Tipo de schema por defecto"** bound a `entry.schema_type` (opciones: `''` →
    "Automático (X)", donde X = `type.defaultSchemaType` del bootstrap; `Article`, `BlogPosting`,
    `NewsArticle`, `WebPage`, `none` → "Ninguno"). Escribe vía `setTemplateField(map, slug, 'schema_type',
    v)` (el helper ya es genérico — acepta cualquier campo; ver §5 para el contrato de borrado cuando `v === ''`).
  - `MediaField` **"Imagen social por defecto"** bound a `entry.og_image`.
  - Las taxonomías (`mapKey === 'taxonomies'`) **no** muestran estos campos.
- **`AttachmentsPanel`** (nuevo; `renderPanel` enruta `pt:attachment` aquí en vez de a `TypePanel`):
  - `ToggleControl` **"Redirigir adjuntos a la entrada padre"** (`attachment_redirect`).
  - Cuando ON: `TextControl` (type=url) **"URL para adjuntos sin entrada padre"**
    (`attachment_redirect_orphan`, placeholder = home) + `Notice` informativo ("Las plantillas SEO de
    adjuntos están desactivadas mientras los rediriges"). **Se ocultan** los campos de plantilla.
  - Cuando OFF: se renderiza el `TypePanel` enriquecido para `attachment` (título/descr/robots + schema +
    imagen social), exactamente como cualquier otro tipo.
- **DRY:** el bloque enriquecido de campos de tipo se extrae a un sub-componente reutilizado por
  `TypePanel` y por la rama OFF de `AttachmentsPanel`, para no duplicar la lógica de schema/imagen.
- **Bootstrap (`Admin\Assets`):** cada item de `contentTypes.postTypes` gana
  `defaultSchemaType = TypeTemplates::schema_type_for( slug )`, para que la opción "Automático (X)" del
  `SelectControl` muestre a qué resuelve. Las taxonomías no lo necesitan.

### 7. Editor — `Admin\Editor\EditorPanel`

Añadir al bootstrap `window.openseoEditor` (en `EditorPanel::enqueue()`) la clave
`schemaTypeDefault => $this->type_templates->schema_type_for( $post_type )`.

**Cambio en `index.js` (M4):** hoy `SCHEMA_OPTIONS` es una **constante de módulo** (index.js:113) con la
opción `{ label: __('Default (automatic)'), value: '' }`. Para mostrar "Automático (X)" hay que
**construir las opciones dinámicamente dentro de `SchemaField()`**: la opción `value: ''` pasa a
`label: sprintf( __('Automatic (%s)', 'openseo'), window.openseoEditor?.schemaTypeDefault ?? '' )`; el
resto de opciones concretas no cambia. Así, al elegir "Automático" en una entrada, el autor ve qué tipo
aplicará realmente según el default del tipo de contenido. Recordar `npm run lint:js` (gate jsdoc/i18n).

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Settings\ContentTypes` (mod) | dejar de excluir `attachment` | — |
| `Settings\Options` (mod) | defaults + sanitize de `schema_type`/`og_image` (post_types) y `attachment_redirect*` | `PostMeta::SCHEMA_TYPES`, helpers existentes |
| `Meta\TemplateDefaults` (mod) | `schema_type($post_type)` (mapa puro por tipo) | — |
| `Meta\TypeTemplates` (mod) | `schema_type_for()` (efectivo) + `og_image_for()` (guardado o '') | `Options`, `TemplateDefaults` |
| `Schema\Pieces\Article` (mod) | `is_needed()`/`type()` consultan el nivel de tipo tras el override | `TypeTemplates` |
| `Meta\Resolver` (mod) | capa de tipo en `social_image()` | `TypeTemplates`, `Options` |
| `Frontend\AttachmentRedirect` (nuevo) | 301 de adjunto → padre / huérfano (template_redirect@6) | `Options`, conditional tags |
| `Plugin` (mod) | registra `AttachmentRedirect` en módulos always-on | — |
| `Admin\Assets` (mod) | `defaultSchemaType` por tipo en el bootstrap | `TypeTemplates` |
| `Admin\Editor\EditorPanel` (mod) | `schemaTypeDefault` en `window.openseoEditor` | `TypeTemplates` |
| `views/Titles.js` (mod) | `TypePanel` enriquecido + `AttachmentsPanel` + ruteo + sub-componente DRY | `TemplateField`, `MediaField`, `SelectControl`, `RobotsFields` |

## Manejo de errores y casos límite

- **Adjunto huérfano** (`post_parent = 0` o padre inexistente): redirige a `attachment_redirect_orphan`
  o, en su defecto, a `home_url('/')`.
- **Orphan URL externa:** `wp_safe_redirect` la bloquea → cae a home (No-objetivo declarado).
- **`attachment_redirect` OFF:** el adjunto se sirve normal y usa su plantilla/robots como cualquier
  tipo; el `TypePanel` enriquecido es visible.
- **Adjuntos × sitemaps × redirect (H2):** con redirect **ON**, los robots/schema/og por-tipo de
  adjuntos son **inertes** (la página redirige antes de `wp_head`, nunca renderiza el `<head>`). Además,
  WordPress core **no registra un provider de sitemap de adjuntos**, así que la plantilla robots de
  adjuntos **no altera** `wp-sitemap.xml` aunque `Sitemap::exclude_noindex_post_types()` la lea (es un
  no-op para adjuntos). Es comportamiento esperado, no un bug: se documenta para que la UI ofrezca esos
  controles sin generar expectativas falsas. Des-excluir `attachment` **no** lo añade a ningún
  `wp_sitemaps_*` (verificado).
- **`schema_type` inválido** (input manipulado): el sanitize lo ignora y conserva el valor previo.
- **Override de entrada vs. default de tipo:** el override `_openseo_schema_type` **siempre gana**; solo
  cuando es `''` (automático) se consulta el nivel de tipo; el default literal por tipo es el último
  fallback (preserva el comportamiento actual: `post`→Article).
- **`og_image` de tipo con imagen destacada presente:** la imagen destacada (contenido de la entrada)
  gana sobre el default de tipo; el default de tipo cubre las entradas sin destacada.
- **Back-compat de schema:** con los defaults sembrados (`post`→Article, `page`→WebPage, resto→none) el
  grafo JSON-LD emitido es idéntico al actual hasta que un admin cambie un valor.
- **Seguridad/i18n:** sanitizar en entrada (`wp_unslash` por clave explícita), escapar en salida; nonce
  + capability los provee `SettingsController` (`manage_options`); cadenas por `__()`; text domain
  `openseo`.

## Testing

**PHP unit (Brain Monkey):**
- `OptionsTest` (ampliar): `sanitize_template_map` con `schema_type` válido/ inválido y `og_image`
  (`esc_url_raw`); persistencia de una entrada **solo** con `og_image` (no se borra); la llamada de
  `taxonomies` ignora `schema_type`/`og_image`; defaults `attachment_redirect`/`attachment_redirect_orphan`
  presentes; checkbox/URL de adjuntos.
- `TemplateDefaultsTest` (ampliar): `schema_type()` devuelve `Article`/`WebPage`/`none` por tipo.
- `TypeTemplatesTest` (ampliar): `schema_type_for()` (guardado gana al default; default por tipo cuando
  vacío); `og_image_for()` (guardado o '').
- `Schema\Pieces` (`ContentPiecesTest`/`ArticleTest`, ampliar): cascada de `@type` — override Article-type
  gana; `none`/`WebPage` suprimen; `''` cae al default de tipo; default sembrado preserva el
  comportamiento actual.
- `ResolverTest` (ampliar): `social_image()` con capa de tipo (override → destacada → tipo → global);
  confirmar que los tests existentes siguen verdes.
- `AttachmentRedirectTest` (nuevo): 301 al padre cuando ON + `is_attachment()`; huérfano → orphan URL o
  home; **no** redirige cuando OFF o no es adjunto (mock de `wp_safe_redirect`/conditional tags/
  `get_post_field`).

**JS (Jest):** `AttachmentsPanel` y el `TypePanel` enriquecido son presentacionales y reúsan helpers ya
cubiertos (`setTemplateField`/merge inmutable de robots); si se añade un helper puro para `schema_type`,
se testea. Correr `npm run lint:js` (gate: Prettier/jsdoc/jsx-a11y) además de `npm run test:js`.

**Integración (wp-env, opcional / smoke manual):**
- Adjuntos: pestaña visible; con redirect ON, `/?attachment_id=N` o el permalink del adjunto → 301 al
  padre; huérfano → home/URL configurada; con OFF, el adjunto se sirve y respeta su robots.
- Schema: una entrada `post` sin override emite `Article`; cambiar el default de tipo a `none` lo suprime;
  override por entrada gana.
- Social: una entrada sin destacada usa la `og_image` del tipo; con destacada, gana la destacada.

## Criterios de aceptación

- **Adjuntos** aparece como pestaña en Content types; el toggle de redirect (ON por defecto) redirige las
  páginas de adjunto a su entrada padre con 301, con fallback de huérfanos; con el toggle OFF, el adjunto
  expone su panel SEO enriquecido.
- Cada **tipo de contenido** (Entradas, Páginas, CPTs) tiene **Tipo de schema por defecto** (consumido por
  `Article` y reflejado como "Automático (X)" en el editor) e **Imagen social por defecto** (capa nueva de
  la cascada social).
- Las **taxonomías** conservan su panel actual (título/descr/robots) sin cambios.
- Sin regresión: con los defaults sembrados, el JSON-LD y la imagen social emitidos son idénticos a los
  actuales hasta que se cambie un valor; singular/taxonomía/portada/special-pages siguen verdes.
- Persistencia vía `openseo/v1/settings` (merge parcial a través de `Options::sanitize`).
- **Nota de release (H3):** documentar en el readme/changelog el cambio de comportamiento del default-ON
  ("Las páginas de adjunto ahora redirigen a su entrada padre por defecto; se desactiva en Titles & Meta
  → Adjuntos").
- **i18n (L3):** todas las cadenas nuevas con `__()`/`sprintf` y dominio `openseo` ("Redirigir adjuntos a
  la entrada padre", "URL para adjuntos sin entrada padre", el `Notice`, "Tipo de schema por defecto",
  "Imagen social por defecto", "Automatic (%s)").
- Gates verdes: `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`,
  `npm run lint:js`, `npm run test:js`, `npm run build`. `languages/openseo.pot` regenerado.
