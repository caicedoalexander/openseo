# OpenSEO — Fase 5: Redirecciones + 404 (documento de diseño)

**Fecha:** 2026-06-19
**Estado:** Aprobado · revisado tras auditoría de `wp-design-reviewer` (C1–C2, H1–H4, M1–M4, L1–L5)
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

El motor de redirección corre en **cada request del front**. El "caso típico" es el request
que **no** matchea (la inmensa mayoría del tráfico): ese camino se resuelve contra el ruleset
cacheado sin escribir nunca en BD. Solo el request que **sí** matchea paga una escritura, y aun
esa se **difiere** (ver `record_hit`, H2) para no añadir latencia antes del redirect.

Realidad de WordPress.org (H3): en instalaciones sin object cache persistente, `wp_cache_*` es
**por-request**, así que el **transient** (persistente en BD) es el almacén efectivo del ruleset
—una lectura barata por request—; `wp_cache_*` solo evita la segunda lectura dentro del mismo
request. El monitor de 404 escribe en BD y por eso es opt-in y agregado. La degradación es **sin
fatales**: sin tabla o sin caché, se reconstruye desde BD.

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

Dos tablas nuevas, creadas con `dbDelta()` y `$wpdb->get_charset_collate()`. **`dbDelta` es
estricto** y el SQL debe seguir sus reglas al pie de la letra, o re-emitirá `ALTER` en cada
chequeo de upgrade (C2): tipos en minúscula; cada columna y cada índice en su propia línea;
`PRIMARY KEY` con **dos espacios** antes del paréntesis; **todo índice secundario nombrado**
(`KEY nombre (col)` / `UNIQUE KEY nombre (col)`); sin `IF NOT EXISTS`, sin `FOREIGN KEY`, sin
`COMMENT`. El SQL literal de abajo es el entregable; tras la primera corrida se valida con
`SHOW CREATE TABLE` que la **segunda** corrida de `dbDelta` produce **cero `ALTER`** (idempotencia).

### 3.1 `{$wpdb->prefix}openseo_redirects`

```sql
CREATE TABLE {$prefix}openseo_redirects (
  id bigint(20) unsigned NOT NULL auto_increment,
  source_path varchar(255) NOT NULL default '',
  target varchar(2048) NOT NULL default '',
  status_code smallint(5) unsigned NOT NULL default 301,
  is_regex tinyint(1) NOT NULL default 0,
  enabled tinyint(1) NOT NULL default 1,
  hits bigint(20) unsigned NOT NULL default 0,
  last_accessed datetime default NULL,
  created_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY source_path (source_path(191)),
  KEY is_regex (is_regex)
) {$charset_collate};
```

`status_code` ∈ {301, 302, 307, 410} (whitelist en la capa de app). `target` vacío solo para 410.
La unicidad de las reglas **exactas** se valida en la capa de aplicación (no índice único: las
reglas regex pueden coincidir de otra forma y `source_path(191)` no garantiza unicidad de la URL
completa). `source_path(191)` cabe en `utf8mb4` (191×4 < límite de índice). El **`Ruleset`
cacheado NO incluye `hits`/`last_accessed`** (columnas volátiles): así `record_hit` nunca obliga
a invalidar la caché (L4, H2).

### 3.2 `{$wpdb->prefix}openseo_404_logs`

```sql
CREATE TABLE {$prefix}openseo_404_logs (
  id bigint(20) unsigned NOT NULL auto_increment,
  url text NOT NULL,
  url_hash char(32) NOT NULL default '',
  hits bigint(20) unsigned NOT NULL default 0,
  first_seen datetime NOT NULL default '0000-00-00 00:00:00',
  last_seen datetime NOT NULL default '0000-00-00 00:00:00',
  referrer varchar(255) default NULL,
  user_agent varchar(255) default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash)
) {$charset_collate};
```

`url_hash = md5(url normalizada)`, calculado **en PHP** y pasado como placeholder; es la clave del
upsert agregado y sortea el límite de índice de 191 sobre URLs largas. El upsert no es expresable
con `$wpdb->insert()`/`->replace()` (`replace` borraría la fila y reiniciaría `first_seen`/`id`),
así que `record()` usa el **único SQL "crudo" de la fase** — ver C1 en §7.

### 3.3 Lifecycle ampliado

- **`src/Lifecycle/Schema.php`** (nuevo) — define el SQL literal de ambas tablas, corre
  `dbDelta()` y, al terminar, `update_option('openseo_db_version', Schema::VERSION)`. Expone la
  constante `Schema::VERSION`, `install()` (idempotente) y `current_version()`.
- **`Activator::activate()`** — además de sembrar opciones, llama a `Schema::install()`.
- **Camino de upgrade (M1)** — un chequeo **barato** en `admin_init`
  (`get_option('openseo_db_version') !== Schema::VERSION`) corre en cada carga de admin, pero
  `Schema::install()` (y por tanto `dbDelta`) **solo** se ejecuta cuando las versiones difieren.
  Esto cubre actualizaciones del plugin sin reactivación y evita trabajo repetido.
- **`Uninstaller::uninstall()`** — añade `DROP TABLE` de ambas tablas y `delete_option('openseo_db_version')`.
- **`Deactivator::deactivate()`** — añade `wp_clear_scheduled_hook('openseo_404_prune')`.
- **Multisite (L1):** **fuera de alcance** en esta fase. Las tablas usan `$wpdb->prefix`
  (por-blog), así que en single-site el esquema es correcto; el install/uninstall por-sitio en
  red (`$network_wide`, iterar blogs) se difiere a una fase posterior si se prioriza multisite.

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
- **`Cache.php`** — ruleset en object cache (`wp_cache_*`) **y** transient. En .org sin object
  cache persistente el transient es el almacén efectivo (§1, H3); `wp_cache_*` evita la segunda
  lectura intra-request. `get()` reconstruye desde `Repository` en miss; **`flush()` invalida
  ambos almacenes (object cache *y* transient) atómicamente** en cada escritura (CRUD manual y
  auto-slug) — si solo borrara el object cache, el transient quedaría stale y dejaría
  redirecciones fantasma. El ruleset cacheado **excluye** `hits`/`last_accessed` (solo
  source/target/status/is_regex/enabled), para que `record_hit` no fuerce `flush()` (L4). Si el
  nº de reglas supera un umbral (constante), devuelve una señal de "degradar" → el `Dispatcher`
  usa `find_active_by_source()` indexado en vez del blob cacheado.
- **`Dispatcher.php`** (Hookable) — engancha `template_redirect` con **prioridad numérica
  explícita anterior a core** (`redirect_canonical` corre en `template_redirect@10`). Se registra
  a **prioridad 5** para que una regla explícita gane sobre el *canonical/404-guessing* de core, y
  el `Normalizer` debe ser consistente con la política de trailing-slash del sitio para no
  encadenar un segundo redirect de `redirect_canonical` (H1). Flujo: `Normalizer` →
  `Cache`/`Repository` → `Matcher`. Con match: `wp_safe_redirect` (interno) / `wp_redirect` +
  `esc_url_raw` (externo) / **410**, y `exit`. **`record_hit` se difiere (H2):** no se escribe en
  BD antes del redirect; se agenda en `shutdown` (o se acumula en object cache y se vuelca) para
  no añadir latencia a la ruta caliente. Con `redirects_track_hits` off, no hay escritura alguna.
- **`SlugWatcher.php`** (Hookable) — engancha `post_updated` (`$post_before`/`$post_after`). La
  decisión "¿crear redirect?" se aísla en un método **casi puro y testeable** que exige TODAS
  estas guardas (M4): no es revisión (`wp_is_post_revision`) ni autosave (`DOING_AUTOSAVE`); el
  estado **anterior era `publish`** (no solo el nuevo — evita drafts→publish); tipo público con
  permalinks "bonitos"; y el **permalink** (no el slug crudo) cambió tras normalizar
  (`old !== new`) — así un cambio de jerarquía/padre que altera la URL también dispara. Si pasa,
  crea un 301 viejo→nuevo vía `Repository`, sin duplicar (`exists_for_source`). Corre como efecto
  de guardado (no procesa input arbitrario de usuario), por lo que no requiere nonce propio.
- **`Admin/RedirectsListTable.php`** — extiende `WP_List_Table`. **`WP_List_Table` es API privada
  de core** (`@access private`, sujeta a cambios) y no siempre está cargada: hay que
  `require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'` en el punto de carga (H4).
  Riesgo aceptado (decisión fijada); mitigación: probar contra betas/RC de WP. Columnas (origen,
  destino, tipo, estado, hits, último acceso), orden, búsqueda, acciones por fila y masivas.
- **`Admin/RedirectsPage.php`** (Hookable, admin) — registra la página bajo Tools, maneja el POST
  (alta/edición/borrado/bulk) con **nonce + `current_user_can('manage_options')`**, valida y
  sanitiza por clave, delega en `Repository`, e invalida la caché. Renderiza los sub-tabs
  (Redirections / 404 Monitor).

### 4.2 `src/NotFound/`

- **`LogRepository.php`** — acceso a `openseo_404_logs`. `record(string $url, ...)` hace el upsert
  agregado con el **único SQL "crudo" de la fase** (C1): `$wpdb->query( $wpdb->prepare( "INSERT …
  ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s, referrer = VALUES(referrer),
  user_agent = VALUES(user_agent)", … ) )` con **todos** los valores como placeholders
  (`url`, `url_hash` calculado en PHP, `referrer`, `user_agent`, `first_seen`/`last_seen`). El
  INSERT inicial fija `first_seen = last_seen`; el UPDATE nunca toca `first_seen`. **Saneo en el
  almacén (M3):** `url` normalizada, `referrer`/`user_agent` saneados y **truncados a 255** antes
  de persistir (no solo escapados a la salida). No se guarda IP (privacidad, L2). Otros métodos:
  `all(...)`, `delete(int $id)`, `clear()`, `prune(int $days)`.
- **`Monitor.php`** (Hookable) — engancha `template_redirect` a **prioridad numérica posterior**
  al `Dispatcher` (p. ej. 99). Si `notfound_monitor_enabled` está on y `is_404()`, registra el
  upsert. El `Dispatcher` (prio 5) hace `exit` al matchear, así que el `Monitor` solo ve 404
  reales. Nota (H1): el *404-guessing* de `redirect_canonical` (prio 10) puede redirigir algunos
  404 antes de llegar al Monitor; es aceptable (son 404 que core "arregla"), y queda documentado.
- **`Pruner.php`** (Hookable) — programa el evento cron `openseo_404_prune` (diario) en `init`
  si no existe; el callback borra filas con `last_seen` más viejo que `notfound_retention_days`.
- **`Admin/NotFoundListTable.php`** — extiende `WP_List_Table` (mismo `require_once` y nota de
  riesgo que en §4.1, H4): listado de 404 (URL, hits, visto primera/última vez), con acción
  **"Crear redirección"** — un enlace **GET** idempotente (sin nonce) que pre-rellena el origen en
  el formulario del gestor. El valor pre-rellenado proviene del `url` almacenado (entrada no
  confiable), así que el `RedirectsPage` lo **re-pasa por `Normalizer` + saneo** al mostrarlo, y
  el **submit** que crea la regla sí lleva nonce + capability (defensa en profundidad, M3).

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

`redirects_default_status` excluye 410 a propósito (L3): **410 no es un "default" global** porque
implica un destino vacío; se elige **por-regla** en el formulario del gestor, no como tipo por
defecto de auto-slug / "crear desde 404".

---

## 6. Flujo de datos

- **Escritura manual:** formulario (Tools → OpenSEO Redirects) → POST nonce + capability →
  sanitizado por clave (`wp_unslash` + sanitizadores) → `Repository` → `Cache::flush()`.
- **Escritura auto-slug:** `post_updated` → `SlugWatcher` → `Repository::create(301)` →
  `Cache::flush()`.
- **Lectura / redirect:** request → `template_redirect@5` (antes de `redirect_canonical@10`) →
  `Normalizer` → `Cache`/`Matcher` → `wp_safe_redirect`/`wp_redirect`/410 + `exit`, o continúa. El
  `record_hit` se difiere a `shutdown` (no bloquea el redirect).
- **Lectura / 404:** sin match → WP renderiza 404 → `Monitor@99` upsert agregado (si on).
- **Mantenimiento:** cron diario `openseo_404_prune` → `LogRepository::prune()`.

---

## 7. Errores y seguridad

- **Autorización:** nonce **+** `current_user_can('manage_options')` en toda acción de estado;
  nunca se procesa `$_POST`/`$_GET` completo — claves explícitas con `wp_unslash` + sanitizado.
- **SQL:** todas las queries con `$wpdb->prepare`; nombres de tabla desde `$wpdb->prefix`. La
  **única excepción de SQL "crudo"** es el upsert de `LogRepository::record()` (C1): se construye
  con `$wpdb->query( $wpdb->prepare(...) )`, todos los valores parametrizados, `url_hash` calculado
  en PHP. Es el punto marcado para revisión de seguridad reforzada.
- **Salida:** escape estricto en los list tables (`esc_html`/`esc_url`/`esc_attr`); además, saneo
  **en la entrada/almacén** del log de 404 (M3), no solo a la salida.
- **Regex (footgun acotado, M2):**
  - **El plugin controla el delimitador y los flags**: envuelve el patrón del usuario con un
    delimitador fijo y un set de flags whitelisteado; **nunca** acepta delimitadores/flags
    arbitrarios del usuario.
  - Al guardar: valida el patrón (`@preg_match($wrapped, '')`, rechazo si `=== false`) y aplica un
    **tope de longitud** del patrón.
  - En runtime: `preg_match` con `@`; un fallo (incluido exceder `pcre.backtrack_limit`) se trata
    como "no match" (nunca fatal). Un patrón patológico degrada el rendimiento por request pero no
    rompe; acotado por el tope de nº de reglas regex (constante) + límites PCRE.
  - Modelo de amenaza: crear reglas regex exige `manage_options` (admin de confianza), lo que
    acota el riesgo. Se documenta como función avanzada.
- **Destinos externos y bucles:** `wp_safe_redirect` para internos; `wp_redirect` +
  `esc_url_raw` para externos; guarda anti-bucle (origen == destino normalizado) al guardar y
  en runtime; interacción con `redirect_canonical` resuelta vía prioridad (H1, §4.1).
- **`record_hit` diferido (H2):** la escritura del contador no bloquea la ruta caliente (se agenda
  en `shutdown` / se acumula); el `Ruleset` cacheado excluye `hits`/`last_accessed`.
- **410:** `status_header(410)` + `nocache_headers()`, sirviendo el body "no encontrado" del tema.
  Limitación conocida (L5): un page-cache externo puede tratar el 410 como 404; no es un bug del
  plugin, sino una expectativa a gestionar.
- **Privacidad (L2):** el monitor de 404 no guarda IP; retención por defecto 30 días; es opt-in.
- **Degradación sin fatales:** sin tabla / sin caché → reconstruye desde BD; los gates de
  calidad (PHPCS, PHPStan nivel 6, PHPUnit) se mantienen en verde.

---

## 8. Testing

- **Unit (Brain Monkey, sin WordPress):**
  - `Normalizer`: subdirectorio del home, trailing slash, query string descartada, decodificación.
  - `Matcher`: match exacto, match regex, precedencia exacto-antes-que-regex, sustitución de
    grupos `$1`, anti-bucle, reglas deshabilitadas excluidas, sin match.
  - Validación de regex (delimitador controlado, rechazo de patrón inválido, tope de longitud),
    construcción de `Ruleset` (excluye `hits`/`last_accessed`), DTO `Redirect`.
  - `SlugWatcher`: método de decisión con TODAS las guardas (revisión/autosave excluidos,
    anterior=publish, permalink cambiado tras normalizar, tipo público) → crear / no crear.
  - `Repository`/`LogRepository`: SQL con `$wpdb` mockeado, incluido el upsert (INSERT inicial vs
    UPDATE: `first_seen` intacto, `hits` incrementado).
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
