# OpenSEO — Fase 2: Redirecciones + 404 en React + REST CRUD (documento de diseño)

**Fecha:** 2026-06-21
**Estado:** Aprobado en brainstorming · revisado tras auditoría de `wp-design-reviewer`
(H1–H4, M1–M5, L1–L4 incorporados) · pendiente de revisión final del usuario
**Alcance:** **Fase 2** de la consolidación de la superficie de administración (continúa la
[Fase 1](./2026-06-20-openseo-admin-ui-consolidation-design.md)). Convierte las páginas de
Redirecciones y 404 —hoy PHP (`WP_List_Table` + `admin-post` CRUD + toggles Settings API)— en
**vistas React** sobre un **REST CRUD propio**, con edición in-place y acciones masivas. El plan
de implementación detallado se redacta aparte (writing-plans).

---

## 1. Objetivo y decisiones fijadas

La Fase 1 dejó las páginas de **Redirecciones** y **404** reubicadas bajo el menú OpenSEO pero
todavía en **PHP**: `RedirectsPage`/`NotFoundPage` renderizan un `WP_List_Table`, el CRUD va por
`admin-post.php`, y los 5 toggles de comportamiento viven en un mini-form de la Settings API
(`Settings\BehaviorSettings`). La Fase 2 las lleva a **React + REST**, igualando el resto del admin.

Al terminar, **las 9 vistas son React** y el plugin queda **100% sobre REST** para la UI de
administración: se retira por completo la Settings API (`BehaviorSettings` y su `register_setting`).

Decisiones tomadas en brainstorming:

| Decisión | Elección |
|----------|----------|
| **Tecnología de tabla** | Componente propio `DataTable` sobre `@wordpress/components` (los primitivos que ya usamos). **No** se usa `@wordpress/dataviews` (no está instalado ni externalizado en WP 7.0; añadiría dependencia, peso de bundle y una API aún en evolución). |
| **Alcance CRUD (redirecciones)** | Completo: listar · crear · **editar in-place** · borrar · activar/desactivar · **acciones masivas** (borrar/activar/desactivar seleccionados) · búsqueda · paginación. La edición se difirió en Fase 1; con React es natural añadirla. |
| **404s** | listar · borrar · **vaciar log** · "crear redirect desde 404" (pre-rellena el formulario de crear redirección). |
| **Toggles de comportamiento** | `redirects_auto_slug`, `redirects_default_status`, `redirects_track_hits`, `notfound_monitor_enabled`, `notfound_retention_days` migran a las vistas React, escritos por el REST de settings **ya existente** (`openseo/v1/settings`). `BehaviorSettings` se **elimina**. |
| **Motor** | `Dispatcher`, `Monitor`, `Pruner`, `SlugWatcher`, `Repository`, `Cache`, `Normalizer`, `Regex`, `Lifecycle\Schema` quedan **intactos**: solo cambia la superficie de admin. |

### Principio rector

Es un cambio de **mecanismo de entrega de la UI** (PHP/Settings-API → React/REST), no del modelo de
datos ni del motor. Las tablas (`{prefix}openseo_redirects`, `{prefix}openseo_404_logs`), el
ruleset cacheado, el auto-301 y el monitor no se tocan. **Toda escritura de reglas pasa por la misma
validación** (normalización, regex, anti-bucle) que hoy, extraída a una unidad reutilizable.

---

## 2. Estado de partida

| Pieza actual | Qué hace hoy | Destino en Fase 2 |
|--------------|--------------|-------------------|
| `Redirects\Admin\RedirectsPage` | `admin-post` CRUD (`handle_save`/`handle_row_action`), validación + anti-bucle (`creates_cycle`), render del list table | **Eliminado**; validación → `RuleValidator`, CRUD → `RedirectsController`, render → vista React |
| `Redirects\Admin\RedirectsListTable` | `WP_List_Table` de reglas | **Eliminado** (sustituido por `DataTable` React) |
| `NotFound\Admin\NotFoundPage` | render del log 404 + toggle | **Eliminado**; CRUD → `NotFoundController`, render → vista React |
| `NotFound\Admin\NotFoundListTable` | `WP_List_Table` de 404 | **Eliminado** |
| `Settings\BehaviorSettings` | Settings API de los 5 toggles | **Eliminado**; toggles → vistas React vía `openseo/v1/settings` |
| `templates/admin/redirects-page.php`, `notfound-page.php`, `notfound-panel.php` | vistas PHP | **Eliminados** |
| `Redirects\Repository`, `NotFound\LogRepository`, `Redirects\Cache`, `Normalizer`, `Regex` | acceso a datos + utilidades puras | **Sin cambios** (reutilizados por los controladores y el validador) |
| `Rest\SettingsController` | `openseo/v1/settings` (Fase 1) | **Sin cambios**; lo reutilizan las vistas para los toggles |
| `Admin\Menu` | `openseo-redirects`/`openseo-404s` como páginas PHP (callbacks vía `$php_pages`) | Pasan a **vistas React** (`view`); el mapa `$php_pages` desaparece |
| `Admin\Assets` | gating CSS (todas) / JS (solo React) | Simplificado: todas las pantallas son React |
| `Plugin::modules()` | construye RedirectsPage/NotFoundPage/BehaviorSettings | +`RedirectsController` +`NotFoundController` (fuera de `is_admin()`); −las tres clases retiradas |

Lógica de validación a preservar literalmente (hoy en `RedirectsPage::handle_save`, líneas 57-99):
normalización de source exacto (`Normalizer`), validación de regex (`Regex::is_valid`), target
absoluto (`esc_url_raw` http/https) **o** root-relative (`/…`, sin `://`), whitelist de estado
`301/302/307/410` con `410 ⇒ target = ''`, y rechazo de **bucle directo de 2 reglas**
(`creates_cycle`). Tras cada escritura, `Cache::flush()`.

---

## 3. Capa REST (extiende `openseo/v1`)

Dos controladores nuevos, ambos `Contracts\Hookable`, registrados en `rest_api_init`
(**fuera** de `is_admin()`), `permission_callback = current_user_can('manage_options')`, nonce
`X-WP-Nonce` vía el middleware automático de `apiFetch`. Reutilizan `Repository`/`LogRepository`/
`Cache`.

**Convenciones fijadas (H1/H2/L2):**
- **Paginación — envelope `{ items, total }`** (no cabeceras `X-WP-Total`). Es una API privada del
  plugin consumida solo por nuestra tabla React; el envelope evita el `apiFetch({parse:false})` +
  lectura de cabeceras y deja que el cliente lea el JSON directo (igual que `getSettings()`). Decisión
  explícita y documentada; se renuncia a `X-WP-Total` a propósito.
- **Verbos:** las rutas de update se registran con `methods => WP_REST_Server::EDITABLE` y las de
  borrado con `WP_REST_Server::DELETABLE` (no las cadenas `'PUT'`/`'DELETE'` sueltas). `apiFetch`
  envía `method:'PUT'`/`'DELETE'` y core acepta además el method-override (`X-HTTP-Method-Override`)
  como fallback de proxies (ver §10).
- **`args` por ruta:** `page` (`absint`, min 1), `per_page` (`absint`, min 1, **max 100**, def. 20),
  `search` (`sanitize_text_field`); `<id>` con `validate_callback` numérico + `sanitize_callback`
  `absint`; `bulk` valida `action` contra la whitelist y `ids` como array de enteros.

### `Rest\RedirectsController`

| Ruta | Método | Cuerpo / query | Devuelve |
|------|--------|----------------|----------|
| `/openseo/v1/redirects` | `GET` | `page`, `per_page` (def. 20), `search` | `{ items: Rule[], total: int }` |
| `/openseo/v1/redirects` | `POST` | `source_path, target, status_code, is_regex` | la regla creada (201) o `WP_Error` 400 (`invalid`/`invalid_regex`/`cycle`) |
| `/openseo/v1/redirects/<id>` | `PUT` | igual que POST (+ `enabled`) | la regla actualizada o `WP_Error` 400 |
| `/openseo/v1/redirects/<id>` | `DELETE` | — | `{ deleted: true }` |
| `/openseo/v1/redirects/bulk` | `POST` | `{ action: 'enable'\|'disable'\|'delete', ids: int[] }` | `{ affected: int }` |

- Crear/editar pasan por `RuleValidator` (§4); el `WP_Error` se serializa con su `code` y mensaje y
  status 400 (vía `rest_ensure_response`/`WP_Error` con `status`).
- Las acciones de **fila** (activar/desactivar) usan `bulk` con un solo id; **borrar fila** usa
  `DELETE /<id>`. Las **masivas** usan `bulk`.
- Cada ruta de escritura (`POST`/`PUT`/`DELETE`/`bulk`) llama `Cache::flush()` tras el cambio.
  **`bulk` aplica todos los `ids` y luego hace un ÚNICO `flush()`** (M3), no uno por id.
- `<id>` se valida con `'args' => ['id' => ['validate_callback' => is numeric, 'sanitize_callback' => absint]]`.

### `Rest\NotFoundController`

| Ruta | Método | Cuerpo / query | Devuelve |
|------|--------|----------------|----------|
| `/openseo/v1/notfound` | `GET` | `page`, `per_page` (def. 20) | `{ items: Hit[], total: int }` |
| `/openseo/v1/notfound/<id>` | `DELETE` | — | `{ deleted: true }` |
| `/openseo/v1/notfound` | `DELETE` | — | `{ cleared: true }` (`LogRepository::clear()`) |

"Crear redirect desde 404" es una acción **de frontend**: la fila enlaza a
`admin.php?page=openseo-redirects&source=<url>`; la vista de Redirecciones lee `?source=` y
pre-abre el formulario de crear con el source pre-rellenado. Sin ruta de backend.

**Sanitización del prefill (H3):** la vista Redirects, al leer `?source=`, hace `decodeURIComponent`
y **descarta cualquier valor con esquema** (`/:/`, p. ej. `javascript:`) o caracteres de marcado
antes de mostrarlo en el `TextControl` (escapar al leer; React ya escapa el render). La validación
final de `RuleValidator` es la red de seguridad al guardar, pero el prefill mostrado nunca refleja
un `source` peligroso. La **creación** resultante la cubre el test de integración de `POST /redirects`
(el prefill solo siembra el form); el click-through completo lo cubre el E2E de Playwright (se ejecuta
en el flujo de verificación, no en CI).

---

## 4. Validación compartida: `Redirects\RuleValidator`

Unidad nueva que **extrae toda** la lógica de `handle_save`, reutilizada por crear y editar.

**Testabilidad (H4):** `Repository` es `final` (convención del proyecto) y golpea `$wpdb`, así que NO
es mockeable en unit (Brain Monkey/Mockery no doblan clases `final`). Para que el anti-bucle sea
unit-testable, se extrae una **interfaz mínima** que `Repository` implementa y `RuleValidator`
consume:

```php
// src/Redirects/RedirectLookup.php
interface RedirectLookup {
    public function find_active_by_source( string $path ): ?Redirect;
}

// Repository implements RedirectLookup (ya tiene el método; solo añade "implements")

final class RuleValidator {
    public function __construct( private readonly RedirectLookup $lookup ) {}

    /**
     * @param array<string,mixed> $input  source_path, target, status_code, is_regex (+ enabled al editar)
     * @param int                 $id      0 al crear; el id al editar (excluido del anti-bucle)
     * @return array{source_path:string,target:string,status_code:int,is_regex:bool,enabled:bool}|\WP_Error
     */
    public function validate( array $input, int $id = 0 ): array|\WP_Error;
}
```

- Normaliza source **exacto** / valida regex (`Regex::is_valid`); resuelve target (absoluto
  `esc_url_raw` http/https **o** root-relative); valida estado (`410 ⇒ target=''`); rechaza bucle
  directo (`creates_cycle`, movido aquí) **solo para reglas exactas (no regex)** (M4), usando
  `RedirectLookup::find_active_by_source`.
- Devuelve datos limpios o `WP_Error` con `code` ∈ {`openseo_invalid`, `openseo_invalid_regex`,
  `openseo_cycle`} y `data.status = 400`. Los mensajes usan el text domain `openseo` (L4) y llegan
  ya traducidos al cliente.
- Unit-testable: el test pasa un **fake/mocked `RedirectLookup`** (interfaz mockeable) y mockea
  `esc_url_raw`. `enabled` por defecto `true` al crear; al editar respeta el `enabled` entrante.

`RedirectsController` solo orquesta: lee el JSON, llama `RuleValidator::validate`, persiste vía
`Repository::create`/`update`, hace `Cache::flush()`, devuelve la fila.

---

## 5. Frontend React (`assets/src/admin/`)

- **`components/DataTable.js`** — tabla genérica a medida sobre `@wordpress/components`:
  - props: `columns` (id, label, render), `items`, `total`, `page`, `perPage`, `loading`,
    `selectable`, `selected`, **`searchable`** (M1: la vista 404 lo pone en `false` porque
    `LogRepository` no soporta búsqueda y el motor no se toca), callbacks
    `onPageChange`/`onSearch`/`onSelectionChange`, `rowActions` (render por fila), `bulkActions`
    (barra cuando hay selección), `emptyLabel`.
  - usa `CheckboxControl` (selección), `SearchControl` (búsqueda con debounce, solo si `searchable`),
    `Spinner` (loading), `Button`/`Flex` (acciones/paginación), `Notice` (errores). Sin dependencia
    nueva. (L1: la API de `DataTable` se valida contra **ambos** consumidores —Redirects con todo;
    404 sin búsqueda ni masivas— para no diseñarla contra un único caso.)
- **`hooks/useRedirects.js`** — estado de lista (`items/total/loading/error/page/search`) + mutaciones
  (`create/update/remove/bulk`) que llaman `apiFetch` y refrescan; reducer puro testeable para las
  transiciones. **`hooks/useNotfound.js`** — análogo (list + `remove/clear`).
- **`api.js`** — añade `getRedirects(params)`, `createRedirect`, `updateRedirect(id,data)`,
  `deleteRedirect(id)`, `bulkRedirects(action,ids)`, `getNotfound(params)`, `deleteNotfound(id)`,
  `clearNotfound()` (rutas relativas, middleware de nonce).
- **`views/Redirects.js`** — `DataTable` + formulario crear/editar en `Modal` (source `TextControl`,
  target `TextControl`, **tipo de la regla** `SelectControl` `301/302/307/410`, regex `ToggleControl`;
  al elegir 410 se deshabilita/limpia target). Acciones de fila: Editar · Activar/Desactivar · Borrar.
  Masivas: Borrar/Activar/Desactivar seleccionados. Arriba, los 3 toggles de redirección vía
  `useSettings` (REST de settings). Lee `?source=` (sanitizado, §3) para pre-abrir "crear" desde un 404.
  **(M5) Dos listas de estado distintas, a propósito:** el `SelectControl` del *formulario de regla*
  ofrece `301/302/307/410`, pero el toggle `redirects_default_status` (ajuste de auto-slug) solo
  `301/302/307` —lo que `Options::sanitize` acepta—; no unificar ambos selects.
- **`views/NotFound.js`** — `DataTable` (columnas URL/Hits/última-vez; acción de fila Borrar +
  "Crear redirect"); toggle del monitor + retención vía `useSettings`; botón "Vaciar log"
  (`clearNotfound`, con confirmación).
- **`App.js`** — registra las vistas `redirects` y `notfound` en el `VIEWS` map.

Errores REST: las vistas muestran el `message` del `WP_Error` en un `Notice`/`Snackbar` (p. ej.
"Esto crearía un bucle de redirección").

---

## 6. Recableo y limpieza

- **`Admin\Menu` (M2)**: en `Menu::pages()`, las dos entradas hoy sin `view`
  (`openseo-redirects`, `openseo-404s`) ganan `'view' => 'redirects'` / `'notfound'`; el parámetro de
  constructor `$php_pages`, la rama de callbacks PHP y el método `track()`-de-php-pages se eliminan
  (todas las pantallas son React). **Actualizar los tests que instancian `Menu(array(...))`**
  (`MenuTest`, `MenuWiringTest`) al nuevo constructor sin argumentos.
- **`Admin\Assets` (L3)**: como ya no hay pantallas PHP propias, `screen_hooks()` ≡
  `react_screen_hooks()`; se simplifica el gating (CSS+JS+bootstrap en todas las pantallas OpenSEO;
  los contadores del Dashboard siguen solo en el hook del Dashboard). El bootstrap sigue inyectando
  `settings` + `connector`; los toggles migrados **leen de `window.openseoAdmin.settings`** (vía
  `useSettings`), así que **no** se hace un fetch redundante de settings al montar Redirects/404. El
  bootstrap **no** precarga las listas de redirec./404 (se piden por REST al montar, por
  paginación/búsqueda).
- **`Plugin::modules()`**: +`RedirectsController` +`NotFoundController` en la lista siempre-activa
  (fuera de `is_admin()`); se retiran `RedirectsPage`, `NotFoundPage`, `BehaviorSettings` y el
  cableado de `$php_pages` en `Menu`. `Repository`/`Cache`/`LogRepository` se inyectan en los
  controladores.

**Nuevos:** `src/Redirects/RedirectLookup.php` (interfaz), `src/Redirects/RuleValidator.php`,
`src/Rest/RedirectsController.php`, `src/Rest/NotFoundController.php`,
`assets/src/admin/components/DataTable.js`, `assets/src/admin/hooks/useRedirects.js`,
`assets/src/admin/hooks/useNotfound.js`, `assets/src/admin/views/Redirects.js`,
`assets/src/admin/views/NotFound.js`.

**Cambiados:** `src/Redirects/Repository.php` (`implements RedirectLookup`), `src/Plugin.php`,
`src/Admin/Menu.php`, `src/Admin/Assets.php`, `assets/src/admin/api.js`, `assets/src/admin/App.js`,
`assets/src/admin/style.scss`, `CLAUDE.md`, `NOTES.md`.

**Eliminados:** `src/Redirects/Admin/RedirectsPage.php`, `src/Redirects/Admin/RedirectsListTable.php`,
`src/NotFound/Admin/NotFoundPage.php`, `src/NotFound/Admin/NotFoundListTable.php`,
`src/Settings/BehaviorSettings.php`, `templates/admin/redirects-page.php`,
`templates/admin/notfound-page.php`, `templates/admin/notfound-panel.php`. Tests a retirar/reescribir:
`tests/Integration/BehaviorSettingsTest.php`, `tests/Integration/NotFoundLinkTest.php` (el enlace
"crear redirect" ahora es React; su equivalente se cubre en el E2E y en el test del controlador).

---

## 7. Seguridad

- `permission_callback = manage_options` en **todas** las rutas; nonce `X-WP-Nonce` vía `apiFetch`.
- Sanitización por clave en el controlador + validación en `RuleValidator` (whitelist de estado,
  normalización, regex validado, target restringido a http/https o root-relative — nunca
  `javascript:`/`://` colado). Nunca se procesa el cuerpo crudo entero.
- `<id>` de ruta saneado con `absint`; bulk valida `action` contra la whitelist y `ids` como enteros.
- Borrados destructivos (`DELETE /<id>`, `bulk delete`, `DELETE /notfound`) exigen capability; la UI
  pide confirmación antes de vaciar el log o borrar en masa.
- El anti-bucle previene cadenas de redirección creadas vía API igual que en el form actual.

---

## 8. Testing

- **Unit (Brain Monkey):** `RuleValidator` — source exacto normalizado, regex válido/ inválido,
  target absoluto y root-relative, rechazo de `javascript:`/protocol-relative, `410⇒''`, estado
  fuera de whitelist, y **anti-bucle solo para reglas exactas** (crea ciclo ⇒ `WP_Error openseo_cycle`;
  no-ciclo ⇒ ok; edición que se excluye a sí misma del lookup; regex ⇒ no se evalúa el ciclo).
  Mockea la interfaz **`RedirectLookup`** (fake/mock, posible por ser interfaz) y `esc_url_raw`.
- **Integración (wp-env):** `RedirectsController` (list paginada + búsqueda; crear válido⇒201,
  inválido/regex/cycle⇒400; editar; borrar; bulk enable/disable/delete; flush de caché observable);
  `NotFoundController` (list, delete, clear); ambos exigen `manage_options` (401/403 sin permiso).
- **JS unit (Jest):** reducers puros de `useRedirects`/`useNotfound` (loading/loaded/error,
  optimistic remove, page/search) y helpers de selección de `DataTable`.
- **E2E (Playwright):** crear → editar → desactivar/activar → borrar una regla; masiva; "crear
  redirect desde 404"; vaciar log; toggles persisten.
- **Gates:** `composer check`, `npm run lint:js`/`lint:css`/`build`, integración wp-env, todo verde.

---

## 9. Plan (sub-fases) y fuera de alcance

Plan multi-tarea (writing-plans):

1. `Redirects\RedirectLookup` (interfaz) + `Repository implements RedirectLookup` +
   `Redirects\RuleValidator` (extracción de la lógica de `handle_save` + unit tests).
2. `Rest\RedirectsController` (CRUD + bulk) + integración.
3. `Rest\NotFoundController` + integración.
4. `DataTable` + `useRedirects`/`useNotfound` + `api.js` (+ JS unit).
5. `views/Redirects.js` + `views/NotFound.js` (form/modal, masivas, migración de toggles).
6. Recableo `Menu`/`Plugin`/`Assets` + borrado de páginas/tablas PHP/`BehaviorSettings`/templates +
   docs (commit coordinado para no dejar el admin a medias).

**Fuera de alcance (YAGNI):** import/export de reglas (CSV), constructor visual de regex, IndexNow,
estadísticas de hits avanzadas, cambios en el motor/monitor. Quedan para "Futuro".

---

## 10. Riesgos y gotchas

| Riesgo | Mitigación |
|--------|------------|
| Controladores REST dentro de `is_admin()` (no registrarían en `rest_api_init`) | Registrarlos en la lista siempre-activa de `Plugin::modules()`, como `SettingsController`. |
| Recableo deja el admin a medias (Menu apunta a vistas React inexistentes, o borra páginas aún referenciadas) | Sub-fase 6 en **un commit coordinado**: vistas React (4-5) existen antes de retirar las páginas PHP y repuntar el menú. |
| `WP_Error` del validador mal serializado en REST | Devolver `WP_Error` con `data.status=400`; el controlador usa `rest_ensure_response`; test de integración asserta status + `code`. |
| Pérdida de paridad (búsqueda/anti-bucle/410) | `RuleValidator` reusa la lógica exacta y se unit-testea; `DataTable` cubre búsqueda/paginación; tests de integración por caso. |
| `@wordpress/dataviews` tentador pero no externalizado | Decisión fijada: `DataTable` propio; sin dependencia nueva. |
| Paginación ambigua (envelope vs cabeceras) (H1) | Decisión fijada: envelope `{items,total}`, `apiFetch` con `parse` por defecto; documentado en §3. |
| `PUT`/`DELETE` degradados por proxies a POST+override (H2) | Registrar con `WP_REST_Server::EDITABLE`/`DELETABLE`; core acepta `X-HTTP-Method-Override`; `apiFetch` envía el verbo. |
| `Repository` `final` no mockeable rompe el unit test del anti-bucle (H4) | Interfaz `RedirectLookup` consumida por `RuleValidator`; el test mockea la interfaz. |
| Prefill `?source=` reflejado sin sanitizar (H3) | La vista sanitiza al leer (decode + descartar esquemas/marcado); `RuleValidator` es la red al guardar. |
| Doble mecanismo de guardado de Fase 1 (REST + options.php) | Se **elimina**: los toggles pasan a REST; `BehaviorSettings` se retira. Único mecanismo: REST. |
