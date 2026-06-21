# OpenSEO — Consolidación de UI/UX del admin (documento de diseño)

**Fecha:** 2026-06-20
**Estado:** Aprobado en brainstorming · revisado tras auditoría de `wp-design-reviewer`
(H1–H3, M1–M5, L1–L4 incorporados) · pendiente de revisión final del usuario
**Alcance:** **Fase 1** de la consolidación de la superficie de administración. Reorganiza el
menú al patrón del sector (menú propio + un submenú por sección, sin tabs) y migra las vistas
de ajustes a React sobre una capa REST. La **Fase 2** (Redirecciones/404 en React + REST CRUD)
queda fuera de este spec. El plan de implementación detallado se redacta aparte (writing-plans).

---

## 1. Objetivo y decisiones fijadas

Hoy OpenSEO expone su configuración como **una sola página con 7 tabs** bajo *Ajustes → OpenSEO*
(`add_options_page`, Settings API, formulario único a `options.php`) y un **gestor aparte** bajo
*Herramientas → OpenSEO Redirects* (`add_management_page`, `WP_List_Table` + `admin_post`, con
sub-tabs internos de redirecciones y 404). Eso no se parece a los plugins del sector (Yoast,
Rank Math) ni deja que cada funcionalidad crezca en su propia vista.

El objetivo es **consolidar** esa superficie: un **menú top-level propio** en el sidebar de WP,
con **un submenú real por sección** (cada uno su URL, deep-linkable y ampliable), **sin tabs**,
y con las **vistas en React** para personalizarlas a fondo.

Decisiones tomadas en brainstorming:

| Decisión | Elección |
|----------|----------|
| **Organización del menú** | Menú top-level `openseo` + 9 submenús **WP reales**. La propia barra lateral de WP es la navegación entre secciones (resalta la activa); **no** se duplica con una nav interna. |
| **Tecnología de las vistas** | **React** (`@wordpress/element` + `@wordpress/components`), reutilizando el patrón ya usado en el editor (`assets/src/editor/`). |
| **Chrome compartido** | Cabecera de marca en **PHP** (un partial reutilizable), común a páginas React y PHP, para coherencia visual mientras Redirec./404 sigan en PHP. |
| **Mapa de submenús** | Dashboard (landing) · General · Títulos y Meta · Social · Sitemaps · Schema · Redirecciones · 404s · IA. |
| **Capa de datos (ajustes React)** | **Controlador REST propio** `openseo/v1/settings` (GET/POST), reutilizando `Options::sanitize()`. No se usa el endpoint core de settings. |
| **Toggles de Redirec./404 en Fase 1** | Se conservan como **mini-formulario Settings API** en sus páginas PHP reubicadas (clase `Settings\BehaviorSettings`). Migran a React+REST en Fase 2 con el CRUD. |
| **Registro de submenús** | **Centralizado en `Admin\Menu`** (un solo bucle, orden determinista). Las páginas PHP solo aportan su render-callback; no se auto-registran (ver §3, H1). |
| **Secuencia** | **Fase 1 (este spec):** menú + cabecera + Dashboard + 6 vistas de ajustes en React sobre REST; Redirecciones/404 **reubicados** conservando su vista PHP (list table + mini-form de toggles). **Fase 2 (futuro):** Redirec./404 → React + REST CRUD. |
| **Compatibilidad de URLs** | Sin redirects de compatibilidad para las URLs antiguas (`options-general.php?page=openseo&tab=…`, `tools.php?page=openseo-redirects`): el plugin aún no está publicado. |

### Principio rector

La reorganización **no añade ni cambia ninguna opción ni feature funcional**: es un cambio de
*organización del menú* y de *mecanismo de entrega de la UI*. El modelo de datos (una sola clave
`openseo_settings`) y su sanitización se conservan intactos, y **ninguna opción existente se
queda sin UI** (los toggles de redirec./404 siguen accesibles, ver §6). Esto mantiene el riesgo
acotado y deja el terreno listo para que cada sección crezca por separado.

---

## 2. Estado de partida

| Pieza actual | Qué hace hoy | Destino en Fase 1 |
|--------------|--------------|-------------------|
| `Admin\SettingsPage` | `add_options_page` (slug `openseo`) + Settings API (7 secciones/tabs) + render por `templates/admin/settings-page.php` | **Eliminado**; menú→`Admin\Menu`, ajustes→`Rest\SettingsController`+React, toggles redirec./404→`Settings\BehaviorSettings` (hereda sus helpers de campos) |
| `templates/admin/settings-page.php` | Tabs `nav-tab-wrapper` + `do_settings_sections` + `options.php` | **Eliminado** |
| `Admin\Assets` | Encola el bundle solo en `settings_page_openseo` | **Reescrito** para encolar en pantallas OpenSEO + bootstrap |
| `assets/src/admin/index.js` | Placeholder (`domReady` + `console.debug`) | **Reescrito** como boot de la app React |
| `Redirects\Admin\RedirectsPage` | `add_management_page` (Tools) + sub-tabs redirec./404 | Ya **no registra menú**; expone `render()`; sub-tab 404 separado; mini-form de toggles arriba del list table |
| `templates/admin/redirects-page.php` | Form + list table + sub-tab nav (`$tab`, hidden inputs) | Sin sub-tab nav ni `$tab`; dentro del shell; enlaces a `admin.php` |
| `templates/admin/notfound-panel.php` | Cuerpo del monitor 404 (sub-tab) | Cuerpo de la **página** 404s; enlace "Enable it…" corregido; sin hidden `tab` |
| `NotFound\Admin\NotFoundListTable` | Enlace "create redirect" a `tools.php?page=…&tab=redirects` | Base `tools.php`→`admin.php`, sin `tab` |
| `Settings\Options` | Una clave `openseo_settings`, `all()`/`get()`/`sanitize()` | **Sin cambios** (la reutilizan REST y BehaviorSettings) |
| `Admin\Editor\EditorPanel` | Panel React del editor | **Sin cambios** |

`Options::sanitize()` ya hace *partial-merge*: parte de **`all()` (defaults fusionados con lo
almacenado)** y solo sobreescribe las claves presentes en el input, descartando las desconocidas.
Esto es exactamente lo que necesita una actualización parcial vía REST: el cliente manda solo las
claves cambiadas y el resto se conserva (una clave conocida ausente del cuerpo mantiene su valor
efectivo; ver caso de test en §10).

---

## 3. Arquitectura del menú

Una clase nueva `Admin\Menu` (Hookable) es la **única** que registra el menú top-level y **todos
los submenús** (React y PHP), en un solo callback de `admin_menu` y un solo bucle ordenado. Las
páginas PHP (Redirecciones, 404s) **no** se auto-registran: solo aportan su render-callback, que
`Plugin` inyecta en `Menu`.

> **Por qué centralizado (H1):** el parámetro `$position` de `add_submenu_page` solo ordena dentro
> de la misma pila de llamadas de un módulo; **no es fiable cuando los submenús se registran desde
> clases distintas** (cada una con su propio `add_action('admin_menu')`). Centralizar en un único
> bucle hace el orden determinista sin depender de `$position` ni de prioridades de hooks.

```
🟢 OpenSEO            (add_menu_page, cap manage_options, icono SVG, posición '58.9')
   Dashboard         slug: openseo          (React)   ← landing (reusa el slug del parent)
   General           slug: openseo-general  (React)
   Títulos y Meta    slug: openseo-titles   (React)
   Social            slug: openseo-social   (React)
   Sitemaps          slug: openseo-sitemaps (React)
   Schema            slug: openseo-schema   (React)
   Redirecciones     slug: openseo-redirects(PHP: list table + mini-form toggles)
   404s              slug: openseo-404s     (PHP: log + mini-form toggle)
   IA                slug: openseo-ai       (React)
```

Detalles:

- **Constante compartida** `Menu::PARENT_SLUG = 'openseo'`.
- **Descriptor de página:** `Menu` mantiene una lista `[slug, título, callback]`. Para páginas
  React, `callback` es un método de `Menu` que renderiza `app-page.php` con el `$view` correcto.
  Para páginas PHP, `callback` es `[$redirects_page, 'render']` / `[$notfound_page, 'render']`.
- **Orden:** lo fija el orden del array (un solo bucle de `add_submenu_page`). No se usa `$position`
  por submenú.
- **Landing en Dashboard:** el primer `add_submenu_page` usa slug `openseo` (igual al parent), de
  modo que pulsar el top-level abre el Dashboard (patrón estándar de WP).
- **Icono:** SVG monocromo inline como *data URI* (placeholder intercambiable cuando haya logo
  definitivo); adopta el esquema de color del admin.
- **Posición del top-level:** `'58.9'` (string con decimal, válido **solo** en `add_menu_page` para
  reducir colisiones), justo bajo *Comentarios*. Valor ajustable de una línea. *(L1: el truco
  decimal no aplica a submenús — `add_submenu_page` castea con `absint`; por eso el orden de
  submenús lo da el bucle, no el decimal.)*
- **Capability:** `manage_options` en todas (igual que hoy).
- **Registro de hook-suffixes:** cada `add_*_page` devuelve su hook-suffix; `Menu` los guarda en una
  lista compartida que consulta `Admin\Assets` para el gating (§7).

### Render de páginas React

El callback de cada página React renderiza el shell + el nodo de montaje (`app-page.php` con
`$view`):

```html
<div class="wrap openseo-admin">
  <!-- templates/admin/header.php : cabecera de marca compartida -->
  <div id="openseo-app" data-view="<?php echo esc_attr( $view ); ?>"></div>
</div>
```

`data-view` lo fija el servidor (valor de una lista cerrada, escapado). **Un único bundle** lee ese
atributo y monta la vista correspondiente — sin router JS y sin estado cruzado entre secciones.

### Render de páginas PHP (Redirec./404)

Mismo `templates/admin/header.php` + el contenido PHP existente (mini-form de toggles +
`WP_List_Table`), para que compartan chrome con las páginas React.

---

## 4. Capa de datos: REST `openseo/v1`

Clase nueva `Rest\SettingsController` (Hookable, registra en `rest_api_init`).

| Ruta | Método | Permiso | Comportamiento |
|------|--------|---------|----------------|
| `/openseo/v1/settings` | `GET` | `current_user_can('manage_options')` | Devuelve `Options::all()` (defaults + almacenado). Sin body. |
| `/openseo/v1/settings` | `POST` | `current_user_can('manage_options')` | Cuerpo = objeto **parcial**; pasa por `Options::sanitize()` → `update_option(Options::OPTION_KEY, …)`; devuelve `Options::all()`. Body no-objeto/vacío degrada a `array()` sin fatal. |

- **Nonce:** se delega en el middleware automático de `apiFetch`. Al declarar `wp-api-fetch` como
  dependencia del bundle en wp-admin, core inyecta `createNonceMiddleware(wp_create_nonce('wp_rest'))`
  y `createRootURLMiddleware(rest_url())`, y el nonce se auto-refresca por heartbeat. **No se captura
  el nonce a mano** (M5): el cliente llama `apiFetch({ path: 'openseo/v1/settings', … })` con ruta
  relativa y el middleware añade `X-WP-Nonce` + root URL.
- **Sanitización/whitelist:** toda la validación vive en `Options::sanitize()` (ya existente y
  unit-tested). Claves desconocidas del cuerpo se descartan porque `sanitize()` parte de `all()` y
  solo sobreescribe claves reconocidas. No se confía en el input crudo.
- **Tipado (PHPStan nivel 6):** el controlador declara retornos explícitos (`Options::all()` es
  `array<string,mixed>`) y tipa `WP_REST_Request` / la respuesta para no necesitar baseline.
- **Por qué un controlador propio y no el endpoint core de settings:** reutiliza `Options::sanitize`
  tal cual (el *partial-merge* ya está resuelto), evita declarar el schema-objeto que exige el
  controlador core para opciones de tipo `object`, y sigue el patrón REST que el codebase ya usa.

### Bootstrap (primer render sin round-trip)

En pantallas React, `Admin\Assets` inyecta vía `wp_add_inline_script` (con `wp_json_encode` +
`JSON_HEX_TAG`, igual que `EditorPanel`) **solo lo que core no provee** — nada de nonce/root, que
los aporta el middleware:

```js
window.openseoAdmin = {
  settings:  { /* Options::all() */ },          // estado inicial, pinta sin round-trip
  connector: { available: <bool>, url: '<options-connectors.php>' },
  dashboard: { redirects: <int>, notfound: <int> }, // solo en la pantalla Dashboard
};
```

React pinta con `settings` al instante y guarda por `apiFetch`. Los contadores del Dashboard salen
de los repositorios existentes: `Redirects\Repository::count_active()` para redirecciones (un
`COUNT(*)` por carga del Dashboard, admin-only, aceptable — **no** es hot-path) y
`NotFound\LogRepository` para 404. *(H3: se usa el conteo directo del repositorio; no se promete un
contador "cacheado" porque `Cache::active_count()` es privado y no hay getter público.)*

---

## 5. App React (`assets/src/admin/`)

Se reescribe el entry `admin-settings` (se **mantiene el nombre del entry** para no tocar
`webpack.config.js` ni los nombres de salida que `Admin\Assets` referencia).

```
assets/src/admin/
  index.js            # boot: lee #openseo-app[data-view] y monta la vista
  api.js              # wrapper apiFetch (get/save settings, ruta relativa)
  hooks/useSettings.js# estado + dirty + guardar (reducer puro testeable)
  views/
    Dashboard.js      # estado del conector, contadores, accesos rápidos
    General.js        # identidad del sitio (tipo/nombre/logo, hoy en Schema)
    Titles.js         # separador, plantillas de título/descripción, home
    Social.js         # imagen social por defecto
    Sitemaps.js       # activar sitemap, incluir autores
    Schema.js         # separador de breadcrumbs (la identidad del sitio migra a General)
    Ai.js             # modelo + estado del conector (reemplaza render_ai_intro)
  components/         # envoltorios de campo sobre @wordpress/components
  style.scss
```

- Campos sobre `@wordpress/components` (`TextControl`, `TextareaControl`, `ToggleControl`,
  `SelectControl`). Toggles mapean a `'1'`/`''` para casar con `Options::sanitize`.
- Guardado con feedback React (`Snackbar`/`Notice`), **no** `options.php`.
- `wp_set_script_translations( handle, 'openseo' )` para i18n; el handle declara `wp-i18n` como
  dependencia (verificado vía el `*.asset.php` generado).
- `useSettings()` mantiene estado local + *dirty*; `save()` hace `POST` y re-sincroniza con la
  respuesta (`Options::all()`).

### Reparto de claves por vista React

| Vista | Claves de `openseo_settings` |
|-------|------------------------------|
| General | `schema_site_type`, `schema_site_name`, `schema_logo` (identidad del sitio, movida desde Schema) |
| Títulos y Meta | `title_separator`, `title_template`, `description_template`, `home_title`, `home_description` |
| Social | `og_default_image` |
| Sitemaps | `sitemap_enabled`, `sitemap_include_authors` |
| Schema | `breadcrumb_separator` |
| IA | `ai_model` (+ estado del conector, solo lectura) |
| Dashboard | — (solo lectura: conector + contadores) |

> Las claves de comportamiento de redirec./404 (`redirects_auto_slug`, `redirects_default_status`,
> `redirects_track_hits`, `notfound_monitor_enabled`, `notfound_retention_days`) **no** se migran a
> React en Fase 1: se gestionan con un mini-form Settings API en sus páginas PHP (§6). Fase 2 las
> integra en las vistas React de Redirec./404.

---

## 6. Reubicación de Redirecciones / 404 (PHP, Fase 1)

**Registro del menú (H1):** `RedirectsPage` y `NotFoundPage` ya **no** llaman `add_submenu_page`;
`Admin\Menu` registra sus submenús apuntando a sus `render()`. Cada clase conserva su `register()`
para sus propios hooks (los `admin_post` de CRUD en `RedirectsPage`).

**Toggles de comportamiento (M3):** clase nueva `Settings\BehaviorSettings` (Hookable) que en
`admin_init`:
- llama `register_setting( Options::OPTION_GROUP, Options::OPTION_KEY, [...Options::sanitize] )`
  (una sola vez; esto mantiene vivo `options.php` para estos toggles), y
- registra dos secciones con sus campos, reutilizando los helpers `add_text_field` /
  `add_checkbox_field` / `add_select_field` **rescatados de `SettingsPage`**:
  - `openseo_redirects`: `redirects_auto_slug`, `redirects_default_status`, `redirects_track_hits`.
  - `openseo_notfound`: `notfound_monitor_enabled`, `notfound_retention_days`.

`RedirectsPage::render()` y `NotFoundPage::render()` muestran, dentro del shell, un pequeño
`<form action="options.php"> settings_fields(OPTION_GROUP); do_settings_sections('openseo_redirects'|'openseo_notfound'); submit_button();</form>`
encima de su `WP_List_Table`. (Dos mecanismos de guardado conviven en Fase 1 —REST para ajustes
React, `options.php` para estos toggles—; Fase 2 unifica todo en REST.)

**`Redirects\Admin\RedirectsPage`:**
- Quita `add_management_page` (lo registra `Menu`).
- Elimina el sub-tab nav **y la lógica de `$tab`** en `render()` (hoy lee `$_GET['tab']`, línea 169):
  404 pasa a página propia (M2).
- `redirect_back()` y enlaces internos: `tools.php?page=openseo-redirects` → `admin.php?page=openseo-redirects`.
- El cuerpo se envuelve en `templates/admin/header.php`.

**`templates/admin/redirects-page.php`:**
- Sin `nav-tab-wrapper`, sin ramas `if ('redirects' === $tab)`, sin `<input type="hidden" name="tab">`
  en el form GET de la list table (M2).

**Nueva `NotFound\Admin\NotFoundPage` (Hookable):** expone `render()` que pinta el shell + el
mini-form `openseo_notfound` + el cuerpo de `templates/admin/notfound-panel.php`.

**`templates/admin/notfound-panel.php`:**
- Corrige el enlace "Enable it… (Settings → OpenSEO → Redirects)" (línea 19) que hoy apunta a
  `options-general.php?page=openseo&tab=redirects` —página que se elimina— → al destino vigente
  (`admin.php?page=openseo-404s`, donde vive ahora el toggle del monitor) (H2).
- Quita el `<input type="hidden" name="tab">` de su form GET de paginación (M2).

**`NotFound\Admin\NotFoundListTable`:** el enlace "create redirect" (`column_url()`, ~líneas 90-97)
cambia base `tools.php`→`admin.php` y **elimina** `tab=redirects`: `admin.php?page=openseo-redirects&source=…` (H2).

> **Barrido obligatorio en el plan:** `grep` de `options-general.php?page=openseo`,
> `tools.php?page=openseo-redirects` y `&tab=` en `templates/` y `src/` para no dejar enlaces muertos.

El motor de redirecciones, el monitor, los repositorios, la caché y el `SlugWatcher` **no se
tocan**: solo cambia dónde vive su pantalla de admin.

---

## 7. Carga de assets (`Admin\Assets`)

- **CSS compartido** (cabecera/chrome): en **todas** las pantallas OpenSEO (React **y** PHP).
- **JS de la app React** + bootstrap `window.openseoAdmin`: **solo** en pantallas React (excluye
  `openseo-redirects` y `openseo-404s`).
- **Gating:** por la **lista compartida de hook-suffixes** que `Admin\Menu` rellena al registrar
  cada página (§3), no por comparaciones de prefijo frágiles. `Assets` consulta esa lista y
  distingue React vs PHP por el slug.

---

## 8. Archivos afectados

**Nuevos**

- `src/Admin/Menu.php` — único registrador del menú top-level + los 9 submenús + render React (Hookable).
- `src/Rest/SettingsController.php` — `openseo/v1/settings` GET/POST (Hookable).
- `src/Settings/BehaviorSettings.php` — `register_setting` + secciones/campos de toggles redirec./404 (Hookable; hereda los helpers de campos de `SettingsPage`).
- `src/NotFound/Admin/NotFoundPage.php` — `render()` de la página 404s (Hookable; no registra menú).
- `templates/admin/header.php` — cabecera de marca compartida.
- `templates/admin/app-page.php` — wrapper React (cabecera + `#openseo-app`).
- `assets/src/admin/{index.js, api.js, hooks/useSettings.js, views/*, components/*}`.

**Cambiados**

- `src/Plugin.php` — wiring: +`Menu` (inyectando los render de Redirec./404), +`SettingsController`,
  +`BehaviorSettings`, +`NotFoundPage`; −`SettingsPage`; `RedirectsPage` ya no registra menú;
  inyección de `Repository`/`LogRepository` para contadores del Dashboard.
- `src/Admin/Assets.php` — encolado en pantallas OpenSEO (lista de hook-suffixes) + bootstrap.
- `src/Redirects/Admin/RedirectsPage.php` — sin `add_management_page`; sin sub-tab nav ni `$tab`;
  URLs a `admin.php`; mini-form de toggles; dentro del shell.
- `templates/admin/redirects-page.php` — sin sub-tab nav, sin `$tab`, sin hidden `tab`; shell; enlaces corregidos.
- `templates/admin/notfound-panel.php` — enlace "Enable it…" corregido; sin hidden `tab`.
- `src/NotFound/Admin/NotFoundListTable.php` — enlace "create redirect": base `admin.php`, sin `tab`.
- `assets/src/admin/style.scss` — estilos de la cabecera/chrome y las vistas.

**Eliminados**

- `src/Admin/SettingsPage.php` (su menú/tabs desaparecen; los helpers de campos y el
  `register_setting` se trasladan a `Settings\BehaviorSettings`).
- `templates/admin/settings-page.php`.

> `Options::OPTION_GROUP` **sigue en uso** (lo usa `BehaviorSettings` para `register_setting` +
> `settings_fields`), así que no es código muerto (L2): se conserva.

---

## 9. Seguridad

- `permission_callback` = `manage_options` en ambas rutas REST; nonce `X-WP-Nonce` vía el middleware
  de `apiFetch` (no capturado a mano).
- Sanitización en escritura mediante `Options::sanitize()` (whitelist + tipado por clave), tanto en
  REST como en el `options.php` de los toggles.
- El CRUD de redirecciones conserva su nonce + `current_user_can` actuales.
- Escapado en la salida de los partials PHP (`esc_attr`/`esc_html`/`esc_url`); `data-view` es valor
  fijo del servidor de una lista cerrada.
- No se procesan `$_POST`/`$_GET` completos: el REST lee el cuerpo JSON validado; la Settings API
  enruta por `Options::sanitize`.
- `wp_json_encode( …, JSON_HEX_TAG )` para el bootstrap inline (igual que `EditorPanel`).

---

## 10. Testing

- **Unit PHP (Brain Monkey):** `SettingsController` — `permission_callback` rechaza sin
  `manage_options`; `POST` enruta el input por `Options::sanitize()` antes de persistir; body
  no-objeto/vacío no rompe. `Options` ya tiene cobertura (`tests/Unit/OptionsTest.php`).
- **Integración (wp-env):**
  - Rutas `openseo/v1/settings` registradas; `GET`/`POST` exigen capability.
  - **Merge parcial (M1/M4):** `POST` de una sola clave **no** altera las demás (assert sobre
    `Options::all()` antes/después).
  - **Claves desconocidas (M4):** una clave no reconocida en el cuerpo se descarta.
  - **Body vacío/no-objeto (M4):** devuelve el estado actual sin fatal.
  - Páginas `admin.php?page=openseo*` existen; Redirec./404 ya **no** están bajo Tools; los toggles
    (`openseo_redirects`/`openseo_notfound`) quedan registrados y accesibles.
  - **Reescribir `tests/Integration/SettingsPageTest.php`:** hoy asserta la Settings API de las 7
    secciones (`get_registered_settings`, `$wp_settings_fields`) vía `SettingsPage`, que se elimina.
    Pasa a asertar: rutas REST registradas, páginas de menú existentes, y las dos secciones de
    toggles de `BehaviorSettings`.
- **JS unit (`wp-scripts test-unit-js`):** reducer puro de `useSettings` (cambios + dirty + merge de
  respuesta). Reutiliza el mock de `@wordpress/i18n` existente.
- **i18n (L3):** confirmar que el handle declara `wp-i18n` y que `make-pot` cubre `assets/src/`
  (flujo `.pot`→`.json` documentado en NOTES.md; para Fase 1, plugin no publicado, basta registrar
  la llamada y la dependencia).
- **Gates:** `composer check` (PHPCS + PHPStan nivel 6 + PHPUnit) y `npm run lint:js`/`lint:css` +
  `npm run build` en verde antes de cerrar.

---

## 11. Fuera de alcance (YAGNI) y Fase 2

**Fuera de Fase 1:**

- Redirecciones y 404 en React + REST CRUD (es la **Fase 2**).
- Cualquier opción/feature nueva: esto es reorganización + cambio de mecanismo, no producto nuevo.
- Migración del modelo de datos (sigue la única clave `openseo_settings`).
- El panel del editor (`EditorPanel`) no se toca.
- Redirects de compatibilidad para URLs antiguas (`?tab=…`, Tools): el plugin no está publicado.
- **Multisite / network-admin (L4):** no se aborda; se mantiene `manage_options` y el
  comportamiento actual (sin network-admin propio).

**Fase 2 (futuro, otro spec):**

- `Redirecciones` y `404s` como vistas React (la vista crece desde el mini-form hasta el CRUD completo).
- REST CRUD: listar/crear/editar/borrar reglas; listar/borrar 404; sobre `openseo/v1`.
- Migrar los toggles `redirects_*` / `notfound_*` de Settings API a las vistas React (unificar en REST).

---

## 12. Riesgos y gotchas

| Riesgo | Mitigación |
|--------|------------|
| Orden de submenús no fiable con `$position` entre clases (H1) | Registro **centralizado** en `Admin\Menu` (un bucle); páginas PHP solo aportan render-callback. |
| Enlaces hardcodeados rotos tras reubicar (H2) | Correcciones listadas en §6 + barrido `grep` obligatorio en el plan. |
| Contador de redirecciones del Dashboard sin API cacheada (H3) | Usar `Repository::count_active()` (admin-only); no prometer "cacheado". |
| Toggles redirec./404 sin UI tras retirar la Settings API (M3) | `Settings\BehaviorSettings` los mantiene como mini-form en sus páginas PHP. |
| Restos de `$tab` / hidden inputs al quitar sub-tabs (M2) | Eliminar lógica de `$tab` en `render()` y `name="tab"` en ambos templates. |
| Nonce capturado a mano se queda obsoleto (M5) | No inyectar nonce/root; usar el middleware de `apiFetch` con ruta relativa. |
| `SettingsPageTest` rojo tras retirar la Settings API de 7 tabs | Reescritura incluida en §10. |
| Posición decimal en submenús se trunca (L1) | El decimal solo en `add_menu_page`; el orden de submenús lo da el bucle. |
| Icono del menú provisional | SVG inline intercambiable cuando haya logo. |
| Identidad del sitio movida de Schema → General | Documentado en §5; sin cambio de claves, solo de ubicación en la UI. |
