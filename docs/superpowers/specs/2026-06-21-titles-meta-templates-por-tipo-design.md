# Diseño — Titles & Meta: templates de título y descripción por tipo de contenido (cimiento)

- **Fecha:** 2026-06-21
- **Estado:** Aprobado para planificación
- **Área:** `Titles & Meta`
- **Tipo:** Cimiento (sub-proyecto 1 de la consolidación de Titles & Meta)

## Contexto

Hoy OpenSEO resuelve título y meta-descripción con un **único template global**
(`title_template` = `%title% %sep% %sitename%`, `description_template` = `%excerpt%`)
aplicado a todas las entradas singulares, más un título/descripción específicos para
la home. No existe forma de dar a Posts, Pages, CPTs o taxonomías su propio template.

Inspirándonos en cómo Rank Math **organiza** sus templates (una configuración por tipo
de contenido y por taxonomía, no una sola global), este spec establece el **cimiento de
datos y lógica** para templates por tipo. La pantalla admin con pestañas estilo Rank Math,
el dropdown inserter de variables y el SERP preview en vivo del editor son specs posteriores
que **consumirán** este cimiento.

### Decisiones congeladas (brainstorming 2026-06-21)

1. **Ancla:** cimiento — templates por tipo de contenido (capas `Settings/Options`,
   `Meta/Resolver`, `Meta/Variables`) + UI admin mínima.
2. **Cobertura:** singulares (Posts, Pages, todos los CPTs públicos) + taxonomías
   (Categorías, Tags, taxonomías custom). La home se mantiene como está.
3. **Alcance de campos:** solo **title + description**. Los robots por tipo y el panel
   Global Meta de robots avanzados quedan para un spec aparte.
4. **Modelo de fallback:** **default propio por tipo calculado en runtime** (modelo
   Rank Math). Se **retira** el template global único; sus valores se **migran** a
   Posts/Pages. Cascada por entidad: `override por entrada → template del tipo → default del tipo`.
5. **UI:** este spec entrega una **UI admin mínima funcional** (sin pestañas/inserter pulidos).

## Objetivo

Que cada tipo de contenido público y cada taxonomía pública tenga su propio template de
título y meta-descripción, con un default sensato cuando no se ha personalizado, y una UI
admin para editarlos.

## No-objetivos (fuera de alcance de este spec)

- Robots por tipo de contenido y panel Global Meta de robots avanzados.
- Pestañas admin estilo Rank Math y dropdown inserter de variables.
- SERP/snippet preview en vivo en el editor (Desktop/Mobile, barras de longitud, badge noindex).
- Set enriquecido de variables (`%page%`, fechas, `%category%`, autor, etc.).
- Overrides de SEO por término (meta por término de taxonomía).
- Superficies autor / archivos de fecha / 404 / búsqueda.

## Arquitectura

### 1. Modelo de datos (`openseo_settings`)

Estructura **anidada**, dos claves nuevas, ambas vacías por defecto (los defaults se
calculan en runtime y no se persisten):

```php
'post_types' => array(
    // 'post' => array( 'title' => '...', 'description' => '...' ),  // solo lo editado
),
'taxonomies' => array(
    // 'category' => array( 'title' => '...', 'description' => '...' ),
),
```

Cambios en `Settings\Options::defaults()`:

- **Añadir** `post_types => []` y `taxonomies => []`.
- **Retirar** `title_template` y `description_template`.
- **Conservar** `title_separator`, `home_title`, `home_description`, `og_default_image`.

*Por qué anidado y no plano (`pt_post_title`):* los CPTs/taxonomías son dinámicos (no se
conocen en `defaults()`), así que se necesitan mapas que crecen en runtime; dos sub-arrays
mantienen `openseo_settings` ordenado y permiten un `sanitize` que itera el sub-array.
`Options::all()` hace `array_merge($defaults, $stored)` a nivel superior, de modo que el
sub-array guardado reemplaza al `[]` por defecto sin problema.

### 2. Defaults por tipo en runtime — nueva clase `Meta\TemplateDefaults`

Clase **pura** (sin WordPress, unit-testeable) que devuelve el template por defecto según
la "superficie":

| Superficie | Title default | Description default |
|---|---|---|
| Singular (post, page, CPT) | `%title% %sep% %sitename%` | `%excerpt%` |
| Taxonomía (category, tag, custom) | `%term% %sep% %sitename%` | `%term_description%` |

API propuesta (nombres orientativos para el plan):

```php
final class TemplateDefaults {
    public function singular_title(): string;        // '%title% %sep% %sitename%'
    public function singular_description(): string;  // '%excerpt%'
    public function taxonomy_title(): string;        // '%term% %sep% %sitename%'
    public function taxonomy_description(): string;  // '%term_description%'
}
```

El `Resolver` lee el valor guardado de la entidad; si está vacío, cae a estos defaults.
"No configurado" siempre produce un template efectivo, sin nivel global intermedio.

### 3. Variables — `Meta\Variables` y `Meta\TemplateContext`

**Tokens nuevos** (lo mínimo que los defaults de taxonomía necesitan):

- `%term%` → nombre del término.
- `%term_description%` → descripción del término, sin HTML (`wp_strip_all_tags`).

Los tokens existentes (`%sitename%`, `%tagline%`, `%sep%`, `%currentyear%`, `%title%`,
`%excerpt%`) se mantienen sin cambios, igual que la limpieza de whitespace y de separadores
colgantes.

**Generalización del contexto.** Hoy la firma es `replace(string $template, int $post_id = 0)`,
que no sirve para taxonomías. Se introduce un **value object inmutable** `Meta\TemplateContext`
(`readonly`) que **guarda primitivos, no objetos de WordPress**:

```php
final class TemplateContext {
    // Construye desde un post: la factory lee get_the_title()/get_the_excerpt() y
    // guarda strings; el objeto no retiene el WP_Post.
    public static function for_post( int $post_id ): self;
    // Construye desde un término: la factory extrae name + description (primitivos);
    // el objeto NO retiene el WP_Term.
    public static function for_term( \WP_Term $term ): self;
    public static function none(): self;
    // getters de primitivos (title, excerpt, term_name, term_description) usados por Variables
}
```

La firma pasa a `replace(string $template, ?TemplateContext $context = null)`
(`null` ≡ `none()`).

*Por qué value object y no array de contexto:* las reglas PHP del proyecto prefieren
DTOs/value objects sobre arrays "shape-heavy", y deja la puerta abierta a
`for_author()` / `for_date()` en specs futuros sin volver a tocar la firma.

*Por qué primitivos y no `WP_Term`/`WP_Post` dentro del VO* (corrige M7 de la auditoría):
mantener `Variables` y `TemplateContext` operando sobre strings los deja realmente puros y
unit-testeables con Brain Monkey **sin** stubear `WP_Term`. Las llamadas a WordPress
(`get_the_title`, `get_the_excerpt`, leer `$term->name`/`$term->description`,
`wp_strip_all_tags`) viven en las **factories** `for_post`/`for_term`, que en los unit tests
se ejercitan mockeando esas funciones; `replace()` en sí no toca WP salvo `get_bloginfo`/separador
(como hoy).

*Impacto — cambio de firma INCOMPATIBLE* (corrige H2 de la auditoría): no se mantiene
compatibilidad con `int`. Se migran **atómicamente en el mismo plan**:
- Call sites en `Meta\Resolver`: `Resolver.php:45` y `Resolver.php:67` pasan de `replace($tpl, $id)`
  a `replace($tpl, TemplateContext::for_post($id))`; `Resolver.php:49` (home, sin id) queda
  `replace($tpl)` (≡ `none()`), más las dos ramas nuevas de taxonomía con `for_term($term)`.
- `tests/Unit/Meta/VariablesTest.php` se reescribe para la nueva firma.
- Único otro consumidor de `Variables` es el constructor en `Plugin.php` (no llama `replace`),
  verificado: no hay más call sites. PHPStan nivel 6 y `test:unit` deben quedar verdes tras la
  migración conjunta (no a mitad).

### 4. `Meta\Resolver` — selección de template por contexto

`title()` y `description()` resuelven según la entidad actual:

```
is_singular()      → tipo = get_post_type($id)
                     override _openseo_title gana primero (sin cambios)
                     guardado: post_types[$tipo]['title'] | si '' → TemplateDefaults::singular_title()
                     contexto = TemplateContext::for_post($id)

is_category() ||
is_tag() ||
is_tax()           → term = get_queried_object(); tax = $term->taxonomy
                     guardado: taxonomies[$tax]['title'] | si '' → TemplateDefaults::taxonomy_title()
                     contexto = TemplateContext::for_term($term)

is_front_page()    → home_title / home_description (igual que hoy)
resto              → '' (sin opinión)
```

La cascada por entidad queda: `override por entrada → template del tipo guardado → default del tipo`.
Para taxonomías no hay override por término en este spec; su valor sale de `taxonomies[$tax]`
o del default.

**Invariante de la home (corrige M5):** la rama `is_front_page()` de `description()` **conserva**
el fallback actual `'' !== home_description ? home_description : get_bloginfo('description')`
(`Resolver.php:70–73`). El refactor de la cascada no debe regresarlo; figura en criterios de aceptación.

**Cambio de comportamiento observable (intencional) — alcance completo sobre el `HeadPrinter`**
(corrige H3): hoy en archivos de taxonomía el `Resolver` devuelve `''` para título/descripción.
Como `HeadPrinter` orquesta varios presenters sobre el **mismo** `Resolver`, este cambio afecta a:

- **`Title`** (`pre_get_document_title`) y **`Description`**: **empiezan a emitir** título y
  meta-descripción en categorías/tags/taxonomías custom. Es la consolidación buscada; consumen el
  `Resolver` de forma genérica, sin cambios estructurales.
- **`OpenGraph`** y **`Twitter`**: `social_title()`/`social_description()` caen a
  `title()`/`description()` cuando no hay override por entrada (en no-singular `meta_value()` da `''`),
  así que **también empezarán a emitir** og:title/og:description/twitter:* derivados en taxonomías.
  Efecto deseado (coherencia entre `<title>` y social), explícitamente documentado.
- **`Robots`**: `robots()` solo lee meta en `is_singular()`, así que en taxonomías **sigue**
  devolviendo `index, follow` como hoy. Este spec **no** toma opinión nueva sobre robots de taxonomía
  (robots por tipo está fuera de alcance).
- **`Canonical`**: `canonical()` devuelve `''` salvo `is_singular()`, así que en taxonomías **sigue**
  delegando en WordPress. Sin cambios.

### 5. Migración del template global — gate de versión de settings

Migrador propio (nueva unidad `Lifecycle\SettingsMigrations` o equivalente), detrás de una
**opción de versión de settings separada** `openseo_settings_version` — **no** se reusa
`openseo_db_version`, que es de tablas (corrige H4): acoplar la migración de settings al schema
de tablas mezclaría dos ciclos de vida independientes.

**Enganche en `init`, no en `admin_init`** (corrige H4): `admin_init` solo corre en wp-admin, y
el nuevo `Resolver` ya no lee `title_template`/`description_template` (§1 los retira de
`defaults()`). Si la migración corriera solo en admin, un visitante del **front-end** que llegue
antes de que cualquier admin abra wp-admin vería el **default runtime** en vez del template
personalizado → ventana de regresión SEO. `init` cubre front y admin; como la migración solo toca
los slugs estáticos `post`/`page` (no CPTs dinámicos), `init` es seguro y suficientemente temprano.

**Lectura del array crudo** (corrige H4): el migrador lee las claves viejas vía
`get_option(Options::OPTION_KEY)` directo, **no** vía `Options::get()`/`all()`, porque tras
retirar los defaults (§1) esos accesores ya no las conocen.

**Orden de operaciones, idempotente** (corrige M8):

1. Si `openseo_settings_version` ya está al día → return (no-op).
2. Leer el array crudo con `get_option`.
3. Si `title_template` ≠ viejo default (`%title% %sep% %sitename%`) → copiar a
   `post_types['post']['title']` y `post_types['page']['title']`.
4. Si `description_template` ≠ viejo default (`%excerpt%`) → copiar a `['post']`/`['page']['description']`
   (independiente del paso 3: se puede haber personalizado solo uno de los dos).
5. Eliminar del array las claves `title_template` y `description_template`.
6. `update_option` con el array resultante.
7. **Solo al final**, tras un guardado exitoso, marcar `openseo_settings_version`. Así un fallo a
   mitad no deja el gate marcado con datos a medias y la migración reintenta en el próximo request.

El plugin está en desarrollo activo, así que en la práctica será casi siempre no-op, pero deja el
upgrade correcto para cualquier instalación que hubiera personalizado el template global.

### 6. `Options::sanitize()` — sub-arrays anidados con whitelist

**Algoritmo preciso (corrige C1).** El borrado accidental de slugs no enviados se evita porque
`sanitize()` arranca en `$clean = $this->all()` (`Options.php:88`), que ya trae el sub-array
guardado completo. **No** es un "merge profundo" (`array_merge` de PHP es *shallow*); la
conservación viene de arrancar en `all()` e ir actualizando slug-a-slug, sin reemplazar el
sub-array entero. Para cada uno de `post_types` y `taxonomies`, si la clave está presente en el
input:

1. Iterar **solo los slugs presentes** en `$input[<grupo>]`.
2. **Whitelist:** saltar cualquier slug que no esté en el criterio único de tipos elegibles
   (ver helper más abajo). Nunca se persiste un slug arbitrario.
3. Merge **a nivel de campo** sobre el valor guardado del slug
   (`$current = $clean[<grupo>][$slug] ?? []`):
   - `title` = `sanitize_text_field(wp_unslash(...))` si viene en el input, si no conserva `$current['title']`.
   - `description` = `sanitize_textarea_field(wp_unslash(...))` si viene, si no conserva `$current['description']`.
4. **Semántica de campo vacío:** si tras sanitizar `title` **y** `description` quedan ambos `''`,
   hacer `unset($clean[<grupo>][$slug])` — el array guardado refleja solo lo personalizado
   (coherente con §1 "solo lo editado" y con la migración §5 que también borra claves; "vacío" ≡
   "volver al default runtime").
5. Los slugs guardados **ausentes** del input se conservan intactos (ya están en `$clean` vía `all()`).

`wp_unslash` se mantiene coherente con el path REST que `wp_slash`ea antes de llamar
(`SettingsController.php:90`). `Rest\SettingsController` **no cambia**.

**Criterio único de tipos elegibles + helper compartido (corrige M6).** La whitelist del sanitize
y la lista del bootstrap (§7) **deben** compartir una sola fuente para no divergir. Se extrae un
helper (p. ej. `Settings\ContentTypes` o métodos en `Options`):
`eligible_post_types(): array` y `eligible_taxonomies(): array`. Criterio: **`public => true`
excluyendo `attachment`** (un media no necesita template de título SEO y `attachment` es
`public => true` en core). El mismo helper alimenta el sanitize (validación) y el bootstrap (UI),
garantizando que no haya tipos editables-pero-no-listados ni viceversa.

### 7. Bootstrap + UI admin mínima

**`Admin\Assets::bootstrap()`** añade la lista que la UI necesita (sin endpoint nuevo):

```php
'contentTypes' => array(
    'postTypes'  => array(
        array( 'slug' => 'post', 'label' => 'Posts',
               'defaultTitle' => '%title% %sep% %sitename%',
               'defaultDescription' => '%excerpt%' ),
        // ... resto de post types públicos
    ),
    'taxonomies' => array(
        array( 'slug' => 'category', 'label' => 'Categories',
               'defaultTitle' => '%term% %sep% %sitename%',
               'defaultDescription' => '%term_description%' ),
        // ... resto de taxonomías públicas
    ),
),
```

Lista de tipos/taxonomías desde el **helper compartido** de §6 (mismo criterio `public => true`
menos `attachment`), no con un `get_post_types` ad-hoc, para no divergir de la whitelist del
sanitize. Labels desde los objetos registrados (`$obj->labels->name`, ya traducidos por core/su
plugin). **Defaults: única fuente de verdad = `TemplateDefaults`** (corrige L9): los literales del
ejemplo (`'%title% %sep% %sitename%'`, etc.) **no** se hardcodean en el bootstrap ni en JS; salen
de `TemplateDefaults`, el mismo objeto que usa el `Resolver`, para que el `placeholder` de la UI
nunca diverja del default real. Cualquier string propio del diseño en `views/Titles.js`
(encabezados "Content types" / "Taxonomies") pasa por `__( …, 'openseo' )` (L11).

**`views/Titles.js`** se reorganiza en tres zonas dentro del mismo `SettingsPanel`:

1. **Global:** `title_separator`, `home_title`, `home_description` (lo que sobrevive).
2. **Tipos de contenido:** por cada `postType`, un `TextControl` (title) + `TextareaControl`
   (description), con `placeholder` = default. Escriben en `post_types[slug]`.
3. **Taxonomías:** idéntico, escriben en `taxonomies[slug]`.

Estado anidado con el `change` existente, merge inmutable:

```js
change('post_types', {
  ...values.post_types,
  [slug]: { ...values.post_types?.[slug], title: v },
});
```

Se extrae un componente presentacional pequeño `TemplateGroup` para no repetir el bloque
tipo/taxonomía. **Sin** pestañas estilo Rank Math ni dropdown inserter de variables (spec de
UI siguiente); aquí los campos van agrupados bajo encabezados.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Settings\Options` (mod) | Defaults nuevos, retiro de claves viejas, sanitize anidado slug-a-slug con whitelist | helper de tipos elegibles, WP sanitize |
| `Settings\ContentTypes` helper (nuevo) | Única fuente de tipos/taxonomías elegibles (`public` menos `attachment`); usado por sanitize y bootstrap | registro de tipos WP |
| `Meta\TemplateDefaults` (nuevo) | Defaults de template por superficie (puro); única fuente de los defaults | — |
| `Meta\TemplateContext` (nuevo) | Value object de contexto con **primitivos** (post/term/none), sin retener `WP_Post`/`WP_Term` | — (factories tocan WP) |
| `Meta\Variables` (mod) | Tokens `%term%`/`%term_description%`, firma con contexto (cambio incompatible) | `TemplateContext`, `Options` |
| `Meta\Resolver` (mod) | Selección de template por entidad + cascada; migra sus 3 call sites de `Variables` | `Options`, `Variables`, `TemplateDefaults` |
| `Lifecycle\SettingsMigrations` (nuevo) | Copiar global → post/page, borrar claves viejas; gate `openseo_settings_version` en `init` | `Options` (lectura cruda `get_option`) |
| `Admin\Assets` (mod) | Bootstrap `contentTypes` desde el helper + `TemplateDefaults` | `ContentTypes`, `TemplateDefaults` |
| `views/Titles.js` + `TemplateGroup` (mod/nuevo) | UI de edición por tipo/taxonomía | `SettingsPanel`, bootstrap |

## Manejo de errores y casos límite

- **Slug no público / inexistente / `attachment` en sanitize:** se descarta (whitelist), no se persiste.
- **Campo vacío (title y description) para un tipo:** `unset` de la sub-clave → cae al default runtime.
- **Separador colgante por token vacío:** ya cubierto por la limpieza existente de `Variables`.
- **Término sin descripción:** `%term_description%` → `''`, se limpia el whitespace.
- **CPT/taxonomía registrado por otro plugin tras guardar:** al ser defaults en runtime,
  aparece automáticamente con su default; si se desregistra, su valor guardado queda inerte
  (no se borra, no estorba).
- **OG/Twitter en taxonomías:** empiezan a emitir título/descripción derivados (efecto deseado, §4);
  robots (`index, follow`) y canonical (delega en WP) **no** cambian en taxonomías.
- **Front-end antes del primer wp-admin tras upgrade:** la migración corre en `init` (no `admin_init`),
  así que no hay ventana en la que el front-end pierda un template global personalizado.

## Testing

**Unit (Brain Monkey, sin WordPress):**

- `TemplateDefaultsTest`: defaults singular/taxonomía correctos.
- `VariablesTest`: `%term%`, `%term_description%`, factories de `TemplateContext`
  (`for_post`/`for_term`/`none`); la limpieza de separador colgante sigue verde.
- `ResolverTest` (ampliar): override gana; template guardado por tipo; default por tipo
  cuando vacío; rama taxonomía; home intacta **incluido el fallback `home_description` → `bloginfo`**.
- `OptionsTest`: sanitize anidado conserva slugs ausentes; whitelist rechaza slug no público y
  `attachment`; merge a nivel de campo (enviar solo `title` no borra `description`); `unset` cuando
  ambos campos quedan vacíos.
- Migración: copia valores no-default y borra claves viejas; **solo title personalizado** copia solo
  title; **idempotencia** (correr dos veces no duplica ni revierte); no-op con defaults; marca la
  versión solo tras guardado exitoso.

**JS (`test:js`):** test del merge inmutable de `TemplateGroup`/`change` (estilo reducer),
si encaja con el setup actual.

**Integración (wp-env, opcional):** `Resolver` real sobre un post y una categoría reales.

## Plan de descomposición (specs posteriores)

Consumirán este cimiento, en este orden sugerido:

1. **Spec UI:** pantalla con pestañas por tipo estilo Rank Math + dropdown inserter de variables.
2. **Spec Editor:** SERP/snippet preview en vivo (resuelto), Desktop/Mobile, barras de longitud,
   badge noindex.
3. **Spec Robots/Global Meta:** robots por tipo + panel de robots avanzados.
4. **Spec Variables enriquecidas:** `%page%`, fechas, `%category%`, autor, etc. + inserter compartido.

## Criterios de aceptación

- Posts, Pages y cada CPT elegible (`public` menos `attachment`) pueden tener su title/description
  template; vacío → default singular.
- Categorías, Tags y taxonomías custom públicas pueden tener su title/description template;
  vacío → default taxonomía.
- En un archivo de taxonomía se emite `<title>`, `meta description`, og:title/og:description y los
  equivalentes de Twitter; **robots** permanece `index, follow` y **canonical** sigue delegando en
  WordPress (sin cambios).
- La home conserva el fallback: `home_description` vacío → `bloginfo('description')`.
- El override `_openseo_*` por entrada sigue ganando sobre el template del tipo.
- La migración (en `init`, gate `openseo_settings_version`) copia un template global personalizado a
  Posts/Pages, retira las claves viejas, y es idempotente; el front-end no pierde el template tras upgrade.
- `sanitize` persiste solo slugs elegibles, conserva los no enviados, mergea a nivel de campo y hace
  `unset` de los vacíos.
- La vista admin "Titles & Meta" lista tipos y taxonomías con sus campos y el default como placeholder.
- Gates verdes: `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`, `npm run lint:js`.
