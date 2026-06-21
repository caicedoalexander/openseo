# Diseño — Robots por tipo + Global Meta

- **Fecha:** 2026-06-21
- **Estado:** Aprobado para planificación
- **Área:** Meta robots (frontend + admin + editor)
- **Tipo:** Sub-proyecto 4 de la consolidación de Titles & Meta

## Contexto

Hoy `Meta\Resolver::robots()` solo lee el override por entrada (`_openseo_robots_noindex` /
`_openseo_robots_nofollow`) en singulares y devuelve `index, follow` por defecto; no hay robots por
defecto configurable, ni por tipo de contenido, ni directivas más allá de noindex/nofollow.
`Frontend\Head\Robots` imprime el `<meta name="robots">`. El editor (pestaña Advanced) tiene dos
toggles noindex/nofollow por entrada. `Sitemap` ya excluye entradas con meta noindex `'1'`. No existe
la pantalla "Meta global" de robots (Index/No Index/No follow/No archive/Sin snippet/Ningún índice de
imagen, noindex de archivos vacíos).

Este sub-proyecto añade una **capa de robots por defecto** (global y por tipo) sobre el override por
entrada, con su cascada, su salida y su UI, reutilizando la estructura `post_types`/`taxonomies`
(sub-proyecto 1) y las pestañas por tipo (sub-proyecto 3).

### Decisiones congeladas (brainstorming 2026-06-21)

1. **Alcance:** robots por defecto global + override por tipo/taxonomía, con 5 directivas **booleanas**
   (noindex, nofollow, noarchive, nosnippet, noimageindex) + noindex de archivos de taxonomía vacíos.
   **Sin** los numéricos avanzados (max-snippet/max-video-preview/max-image-preview).
2. **Override por entrada tri-estado** (Predeterminado / Sí / No), que hereda del tipo→global.
3. **Por entrada solo noindex + nofollow** (tri-estado); las otras 3 directivas viven solo a nivel
   global + tipo.

## Objetivo

Permitir definir robots por defecto a nivel global y por tipo de contenido/taxonomía, con una cascada
clara `entrada → tipo → global` y herencia explícita, reflejada en el `<meta robots>` del frontend y
en el sitemap.

## No-objetivos

- Robots avanzados numéricos (max-snippet / max-video-preview / max-image-preview).
- Override por entrada de noarchive/nosnippet/noimageindex (solo global + tipo).
- Robots para autores / archivos de fecha / 404 / búsqueda (otras superficies).
- Migración masiva de metas existentes (se usa compatibilidad de lectura).

## Arquitectura

### 1. Modelo de datos (3 niveles)

**Global** — nuevo sub-array `robots` en `openseo_settings`, base de la cascada (booleano `'1'`/`''`):
```php
'robots' => array(
    // 'noindex' => '1', 'nofollow' => '', 'noarchive' => '', 'nosnippet' => '',
    // 'noimageindex' => '', 'noindex_empty_terms' => '',
),  // vacío por defecto → index, follow, sin directivas extra
```

**Por tipo/taxonomía** — cada entrada de `post_types[slug]` / `taxonomies[slug]` se amplía con un
`robots` **tri-estado por directiva** (`''`/ausente = heredar global, `'on'`, `'off'`):
```php
'robots' => array(
    // 'noindex' => 'on'|'off', 'nofollow' => …, 'noarchive' => …,
    // 'nosnippet' => …, 'noimageindex' => …,
),  // solo se persisten las directivas != heredar
```

**Por entrada** (meta) — `_openseo_robots_noindex` / `_openseo_robots_nofollow` pasan de toggle
`'1'`/`''` a **tri-estado** `''` (heredar) | `'on'` | `'off'`.
**Compatibilidad sin migración masiva:** el valor legado `'1'` se lee como `'on'`; `''` ya significaba
"sin override" y ahora significa "heredar". Como el global por defecto es index/follow, el
comportamiento previo se preserva (todas las entradas seguían index). `PostMeta` no cambia el registro
(el meta sigue siendo `string`). Las otras 3 directivas no tienen meta por entrada.

### 2. Cascada — `Meta\RobotsResolver` (PHP puro) + `Meta\Resolver::robots()`

`RobotsResolver::resolve(string $entry, string $type, bool $global): bool` — el nivel más específico
con opinión gana:
- `entry`: `'on'`/`'1'` → `true`; `'off'` → `false`; `''`/otro → siguiente nivel.
- `type`: `'on'` → `true`; `'off'` → `false`; `''`/ausente → siguiente nivel.
- `global`: el booleano base.

> **Cast del global (corrige MEDIUM-3):** el dato global vive como `'1'`/`''` (string) en
> `openseo_settings['robots'][$d]`. `RobotsResolver` recibe ya un `bool` (se mantiene puro y
> trivialmente testeable, como `Matcher`/`RuleValidator`); es `Resolver::robots()` quien castea con
> `'1' === (string) ($global[$d] ?? '')` antes de invocar. Así PHPStan nivel 6 no mezcla `string|bool`.

`Resolver::robots()` reescrito:
```
singular:
  noindex/nofollow  = resolve(entry, type, global)
  noarchive/nosnippet/noimageindex = resolve('', type, global)   (sin nivel entrada → entry '')
taxonomía (is_category|is_tag|is_tax, get_queried_object instanceof WP_Term):
  las 5 = resolve('', taxonomies[tax].robots[d], global[d])
  + si global.noindex_empty_terms y 0 === (int) $term->count → noindex forzado a true
    (se confía en el `count` de core del término — sin query adicional por request)
resto (no singular ni taxonomía):
  las 5 = resolve('', '', global[d])   (solo global)
```
`entry` se lee de `_openseo_robots_noindex`/`nofollow`; `type` de `post_types[type].robots` (singular)
o `taxonomies[tax].robots` (taxonomía); `global` de `robots[d]`.

### 3. Salida del meta robots

`robots()` construye la lista de directivas efectivas, manteniendo el estilo actual (index/follow
explícitos):
- `noindex` si noindex else `index`; `nofollow` si nofollow else `follow`;
- añade `noarchive`, `nosnippet`, `noimageindex` cuando están activas.
- Ej.: `index, follow` · `noindex, follow, noarchive` · `index, nofollow, nosnippet`.

> Nota (MEDIUM-4): `noimageindex`/`nosnippet`/`noindex`/`nofollow` son directivas vigentes de Google;
> `noarchive` ya no la usa Google Search (la caché desapareció) pero la respetan otros buscadores
> (Bing) y emitirla es inocuo — se mantiene por compatibilidad.

`Frontend\Head\Robots` no cambia (sigue imprimiendo `<meta name="robots" content="{robots()}">`).

> **Cambio observable (intencional):** robots empieza a actuar en archivos de taxonomía y a aplicar
> defaults global/tipo donde antes siempre era `index, follow`. Coherente con que título/descripción
> ya se emiten en taxonomías (sub-proyecto 1). Robots/canonical en otras superficies no cambian salvo
> el default global, que ahora puede teñir cualquier contexto.

### 4. UI (tres lugares)

- **Global** — sección "Robots por defecto" en la pestaña **General** de Titles & Meta: 5
  `CheckboxControl` (las directivas) + `ToggleControl` "Noindex de archivos de taxonomía vacíos".
  Escriben en `values.robots` (sub-array) vía `change('robots', { ...values.robots, [d]: '1'|'' })`.
- **Por tipo/taxonomía** — componente nuevo **`RobotsFields`** dentro de `TypePanel`: 5
  `SelectControl` tri-estado (Predeterminado / Sí / No) que escriben en
  `post_types[slug].robots` / `taxonomies[slug].robots` (vía un merge inmutable).
- **Por entrada** — editor `AdvancedTab` (`assets/src/editor/index.js`): los 2 `ToggleControl`
  noindex/nofollow → 2 `SelectControl` tri-estado (Predeterminado / Sí / No). Lee `'1'` legado como `'on'`.
- **Segundo lector del meta en el editor (corrige HIGH-1):** `GeneralTab` (`editor/index.js:201,248`)
  lee `_openseo_robots_noindex` y alimenta el badge "noindex" del SERP preview con `isNoindex={ noindex === '1' }`.
  Con el tri-estado el valor pasa a `'on'`, así que esa condición se romperá silenciosamente. **Hay que
  actualizarla** a `isNoindex={ noindex === 'on' || noindex === '1' }` (idealmente vía un helper
  `isNoindexValue(v)` en las constantes locales del editor). El badge refleja **solo el meta de la
  entrada** (no la herencia tipo/global), que es lo correcto para un preview por entrada — decisión explícita.

> **Aclaración de ubicación (corrige MEDIUM-2):** la UI global va en el `GeneralPanel` interno de
> `assets/src/admin/views/Titles.js` (primer tab del `VerticalTabs`), **no** en `assets/src/admin/views/General.js`
> (que es la vista Schema/identidad del menú). El merge usa `change('robots', { ...values.robots, [d]: '1'|'' })`
> (el reducer `CHANGE` reemplaza la clave completa, así que el spread es necesario y suficiente).

### 5. Helpers JS

- **`assets/src/admin/robots.js`** (puro, testeable): `ROBOTS_DIRECTIVES`
  (`['noindex','nofollow','noarchive','nosnippet','noimageindex']`), `TRISTATE_OPTIONS`
  (`[{label:'Default',value:''},{label:'Yes',value:'on'},{label:'No',value:'off'}]` con `__()`),
  y `setRobotsField(robotsMap, directive, value)` (merge inmutable, devuelve nuevo map).
- El editor define sus 2 selects tri-estado con las mismas opciones (constante local en el bundle del
  editor; los bundles admin/editor no comparten módulos hoy — duplicación mínima de un array de 3 opciones).

### 6. Sanitize (`Options::sanitize`)

- **Global `robots`** (si `isset($input['robots'])`): por cada directiva conocida
  (`noindex,nofollow,noarchive,nosnippet,noimageindex,noindex_empty_terms`) → `'1'`/`''`; ignora claves
  desconocidas.
- **Por tipo `robots`**: se amplía `sanitize_template_map` (sub-proyecto 1) para aceptar, por slug,
  además de `title`/`description`, un `robots` map con las 5 directivas **whitelisteadas a
  `''|'on'|'off'`** (otro valor → `''`; directivas `''` se omiten del map guardado, merge
  directiva-a-directiva preservando las stored no enviadas). **Si el `robots` resultante queda vacío,
  no se incluye la clave `robots` en el slug** (corrige LOW-3), para que el `unset` por slug funcione.
  El `unset` de slug vacío considera los **3** campos (title `''`, description `''` y robots vacío → unset).

### 7. Sitemap (`Sitemap.php`) — alineación con la cascada

Dos planos que, juntos, **cubren todos los casos sin huecos**:

1. **Override por entrada** — `wp_sitemaps_posts_query_args` excluye entradas cuyo meta noindex sea
   `'on'` **o** `'1'` (no solo `'1'`).
2. **Nivel de tipo/taxonomía** — se **omite el sub-sitemap completo** de un post type/taxonomía cuyo
   robots efectivo (tipo→global, `entry=''`) sea `noindex`, mediante **dos filtros nuevos** que
   `Sitemap::register()` debe añadir (corrige HIGH-3):
   - `wp_sitemaps_post_types` → callback `exclude_noindex_post_types( $post_types )`
   - `wp_sitemaps_taxonomies` → callback `exclude_noindex_taxonomies( $taxonomies )`
   Ambos reciben un **array de objetos keyed by slug**; el callback hace `unset( $items[ $slug ] )`
   cuando `RobotsResolver::resolve('', $type_noindex, $global_noindex)` es `true` (lee el slug como
   **clave del array**, no como propiedad del objeto).

> **Coherencia sitemap↔cascada (corrige HIGH-2):** el `noindex` heredado de tipo/global es **uniforme
> por tipo** (no depende de la entrada salvo override). Por tanto: si un tipo/taxonomía resuelve
> `noindex` por la cascada tipo→global, el plano (2) omite su provider entero (cubre todas sus entradas
> con meta `''` que heredan noindex); las entradas con override `'on'`/`'1'` las cubre el plano (1). No
> quedan entradas que el frontend marque `noindex` mientras el sitemap las lista.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Meta\RobotsResolver` (nuevo) | `resolve(entry,type,global)` por directiva (puro) | — |
| `Meta\Resolver` (mod) | `robots()` cascada singular/taxonomía/otros + términos vacíos + string | `RobotsResolver`, `Options`, WP conditional tags |
| `Settings\Options` (mod) | defaults `robots` global; sanitize global + robots por tipo | helpers existentes |
| `Sitemap\Sitemap` (mod) | meta `'on'`/`'1'` en query; +2 filtros `wp_sitemaps_post_types`/`wp_sitemaps_taxonomies` (excluir tipos/tax noindex) | `RobotsResolver`, `Options` |
| `robots.js` (nuevo) | directivas, opciones tri-estado, `setRobotsField` (puro) | — |
| `components/RobotsFields` (nuevo) | selects tri-estado por tipo/taxonomía | `robots.js` |
| `views/Titles.js` (mod) | robots global en `GeneralPanel`; `RobotsFields` en `TypePanel` | `RobotsFields`, `change`/`setTemplateField` |
| `editor/index.js` (mod) | `AdvancedTab` noindex/nofollow tri-estado **+ `GeneralTab` badge `isNoindex` tri-estado (HIGH-1)** | — |

## Manejo de errores y casos límite

- **Meta legado `'1'`:** se lee como `'on'` (forzar noindex/nofollow) en Resolver y editor.
- **Directiva tipo desconocida / valor inválido:** sanitize la normaliza a `''` (heredar).
- **Slug con solo robots (sin title/description):** se conserva (no se hace unset).
- **Taxonomía no `WP_Term`:** la rama de taxonomía cae a global (guard `instanceof WP_Term`).
- **`noindex_empty_terms` con término sin posts:** fuerza noindex aunque tipo/global digan index.
- **Global vacío (defecto):** todas las directivas off → `index, follow` (comportamiento actual preservado).
- **Sitemap:** un tipo noindex se omite por completo; las entradas con override noindex (`on`/`1`) se
  excluyen individualmente.
- **Seguridad/i18n:** sanitize en entrada; bootstrap ya usa `JSON_HEX_TAG`; cadenas por `__()`; sin
  `dangerouslySetInnerHTML`.

## Testing

**PHP unit (Brain Monkey):**
- `RobotsResolverTest`: entrada gana sobre tipo gana sobre global; `'1'`-legacy → on; `'off'` fuerza
  false; `''`/ausente → siguiente nivel.
- `ResolverTest` (ampliar): cascada singular (entrada/tipo/global) para noindex/nofollow;
  noarchive/nosnippet/noimageindex tipo→global; rama taxonomía; términos vacíos → noindex; string de
  salida con varias directivas; default global vacío → `index, follow` (no romper tests existentes).
- `OptionsTest` (ampliar): sanitize global `robots` (whitelist + `'1'`/`''`); robots por tipo
  tri-estado whitelist (`''|'on'|'off`); `unset` con los 3 campos vacíos; conserva slug con solo robots.

**JS (Jest):** `robots.test.js`: `setRobotsField` (merge inmutable, no muta, preserva otras
directivas); forma de `ROBOTS_DIRECTIVES`/`TRISTATE_OPTIONS`.

**Tests existentes a actualizar (no romper):** `SitemapTest::test_exclude_noindex_builds_or_clause`
asserta hoy la forma exacta de la `meta_query` (`value '1'`); el plan debe actualizarlo a la nueva
forma que excluye `'1'` **y** `'on'` (corrige LOW-1). `RobotsTest`/`PluginBootTest` (default
`index, follow` y `'1'`→noindex) siguen verdes bajo la lectura legacy — confirmar.

**Integración (wp-env, opcional):** post type marcado noindex se omite del sitemap; archivo de
taxonomía vacío emite `noindex`.

## Criterios de aceptación

- Robots por defecto configurable a nivel global (5 directivas + noindex de términos vacíos).
- Robots por tipo de contenido y por taxonomía con tri-estado (heredar / forzar / desactivar) para las
  5 directivas.
- Override por entrada tri-estado para noindex/nofollow; `'1'` legado se respeta como `'on'`.
- Cascada `entrada → tipo → global` por directiva; una entrada puede forzar `index` aunque su tipo sea
  noindex (gracias al tri-estado).
- El `<meta robots>` refleja las directivas efectivas en singulares, taxonomías y resto.
- Un tipo/taxonomía noindex se omite del sitemap; entradas noindex se excluyen.
- El default global vacío preserva `index, follow` (sin regresión).
- Gates verdes: `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`,
  `npm run lint:js`, `npm run test:js`, `npm run build`.
