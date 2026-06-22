# Variables enriquecidas — Diseño (sub-proyecto 5)

**Fecha:** 2026-06-21
**Estado:** aprobado por el usuario (pendiente de auditoría de diseño + revisión del spec)

## Objetivo

Ampliar el catálogo de variables de plantilla de OpenSEO con seis tokens de
entrada de uso común —`%date%`, `%modified%`, `%author%`, `%category%`,
`%tag%`, `%parent_title%`— resolviéndolos también en el preview SERP en vivo
del editor (paridad práctica, con un par de divergencias menores documentadas
en "Riesgos"). Inspirado en Rank Math, pero recortado a lo que aporta valor
real en plantillas de título/meta sin abrir features mayores.

## Contexto y arquitectura actual

OpenSEO ya tiene un sistema de variables maduro (Fases 1–3) que este
sub-proyecto **extiende**, sin reescribir:

- **`OpenSEO\Meta\Variables::replace(string $template, ?TemplateContext $ctx): string`**
  — expansión en servidor vía `strtr`, luego colapso de whitespace y limpieza
  de separadores colgantes. Es **puro respecto a la entrada**: los únicos reads
  de WP son `get_bloginfo`/`$options->get` (globales); todo lo específico de la
  entrada/término llega como primitivos vía `TemplateContext`.
- **`OpenSEO\Meta\TemplateContext`** — value object inmutable con factories
  `for_post(int $id)`, `for_term(WP_Term $t)`, `none()`. **Todas las lecturas de
  WP por entrada viven aquí**, no en `Variables`.
- **`OpenSEO\Meta\VariableCatalog::all()`** — metadata (`token`/`label`/
  `description`/`scope`) para el inserter del admin. Un **test anti-drift**
  exige que el conjunto de tokens del catálogo coincida con los que
  `Variables::replace` expande.
- **Bootstrap del catálogo:** `Admin\Assets` serializa `VariableCatalog::all()`
  a `window.openseoAdmin.variables` (lo consume el inserter en *Titles & Meta*).
  Los tokens nuevos aparecen **automáticamente** en el inserter al añadirlos al
  catálogo; no hay trabajo de UI de inserter en esta fase.
- **Preview SERP del editor:** `assets/src/editor/preview.js` `expandTokens()`
  refleja `Variables::replace` en cliente. `GeneralTab` (en
  `assets/src/editor/index.js`) ensambla hoy el mapa de tokens
  (`%title%`, `%excerpt%`, `%sitename%`, `%tagline%`, `%sep%`, `%currentyear%`)
  desde `window.openseoEditor` + el store de Gutenberg, y lo pasa a
  `resolveSnippet({override, template, tokens})`.

Estado actual del catálogo (8 tokens): global `%sitename%`/`%tagline%`/`%sep%`/
`%currentyear%`; singular `%title%`/`%excerpt%`; taxonomy `%term%`/
`%term_description%`.

## Tokens nuevos (6, scope `singular`)

| Token | Resuelve a | Lectura WP (en `for_post`) |
|-------|-----------|-----------------------------|
| `%date%` | Fecha de publicación con el `date_format` del sitio | `get_the_date('', $id)` |
| `%modified%` | Fecha de última modificación con el `date_format` del sitio | `get_the_modified_date('', $id)` |
| `%author%` | Nombre visible del autor de la entrada | `get_the_author_meta('display_name', (int) get_post_field('post_author', $id))` |
| `%category%` | Nombre de la **primera** categoría (orden de `get_the_category`) | `$c = get_the_category($id); $c[0]->name ?? ''` — `get_the_category` devuelve **`[]`** cuando no hay |
| `%tag%` | Nombre de la **primera** etiqueta | `$t = get_the_tags($id); is_array($t) ? ($t[0]->name ?? '') : ''` — `get_the_tags` devuelve **`false`** (no `[]`) cuando no hay |
| `%parent_title%` | Título de la entrada padre (jerárquicas), `''` si no hay padre | `wp_get_post_parent_id($id)` → `get_the_title($parent)` si `>0` |

Notas de decisión (cerradas durante el brainstorm):

- **Sin "término primario" configurable.** WordPress core no tiene ese
  concepto (es postmeta propio de Yoast/Rank Math). `%category%`/`%tag%` usan la
  **primera** en el orden devuelto por core. No se añade UI ni postmeta de
  término primario.
- **Sin variables parametrizadas** (`%date(F j, Y)%`): romperían el modelo
  `strtr`. `%date%`/`%modified%` usan el `date_format` del sitio.
- Todos son scope `singular` (aparecen en las pestañas de tipo de contenido del
  inserter). En entradas sin categorías/tags/padre el token resuelve `''` y la
  lógica de whitespace/separador existente lo colapsa.

## Arquitectura — 4 capas por token

Cada token toca las mismas cuatro capas; el patrón ya existe, solo se extiende.

### Capa 1 — `TemplateContext`
Añadir seis propiedades `readonly string`: `date`, `modified`, `author`,
`category`, `tag`, `parent_title`. El constructor privado las recibe; `for_post`
las puebla con las lecturas de la tabla de arriba; `for_term` y `none` las
dejan `''`. **`Variables` sigue sin reads de WP nuevos.**

### Capa 2 — `Variables::replace`
Seis entradas nuevas en el mapa `$replacements`, leídas de `$context`
(`'%date%' => $context->date`, etc.). Sin otros cambios: el colapso de
whitespace y la limpieza de separador ya manejan tokens vacíos.

### Capa 3 — `VariableCatalog::all`
Seis entradas nuevas (`token`/`label`/`description`/`scope => 'singular'`), con
strings i18n (`__( …, 'openseo' )`). El test anti-drift pasa a comparar 14
tokens en ambos lados.

### Capa 4 — Editor (paridad de preview en vivo)
Extraer el ensamblado del mapa de tokens de `GeneralTab` a un hook
**`useTemplateTokens()`** (nuevo archivo `assets/src/editor/useTemplateTokens.js`),
para no inflar `index.js`. El hook devuelve el mapa completo (los 6 actuales +
los 6 nuevos). Fuentes cliente de los nuevos:

- `%date%`/`%modified%` → `getEditedPostAttribute('date'|'modified')` (ISO),
  formateado con **`@wordpress/date`** `dateI18n( getSettings().formats.date, iso )`
  → paridad con el `date_format` del servidor sin tocar el bootstrap. Importar
  `@wordpress/date` como **módulo ES** (`import { dateI18n, getSettings } from
  '@wordpress/date'`), no `window.wp.date`, para que `@wordpress/scripts` lo
  externalice a `wp-date` y lo añada a `editor.asset.php` (verificar tras
  `npm run build`). `@wordpress/core-data` ya está externalizado (lo usa
  `useEntityProp`).
- `%author%` → `getEditedPostAttribute('author')` (ID) → `core` store
  **`getEntityRecord('root','user', authorId)?.name`** (display name). NO usar
  `getUser()` (deprecado / no estable en core-data). `''` mientras resuelve.
- `%category%`/`%tag%` → `getEditedPostAttribute('categories'|'tags')` (IDs) →
  primer ID → `core` `getEntityRecord('taxonomy','category'|'post_tag', id)` →
  `name`. `''` mientras resuelve / si no hay.
- `%parent_title%` → `getEditedPostAttribute('parent')` (ID) →
  `getEntityRecord('postType', postType, parentId)` → `title.rendered`. `''` si
  no hay padre.

Mientras core-data resuelve (selectors devuelven `undefined`), el token queda
`''` y el preview no rompe. `GeneralTab` pasa a consumir `useTemplateTokens()`
en lugar de construir el mapa inline.

> El editor **no** tiene inserter (solo edita el override por entrada + ve el
> preview); los tokens nuevos importan ahí únicamente para resolver el preview
> cuando la plantilla de tipo los usa. No hay cambios de inserter en el editor.

## Testing

- **PHP unit (`composer test:unit`):**
  - `VariablesTest`: un caso por token nuevo, construyendo un `TemplateContext`
    con los primitivos correspondientes y verificando la expansión (incl. el
    colapso cuando el token es `''`).
  - `VariableCatalogTest`: el test anti-drift existente cubre los 6 nuevos
    (catálogo ↔ Variables = 14 tokens).
  - `TemplateContext`: un test de `for_post()` con Brain Monkey mockeando
    `get_the_date`/`get_the_modified_date`/`get_post_field`/
    `get_the_author_meta`/`get_the_category`/`get_the_tags`/
    `wp_get_post_parent_id`/`get_the_title`, verificando que los primitivos se
    pueblan. (Brain Monkey, sin WordPress cargado.)
- **JS (`npm run test:js`):**
  - `preview.test.js` ya cubre `expandTokens`. Para no dejar la Capa 4 sin red
    automatizada, **extraer como funciones puras** (testeables sin core-data) la
    lógica que sí se puede aislar: selección de "primer término" a partir de un
    array de registros (`firstTermName(records)`), extracción del nombre de
    autor de un registro (`authorName(user)`), y el formateo de fecha
    (envoltura fina sobre `dateI18n`). Añadir casos Jest para cada una (incl.
    entradas vacías/`undefined`).
  - El hook `useTemplateTokens` queda como una **capa fina de cableado de
    selectores** sobre esos helpers puros; solo ese cableado se valida vía
    `build` + smoke test manual (no se fuerza un test de integración de React).
- **Gates de siempre:** `composer check` (PHPCS + PHPStan nivel 6 + PHPUnit) y
  `npm run lint:js` / `test:js` / `build`, todos en verde antes de cada commit.

## Fuera de alcance (YAGNI)

- Tokens ambientales `%page%` (paginación) y `%search_query%` (no encajan en
  `TemplateContext`, sin preview en vivo).
- Término primario configurable (postmeta + UI).
- Variables con argumentos (`%date(formato)%`).
- Variables en plural / listas (`%categories%`, `%tags%`).
- Tokens de organización (`%org_name%`, etc.) y `%post_url%`/`%thumbnail%`.

## Riesgos y mitigaciones

- **Drift catálogo ↔ Variables:** mitigado por el test anti-drift existente
  (falla si los conjuntos divergen).
- **Paridad de fecha cliente↔servidor:** `@wordpress/date` `dateI18n` con
  `getSettings().formats.date` replica `get_the_date` (mismo `date_format` y
  locale que core). Riesgo residual bajo (diferencias de zona horaria en el ISO
  del editor) — aceptable para un preview.
- **Estados de carga de core-data:** los selectors devuelven `undefined` hasta
  resolver; el hook normaliza a `''` para que el preview nunca muestre
  `undefined` ni el token crudo.
- **`get_the_tags` devuelve `false`** (no `[]`) cuando no hay etiquetas, y
  **`get_the_category` devuelve `[]`**: dos formas de "vacío" distintas. El
  factory usa guards distintos (ver tabla). El `is_array()` de `%tag%` es
  además necesario para PHPStan nivel 6 (retorno `array|false`).
- **Orden del "primer término" puede diferir cliente↔servidor** (M2,
  divergencia aceptada): el servidor toma `get_the_category()[0]` (orden de
  core) y el cliente `getEditedPostAttribute('categories')[0]` (orden del store
  del editor); ambos son una categoría válida de la entrada, pero pueden no ser
  la misma. Se acepta la divergencia; alinear estrictamente queda fuera de
  alcance.
- **`%author%` en el preview puede quedar vacío para roles sin `list_users`**
  (H2): `GET wp/v2/users/<id>` puede devolver 403 a autores/colaboradores, así
  que `getEntityRecord('root','user', id)` resuelve `undefined` → token `''` en
  el preview. El frontend (`get_the_author_meta`) lo resuelve siempre. Aceptable
  para un preview.
- **No tocar `%currentyear%`** (L1): hoy usa `gmdate('Y')` (UTC) en servidor y
  `new Date().getUTCFullYear()` en cliente — coherente entre sí. Los nuevos
  `%date%`/`%modified%` usan la zona/formato del sitio (coherentes entre sí). No
  "armonizar" `%currentyear%` a zona local: rompería la paridad existente.
- **`%modified%` en una entrada nunca modificada** (L2): `get_the_modified_date`
  devuelve la fecha de publicación cuando `post_modified == post_date`
  (comportamiento de core, esperado — no es un bug).
