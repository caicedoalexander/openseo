# OpenSEO — Notas de desarrollo

Documentación interna del proyecto. No se incluye en el ZIP de distribución.

- **Versiones objetivo:** WordPress 7.0+ · PHP 8.1+
- **Stack:** Composer (PSR-4 `OpenSEO\` → `src/`) · `@wordpress/scripts` · `wp-env` · GPL-2.0-or-later
- **Distribución:** WordPress.org (público)

---

## 1. Requisitos

| Herramienta | Versión | Para qué |
|-------------|---------|----------|
| PHP | 8.1+ | Ejecutar el plugin y los tests/linters |
| Composer | 2.x | Dependencias y autoload PSR-4 |
| Node | 20+ (`.nvmrc`) | Build de assets y `wp-env` |
| Docker | — | Requerido por `wp-env` (entorno WP local) |

> WP-CLI **no** está instalado globalmente. Se usa a través de `wp-env` (ver sección 6).

---

## 2. Instalar dependencias

```bash
composer install     # dependencias PHP + tooling (PHPCS, PHPStan, PHPUnit) + autoload
npm install          # @wordpress/scripts + @wordpress/env
```

El plugin **no arranca** hasta que `composer install` genera `vendor/autoload.php`
(si falta, muestra un admin notice en lugar de un fatal).

---

## 3. Build de assets

Fuentes en `assets/src/`, salida compilada en `assets/build/` (git-ignored, pero **sí**
se incluye en el ZIP de release).

```bash
npm run build        # build de producción (one-off)
npm run start        # modo watch durante el desarrollo
```

`@wordpress/scripts` genera además `*.asset.php` con las dependencias y la versión;
`src/Admin/Assets.php` lo lee para encolar el bundle solo en la pantalla de ajustes.

---

## 4. Entorno local (wp-env)

```bash
npm run env:start    # levanta WP 7.0 + PHP 8.1 en http://localhost:8888 (admin/password)
npm run env:stop
npm run env:clean    # resetea la base de datos / entorno
```

El plugin se monta automáticamente (`"plugins": ["."]` en `.wp-env.json`).

---

## 5. Tests y calidad

### PHP

```bash
composer test:unit         # unit tests (Brain Monkey, sin WordPress) — rápido
composer lint              # PHPCS con WordPress Coding Standards
composer lint:fix          # PHPCBF: auto-corrige lo que pueda
composer analyze           # PHPStan nivel 6 (usa --memory-limit=1G internamente)
composer check             # lint + analyze + unit, todo de una
```

### Integración (necesita wp-env corriendo)

```bash
npm run env:start
npm run test:integration   # corre PHPUnit dentro del contenedor con la suite de WP
```

### JavaScript / CSS

```bash
npm run lint:js
npm run lint:css
npm run format             # formatea JS/CSS
npm run test:js            # unit tests JS
```

> Mantener los tres gates (PHPCS, PHPStan, PHPUnit) en verde antes de cada commit.

### IA (Fase 2): qué cubre CI y cómo probar de verdad

El AI Client de WP 7.0 está en el core, pero **CI no tiene ningún conector configurado** (sin
clave de proveedor), así que la suite solo ejercita el camino determinista
**`openseo_no_connector`** y la exposición REST de las abilities
(`tests/Integration/AbilitiesTest.php`). El editor invoca las abilities con `apiFetch` al
endpoint REST `/wp-abilities/v1/abilities/<name>/run` (no `executeAbility`).

Para probar una generación **real** contra un proveedor (no va a CI, requiere clave):

```bash
# instala un AI Provider plugin en wp-env y actívalo
npm run env:run -- cli wp plugin install ai-provider-for-openai --activate
# configura la clave en Settings → Connectors (o vía constante en wp-config)
# luego, en el editor de una entrada, pulsa "Generate with AI"
```

Sin conector, el panel del editor muestra "Connect an AI provider… Settings → Connectors" en
lugar del botón, y la pestaña **Settings → OpenSEO → AI** indica "No AI connector is configured".

### Sitemaps (Fase 3): qué cubre y cómo probar

OpenSEO no genera XML propio: personaliza el sitemap nativo de WordPress
(`WP_Sitemaps`, URL `wp-sitemap.xml`) vía los filtros `wp_sitemaps_*` desde
`src/Sitemap/Sitemap.php`. Tres comportamientos:

- **Excluye `noindex`:** las entradas con `_openseo_robots_noindex = '1'` se
  omiten del sub-sitemap de posts (`wp_sitemaps_posts_query_args`).
- **Master on/off:** la pestaña *Settings → OpenSEO → Sitemaps* permite
  desactivar todo el sitemap (`wp_sitemaps_enabled`).
- **Autores fuera por defecto:** el sub-sitemap de usuarios se quita salvo que se
  active en esa misma pestaña (`wp_sitemaps_add_provider`).

El descubrimiento ya lo cubre el `robots.txt` virtual de core (`Sitemap:
…/wp-sitemap.xml`); no hay ping a buscadores (obsoleto). IndexNow queda en "Futuro".

Smoke test manual: publicar una entrada y abrir `/wp-sitemap.xml`; marcar la
entrada como noindex y confirmar que desaparece del sub-sitemap de posts.

### Schema + Breadcrumbs (Fase 4): qué cubre y cómo probar

OpenSEO emite un único `@graph` JSON-LD en `wp_head` (`src/Schema/`): WebSite,
Organization/Person (identidad configurable en *Settings → OpenSEO → Schema*),
WebPage, Article (tipo elegible por entrada en el panel del editor), y
BreadcrumbList. Las piezas reutilizan el `Resolver` de la Fase 1.

Los breadcrumbs (`src/Breadcrumbs/`) tienen una sola fuente (`Trail`), consumida
por la función de tema `openseo_breadcrumbs()`, el bloque dinámico
`openseo/breadcrumbs`, y la pieza `BreadcrumbList`.

La ability `openseo/suggest-schema-type` recomienda (no aplica) el tipo más rico
para una entrada; solo se llama on-demand desde el editor. Como en la Fase 2, CI
solo ejercita la ruta `openseo_no_connector` y la exposición REST.

Smoke test manual: publicar una entrada y ver el código fuente → un
`<script type="application/ld+json">` con `@graph`; cambiar el tipo de schema a
*None* y confirmar que desaparece el nodo Article; añadir el bloque de breadcrumbs
a una página.

### Redirecciones + 404 (Fase 5): qué cubre y cómo probar

**Tablas propias.** `src/Lifecycle/Schema.php` crea las dos primeras tablas
personalizadas del plugin (`{prefix}openseo_redirects` y
`{prefix}openseo_404_logs`) con `dbDelta()` detrás de un gate `openseo_db_version`
comprobado en `admin_init`; se borran al desinstalar.

**Motor de redirecciones (`src/Redirects/`).** `Dispatcher` se engancha a
`template_redirect` con prioridad **5** (antes que `redirect_canonical` en @10) y
difiere la escritura del contador de hits a `shutdown`. Las unidades centrales son
puras (sin WP): `Normalizer` (path de la petición), `Regex` (delimitador
controlado por el plugin), `Ruleset` (mapa exacto O(1) + lista regex ordenada),
`Matcher` (exacto gana sobre regex, sustitución `$1`, protección anti-bucle).
`Repository` encapsula todo el SQL sobre `{prefix}openseo_redirects`. `Cache`
almacena el ruleset en el object cache con caída a transient; invalidación
dual-store; cuenta activa cacheada para evitar COUNT por petición; modo degradado
por encima de un umbral. `SlugWatcher` crea automáticamente un 301 cuando cambia
el permalink de una entrada publicada (`pre_post_update` + `post_updated`; activo
por defecto para todos los CPTs públicos). El gestor de admin está bajo *Herramientas
→ OpenSEO Redirects* (`WP_List_Table`, nonce + capability CRUD). Nuevas keys en
`openseo_settings`: `redirects_auto_slug`, `redirects_default_status`,
`redirects_track_hits`. Nueva pestaña *Redirects* en *Settings → OpenSEO*.

**Monitor de 404 (`src/NotFound/`).** `Monitor` se carga en `template_redirect`
con prioridad **99** y es opt-in vía `notfound_monitor_enabled`. `LogRepository`
hace un upsert agregado (`INSERT … ON DUPLICATE KEY UPDATE` con clave `url_hash`;
fechas en UTC; sin IP almacenada). `Pruner` programa el cron diario
`openseo_404_prune`; la retención se controla con `notfound_retention_days`
(por defecto 30 días). `Admin/NotFoundListTable` lista los hits con un enlace
"crear redirect desde este 404". Nuevas keys: `notfound_monitor_enabled`,
`notfound_retention_days`.

Smoke test manual:

1. **Redirect manual:** ir a *Herramientas → OpenSEO Redirects*, crear una regla
   `/old-url → /new-url` (301), visitar `/old-url` y confirmar la redirección.
2. **Auto-slug:** renombrar el slug de una entrada publicada → debe aparecer un 301
   automático en el gestor apuntando de la URL antigua a la nueva.
3. **Monitor 404:** activar *notfound_monitor_enabled* en *Settings → OpenSEO →
   Redirects*, visitar una URL inexistente, y confirmar que aparece en la lista de
   *Herramientas → OpenSEO 404s*.

> **Gotcha de wp-env (importante para el smoke test).** El motor (Dispatcher@5) y
> el monitor (Monitor@99) solo actúan si la petición llega a WordPress, lo que
> exige permalinks "bonitos" con el rewrite de Apache funcionando. En wp-env,
> `wp rewrite structure '/%postname%/'` actualiza la opción pero **no siempre
> regenera el `.htaccess`**, así que una ruta arbitraria como `/old-url` la
> contesta **Apache con su propio 404** (charset `iso-8859-1`, sin cabeceras de WP)
> antes de que `template_redirect` corra — y parece que el redirect "no funciona"
> cuando en realidad el plugin nunca se ejecutó. Solución: forzar el flush duro una
> vez por entorno:
>
> ```bash
> npm run env:run -- cli wp rewrite flush --hard
> ```
>
> Para distinguir un fallo de entorno de uno del plugin, comprobar la respuesta
> cruda: `curl -sI http://localhost:8888/old-url`. Un **301 con `X-Redirect-By:
> WordPress`** es el plugin; un **404 de Apache** (sin cabeceras WP) es el rewrite.
> El motor se puede verificar en aislamiento aunque el rewrite falle:
> `wp eval` instanciando `Repository::find_active_ruleset()` + `Matcher`.

### Consolidación de UI admin (Fase 6): qué cubre y cómo probar

La superficie de admin pasó de *Ajustes → OpenSEO* (7 tabs, Settings API) +
*Herramientas → OpenSEO Redirects* a un **menú propio** con un submenú por sección.
Las vistas de ajustes (Dashboard, Títulos, Social, Sitemaps, Schema, IA)
son **React** (`assets/src/admin/`) sobre el REST `openseo/v1/settings`
(`src/Rest/SettingsController.php`, reutiliza `Options::sanitize`). Redirecciones y
404 se reubicaron bajo el menú conservando su `WP_List_Table` (PHP); sus toggles
viven en un mini-form Settings API (`src/Settings/BehaviorSettings.php`).

CI ejercita: rutas REST (`RestSettingsTest`, merge parcial/claves desconocidas),
registro de menú (`MenuTest`, `MenuWiringTest`), secciones de toggles
(`BehaviorSettingsTest`) y el enlace 404→redirect (`NotFoundLinkTest`).

Smoke test manual: en wp-admin, abrir **OpenSEO** en el sidebar; cada submenú es su
propia URL (`admin.php?page=openseo-*`); la identidad de schema (persona/empresa,
nombre, logo, URL, email) vive en la pestaña *SEO Local* de *Titles & Meta*; cambiar
un campo ahí y Guardar →
recargar y confirmar persistencia; *404s* muestra el toggle del monitor arriba y el
log abajo.

### Redirec./404 a React + REST (Fase 7): qué cubre y cómo probar

Redirecciones y 404 pasaron de `WP_List_Table` (PHP) + `admin-post` CRUD + toggles Settings API a
**vistas React** sobre REST: `Rest\RedirectsController` (`openseo/v1/redirects`: GET/POST,
PUT/DELETE `/<id>`, POST `/bulk`) y `Rest\NotFoundController` (`openseo/v1/notfound`). La validación
(normalización/regex/target/whitelist/anti-bucle) vive en `Redirects\RuleValidator` sobre la interfaz
`Redirects\RedirectLookup` (testeable; `Repository` la implementa). La UI es un `DataTable` propio
(`assets/src/admin/components/DataTable.js`) con CRUD completo, edición en `Modal` y acciones masivas.
Los 5 toggles migraron a las vistas React (vía `openseo/v1/settings`); `Settings\BehaviorSettings` y
las páginas/tablas PHP se eliminaron. El motor (Dispatcher/Monitor/Pruner/SlugWatcher/Repository/
Cache) no cambió.

CI ejercita: `RuleValidatorTest` (unit), `RedirectsRestTest`/`NotFoundRestTest` (integración:
CRUD, bulk, búsqueda, anti-bucle 400, permisos 401), y el reducer JS `listReducer`.

Smoke test manual: *OpenSEO → Redirecciones* → "Add redirect" → crear `/old → /new` (301) → Guardar;
editar, desactivar y borrar; seleccionar varias y borrar en masa. *OpenSEO → 404s* → activar el
monitor, visitar una URL inexistente, "Create redirect" desde la fila, y "Clear log".

---

## 6. WP-CLI (vía wp-env)

WP-CLI viene dentro de `wp-env`, no hace falta instalarlo:

```bash
npm run env:run -- cli wp plugin list
npm run env:run -- cli wp option get openseo_settings
npm run env:run -- cli wp eval 'var_dump( function_exists("wp_register_ability") );'

# Regenerar el archivo de traducciones (.pot):
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo languages/openseo.pot
```

`wp-cli.yml` aporta defaults para scripts repetibles.

---

## 7. Empaquetado / release

```bash
composer install --no-dev --optimize-autoloader   # autoloader de producción
npm ci && npm run build                            # assets compilados
npm run plugin-zip                                 # genera openseo.zip respetando .distignore
```

El ZIP **incluye** `vendor/` (autoloader prod) y `assets/build/`, y **excluye** fuentes,
tests y tooling.

---

## 8. Skills de WordPress disponibles y cuándo usarlas

Se invocan con `/<nombre>` (o se activan solas cuando aplica). Las relevantes para OpenSEO:

| Skill | Cuándo usarla en este proyecto |
|-------|--------------------------------|
| `wp-plugin-development` | Base del plugin: arquitectura, hooks, ciclo de vida, Settings API, seguridad, packaging. |
| `wp-project-triage` | Inspección determinista del repo (tooling/tests/versiones). Útil al retomar o diagnosticar. |
| `wp-abilities-api` | Definir/registrar *abilities* (`wp_register_ability`, categorías, schemas, permisos). Capa IA — `src/Ai/Abilities.php`. |
| `wp-abilities-audit` | Auditar la superficie REST y proponer qué exponer como *abilities*. |
| `wp-abilities-verify` | Verificar que cada *ability* hace lo que declara (detecta "readonly que escribe", permisos y schemas mal puestos). |
| `wp-rest-api` | Añadir endpoints REST propios (`register_rest_route`, controllers, validación, `permission_callback`). |
| `wp-wpcli-and-ops` | Operaciones WP-CLI seguras (db export/import, `search-replace`, cron, cache) vía wp-env. |
| `wp-block-development` | Bloques Gutenberg (panel SEO en el editor): `block.json`, render dinámico, build. |
| `wp-interactivity-api` | Frontend interactivo en bloques con directivas `data-wp-*` y store de Interactivity. |
| `wpds` | UI del admin con el WordPress Design System (componentes, tokens). |
| `wp-performance` | Auditar rendimiento backend: queries, *autoloaded options*, cron, object cache, llamadas HTTP. |
| `wp-phpstan` | Ajustar/escalar PHPStan en WP (nivel, baseline, tipado WP). Config en `phpstan.neon.dist`. |
| `wp-plugin-directory-guidelines` | Cumplimiento GPL, naming/trademark, freemium y las 18 guías de WordPress.org. |
| `wp-playground` | Instancia WP desechable (navegador/local) para probar el plugin sin instalar nada. |
| `blueprint` | Crear/editar el JSON de WordPress Playground (demo reproducible del plugin). |
| `wp-block-themes` | Solo si se toca `theme.json`/temas de bloques. |
| `wordpress-router` | Meta-skill: clasifica el repo y enruta al workflow correcto. |

> No-WP pero útiles: `code-review` / `security-review`, `frontend-design`, `test-coverage`.

---

## 9. Arquitectura y convenciones

- **Módulos `Hookable`:** cada funcionalidad es una clase en `src/` que implementa
  `register(): void`. Para añadir una nueva, se crea y se registra en `Plugin::modules()`.
  El código de admin va detrás de `is_admin()`.
- **Seguridad (no negociable):** sanitizar en la entrada, escapar en la salida; nonce
  **+** `current_user_can()` en toda acción de estado; nunca procesar `$_POST`/`$_GET`
  completos (leer claves explícitas con `wp_unslash`).
- **Opciones:** todo bajo una sola key `openseo_settings` (ver `Settings/Options.php`).
  Facilita el *seed* en activación y la limpieza en uninstall.
- **i18n:** text domain `openseo`. WP 7.0 auto-carga traducciones de plugins de .org.
- **Prefijos globales:** `openseo` / `OpenSEO` / `OPENSEO` (enforced por PHPCS).

### Gotchas

- `wordpress-stubs` resuelve a una versión sin las funciones de WP 7.0, por eso existe el
  stub local `stubs/abilities-api.php` para PHPStan.
- PHPStan usa `--memory-limit=1G` (los stubs de WP agotan los 128M por defecto) y las
  constantes `OPENSEO_*` están como `dynamicConstantNames` para evitar falsos
  `require.fileNotFound` en rutas que dependen de la instalación.
- `vendor/` y `assets/build/` están en `.gitignore` pero **deben** ir en el ZIP — los genera
  el flujo de release, no el repo.
