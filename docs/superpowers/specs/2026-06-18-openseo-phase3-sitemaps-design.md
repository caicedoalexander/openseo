# OpenSEO — Fase 3: Sitemaps XML (spec)

**Fecha:** 2026-06-18
**Estado:** Aprobado
**Fase del roadmap:** 3 (ver `2026-06-18-openseo-design.md`)
**Ciclo:** spec (este documento) → plan → implementación

---

## 1. Resumen

La Fase 3 dota a OpenSEO de sitemaps XML **personalizando el sitemap nativo de
WordPress** (`WP_Sitemaps`), garantizado en WP 7.0+, en lugar de reimplementarlo. Core ya
produce el índice `wp-sitemap.xml`, los sub-sitemaps por tipo (`wp-sitemap-posts-post-N.xml`,
taxonomías, usuarios), la paginación y el stylesheet XSL, y ya anuncia el sitemap en el
`robots.txt` virtual. OpenSEO añade exactamente tres comportamientos vía los filtros
`wp_sitemaps_*`:

1. **Excluir el contenido marcado `noindex`** (requisito central del design doc maestro).
2. **Master on/off** del sitemap completo.
3. **Desactivar el sub-sitemap de autores** (por defecto, autores **fuera**).

### Decisiones fijadas en brainstorming

| Decisión | Elección |
|----------|----------|
| **Motor** | Extender el sitemap de core con filtros `wp_sitemaps_*`. Mínimo código, WP-native, menor superficie de bugs/seguridad. URL pública: `wp-sitemap.xml` (la de core). |
| **Controles en ajustes** | Solo dos: master on/off + desactivar autores. **Sin** toggles por post type ni por taxonomía. |
| **Exclusión de noindex** | Siempre activa; excluye entradas con `_openseo_robots_noindex = '1'`. |
| **Ping a buscadores** | **Retirado de la Fase 3** (obsoleto desde 2023; core ya publica el sitemap en `robots.txt`). IndexNow permanece en "Futuro". |

---

## 2. Alcance y no-objetivos

**Dentro de alcance:**

- Módulo `Hookable` nuevo que engancha tres filtros de core.
- Exclusión de entradas `noindex` del sub-sitemap de posts.
- Ajuste master on/off (equivale a `wp_sitemaps_enabled`).
- Ajuste para quitar el provider `users` (autores), apagado por defecto.
- Nueva pestaña **"Sitemaps"** en la página de ajustes con dos casillas.

**No-objetivos (YAGNI / fuera de esta fase):**

- ❌ Ping a buscadores — obsoleto; descubrimiento cubierto por el `robots.txt` de core.
- ❌ Toggles por post type / taxonomía individual.
- ❌ `lastmod` / `changefreq` / `priority` — core los omite a propósito; los seguimos.
- ❌ Overrides de `noindex` por término — los términos no tienen override editable aún
  (consistente con la Fase 1, que difirió los overrides de taxonomías). La exclusión de
  `noindex` aplica solo a posts.
- ❌ Sitemap propio con URL `sitemap_index.xml` (estilo Yoast/Rank Math).

---

## 3. Arquitectura

Un único módulo nuevo, fiel al patrón `Hookable` existente.

### `src/Sitemap/Sitemap.php`

- Implementa `Contracts\Hookable`; recibe `Settings\Options` por constructor.
- Se registra en `Plugin::modules()` **sin** la guarda `is_admin()` — el sitemap se sirve en
  peticiones de front-end (igual que `HeadPrinter`).
- En `register()` engancha tres filtros de core. **Cada callback delega en un método puro**
  para mantener los callbacks delgados y permitir unit tests con Brain Monkey sin WordPress.

| Filtro de core | Comportamiento | Método puro |
|----------------|----------------|-------------|
| `wp_sitemaps_enabled` | Devuelve `false` si el master on/off está apagado; si no, respeta el valor de core. | `is_enabled( bool $core ): bool` |
| `wp_sitemaps_add_provider` | Devuelve `false` para el provider cuyo `$name === 'users'` cuando autores está apagado; si no, devuelve `$provider` intacto. | `filter_provider( mixed $provider, string $name ): mixed` |
| `wp_sitemaps_posts_query_args` | Inyecta un `meta_query` que excluye los `noindex`. | `exclude_noindex( array $args ): array` |

**Registro en `Plugin::modules()`:** añadir `new Sitemap( $options )` al array de módulos de
front-end (el bloque que se construye antes de la guarda `is_admin()`), reusando la instancia
`$options` ya creada en `modules()`.

---

## 4. Exclusión de `noindex` (detalle)

El meta `_openseo_robots_noindex` (registrado en `Meta\PostMeta`) se almacena `'1'` cuando el
usuario marca noindex, y puede estar **ausente** en la mayoría de entradas o valer `'0'`/`''`.
Por eso el `meta_query` **no** puede ser un simple `NOT EXISTS` (excluiría entradas con `'0'`).
Se usa un `OR` robusto que excluye únicamente las entradas con valor exactamente `'1'`:

```php
$args['meta_query'] = array(
    'relation' => 'OR',
    array(
        'key'     => '_openseo_robots_noindex',
        'compare' => 'NOT EXISTS',
    ),
    array(
        'key'     => '_openseo_robots_noindex',
        'value'   => '1',
        'compare' => '!=',
    ),
);
```

- Incluye entradas **sin** el meta (`NOT EXISTS`) y entradas con valor **≠ `'1'`**.
- Excluye solo las marcadas `noindex` (`= '1'`).
- Si `$args` ya trajera un `meta_query` de otro plugin, se respeta combinándolo bajo una
  relación `AND` con el nuestro (el plan detallará el merge defensivo).

> **Nota técnica:** `register_post_meta` declara `'default' => ''` para esta clave. El `OR`
> explícito con `NOT EXISTS` es robusto independientemente de cómo WordPress trate los valores
> por defecto en las meta queries, por lo que es preferible a confiar en un único comparador.

---

## 5. Ajustes (Options + SettingsPage + template)

### `Settings\Options`

Dos defaults nuevos en `defaults()`:

```php
'sitemap_enabled'         => '1',  // master on
'sitemap_include_authors' => '',   // autores fuera por defecto
```

Sanitización en `sanitize()`: ambas son casillas, se normalizan a `'1'` o `''`
(p. ej. `$clean[$key] = isset($input[$key]) && '1' === $input[$key] ? '1' : '';` para las
claves de checkbox, leyendo claves explícitas — nunca el `$_POST` completo).

### `Admin\SettingsPage`

- Nueva sección `add_settings_section( 'openseo_sitemaps', __( 'Sitemaps', 'openseo' ), … )`.
- **Dos campos checkbox.** El renderer de texto actual (`add_text_field`) no sirve; se añade un
  helper de checkbox que emite el patrón de **campo hidden compañero** (ver abajo).

### `templates/admin/settings-page.php`

- Añadir `'sitemaps' => __( 'Sitemaps', 'openseo' )` al array `$openseo_tabs` (entre `social`
  y `ai`, o al final — orden a decidir en el plan; no afecta funcionalidad). El resto del
  template ya itera el array y renderiza `do_settings_sections( 'openseo_' . $active )`, así que
  no requiere más cambios.

### Gotcha de la Settings API con checkboxes

Una casilla desmarcada **no envía ninguna clave**, y el `sanitize()` actual (por diseño de
multi-pestaña) solo toca las claves presentes en el envío. Para poder **apagar** una casilla se
usa el patrón de **campo hidden compañero**:

```php
<input type="hidden"   name="openseo_settings[sitemap_enabled]" value="0" />
<input type="checkbox" name="openseo_settings[sitemap_enabled]" value="1" <?php checked( '1', $value ); ?> />
```

Cuando la casilla está desmarcada llega solo el hidden (`'0'`); cuando está marcada llegan ambos
y, por orden en el DOM, gana el checkbox (`'1'`). Así la clave **siempre** está presente al
enviar la pestaña Sitemaps, `sanitize()` la trata igual que el resto sin lógica especial de
pestaña, y las demás pestañas (que nunca incluyen estas claves) conservan su valor guardado.

---

## 6. Errores y degradación

- **Sin fatales:** los `add_filter` son inertes si `WP_Sitemaps` no existiera (no debería en
  7.0+); no se asume su presencia para nada crítico.
- **Master off:** `wp_sitemaps_enabled => false` desactiva limpiamente; core deja de servir el
  sitemap y de anunciarlo en `robots.txt`.
- **Salida:** OpenSEO **no** imprime XML propio → el escaping lo hace core. Solo se manipulan
  argumentos de consulta y el registro de providers.
- **Seguridad:** los ajustes ya pasan por `current_user_can('manage_options')` + el nonce de la
  Settings API; la sanitización vive en `Options`. No se procesa `$_POST`/`$_GET` completo: se
  leen claves explícitas.

---

## 7. Testing

- **Unit (Brain Monkey, sin WordPress):**
  - `Sitemap::is_enabled()` — on/off según el ajuste.
  - `Sitemap::filter_provider()` — quita `users` solo cuando autores off; deja el resto intacto.
  - `Sitemap::exclude_noindex()` — verifica la forma del `meta_query` (`OR` + `NOT EXISTS` +
    `!= '1'`) y el merge defensivo si ya había `meta_query`.
  - `OptionsTest` — `defaults()` incluye las dos claves nuevas; `sanitize()` normaliza las
    casillas a `'1'`/`''` y deja intactas las claves de otras pestañas.
- **Integración (wp-env):**
  - Una entrada `noindex` está **ausente** de `wp-sitemap-posts-post-1.xml`; una entrada normal
    está **presente**.
  - El provider `users` está **ausente** cuando autores off (y presente si se activa).
  - Con master off, `wp-sitemap.xml` no se sirve (sitemaps deshabilitados).
- **Cobertura objetivo:** ≥ 80% en la lógica nueva.

> CI no configura ningún conector de IA, pero estos tests no dependen de IA: ejercitan filtros
> de core y la Settings API, así que corren íntegros en la suite de integración de wp-env.

---

## 8. Resumen de archivos tocados

| Archivo | Cambio |
|---------|--------|
| `src/Sitemap/Sitemap.php` | **Nuevo.** Módulo `Hookable` con los tres filtros + métodos puros. |
| `src/Plugin.php` | Registrar `new Sitemap( $options )` en los módulos de front-end. |
| `src/Settings/Options.php` | Dos defaults nuevos + su sanitización (checkboxes). |
| `src/Admin/SettingsPage.php` | Sección `openseo_sitemaps` + helper de checkbox + dos campos. |
| `templates/admin/settings-page.php` | Entrada `'sitemaps'` en `$openseo_tabs`. |
| `tests/Unit/SitemapTest.php` | **Nuevo.** Unit de los métodos puros. |
| `tests/Unit/OptionsTest.php` | Casos para los defaults y la sanitización nuevos. |
| `tests/Integration/SitemapTest.php` | **Nuevo.** Exclusión noindex, provider autores, master off. |

---

## 9. Actualización del roadmap maestro

El design doc maestro (`2026-06-18-openseo-design.md`, §7) describe la Fase 3 como
"Sitemaps XML: índice + sub-sitemaps por tipo, exclusiones (respeta `noindex`), ping a
buscadores". Esta spec **retira el ping** por obsolescencia y deja constancia de que el índice y
los sub-sitemaps los aporta core; el valor de OpenSEO es la exclusión de `noindex` y los dos
controles de ajustes. (No se edita el design doc maestro en esta fase; esta spec lo refina.)
