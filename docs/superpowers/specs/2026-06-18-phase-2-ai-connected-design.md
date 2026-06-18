# OpenSEO — Fase 2: IA conectada (solo texto)

**Fecha:** 2026-06-18
**Estado:** Aprobado
**Documento maestro:** [`2026-06-18-openseo-design.md`](./2026-06-18-openseo-design.md) (§7, Fase 2)
**Alcance de este documento:** spec de la Fase 2. El plan de implementación detallado se redacta
aparte con la skill `writing-plans`.

---

## 1. Objetivo y decisiones fijadas

Conectar las *abilities* de OpenSEO al **AI Client nativo de WordPress 7.0**, de modo que el panel
del editor pueda **proponer** meta description y título SEO generados por IA, reutilizando el
proveedor y la clave que el usuario ya configuró en **Settings → Connectors**. La IA **propone, no
escribe**: cada generación rellena el campo para que el usuario lo revise y guarde.

| Decisión | Elección | Razón |
|----------|----------|-------|
| **Alcance** | Solo **texto**: meta description + título SEO. | Comparten entrada (post→texto), superficie (panel General) e infraestructura. |
| **Alt-text** | **Diferido** a una sub-fase posterior. | Es multimodal (visión) y vive en otra superficie (biblioteca de medios). |
| **Invocación** | El editor llama la **misma ability** vía `executeAbility` (`@wordpress/abilities`). | Una sola lógica, dos consumidores (humano + agente/MCP). Sin endpoint propio. |
| **Sin conector** | `WP_Error('openseo_no_connector')` claro. **Sin fallback determinista.** | Un extracto truncado disfrazado de IA es un fallo silencioso. |
| **Selector de modelo** | **No** en UI. Se usa el modelo por defecto del conector. | `using_model_preference()` exige IDs por proveedor; frágil de exponer. |
| **Anotación abilities** | `readonly: false` (→ POST). | No mutan datos de WP, pero **no son idempotentes** (consumen créditos): no son un GET seguro/cacheable. |

---

## 2. Punto de partida (lo que ya existe)

- `Ai\Abilities` — categoría `openseo` + ability `openseo/generate-meta-description` con un
  **fallback determinista** (`wp_trim_words`) y un `TODO` explícito de enrutar al AI Client.
  `permission_callback` ya exige `edit_post` (`can_edit_post`).
- `Admin\Editor\EditorPanel` — solo encola el bundle; **no localiza datos**.
- `assets/src/editor/index.js` — panel React con pestañas General/Social/Advanced, lee/escribe
  postmeta con `useEntityProp`. **Sin botones de IA.**
- `Settings\Options` — incluye ya `ai_model` (string, default `''`).
- `stubs/abilities-api.php` — patrón a replicar para los stubs del AI Client.

> El AI Client **no** está declarado como dependencia ni instalado en `.wp-env.json`: es nativo de
> WP 7.0. En CI no habrá proveedor/clave, lo que hace del camino `openseo_no_connector` el estado
> realista a testear (ver §8).

---

## 3. API de WordPress 7.0 que usamos

**AI Client** (núcleo, sin proveedor propio):

```php
$text = wp_ai_client_prompt( $user_prompt )
    ->using_system_instruction( $system )
    ->using_max_tokens( $max )
    ->as_json_response( $output_schema )  // fuerza la forma estructurada
    ->generate_text();                    // string | WP_Error

if ( is_wp_error( $text ) ) { /* propagar */ }
```

- **Detección de conector sin llamar a la API:** `$builder->is_supported_for_text_generation()` → `bool`.
- **Override de modelo opcional:** `->using_model_preference( $id, … )` solo si `ai_model` está definido.
- **Control de acceso adicional:** filtro `wp_ai_client_prevent_prompt`.

**Connectors API** (para el estado en Settings):

```php
$connectors = wp_get_connectors();            // [ id => [ name, description, type, authentication, … ] ]
$c          = wp_get_connector( 'anthropic' ); // array | null
$ok         = wp_is_connector_registered( 'openai' );
```

**Abilities client-side** (editor):

```js
import { executeAbility } from '@wordpress/abilities';
const result = await executeAbility( 'openseo/generate-meta-description', { post_id } );
```

> `executeAbility` resuelve método/endpoint REST (`…/wp-abilities/v1/openseo/<ability>/run`) y
> autenticación. No registramos rutas REST propias.

---

## 4. Arquitectura — módulos

### 4.1 `src/Ai/Prompts.php` (nuevo, puro)

Construcción de prompts, **sin dependencias de WP en su lógica de plantilla** (recibe los datos ya
extraídos), 100% testeable en unit como `Resolver`/`Variables`:

- `system_meta_description(): string` y `system_title(): string` — instrucción de sistema (rol SEO +
  restricciones: longitud objetivo, idioma del sitio, texto plano sin comillas).
- `user_for_post( string $title, string $content ): string` — prompt de usuario a partir de título
  y contenido ya saneado/recortado.
- Constantes de longitud objetivo (description ≈ 155, title ≈ 60) como `UPPER_SNAKE_CASE`.

La extracción de datos del `WP_Post` (que sí toca WP) vive en la ability, no en `Prompts`.

### 4.2 `src/Ai/Abilities.php` (ampliado)

- **`generate-meta-description`** — se elimina el fallback `wp_trim_words`; `execute_callback`
  pasa a llamar al AI Client (ver §5). Se añade `readonly => false`.
- **`generate-title`** (nuevo) — `input { post_id }` → `output { title: string }`. Misma forma,
  mismo `permission_callback`, su propio prompt/longitud.

### 4.3 `src/Admin/Editor/EditorPanel.php` (ampliado)

Tras encolar el bundle, **localiza datos** con `wp_add_inline_script` (objeto global `openseoEditor`):

```php
wp_add_inline_script( self::HANDLE,
  'window.openseoEditor = ' . wp_json_encode( array(
    'aiAvailable'   => $this->ai_available(),       // is_supported_for_text_generation()
    'connectorsUrl' => admin_url( 'options-general.php?page=connectors' ),
  ) ) . ';',
  'before'
);
```

`ai_available()` construye un builder de prueba y devuelve `is_supported_for_text_generation()`,
guardado con `function_exists( 'wp_ai_client_prompt' )` (degrada a `false` pre-7.0).

### 4.4 `assets/src/editor/` (ampliado)

- Nuevo componente `GenerateButton` (botón + spinner + `Notice` de error) reutilizable.
- `GeneralTab` añade un `GenerateButton` bajo *Title* y otro bajo *Description*.
- Helper `runAbility( name, postId )` que envuelve `executeAbility` y normaliza errores.
- Lee `window.openseoEditor.aiAvailable` para el estado deshabilitado + tooltip/enlace.

### 4.5 `src/Admin/SettingsPage.php` + `Settings\Options` (ampliado)

- Sección/pestaña **"IA"**: lista el estado de conectores (`wp_get_connectors()`) y enlaza a
  *Settings → Connectors*. Solo lectura informativa.
- `ai_model` permanece como override avanzado opcional (ya se sanea en `Options::sanitize`).

### 4.6 `stubs/ai-client.php` (nuevo, solo PHPStan)

Firmas de `wp_ai_client_prompt()`, la clase `WP_AI_Client_Prompt_Builder` (métodos encadenables que
usamos) y `wp_get_connector(s)` / `wp_is_connector_registered()`. No se carga en runtime. Se añade a
`phpstan.neon.dist` igual que el stub de abilities.

---

## 5. Flujo del `execute_callback`

```
1. $post_id = absint( $input['post_id'] );  $post = get_post( $post_id );
   if ( ! $post instanceof WP_Post ) → WP_Error('openseo_invalid_post').

2. $content = wp_strip_all_tags( $post->post_content );  // recorte razonable de longitud
   $builder = wp_ai_client_prompt( Prompts::user_for_post( $post->post_title, $content ) )
       ->using_system_instruction( Prompts::system_meta_description() )
       ->using_max_tokens( … )
       ->as_json_response( $output_schema );
   if ( $ai_model ) $builder->using_model_preference( $ai_model );

3. if ( ! $builder->is_supported_for_text_generation() )
       → WP_Error('openseo_no_connector', <mensaje accionable>).

4. $text = $builder->generate_text();
   if ( is_wp_error( $text ) ) → propagar.

5. return array( 'meta_description' => sanitize_text_field( $text ) );
```

`generate-title` es idéntico cambiando prompt/longitud y la clave de salida (`title`).

---

## 6. UX del editor

- **Botón "Generar con IA"** bajo *Title* y *Description* (pestaña General).
- **Click → spinner →** `runAbility('openseo/generate-…', postId)` → **rellena el campo** con el
  `setMeta` existente. Nunca guarda solo; el guardado real sigue siendo el postmeta de la Fase 1.
- **`aiAvailable === false`** → botones deshabilitados con tooltip + `Notice` enlazando a Conectores.
- **Error** (incl. `openseo_no_connector` devuelto en runtime) → `Notice` inline con el mensaje y, si
  aplica, enlace a *Settings → Connectors*.

---

## 7. Errores y seguridad

- **Autorización:** `permission_callback` (`edit_post`) en cada ability + filtro
  `wp_ai_client_prevent_prompt` como segunda capa.
- **Salida del modelo = no confiable:** `sanitize_text_field` antes de devolverla; al guardar pasa
  además por el `sanitize_callback` del postmeta (Fase 1).
- **Sin fallback silencioso:** ausencia de conector ⇒ `WP_Error` explícito, no texto degradado.
- **Degradación pre-7.0:** todo guardado con `function_exists`; sin AI Client, las abilities
  devuelven `openseo_no_connector` y los botones aparecen deshabilitados.
- **Sin secretos en el repo:** las claves viven en el conector (env/constante/BD), nunca en OpenSEO.

---

## 8. Testing

- **Unit (Brain Monkey):**
  - `Ai\Prompts` — cada prompt y casos borde (contenido vacío, HTML, longitud).
  - Abilities — mockeando `wp_ai_client_prompt` (builder encadenable) los tres caminos: éxito,
    `openseo_no_connector` (`is_supported_*` → false), `openseo_invalid_post`.
- **Integración (wp-env, sin clave en CI):**
  - Las abilities se registran y exponen en REST.
  - `execute_callback` con conector ausente ⇒ `openseo_no_connector` (camino determinista real).
- **JS:** estados del `GenerateButton` (idle/loading/disabled-sin-conector) y relleno del campo.
- **Manual (documentado, no CI):** instalar un *AI Provider plugin* (Anthropic/OpenAI/Google) +
  clave por env/constante para probar contra un proveedor real.
- **Cobertura objetivo:** ≥ 80% en la lógica nueva.

---

## 9. Fuera de alcance (diferido)

- **Alt-text** de imágenes (multimodal + biblioteca de medios) → sub-fase posterior.
- Selector de modelo en UI, *streaming*, historial de generaciones, generación en lote.

---

## 10. Criterios de éxito

1. `openseo/generate-meta-description` y `openseo/generate-title` llaman al AI Client real y
   devuelven `{ … : string }` o un `WP_Error` claro; sin fallback determinista.
2. Sin conector ⇒ `WP_Error('openseo_no_connector')` (servidor) y botones deshabilitados con enlace
   a Conectores (editor).
3. Los botones del panel invocan las abilities vía `executeAbility`, muestran *loading* y **rellenan**
   el campo en éxito (el usuario revisa y guarda).
4. Settings muestra el estado de conectores y enlaza a *Settings → Connectors*; sin selector de modelo.
5. Gates en verde: PHPCS, PHPStan (con `stubs/ai-client.php`), PHPUnit unit + el test de integración
   `openseo_no_connector`, y el test JS del botón.

---

## Fuentes

- [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- [Client-Side Abilities API in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)
- [Abilities API — REST API endpoints](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/)
