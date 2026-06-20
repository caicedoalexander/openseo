# OpenSEO — Fase 5: Redirecciones + 404 (documento de diseño)

**Fecha:** 2026-06-19
**Estado:** Aprobado
**Alcance:** diseño completo de la Fase 5 del [diseño maestro](./2026-06-18-openseo-design.md).
El plan de implementación detallado se redacta aparte (writing-plans).

---

## 1. Objetivo y decisiones fijadas

La Fase 5 dota a OpenSEO de un sistema de **redirecciones** (motor + gestor en admin +
auto-redirect al cambiar el slug) y un **monitor de 404**. Es la primera fase que introduce
**tablas propias** en `$wpdb`, lo que obliga a tocar el ciclo de vida del plugin
(creación de esquema en activación, versión de BD para migraciones, `DROP` en uninstall).

Decisiones tomadas en brainstorming:

| Decisión | Elección |
|----------|----------|
| **Alcance** | Toda la Fase 5 en un solo spec coherente (motor, gestor, 301/302/307/410, regex, auto-slug, monitor de 404). El plan puede implementarlo en sub-fases. |
| **UI del gestor** | `WP_List_Table` (PHP, servidor). Patrón nativo de WP para CRUD en admin: paginación, orden, búsqueda y acciones masivas. Menos JS, coherente con la Settings API actual. |
| **Monitor de 404** | Opt-in (desactivado por defecto), almacenamiento **agregado** (una fila por URL: contador + visto primera/última vez), con auto-purga por retención. |
| **Auto-slug** | Activado por defecto, para **todos los CPT públicos**. Crea un 301 al cambiar el permalink de contenido publicado; la redirección aparece en el gestor para revisarla. |
| **Motor** | Enfoque A: **ruleset cacheado + match en memoria**. Hashmap exacto O(1) + lista regex, invalidación en CRUD, degradación a lookup indexado si el set es enorme. Matcher puro y testeable. |
| **Ubicación del menú** | Toggles de comportamiento en una pestaña **"Redirects"** de Settings → OpenSEO; gestor CRUD + log de 404 en una página propia bajo **Tools → "OpenSEO Redirects"** con sub-tabs internos. |

### Principio rector

El motor de redirección corre en **cada request del front**, así que la ruta caliente debe
evitar consultas a BD en el caso típico (ruleset servido desde object cache). El monitor de
404 escribe en BD y por eso es opt-in y agregado. La degradación es **sin fatales**: sin
tabla o sin caché, se reconstruye desde BD; sin caché persistente, una sola query por request.

---

## 2. Estado de partida

El plugin trae (Fases 0–4): bootstrap, composition root `Plugin` (`modules()` + `boot()`),
contrato `Hookable`, `Settings\Options` (una sola clave `openseo_settings`), salida de `<head>`,
capa de IA (Abilities API), sitemaps sobre `WP_Sitemaps`, y Schema/Breadcrumbs.

Hasta ahora **no existe ninguna tabla propia**: todo vive en opciones y postmeta. El lifecycle
actual solo siembra opciones (`Activator`), limpia hooks programados (`Deactivator`) y borra
opciones (`Uninstaller`). La Fase 5 amplía los tres.

---

## 3. Modelo de datos

Dos tablas nuevas, creadas con `dbDelta()` y `$wpdb->get_charset_collate()`, siguiendo las
convenciones de formato de `dbDelta` (dos espacios, sintaxis `KEY`).

### 3.1 `{$wpdb->prefix}openseo_redirects`

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | `BIGINT UNSIGNED` PK AUTO_INCREMENT | |
| `source_path` | `VARCHAR(255)` | Índice con prefijo 191. Ruta normalizada (relativa al home). |
| `target` | `VARCHAR(2048)` | URL/ruta destino (vacío permitido solo para 410). |
| `status_code` | `SMALLINT UNSIGNED` | Whitelist: 301, 302, 307, 410. |
| `is_regex` | `TINYINT(1)` | Índice. 1 = `source_path` es patrón regex. |
| `enabled` | `TINYINT(1)` | 1 = activa. |
| `hits` | `BIGINT UNSIGNED` | Contador (si `redirects_track_hits` on). |
| `last_accessed` | `DATETIME NULL` | Último uso. |
| `created_at` | `DATETIME` | |

La unicidad de las reglas **exactas** se valida en la capa de aplicación (no índice único,
porque las reglas regex pueden coincidir de otra forma y `source_path` largo complica el índice).

### 3.2 `{$wpdb->prefix}openseo_404_logs`

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | `BIGINT UNSIGNED` PK AUTO_INCREMENT | |
| `url` | `TEXT` | URL solicitada (sin límite de índice). |
| `url_hash` | `CHAR(32)` UNIQUE | `md5(url normalizada)`; clave del upsert agregado, evita el límite de índice de 191. |
| `hits` | `BIGINT UNSIGNED` | Contador agregado. |
| `first_seen` | `DATETIME` | |
| `last_seen` | `DATETIME` | |
| `referrer` | `VARCHAR(255) NULL` | Referrer del último golpe. |
| `user_agent` | `VARCHAR(255) NULL` | UA del último golpe. |

### 3.3 Lifecycle ampliado

- **`src/Lifecycle/Schema.php`** (nuevo) — define el SQL de ambas tablas, corre `dbDelta()`,
  y escribe `openseo_db_version` (constante de versión de esquema). Expone `install()` y
  `current_version()`.
- **`Activator::activate()`** — además de sembrar opciones, llama a `Schema::install()`.
- **Camino de upgrade** — un chequeo en `admin_init` (o en `plugins_loaded`) compara
  `openseo_db_version` almacenada con la del código; si difiere, corre `Schema::install()`
  (idempotente vía `dbDelta`). Esto cubre actualizaciones del plugin sin reactivación.
- **`Uninstaller::uninstall()`** — añade `DROP TABLE` de ambas tablas y `delete_option('openseo_db_version')`.
- **`Deactivator::deactivate()`** — añade `wp_clear_scheduled_hook('openseo_404_prune')`.

---

## 4. Arquitectura — módulos nuevos

Todo sigue el patrón existente: clases `Hookable` bajo `src/`, registradas en `Plugin::modules()`
(las de admin detrás de `is_admin()`). Lógica de matching extraída a clases **puras** (sin WP),
testeables de forma aislada.

### 4.1 `src/Redirects/`

- **`Redirect.php`** — DTO inmutable (`readonly`) de una redirección: id, source, target,
  status, is_regex, enabled, hits, last_accessed.
- **`Repository.php`** — patrón Repository sobre `openseo_redirects`. Métodos:
  `find_active_ruleset()`, `find(int $id)`, `find_active_by_source(string $path)` (lookup
  indexado para el camino de degradación), `create(...)`, `update(...)`, `delete(int $id)`,
  `record_hit(int $id)`, `exists_for_source(string $path)`. **Todo el SQL** vive aquí, siempre
  con `$wpdb->prepare`; el nombre de tabla se interpola desde `$wpdb->prefix` (no es input).
- **`Normalizer.php`** — **puro**: `REQUEST_URI` → ruta comparable. Quita el path del home
  (instalaciones en subdirectorio), decodifica, normaliza trailing slash, **descarta la query
  string** para el match.
- **`Matcher.php`** — **puro**: dado un `Ruleset` + ruta → `MatchResult { target, status }` o
  null. Exacto primero (hashmap O(1)); si no, recorre reglas regex en orden y la primera que
  matchea gana. Soporta sustitución de grupos (`$1`, `$2`) en el target de reglas regex.
  Guarda anti-bucle: descarta el match si destino normalizado == origen.
- **`Ruleset.php`** — estructura construida desde las filas: hashmap de fuentes exactas + lista
  ordenada de reglas regex. Construible en memoria, sin WP.
- **`Cache.php`** — ruleset en object cache (`wp_cache_*`) con fallback a transient. `get()`
  reconstruye desde `Repository` en miss; `flush()` invalida. Se invalida en cada escritura
  (CRUD manual y auto-slug). Si el nº de reglas supera un umbral, devuelve una señal de
  "degradar" para que el `Dispatcher` use lookup indexado directo en vez del blob cacheado.
- **`Dispatcher.php`** (Hookable) — engancha `template_redirect` con prioridad temprana.
  Flujo: `Normalizer` → `Cache`/`Repository` → `Matcher`. Con match: `record_hit` (si el toggle
  está on), luego `wp_safe_redirect` (destino interno) / `wp_redirect` con `esc_url_raw`
  (destino externo) / **410** (`status_header(410)` + `nocache_headers()` + body "no encontrado"
  del tema), y `exit`.
- **`SlugWatcher.php`** (Hookable) — engancha `post_updated`. Si el toggle `redirects_auto_slug`
  está on, el post es público y estaba **publicado**, y el permalink cambió, crea un 301 de la
  ruta vieja → nueva (vía `Repository`), evitando duplicados (`exists_for_source`). La decisión
  (¿debe crear?) se aísla en un método casi puro, testeable.
- **`Admin/RedirectsListTable.php`** — extiende `WP_List_Table`: columnas (origen, destino, tipo,
  estado, hits, último acceso), orden, búsqueda, acciones por fila (editar/borrar/activar) y
  masivas.
- **`Admin/RedirectsPage.php`** (Hookable, admin) — registra la página bajo Tools, maneja el POST
  (alta/edición/borrado/bulk) con **nonce + `current_user_can('manage_options')`**, valida y
  sanitiza por clave, delega en `Repository`, e invalida la caché. Renderiza los sub-tabs
  (Redirections / 404 Monitor).

### 4.2 `src/NotFound/`

- **`LogRepository.php`** — acceso a `openseo_404_logs`: `record(string $url, ...)` (upsert
  agregado `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = …`), `all(...)`,
  `delete(int $id)`, `clear()`, `prune(int $days)`.
- **`Monitor.php`** (Hookable) — engancha `template_redirect` con prioridad **posterior** al
  `Dispatcher`. Si `notfound_monitor_enabled` está on y `is_404()`, registra el upsert. Como el
  `Dispatcher` hace `exit` al matchear, el `Monitor` solo ve 404 reales (no redirigidos).
- **`Pruner.php`** (Hookable) — programa el evento cron `openseo_404_prune` (diario) en `init`
  si no existe; el callback borra filas con `last_seen` más viejo que `notfound_retention_days`.
- **`Admin/NotFoundListTable.php`** — extiende `WP_List_Table`: listado de 404 (URL, hits, visto
  primera/última vez), con acción **"Crear redirección"** que enlaza al formulario del gestor con
  el origen pre-rellenado (query args → el `RedirectsPage` los lee y sanitiza).

### 4.3 Registro en `Plugin::modules()`

Front (siempre): `Dispatcher`, `SlugWatcher`, `Monitor`, `Pruner`.
Admin (`is_admin()`): `RedirectsPage` (que internamente usa los dos list tables).
Las dependencias (`Repository`, `LogRepository`, `Cache`, `Options`) se construyen en `modules()`
y se inyectan por constructor, como ya se hace con `Resolver`/`Graph`.

---

## 5. Settings ampliados

Nuevas claves en `openseo_settings` (con sus defaults y sanitizado por clave en `Options`):

| Clave | Default | Tipo / sanitizado |
|-------|---------|-------------------|
| `redirects_auto_slug` | `'1'` | checkbox (`'1'`/`''`) |
| `redirects_default_status` | `'301'` | select whitelist {301,302,307} |
| `redirects_track_hits` | `'1'` | checkbox |
| `notfound_monitor_enabled` | `''` | checkbox (opt-in) |
| `notfound_retention_days` | `'30'` | entero positivo (`absint`, con mínimo razonable) |

El `SettingsPage` añade la sección/pestaña **"Redirects"** (`openseo_redirects`) con estos
campos, reutilizando los helpers `add_text_field` / `add_checkbox_field` / `add_select_field`
existentes, y la pestaña al template `settings-page.php`.

---

## 6. Flujo de datos

- **Escritura manual:** formulario (Tools → OpenSEO Redirects) → POST nonce + capability →
  sanitizado por clave (`wp_unslash` + sanitizadores) → `Repository` → `Cache::flush()`.
- **Escritura auto-slug:** `post_updated` → `SlugWatcher` → `Repository::create(301)` →
  `Cache::flush()`.
- **Lectura / redirect:** request → `template_redirect` → `Normalizer` → `Cache`/`Matcher` →
  `wp_safe_redirect`/`wp_redirect`/410 + `exit`, o continúa.
- **Lectura / 404:** sin match → WP renderiza 404 → `Monitor` upsert agregado (si on).
- **Mantenimiento:** cron diario `openseo_404_prune` → `LogRepository::prune()`.

---

## 7. Errores y seguridad

- **Autorización:** nonce **+** `current_user_can('manage_options')` en toda acción de estado;
  nunca se procesa `$_POST`/`$_GET` completo — claves explícitas con `wp_unslash` + sanitizado.
- **SQL:** todas las queries con `$wpdb->prepare`; nombres de tabla desde `$wpdb->prefix`.
- **Salida:** escape estricto en los list tables (`esc_html`/`esc_url`/`esc_attr`).
- **Regex (footgun acotado):**
  - Al guardar: se valida el patrón (`@preg_match($pattern, '')` y rechazo si `=== false`).
  - En runtime: `preg_match` con `@`; un fallo se trata como "no match" (nunca fatal).
  - Tope del nº de reglas regex (constante de clase, no setting); el riesgo ReDoS queda acotado
    por `pcre.backtrack_limit`/`pcre.recursion_limit` + el tope. Se documenta como función avanzada.
- **Destinos externos y bucles:** `wp_safe_redirect` para internos; `wp_redirect` +
  `esc_url_raw` para externos; guarda anti-bucle (origen == destino normalizado) al guardar y
  en runtime.
- **410:** `status_header(410)` + `nocache_headers()`, sirviendo el body "no encontrado" del tema.
- **Degradación sin fatales:** sin tabla / sin caché → reconstruye desde BD; los gates de
  calidad (PHPCS, PHPStan nivel 6, PHPUnit) se mantienen en verde.

---

## 8. Testing

- **Unit (Brain Monkey, sin WordPress):**
  - `Normalizer`: subdirectorio del home, trailing slash, query string descartada, decodificación.
  - `Matcher`: match exacto, match regex, precedencia exacto-antes-que-regex, sustitución de
    grupos `$1`, anti-bucle, reglas deshabilitadas excluidas, sin match.
  - Validación de regex (rechazo de patrón inválido), construcción de `Ruleset`, DTO `Redirect`.
  - `SlugWatcher`: lógica de decisión (publicado + cambio de permalink + toggle → crear).
  - `Repository`/`LogRepository`: SQL con `$wpdb` mockeado donde aplique.
- **Integración (wp-env):**
  - `Activator` crea ambas tablas; CRUD del `Repository` round-trip contra `$wpdb` real.
  - `Dispatcher` emite `Location` + status correctos (capturando `wp_redirect` por filtro).
  - Auto-slug crea el 301 al cambiar el slug de una entrada publicada.
  - `Monitor` agrega 404 (upsert incrementa `hits`); `prune` borra filas viejas.
  - Uninstall hace `DROP` de ambas tablas.
- **Cobertura objetivo:** ≥ 80% en la lógica nueva.

---

## 9. Fuera de alcance (diferido)

- Import/export CSV o JSON de redirecciones.
- Grupos / categorías de redirecciones.
- Status 451 (legal).
- Matching que considere la query string.
- Importación desde Yoast / Rank Math (sigue siendo **Fase 6**).
- Exposición del CRUD de redirecciones como abilities/REST (posible iteración AI-native futura).
