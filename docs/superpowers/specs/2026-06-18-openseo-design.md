# OpenSEO — Documento de diseño

**Fecha:** 2026-06-18
**Estado:** Aprobado (diseño maestro)
**Alcance de este documento:** visión, arquitectura y roadmap por fases. El plan de
implementación detallado se redacta aparte y **solo para la Fase 1**; cada fase posterior
tendrá su propio ciclo spec → plan → implementación.

---

## 1. Visión y decisiones fijadas

OpenSEO es un plugin de WordPress **SEO esencial + AI-native**, de código abierto
(GPL-2.0-or-later) y 100% gratuito, construido sobre la **WordPress 7.0 Abilities API** y el
**AI Client** nativo (Settings → Connectors). No incluye proveedor de IA propio: reutiliza las
claves que el usuario ya configuró, gastando sus créditos del proveedor de su preferencia.

Decisiones de producto tomadas en la fase de brainstorming:

| Decisión | Elección |
|----------|----------|
| **Posicionamiento** | Esencial + AI-native: clavar las features *table-stakes* y diferenciarse con IA profunda vía Abilities API. No perseguir paridad amplia con Rank Math. |
| **Alcance Fase 1** | Núcleo on-page completo (no un mínimo estricto). |
| **Modelo de IA** | Las *abilities* son la fuente de verdad única; el editor expone botones que invocan esas mismas abilities. Una sola lógica, dos consumidores (humano + agente/MCP). |
| **Roadmap** | Enfoque A: bases primero, IA temprano (Fase 2). |
| **Monetización** | Sin freemium ni capa de licencias. Todo GPL/gratis, distribución en WordPress.org. |
| **Migración** | Importante para la adopción, pero programada como fase posterior (Fase 6), no en el MVP. |

### Principio rector de la IA

La IA **propone, no escribe a ciegas**: cada operación devuelve una sugerencia que el usuario
revisa y guarda. Las abilities son descubribles por agentes externos (Claude vía MCP, otros
plugins) y, a la vez, las consume la UI del editor.

---

## 2. Estado de partida (Fase 0, ya existente)

El scaffold del plugin está terminado y los gates de calidad (PHPCS, PHPStan nivel 6, PHPUnit)
en verde:

- Bootstrap (`openseo.php`), composition root `Plugin` (singleton, `modules()` + `boot()`).
- Contrato `Hookable` (`register(): void`) — mecanismo único de descubrimiento de módulos.
- `Settings\Options` — todos los ajustes bajo una sola clave `openseo_settings`.
- `Ai\Abilities` — categoría + ability `openseo/generate-meta-description` **stub** (hoy usa un
  fallback determinista con `wp_trim_words`; todavía no llama al AI Client).
- `Frontend\MetaTags` — imprime `<meta name="description">` en `wp_head` (única feature real).
- Lifecycle (Activator/Deactivator/Uninstaller), tests unit + integration, tooling y release.

La Fase 1 **reemplaza** `Frontend\MetaTags` por una salida `<head>` completa y dota al plugin de
almacenamiento SEO por entrada.

---

## 3. Arquitectura — módulos nuevos de la Fase 1

Todo sigue el patrón existente: clases `Hookable` bajo `src/`, registradas en `Plugin::modules()`
(las de admin detrás de `is_admin()`). Tres áreas nuevas:

### 3.1 `src/Meta/` — datos on-page y resolución en cascada

- **`PostMeta`** — registra las claves por entrada con `register_post_meta()`, cada una con
  `show_in_rest` (para que el panel del editor las lea/escriba por REST sin endpoints propios),
  `sanitize_callback` y `auth_callback` (exige `edit_post`). Claves previstas:
  - `_openseo_title`, `_openseo_description`
  - `_openseo_robots_noindex`, `_openseo_robots_nofollow`
  - `_openseo_canonical`
  - `_openseo_og_title`, `_openseo_og_description`, `_openseo_og_image`
  - `_openseo_twitter_title`, `_openseo_twitter_description`, `_openseo_twitter_image`
- **`Resolver`** — dado el objeto consultado (post singular, término, front page, archivo),
  devuelve los **valores efectivos** en cascada:
  *override por entrada → plantilla por tipo de contenido (settings) → fallback global de WP*.
  Lógica pura, sin efectos de salida → 100% testeable de forma aislada.
- **`Variables`** — resuelve marcadores en plantillas de título/descripción:
  `%title%`, `%sitename%`, `%sep%`, `%excerpt%`, `%page%`, `%category%`, etc.

### 3.2 `src/Frontend/Head/` — salida del `<head>`

Reemplaza `Frontend\MetaTags` por un **`HeadPrinter`** (`Hookable`, engancha `wp_head`) que
orquesta *presenters* pequeños y de responsabilidad única:

- `Title` — controla el `<title>` vía `pre_get_document_title` / `document_title_parts`
  (no imprime una etiqueta `<title>` duplicada).
- `Description`, `Robots`, `Canonical`, `OpenGraph`, `Twitter`.

Cada presenter pide su valor al `Resolver` y lo imprime **escapado** (`esc_attr` / `esc_url`).

### 3.3 `src/Admin/Editor/` — UI del editor

- Registra el bundle del panel del editor; el almacenamiento se delega en `Meta\PostMeta`.
- **`assets/src/editor/`** — panel React con `registerPlugin` + `PluginDocumentSettingPanel`:
  - Vista previa tipo Google (snippet).
  - Campos *Title* / *Description* con contador de caracteres.
  - Pestañas *Social* (OG / Twitter) y *Avanzado* (robots, canonical).
  - Lee/escribe el postmeta con `useEntityProp` (REST). Sin AJAX propio.
  - **Alcance Fase 1:** la UI de edición por entrada cubre entradas y páginas (post types
    públicos con editor de bloques). Los términos y archivos no tienen override editable
    todavía: el `Resolver` los sirve mediante los defaults globales (degrada sin fatales).
    La UI de overrides para taxonomías queda para una fase posterior.

### 3.4 `Settings\Options` ampliado

Defaults globales nuevos: plantillas de title/description por post type, separador de título,
robots por defecto, imagen social por defecto. El `Admin\SettingsPage` pasa a pestañas:
**General · Títulos y meta · Social**.

---

## 4. Flujo de datos

- **Escritura:** Panel React → REST de `postmeta` (vía `show_in_rest`) → `wp_postmeta`.
- **Lectura / frontend:** `wp_head` → `HeadPrinter` → `Resolver(queried object)` → override o
  defaults → los presenters escapan e imprimen.
- **IA (Fase 2, ya encaja en este diseño):** botón *"Generar con IA"* en el panel → invoca la
  ability vía la REST de la Abilities API → la ability lee el post, llama al **AI Client**
  (Settings → Connectors) y devuelve texto → React lo coloca en el campo para que el usuario lo
  **revise y guarde**.

---

## 5. Errores y seguridad

- **Entrada:** `sanitize_callback` por clave de meta + `sanitize()` en `Options`. Nunca se
  procesa `$_POST`/`$_GET` completo: se leen claves explícitas con `wp_unslash`.
- **Salida:** escape estricto en todos los presenters (`esc_attr` / `esc_url`).
- **Autorización:** `auth_callback` por meta exige `edit_post`; toda acción de estado combina
  nonce **+** `current_user_can()`.
- **Degradación sin fatales:** sin override → cascada a defaults → fallback al comportamiento
  nativo de WP. La ability de IA devuelve `WP_Error` si no hay conector configurado, y el botón
  del editor muestra un aviso claro en vez de fallar en silencio.

---

## 6. Testing

- **Unit (Brain Monkey, sin WordPress):** `Resolver` (cada nivel de la cascada), `Variables`
  (cada reemplazo y casos borde), sanitización por clave.
- **Integración (wp-env):** las metas se registran y se exponen en REST; `wp_head` emite las
  etiquetas correctas para un post con y sin overrides; el filtrado del `<title>` funciona.
- **JS:** test del componente de panel (contadores de caracteres, vista previa).
- **Cobertura objetivo:** ≥ 80% en la lógica nueva.

---

## 7. Roadmap por fases (Enfoque A)

| Fase | Entregable |
|------|-----------|
| **0 ✅** | Scaffold, bootstrap, settings single-key, ability stub, meta description básica *(ya existe)*. |
| **1** | **Núcleo on-page**: `postmeta` + panel del editor + `<head>` completo (title con variables, description, robots, canonical, Open Graph, Twitter Cards) + defaults globales. *Reemplaza la meta description actual.* |
| **2** | **IA conectada**: AI Client real tras las abilities (meta, título, alt-text), botones *"Generar con IA"* en el panel del editor, manejo de "sin conector configurado". |
| **3** | **Sitemaps XML**: índice + sub-sitemaps por tipo, exclusiones (respeta `noindex`), ping a buscadores. |
| **4** | **Schema + Breadcrumbs**: JSON-LD (`WebSite`, `WebPage`, `Article`, `BreadcrumbList`, `Organization`/`Person`) + breadcrumbs (función + bloque) + ability "sugerir schema". |
| **5** | **Redirecciones + 404**: tabla propia, gestor en admin, 301/302/410, regex, monitor de 404, auto-redirect al cambiar el slug. |
| **6** | **Migración + Setup Wizard**: importación del `postmeta` de Yoast / Rank Math, asistente de configuración inicial, import/export de ajustes. |
| **Futuro** | Search Console (OAuth), análisis de contenido/legibilidad en el editor, internal linking, IndexNow. *(Fuera del core inicial.)* |

---

## 8. Referencia: features de Rank Math (table-stakes vs. diferenciadores)

Inventario destilado del análisis de `seo-by-rank-math/` (referencia de inspiración, sin
código compartido). Se usa para priorizar; OpenSEO **no** copia su implementación.

**Table-stakes** (todo plugin SEO las necesita): meta tags (title/description/robots), focus
keyword, sitemaps XML/HTML, análisis de contenido/legibilidad, schema básico, redirecciones,
Search Console, dashboard de analítica, gestor de roles, panel de ajustes por pestañas,
importación 1-click (Yoast/AIO), breadcrumbs + schema frontend.

**Diferenciadores premium de Rank Math** (que OpenSEO aborda con su ángulo AI-native o deja
fuera): Content AI, schema builder avanzado, link genius, analítica multi-servicio, instant
indexing, WooCommerce avanzado, integración profunda con page builders, herramientas de
importación múltiples, LLMs.txt / MCP.

El diferenciador de OpenSEO **no** es la breadth, sino que **cada operación de IA es una ability
estándar**, descubrible y reutilizable por agentes — algo que en Rank Math está acoplado a su
propia UI y a su API de pago.
