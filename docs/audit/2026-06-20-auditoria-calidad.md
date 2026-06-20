# Informe Ejecutivo de Auditoría de Calidad — OpenSEO

> Plugin WordPress AI-native sobre la Abilities API de WP 7.0 · PHP 8.1+ · GPL-2.0-or-later
> Fecha: 2026-06-20 · Alcance: Fases 1–5 · Método: auditoría multi-agente (10 dimensiones + verificación adversarial de HIGH/CRITICAL)

---

## 1. Veredicto general

OpenSEO está en **muy buena forma**: arquitectura ejemplar y disciplinada (bootstrap → composition root → módulos `Hookable`), seguridad sólida en las capas de admin, SQL y escape de salida, y **los 6 quality gates en verde** (PHPCS, PHPStan nivel 6, PHPUnit, lint JS/CSS y tests JS). No se confirmó **ningún hallazgo CRITICAL ni HIGH**: de los cuatro candidatos HIGH que pasaron verificación adversarial, los cuatro fueron rebajados (tres a MEDIUM, uno a LOW). El plugin es mergeable en su estado actual. Los puntos de atención reales son acotados y se concentran en: (a) un *footgun* de corrección en la guarda anti-bucle del motor de redirects (MEDIUM), (b) huecos de cobertura de tests en `Cache`, presenters de `Head` y rama regex del `Matcher`, y (c) tareas de empaquetado/distribución pendientes antes del primer release en WordPress.org (LICENSE verbatim, `.distignore`, `readme.txt`).

### Puntuación por dimensión

| Dimensión | Score |
|---|---:|
| Arquitectura y estructura | 95 |
| Seguridad: SQL y datos | 92 |
| Seguridad: admin y CRUD | 88 |
| Seguridad: escape de salida (XSS) | 88 |
| Calidad de código PHP | 88 |
| Corrección de la Abilities API | 88 |
| Rendimiento backend | 88 |
| Cumplimiento WordPress.org y GPL | 82 |
| Corrección funcional: redirects + 404 | 78 |
| Cobertura y calidad de tests | 72 |
| **Promedio** | **86** |

---

## 2. Quality gates

| Gate | Comando | Resultado | Detalle |
|---|---|:---:|---|
| PHP lint (PHPCS WPCS) | `composer lint` | ✅ PASS | 60/60 archivos sin violaciones (~6.9s) |
| PHP análisis estático (PHPStan nivel 6) | `composer analyze` | ✅ PASS | `[OK] No errors` en 57/57 archivos |
| PHP unit tests (PHPUnit + Brain Monkey) | `composer test:unit` | ✅ PASS | 134 tests / 259 asserts OK (~0.42s) |
| JS lint | `npm run lint:js` | ✅ PASS | Sin errores (exit 0) |
| CSS lint | `npm run lint:css` | ✅ PASS | Sin errores (exit 0) |
| JS unit tests | `npm run test:js` | ✅ PASS | 2 suites / 5 tests OK |

**6/6 en verde.** Nota: las líneas `NativeCommandError` que aparecen en `analyze` y `test:js` son artefactos del *wrapping* de stderr de PowerShell sobre salida informativa/PASS (exit code 0), **no fallos reales**.

---

## 3. Hallazgos confirmados

No hay hallazgos **CRITICAL** ni **HIGH** confirmados. Todos los candidatos HIGH fueron rebajados en la verificación adversarial.

### MEDIUM

**FUNC-1 — Guarda anti-bucle compara el target sin normalizar → bucle infinito por barra final**
`src/Redirects/Matcher.php:52` (también rama degradada en `Dispatcher.php:105`)
- **Qué pasa:** la única protección anti-bucle es `if ( $target === $path ) return null;`. El `$path` viene normalizado por `Normalizer` (colapsa la barra final), pero `$rule->target` se persiste **verbatim** (`RedirectsPage::handle_save` normaliza la *fuente* pero nunca el *target*). Si un admin crea una regla cuya fuente normaliza a `/x` y el target queda como `/x/`, la guarda no dispara (`'/x/' !== '/x'`) y se produce `ERR_TOO_MANY_REDIRECTS` autosostenido (Dispatcher@5 hace `exit` antes de que `redirect_canonical@10` corrija la barra). Caso insidioso: teclear el mismo `/x/` en ambos campos también dispara el bucle, porque la fuente se normaliza y el target no.
- **Por qué importa:** DoS total de la URL afectada. Atenuantes: requiere un usuario `manage_options` creando la regla manualmente, no es remoto ni anónimo, sin dimensión de seguridad, y el único generador automático (`SlugWatcher`) sí normaliza su target. Por eso HIGH → **MEDIUM**.
- **Cómo arreglarlo:** normalizar el target interno (cuando es relativo) en `handle_save`, y/o comparar en la guarda `Normalizer->normalize($target) === $path`. Añadir test con regla `/x → /x/` que afirme match nulo.

**FUNC-2 — Sin chequeo de ciclos multi-regla: `/a→/b` + `/b→/a` produce bucle**
`src/Redirects/Admin/RedirectsPage.php:101`
- **Qué pasa:** la guarda es de un solo salto. Dos reglas exactas creadas desde la UI pueden formar un ciclo que el navegador corta con `ERR_TOO_MANY_REDIRECTS`. `handle_save` no lo detecta (`SlugWatcher` sí colapsa el rename-back, pero solo en la ruta auto-slug).
- **Cómo arreglarlo:** en `handle_save`, para targets internos exactos, consultar `repo->find_active_by_source(targetPath)` y avisar si apunta de vuelta a la fuente. Como mínimo, documentar el límite.

**FUNC-3 — El modo degradado ignora silenciosamente todas las reglas regex**
`src/Redirects/Dispatcher.php:103`
- **Qué pasa:** con `Cache::is_degraded()` (>2000 reglas activas, `DEGRADE_THRESHOLD`), `resolve()` solo usa `find_active_by_source()`, que filtra `is_regex = 0`. Las reglas regex dejan de aplicarse por completo, sin log ni aviso.
- **Por qué importa:** cambio de comportamiento invisible para el operador; el umbral es alto pero el punto ciego es real (los tests de integración del modo degradado solo cubren reglas exactas).
- **Cómo arreglarlo:** en modo degradado, seguir evaluando las reglas regex desde un `Ruleset` construido solo con `is_regex = 1` (normalmente pocas), manteniendo el O(1) exacto. Documentar el límite en la UI.

**SQL-2 — El fallback de `uninstall.php` no elimina las tablas personalizadas**
`uninstall.php:24-26`
- **Qué pasa:** si `vendor/autoload.php` no está disponible al desinstalar, el bloque fallback borra opciones pero **no** ejecuta `DROP TABLE` sobre `openseo_redirects` ni `openseo_404_logs`, dejando datos de usuario huérfanos.
- **Por qué importa:** WordPress.org exige limpieza completa en uninstall; el fallback es el peor escenario para incumplirlo.
- **Cómo arreglarlo:** añadir `DROP TABLE IF EXISTS {$wpdb->prefix}openseo_redirects` y `..._404_logs` (usando `$wpdb->prefix` directo, sin autoloader) y borrar `openseo_db_version` en el fallback.

**QC-1 — `sanitize_text_field` sobre `REQUEST_URI` corrompe paths con caracteres especiales**
`src/Redirects/Dispatcher.php:67`
- **Qué pasa:** el `REQUEST_URI` pasa por `sanitize_text_field()` antes del pipeline de normalización; esa función convierte entidades, elimina tags y colapsa espacios, alterando paths legítimos con `<`, `>`, `%` codificado, etc. El `Normalizer` ya hace strip de query, decode y normalización de slashes.
- **Cómo arreglarlo:** usar solo `wp_unslash()` aquí; la defensa XSS no depende de este punto (el valor pasa por `esc_url_raw`/`wp_safe_redirect` antes de emitirse).

**QC-3 / ADM-2 — Instanciación directa de colaboradores en `notfound-panel.php`**
`templates/admin/notfound-panel.php:15`
- **Qué pasa:** la plantilla hace `new LogRepository()`, `new NotFoundListTable()` y `new Options()` fuera de cualquier composition root, rompiendo la inyección de dependencias del resto del plugin y dificultando el testing. Sin implicación de seguridad.
- **Cómo arreglarlo:** crear un controlador `NotFoundPage` Hookable análogo a `RedirectsPage` que inyecte `LogRepository` y `Options` por constructor y pase los colaboradores a la vista.

**PERF-1 — `Options::get` reconstruye el array de settings en cada acceso sin memoizar**
`src/Settings/Options.php:73`
- **Qué pasa:** `get()` llama a `all()`, que hace `get_option` + `array_merge` de ~18 defaults en cada acceso (decenas de veces en `wp_head`). Sin query repetida, pero con merge redundante.
- **Cómo arreglarlo:** memoizar `all()` en una propiedad nullable (`Options` se instancia una vez por request).

**WPORG-2 — `.distignore` no excluye `docs/` ni `NOTES.md` del ZIP de distribución**
`.distignore:38-41`
- **Qué pasa:** `.distignore` excluye `CONTRIBUTING.md`, `CHANGELOG.md` y `README.md`, pero NO `docs/` (planes internos) ni `NOTES.md` —que el propio archivo declara "No se incluye en el ZIP de distribución"—. `wp-scripts plugin-zip` solo omite lo listado, así que ambos se empaquetarían en el release.
- **Cómo arreglarlo:** añadir `/docs` y `/NOTES.md` a `.distignore` y verificar con `npm run plugin-zip` + `unzip -l openseo.zip` antes del primer release.

**WPORG-3 — `readme.txt` desactualizado: omite features ya implementadas y `Tested up to` sin verificar**
`readme.txt:5`
- **Qué pasa:** el `readme.txt` describe solo el "initial scaffold" (meta description, settings, una ability), pero el plugin ya tiene Fases 3–5: sitemaps, Schema/JSON-LD, breadcrumbs, motor de redirecciones y monitor de 404. La Description, el Changelog (`0.1.0 - Initial scaffold`) y la lista de features no reflejan la realidad; `Tested up to: 7.0` debe corresponder a una versión realmente probada.
- **Cómo arreglarlo:** actualizar Description, features y Changelog para cubrir Fases 3–5; confirmar `Tested up to` contra la versión real de wp-env.

### LOW

| ID | Archivo:línea | Qué pasa | Cómo arreglarlo |
|---|---|---|---|
| **WPORG-1** | `LICENSE:17-28` | `LICENSE` es un placeholder de 28 líneas con un puntero `curl`, no el texto verbatim de la GPL-2.0. *Rebajado HIGH→LOW:* las cabeceras `License`/`License URI` en `openseo.php` y `readme.txt` satisfacen la Guideline 1; no es bloqueador. | `curl -fsSL https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt -o LICENSE` y commitear antes del release. |
| **TC-1** | `tests/Unit/Redirects/MatcherTest.php:53` | La guarda anti-bucle no se testea para targets regex-sustituidos (`'^(/loop)$' → '$1'`). *Rebajado HIGH→MEDIUM/LOW:* el guard ya es agnóstico a la rama y atrapa el loop en runtime; es hueco de test, no bug shippable. | Test: `Redirect(1,'^(/loop)$','$1',301,true,true)` contra `/loop` debe devolver `null`. |
| **ADM-1** | `src/Redirects/Admin/RedirectsPage.php:76` | Doble lectura del campo `target`: si `esc_url_raw` devuelve vacío, el fallback usa `$relative` (solo `sanitize_text_field`), permitiendo paths no validados como esquema. Gated tras `manage_options`. | Validar path relativo explícito: `str_starts_with($relative,'/') && !str_contains($relative,'://')`. |
| **SQL-1** | `src/NotFound/Monitor.php:50-52` | Doble sanitización redundante Monitor→`LogRepository::trim()`; inocua pero confunde la responsabilidad. | `Monitor` pasa valores crudos (solo `wp_unslash`); `LogRepository::trim()` único saneador. |
| **SQL-3** | `src/Redirects/Cache.php:51-55` | Deserialización de `Ruleset` desde transient con `instanceof` (correcto); solo amenaza si la BD ya está comprometida. | Defensa en profundidad: loggear si el transient no es `Ruleset` ni `false`. Sin cambio de lógica. |
| **XSS-1** | `src/Admin/Editor/EditorPanel.php:51` | `wp_json_encode` sin `JSON_HEX_TAG` en el inline script `window.openseoEditor` (valores actuales seguros: booleano + `admin_url`). | Añadir `JSON_HEX_TAG` como 2º argumento. Defensa en profundidad. |
| **XSS-2** | `templates/admin/settings-page.php:35` | Escape temprano (`esc_url` al asignar) + `echo` desnudo con `phpcs:ignore` en lugar de escapar en el punto de salida. | `echo esc_url(add_query_arg(...))` en el `echo`; eliminar el `phpcs:ignore`. |
| **ABL-1** | `src/Ai/Abilities.php:108` | La description dice "Read-only" pero la annotation (correcta) declara `readonly:false`. Solo roce textual. | Reformular la description para no usar "Read-only". |
| **ABL-2** | `src/Ai/Abilities.php:291` | `input_schema` de objeto sin `additionalProperties:false`; claves con typo pasan silenciosas. | Añadir `'additionalProperties' => false`. |
| **ABL-3** | `src/Ai/Abilities.php:224` | Código de error `openseo_no_connector` fuera del vocabulario estándar de bootstrap. Bien cubierto por tests. | Opcional: alinear el código o documentarlo como house-style en `NOTES.md`. |
| **ABL-4** | `src/Ai/Abilities.php:291` | (No-issue documentado) ausencia de `default` raíz es **intencional**: las tres abilities requieren `post_id`. | Sin cambios. |
| **QC-2** | `src/Redirects/SlugWatcher.php:143` | `path_of()` sin type hint en `$permalink` (`string\|false`); sería rechazado en PHPStan ≥7. | `private function path_of( string\|false $permalink ): string`. |
| **QC-4** | `src/Redirects/Dispatcher.php:100` | `resolve()` instancia `Normalizer` + parsea `home_url()` en cada llamada (1×/request, impacto mínimo). | Construir el `Normalizer` una vez (constructor o lazy). |
| **FUNC-4** | `src/Redirects/Dispatcher.php:83` | 307 permitido sin distinción de método (reenvía cuerpo en POST). Comportamiento aceptable. | Opcional: documentar en la UI o restringir a 301/302. |
| **FUNC-5** | `src/Redirects/Matcher.php:50` | Target regex (`$1`) contenido por `wp_safe_redirect` para internos; no es open redirect. | Mantener. Opcional: test que confirme contención de `//evil.com`. |
| **PERF-2** | `src/NotFound/Pruner.php:37` | `Pruner::schedule` corre en `init` en todo request del front, aunque el monitor sea opt-in. | Mover a `admin_init` o gatear tras `notfound_monitor_enabled`. |
| **PERF-3** | `src/Lifecycle/Activator.php:27` | `add_option` sin `autoload` explícito (default `yes`; correcto para opción pequeña). | Hacer el autoload explícito. |
| **ARCH-1** | `src/Breadcrumbs/Block.php:68` | Atributos del bloque duplicados PHP/JS (en sincronía y documentados); riesgo de deriva futura. | Test de paridad de claves PHP↔JS, o JSON compartido. |
| **ARCH-2** | `src/Frontend/Head/OpenGraph.php:38` | Bucle de emisión de `<meta>` casi idéntico entre OpenGraph y Twitter (duplicación estructural genuina). | Opcional: helper/trait `MetaTagWriter` compartido. |
| **WPORG-4** | `src/Lifecycle/Uninstaller.php:24` | Transients de cache (`openseo_redirects_ruleset`, `..._count`) no se borran en uninstall (cache regenerable, no datos de usuario). | Añadir `delete_transient()` para ambos keys. |
| **TC-2** | `src/Redirects/Cache.php` | `Cache` sin tests unitarios: rutas object-cache-hit, miss-then-build y `flush()` dual-store no aseveradas. *(MEDIUM en la dimensión tests)* | `CacheTest.php` con Brain Monkey: hit, transient-warm, full-miss, `flush()`. |
| **TC-3** | `src/Frontend/Head/Description.php` | Los 5 presenters de `Head` solo cubiertos por el smoke test de integración. *(MEDIUM en la dimensión tests)* | Tests unitarios por presenter (Description skip-when-empty, OpenGraph url-vs-attr, Robots). |
| **TC-4** | `tests/Integration/SlugWatcherTest.php` | La rama duplicate-guard (`exists_for_source`) no se ejercita. *(MEDIUM en la dimensión tests)* | Pre-sembrar una regla para el slug antiguo y afirmar count == 1 tras el rename. |
| **TC-5** | `src/NotFound/LogRepository.php:92` | `delete()` y `clear()` sin cobertura. | Extender `NotFoundTest`: `delete($id)` → count 0; `clear()` con 2 filas → count 0. |
| **TC-6** | `tests/Unit/Schema/GraphTest.php` | `print_graph()` (JSON_HEX_TAG + echo) y el early-return de grafo vacío no se aseveran. | Test con valor `</script>` → afirmar escapado; test de grafo vacío → sin `<script>`. |

> **Verificación adversarial:** los 4 candidatos HIGH (**FUNC-1**, **TC-1**, **WPORG-1**, y el HIGH de redirects) son **reales factualmente** pero **mal calibrados en severidad**. Se respeta el veredicto: FUNC-1 → MEDIUM (gated tras input privilegiado, sin dimensión de seguridad), TC-1 → MEDIUM/LOW (hueco de test, no bug shippable), WPORG-1 → LOW (las cabeceras de licencia ya satisfacen la Guideline 1). **Ningún hallazgo fue descartado por `isReal=false`.**

---

## 4. Top riesgos y plan de remediación priorizado

1. **[FUNC-1 · MEDIUM]** Normalizar el target interno en `handle_save` **y** comparar el target normalizado en la guarda anti-bucle (`Matcher::result()` y rama degradada del `Dispatcher`). Añadir test `/x → /x/`. *Elimina el bucle infinito por barra final.*
2. **[SQL-2 · MEDIUM]** Añadir `DROP TABLE IF EXISTS` (ambas tablas) y `delete_option('openseo_db_version')` al bloque fallback de `uninstall.php`. *Requisito de limpieza para WordPress.org.*
3. **[Distribución · WPORG-2/WPORG-3/WPORG-1]** Antes del primer release: añadir `/docs` y `/NOTES.md` a `.distignore` (verificar con `unzip -l`); actualizar `readme.txt` (Description, features, Changelog, `Tested up to`); materializar el texto verbatim de la GPL-2.0 en `LICENSE`.
4. **[QC-1 · MEDIUM]** Cambiar `sanitize_text_field` por solo `wp_unslash` sobre `REQUEST_URI` en `Dispatcher`. *Evita corromper paths legítimos.*
5. **[FUNC-3 · MEDIUM]** En modo degradado, evaluar reglas regex desde un `Ruleset` filtrado por `is_regex = 1`; documentar el límite en la UI.
6. **[FUNC-2 · MEDIUM]** Detectar (o al menos documentar) ciclos directos de 2 reglas en `handle_save`.
7. **[QC-3/ADM-2 · MEDIUM/LOW]** Introducir `NotFoundPage` Hookable que inyecte `LogRepository`/`Options`.
8. **[PERF-1 · MEDIUM]** Memoizar `Options::all()` en propiedad nullable.
9. **[Cobertura · TC-1..TC-6]** Cerrar huecos de tests: `CacheTest`, presenters de `Head`, rama regex del `Matcher`, duplicate-guard de `SlugWatcher`, `LogRepository::delete/clear`, `Graph::print_graph()`.
10. **[Pulido LOW]** Defensas en profundidad y consistencia: `JSON_HEX_TAG` (XSS-1), escape late (XSS-2), validación de path relativo (ADM-1), `additionalProperties:false` y descripción de abilities (ABL-1/2/3), type hint `path_of()` (QC-2), `delete_transient` en uninstall (WPORG-4), helper OpenGraph/Twitter (ARCH-2), paridad de atributos del bloque (ARCH-1), PERF-2/PERF-3.

---

## 5. Fortalezas

- **Arquitectura ejemplar (95).** Patrón bootstrap → composition root (singleton idempotente) → módulos `Hookable` aplicado de forma consistente en los 55 archivos de `src/`, todos muy por debajo del límite de 800 líneas (el mayor, `Ai\Abilities.php`, 319). Alta cohesión, bajo acoplamiento; tres contratos (`Hookable`, `Presenter`, `Piece`) desacoplan orquestador e implementaciones, el patrón Repository aísla todo el SQL, y las unidades puras (`Normalizer`, `Matcher`, `Regex`, `Ruleset`) son testables sin WordPress. Separación admin/frontend tras `is_admin()` correcta. **Sin defectos arquitectónicos.**
- **Seguridad sólida en las tres capas.** Admin/CRUD (88): toda acción de estado empareja `current_user_can()` con `check_admin_referer()`, nonces específicos por acción/fila, nunca se procesa `$_POST`/`$_GET` completo. SQL (92): todo parametrizado con `$wpdb->prepare()`/`insert`/`update`/`delete`, nombres de tabla solo desde `$wpdb->prefix`, **sin IP almacenada** en los logs 404. Escape de salida (88): `esc_attr`/`esc_url`/`esc_html` consistentes; JSON-LD con `wp_json_encode` + `JSON_HEX_TAG` y guardia de `false`.
- **Calidad de código PHP alta (88).** `strict_types=1` en todos los archivos, VOs/DTOs inmutables con `readonly`, firmas tipadas, sin código de depuración, secrets hardcodeados ni SQL por concatenación.
- **Abilities API correcta (88).** Tres abilities con annotations coherentes con su comportamiento real (ninguna `readonly-pero-escribe`); categoría en `wp_abilities_api_categories_init`, `show_in_rest=true`, `permission_callback` con `current_user_can` real, y camino `openseo_no_connector` que devuelve `WP_Error` sin fallback silencioso.
- **Motor de redirects bien estructurado (78).** Unidades puras bien testeadas, exacto-gana-sobre-regex, sustitución `$1`, deferimiento del contador a `shutdown`, prioridad 5 antes de `redirect_canonical`, monitor 404@99 con upsert agregado por `url_hash` y poda por retención en UTC; delimitador de regex controlado por el plugin y patrones acotados en longitud (superficie ReDoS limitada).
- **Suite de tests bien estructurada (72).** Separación limpia unit/integración, Brain Monkey correcto, patrón AAA y nombres descriptivos. Las unidades puras de alto riesgo y la capa de BD (Repository, LogRepository, idempotencia de Schema) tienen cobertura conductual real.
- **Cumplimiento WordPress.org y GPL en buena forma (82).** Cabeceras de licencia correctas, naming/trademark sin problemas, sin código ofuscado ni HTTP externo no declarado, sin upsell/trialware, y `uninstall.php` (camino normal) borra ambas tablas y todas las opciones. Los pendientes son de empaquetado.
- **Los 6 quality gates en verde**, sin deuda de lint, tipos o tests rotos.

---

*Generado por auditoría multi-agente (15 sub-agentes; arquitectura, seguridad ×3, PHP, Abilities, rendimiento, redirects, WordPress.org, tests + verificación adversarial). Read-only: ningún archivo del plugin fue modificado.*
