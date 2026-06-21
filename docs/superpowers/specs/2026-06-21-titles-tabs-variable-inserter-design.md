# Diseño — UI con pestañas + inserter de variables (Titles & Meta)

- **Fecha:** 2026-06-21
- **Estado:** Aprobado para planificación
- **Área:** Admin · vista "Titles & Meta"
- **Tipo:** Sub-proyecto 3 de la consolidación de Titles & Meta

## Contexto

La vista admin "Titles & Meta" (`assets/src/admin/views/Titles.js`, del sub-proyecto 1) es
**una sola columna larga**: separador + título/descripción de home, luego un encabezado
"Content types" con un campo título+descripción por tipo (`TemplateGroup`), y otro "Taxonomies"
igual. Dos carencias frente a Rank Math:

1. **Sin pestañas:** con varios CPTs/taxonomías la página se vuelve una lista interminable.
2. **Sin descubrimiento de variables:** existen 8 tokens (`%title%`, `%excerpt%`, `%sitename%`,
   `%tagline%`, `%sep%`, `%currentyear%`, `%term%`, `%term_description%`) pero la UI no los
   muestra ni permite insertarlos — el usuario debe conocerlos de memoria.

Este sub-proyecto reorganiza la vista en pestañas verticales y añade un dropdown inserter de
variables en cada campo de template.

### Decisiones congeladas (brainstorming 2026-06-21)

1. **Pestañas verticales (sidebar)** agrupadas: *General* · "Tipos de contenido" (una por CPT) ·
   "Taxonomías" (una por taxonomía). Estilo Rank Math; escala con muchos tipos.
2. **Variables filtradas por contexto:** tipo de contenido → `global + singular`; taxonomía →
   `global + taxonomy`; General → solo `global`.
3. **Inserción en la posición del cursor** (no al final).

## Objetivo

Que "Titles & Meta" sea navegable por pestañas (una por superficie) y que cada campo de template
ofrezca un dropdown buscable de las variables aplicables, insertándolas donde está el cursor.

## No-objetivos

- Ampliar el set de variables (es el sub-proyecto 5; aquí se usan las 8 existentes).
- Tocar otras vistas del menú (General, Social, Sitemaps, Schema… son submenús aparte).
- Persistir la pestaña activa en la URL (solo estado de sesión).
- Editar robots/social por tipo (otros sub-proyectos).

## Arquitectura

### 1. Catálogo de variables — `Meta\VariableCatalog` (PHP, nuevo)

`Variables.php` conoce los tokens pero sin metadata. `VariableCatalog` devuelve la lista con
etiqueta, descripción y scope (las cadenas pasan por `__()` con text domain `openseo`):

| token | label | description | scope |
|---|---|---|---|
| `%sitename%` | Site title | Your site's name | global |
| `%tagline%` | Tagline | Your site's tagline | global |
| `%sep%` | Separator | The separator character | global |
| `%currentyear%` | Current year | The current year | global |
| `%title%` | Title | The entry title | singular |
| `%excerpt%` | Excerpt | The entry excerpt | singular |
| `%term%` | Term name | The taxonomy term name | taxonomy |
| `%term_description%` | Term description | The taxonomy term description | taxonomy |

API: `VariableCatalog::all(): array<int, array{token:string,label:string,description:string,scope:string}>`.

### 2. Bootstrap

`Admin\Assets::bootstrap()` añade `variables` (el catálogo) a `window.openseoAdmin`.
`VariableCatalog` se inyecta en `Assets` (igual que `ContentTypes`/`TemplateDefaults` en el
sub-proyecto 1), construido una vez en `Plugin::modules()`.

### 3. Anti-drift (clave)

Riesgo: que el catálogo liste un token que `Variables::replace` no expande → el inserter ofrecería
un token muerto. Mitigación: un **test** que recorre cada token del catálogo y verifica que la salida
de `Variables::replace('%token%', $contexto_adecuado)` **no contiene la cadena literal `%token%`**
(corrige M3: "no contiene" es más robusto que "≠ literal" — cubre plantillas con texto alrededor; si
un token del catálogo no está en el mapa de `Variables::replace`, `strtr` lo deja intacto y la salida
sí lo contiene → el test falla). El contexto se elige por scope, con valores **no vacíos** para que
la aserción sea fuerte: `singular` → `TemplateContext::for_post` (mockear `get_the_title`/`get_the_excerpt`
no vacíos); `taxonomy` → `for_term` con un `WP_Term` poblado; `global` → `none()`. Caso documentado:
`%sep%` resuelve y luego la limpieza de separadores colgantes de `Variables::replace` lo deja vacío
— sigue cumpliendo "no contiene `%sep%`". Así catálogo y reemplazo no pueden divergir sin romper el test.

> Deja listo el sub-proyecto 5 (variables enriquecidas): añadir un token = añadir entrada al
> catálogo **y** su reemplazo en `Variables`; el test anti-drift obliga a ambos.

### 4. Componentes React (`assets/src/admin/components/`)

- **`VerticalTabs`** — sidebar de pestañas + panel. Props: `groups`
  (`[{ label?:string, tabs:[{name:string,title:string}] }]`), `active:string`, `onSelect:(name)=>void`,
  `children:(active)=>node`. Renderiza encabezados de grupo (General sin encabezado, "Content types",
  "Taxonomies"), resalta la pestaña activa, y muestra `children(active)` en el panel. Presentacional.

  **Contrato ARIA/teclado (corrige H2)** — `VerticalTabs` propio (los encabezados de grupo justifican
  no usar `TabPanel`, que no soporta secciones), pero replicando el patrón WAI-ARIA Tabs:
  - Contenedor de pestañas: `role="tablist"` + `aria-orientation="vertical"`.
  - Cada pestaña: `<button role="tab">` con `id` único, `aria-selected={active===name}`,
    `aria-controls={panelId}`, y **roving tabindex** (`tabindex=0` la activa, `-1` el resto).
  - Panel: `role="tabpanel"` con `id={panelId}` y `aria-labelledby={tabId}`.
  - Encabezados de grupo: NO son `tab` (texto con `role="presentation"`, fuera del `tablist` o como
    separador no enfocable).
  - Teclado: ↑/↓ mueven entre pestañas (envuelven), Home/End a la primera/última; Enter/Space activan.

- **`VariableInserter`** — `Button` (icono `+`/variable) que abre un `Dropdown` de
  `@wordpress/components` con `SearchControl` + la lista de variables del scope. Props:
  `catalog:array`, `scope:string`, `onInsert:(token)=>void`. Filtra con `variablesForScope(catalog, scope)`.
  - **El catálogo llega por prop** (corrige L4): `Titles.js` lee `window.openseoAdmin.variables ?? []`
    una vez en el container y lo pasa hacia abajo; el componente queda puro/testeable sin stubbear `window`.
  - **Búsqueda (corrige L2):** búsqueda vacía → todas las del scope; con texto → filtra por
    label/token/description. "No variables" si el scope no tiene; "No results" si la búsqueda no coincide.
  - **Foco/teclado (corrige H3):** usar el render-prop de `Dropdown` (`renderToggle`/`renderContent`)
    para heredar `aria-haspopup`/`aria-expanded`. Al abrir, el foco va al `SearchControl`. Las variables
    son `<button>` (foco/Enter/Space nativos). Esc cierra. Al insertar: cerrar el dropdown **y devolver
    el foco al input del `TemplateField`** con el cursor restaurado (ver §5).

- **`TemplateField`** — un campo de template con su `VariableInserter` al lado; inserta **en la
  posición del cursor**. Props: `label`, `value`, `placeholder`, `multiline`, `scope`, `catalog`,
  `onChange:(value)=>void`. **Presentacional con `onChange(value)`** (corrige L1): la adaptación a
  `setTemplateField`/`change` vive en `Titles.js`.

  **Mecanismo del input (corrige H1):** `TemplateField` **NO** usa `TextControl`/`TextareaControl`
  (su `ref` no apunta al DOM input — Gutenberg #28756). Renderiza su propio `<label>` + `<input
  class="components-text-control__input">` (o `<textarea>` si `multiline`) con un `useRef` de
  `@wordpress/element`, para tener control total de `selectionStart/End` y `setSelectionRange`. Así el
  estilo coincide con los controles de WP y el cursor es manejable de forma fiable.

### 5. Módulos puros JS (testeables sin React)

- **`cursor.js`**: `insertAtCursor(value, token, start, end)` → `{ value, cursor }` — inserta
  `token` reemplazando el rango `[start,end)` (o en `start` si no hay selección) y devuelve la nueva
  posición del cursor (`start + token.length`).
- **`variables.js`**: `variablesForScope(catalog, scope)` → las variables con `scope === 'global'`
  más las del `scope` dado, en orden de catálogo.

*Mecanismo del cursor (con guarda — corrige M1):* al insertar, el handler lee `selectionStart/End`
del input nativo vía `ref`, llama `insertAtCursor`, propaga el nuevo valor por `onChange`, y **guarda
la posición del cursor resultante en un `pendingCursorRef`**. Un `useEffect` aplica
`setSelectionRange` + `focus()` **solo cuando `pendingCursorRef.current` tiene valor**, y lo limpia
después. El tecleo normal (cada pulsación es un `onChange` → re-render) **no** toca ese ref, así que
el navegador mantiene el cursor por sí mismo — sin saltos. Si `ref.current` fuera `null` (caso
degenerado de primer render), el fallback es insertar al final del valor.

*Overflow del dropdown (corrige M2):* el `Popover` del `Dropdown` se renderiza en su slot/portal por
defecto (no forzar `inline`); el SCSS del layout sidebar+panel **no** debe poner `overflow:hidden`
en el contenedor ancestro del inserter, para que el popover no se recorte. Verificar en el smoke test
con el último campo del panel (popover abriendo hacia abajo).

### 6. Reescritura de `views/Titles.js`

- Construye `groups` desde `window.openseoAdmin.contentTypes`:
  - `{ tabs: [{ name:'general', title: __('General') }] }`
  - `{ label: __('Content types'), tabs: postTypes.map(t => ({ name:`pt:${t.slug}`, title:t.label })) }`
  - `{ label: __('Taxonomies'), tabs: taxonomies.map(t => ({ name:`tax:${t.slug}`, title:t.label })) }`
- `useState('general')` para la pestaña activa; el panel según `active`:
  - **General**: `Title separator` (campo simple, **sin** inserter — es un carácter, no un template)
    + `home_title` + `home_description` (`TemplateField` scope `global`).
  - **`pt:slug`**: title + description del tipo (`TemplateField` scope `singular`, `placeholder` =
    default del bootstrap), escribe en `post_types[slug]` con `setTemplateField`.
  - **`tax:slug`**: igual, scope `taxonomy`, escribe en `taxonomies[slug]`.
- Se **elimina** `components/TemplateGroup.js` (la lista plana se sustituye por el render
  por-pestaña con `TemplateField`); `templateFields.setTemplateField` se conserva.

### 7. CSS

`assets/src/admin/style.scss`: layout sidebar + panel (flex/grid), encabezados de grupo, estado de
pestaña activa, y el dropdown del inserter (lista, hover, búsqueda). El bundle admin ya extrae
`style-admin-settings.css`.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Meta\VariableCatalog` (nuevo) | Lista de variables con label/description/scope | `__()` |
| `Admin\Assets` (mod) | Bootstrap `variables` | `VariableCatalog` |
| `Plugin` (mod) | Construir+inyectar `VariableCatalog` | — |
| `variables.js` (nuevo) | `variablesForScope` (puro) | — |
| `cursor.js` (nuevo) | `insertAtCursor` (puro) | — |
| `VerticalTabs` (nuevo) | Navegación sidebar + panel (ARIA tablist vertical + teclado) | — |
| `VariableInserter` (nuevo) | Dropdown buscable de variables (catálogo por prop) | `variablesForScope` |
| `TemplateField` (nuevo) | Campo nativo (`useRef`) + inserter + inserción en cursor | `VariableInserter`, `cursor.js` |
| `views/Titles.js` (mod) | Pestañas + paneles por superficie; lee `variables`/`contentTypes` del bootstrap y los pasa como props | `VerticalTabs`, `TemplateField`, `setTemplateField` |

## Manejo de errores y casos límite

- **Sin CPTs/taxonomías extra:** los grupos respectivos quedan con solo Posts/Pages o
  Categorías/Tags; si un grupo queda vacío, no se renderiza su encabezado.
- **`window.openseoAdmin.variables` ausente:** el inserter cae a `[]` (no rompe; el campo sigue
  editándose a mano).
- **Scope sin variables tras el filtro:** el dropdown muestra un estado vacío ("No variables").
- **Búsqueda sin resultados:** lista vacía con mensaje.
- **Inserción sin selección:** inserta en `selectionStart`; si el ref no está disponible, inserta al
  final (fallback degradado).
- **Pestaña activa apunta a un tipo que ya no existe** (CPT desregistrado entre cargas): el panel
  cae a General. La pestaña **General es incondicional** (estática, no derivada de `contentTypes`),
  así que el fallback siempre tiene destino válido (corrige M4): si `active` no figura entre las
  pestañas de `groups`, normalizar a `'general'`. Con `contentTypes` ausente → `?? { postTypes:[], taxonomies:[] }`
  (patrón actual de `Titles.js`): los grupos pt/tax quedan vacíos pero General permanece.
- **i18n:** todas las cadenas propias (encabezados, "General", labels del inserter) pasan por `__()`.
  Las labels/descriptions del catálogo se traducen en PHP (`__('…','openseo')`) y viajan ya traducidas
  en el bootstrap; las cadenas JS se traducen vía `wp_set_script_translations` (ya presente). Regenerar
  `languages/openseo.pot` tras añadir cadenas (L3).
- **XSS:** todo se renderiza como texto en React (sin `dangerouslySetInnerHTML`); el bootstrap usa
  `wp_json_encode(..., JSON_HEX_TAG)`.

## Testing

**Unit PHP (Brain Monkey):**
- `VariableCatalogTest`: estructura (8 entradas con token/label/description/scope), scopes correctos.
- **Anti-drift**: por cada token del catálogo, `Variables::replace('%token%', contexto-por-scope)`
  no devuelve el token literal (mockeando las funciones WP que toca cada contexto).

**Unit JS (Jest):**
- `cursor.test.js`: `insertAtCursor` en medio, al final, con selección (reemplaza el rango), cursor
  resultante correcto.
- `variables.test.js`: `variablesForScope` → global+singular, global+taxonomy, solo global; preserva
  el orden del catálogo.

**Visual/manual (wp-env):** navegar las pestañas, abrir el inserter, buscar e insertar un token en
la posición del cursor; verificar que cada scope ofrece las variables correctas.

## Criterios de aceptación

- "Titles & Meta" se navega por pestañas verticales: General + una por tipo de contenido + una por
  taxonomía, agrupadas con sus encabezados.
- Cada campo de template (home title/description, y title/description por tipo/taxonomía) tiene un
  inserter que lista las variables del scope correcto y las inserta en la posición del cursor.
- El `Title separator` no tiene inserter (es un carácter).
- El catálogo y `Variables::replace` no divergen (test anti-drift verde).
- El guardado sigue funcionando por `openseo/v1/settings` (sin cambios en el REST ni en el modelo de
  datos `post_types`/`taxonomies`).
- Gates verdes: `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`,
  `npm run lint:js`, `npm run test:js`, `npm run build`.
