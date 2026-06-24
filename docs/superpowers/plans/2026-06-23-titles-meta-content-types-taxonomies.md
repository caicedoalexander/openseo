# Titles & Meta — Tipos de contenido y Taxonomías (Adjuntos + defaults por tipo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Llevar las pestañas de Tipos de contenido y Taxonomías de Titles & Meta a paridad con Rank Math: añadir Adjuntos (con redirección a la entrada padre), un tipo de schema por defecto por tipo de contenido, y una imagen social/OG por defecto por tipo.

**Architecture:** Se extiende el mapa anidado `post_types[slug]` de `openseo_settings` con dos campos opcionales (`schema_type`, `og_image`), se añaden dos claves top-level para el redirect de adjuntos, se insertan ramas nuevas en dos cascadas existentes (`Schema\Pieces\Article` para el `@type`, `Meta\Resolver::social_image()` para la imagen) y se añade un módulo frontend `Hookable` (`Frontend\AttachmentRedirect`). La UI reutiliza el `TypePanel` de `views/Titles.js`, enriquecido, más un `AttachmentsPanel` dedicado. Las taxonomías no cambian.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey (unit), `@wordpress/scripts` (React/JS admin + editor), `@wordpress/components`, `@wordpress/i18n`.

## Global Constraints

- **Versiones objetivo:** WordPress 7.0+ · PHP 8.1+. `declare( strict_types=1 );` en todo archivo PHP nuevo.
- **Prefijos globales** (enforced por PHPCS): `openseo` / `OpenSEO` / `OPENSEO`; text domain `openseo`.
- **Seguridad:** sanitizar en entrada, escapar en salida; nonce + capability los aporta `Rest\SettingsController` (`manage_options`); nunca procesar `$_POST`/`$_GET` completos.
- **Opciones:** todo bajo la única key `openseo_settings` (`Options::OPTION_KEY`). `sanitize()` parte de `all()` (merge sobre lo guardado), no de defaults, para no resetear tabs no enviados.
- **Schema whitelist única:** los valores válidos de `schema_type` (`'', Article, BlogPosting, NewsArticle, WebPage, none`) son `PostMeta::SCHEMA_TYPES` — no duplicar la lista.
- **Robots por-tipo:** tri-estado `'on'/'off'` (no `'1'`); "vacío" se evalúa con `empty($robots)`.
- **Attachment redirect default ON** (decisión congelada): `attachment_redirect => '1'`.
- **Gates verdes antes de cada commit donde apliquen:** `composer lint`, `composer analyze` (PHPStan 6, `--memory-limit=1G`), `composer test:unit`; en tareas JS además `npm run lint:js` y `npm run test:js`. Build final con `npm run build`.
- **i18n:** todas las cadenas nuevas con `__()`/`sprintf` y dominio `openseo`.
- **Spec de referencia:** `docs/superpowers/specs/2026-06-23-titles-meta-content-types-taxonomies-design.md`.

---

## File Structure

**PHP — modificar:**
- `src/Settings/Options.php` — defaults (claves de adjuntos) + `sanitize()` (campos ricos en el map, checkbox/url de adjuntos) + `sanitize_template_map()` (param `$allow_rich`).
- `src/Settings/ContentTypes.php` — dejar de excluir `attachment`.
- `src/Meta/TemplateDefaults.php` — `schema_type( $post_type )` (literal puro).
- `src/Meta/TypeTemplates.php` — `schema_type_for()` + `og_image_for()`.
- `src/Meta/Resolver.php` — capa `og_image` por tipo en `social_image()`.
- `src/Schema/Pieces/Article.php` — cascada `@type` override→tipo→default + tercer parámetro `TypeTemplates`.
- `src/Plugin.php` — pasar `TypeTemplates` a `Article`; registrar `AttachmentRedirect`.
- `src/Admin/Assets.php` — `defaultSchemaType` por tipo en el bootstrap.
- `src/Admin/Editor/EditorPanel.php` — `schemaTypeDefault` en `window.openseoEditor`.

**PHP — crear:**
- `src/Frontend/AttachmentRedirect.php` — módulo `Hookable` (template_redirect@6).

**JS — modificar:**
- `assets/src/admin/views/Titles.js` — `TypePanel` enriquecido + `AttachmentsPanel` + ruteo.
- `assets/src/editor/index.js` — `SCHEMA_OPTIONS` dinámico (label "Automatic (X)").

**Tests — crear/modificar:**
- `tests/Unit/OptionsTest.php` (mod), `tests/Unit/Settings/ContentTypesTest.php` (mod), `tests/Unit/Meta/TemplateDefaultsTest.php` (mod), `tests/Unit/Meta/TypeTemplatesTest.php` (mod), `tests/Unit/Meta/ResolverTest.php` (mod), `tests/Unit/Schema/Pieces/ContentPiecesTest.php` (mod), `tests/Unit/Frontend/AttachmentRedirectTest.php` (crear).

---

## Task 1: Adjuntos elegible + claves de redirect en defaults

**Files:**
- Modify: `src/Settings/ContentTypes.php:20` (y el docblock de clase, líneas 12-17)
- Modify: `src/Settings/Options.php` (array `defaults()`, punto de inserción ~líneas 62-64, tras `'notfound_retention_days'`)
- Test: `tests/Unit/Settings/ContentTypesTest.php` (actualizar), `tests/Unit/OptionsTest.php` (añadir)

**Interfaces:**
- Produces: `attachment` aparece en `ContentTypes::post_types()`/`post_type_slugs()`; `Options::defaults()` incluye `'attachment_redirect' => '1'` y `'attachment_redirect_orphan' => ''`.

- [ ] **Step 1: Actualizar el test de ContentTypes (de excluir a incluir attachment)**

En `tests/Unit/Settings/ContentTypesTest.php` reemplaza el método `test_post_types_exclude_attachment()` por:

```php
	public function test_post_types_include_attachment(): void {
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => $this->fake_type( 'post', 'Posts' ),
				'page'       => $this->fake_type( 'page', 'Pages' ),
				'attachment' => $this->fake_type( 'attachment', 'Media' ),
			)
		);

		$slugs = ( new ContentTypes() )->post_type_slugs();

		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
		$this->assertContains( 'attachment', $slugs );
	}
```

- [ ] **Step 2: Añadir el test de defaults de adjuntos en OptionsTest**

En `tests/Unit/OptionsTest.php` añade:

```php
	public function test_defaults_include_attachment_redirect_keys(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$all = ( new Options() )->all();

		$this->assertSame( '1', $all['attachment_redirect'] );
		$this->assertSame( '', $all['attachment_redirect_orphan'] );
	}
```

- [ ] **Step 3: Correr ambos tests para verque fallan**

Run: `vendor/bin/phpunit tests/Unit/Settings/ContentTypesTest.php tests/Unit/OptionsTest.php --filter "attachment"`
Expected: FAIL — `test_post_types_include_attachment` falla en `assertContains('attachment', ...)`; `test_defaults_include_attachment_redirect_keys` falla con "Undefined array key 'attachment_redirect'".

- [ ] **Step 4: Dejar de excluir `attachment` en ContentTypes**

En `src/Settings/ContentTypes.php`, cambia la constante (línea 20):

```php
	private const EXCLUDED_POST_TYPES = array();
```

Y **reemplaza el docblock completo de la clase (líneas 12-17)** — incluida la coletilla "minus attachment (a media item needs no SEO title template)", que no debe quedar colgada — por:

```php
/**
 * Single source of truth for which post types and taxonomies get SEO templates.
 * Used by Options::sanitize() (whitelist) and Admin\Assets (bootstrap) so the
 * editable set and the validated set never diverge. Criterion: public post
 * types and taxonomies (attachments included so their pages can be redirected
 * or templated from Titles & Meta).
 */
```

- [ ] **Step 5: Añadir las claves de adjuntos a `Options::defaults()`**

En `src/Settings/Options.php`, dentro del array que devuelve `defaults()`, junto al bloque de redirects/404 (tras `'notfound_retention_days' => '30',`), añade:

```php
			'attachment_redirect'          => '1',
			'attachment_redirect_orphan'   => '',
```

- [ ] **Step 6: Correr los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/Unit/Settings/ContentTypesTest.php tests/Unit/OptionsTest.php`
Expected: PASS (toda la suite de ambos archivos en verde).

- [ ] **Step 7: Commit**

```bash
git add src/Settings/ContentTypes.php src/Settings/Options.php tests/Unit/Settings/ContentTypesTest.php tests/Unit/OptionsTest.php
git commit -m "feat(titles): attachments eligible as content type + redirect defaults"
```

---

## Task 2: `TemplateDefaults::schema_type()` — default automático por tipo

**Files:**
- Modify: `src/Meta/TemplateDefaults.php` (añadir método)
- Test: `tests/Unit/Meta/TemplateDefaultsTest.php`

**Interfaces:**
- Produces: `TemplateDefaults::schema_type( string $post_type ): string` → `'Article'` para `post`, `'WebPage'` para `page`, `'none'` para el resto. Preserva el comportamiento actual (hoy solo `post` emite Article).

- [ ] **Step 1: Escribir el test que falla**

En `tests/Unit/Meta/TemplateDefaultsTest.php` añade:

```php
	public function test_schema_type_defaults_per_post_type(): void {
		$defaults = new TemplateDefaults();

		$this->assertSame( 'Article', $defaults->schema_type( 'post' ) );
		$this->assertSame( 'WebPage', $defaults->schema_type( 'page' ) );
		$this->assertSame( 'none', $defaults->schema_type( 'attachment' ) );
		$this->assertSame( 'none', $defaults->schema_type( 'product' ) );
	}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit tests/Unit/Meta/TemplateDefaultsTest.php --filter test_schema_type_defaults_per_post_type`
Expected: FAIL con "Call to undefined method OpenSEO\Meta\TemplateDefaults::schema_type()".

- [ ] **Step 3: Implementar el método**

En `src/Meta/TemplateDefaults.php`, antes del cierre de la clase, añade:

```php
	/**
	 * Default schema @type for a content type when no per-type or per-entry
	 * value is set. Mirrors the historical behavior (Article only for posts).
	 *
	 * @param string $post_type Post type slug.
	 */
	public function schema_type( string $post_type ): string {
		$map = array(
			'post' => 'Article',
			'page' => 'WebPage',
		);

		return $map[ $post_type ] ?? 'none';
	}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/Unit/Meta/TemplateDefaultsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Meta/TemplateDefaults.php tests/Unit/Meta/TemplateDefaultsTest.php
git commit -m "feat(schema): default schema type per content type"
```

---

## Task 3: `TypeTemplates` — `schema_type_for()` + `og_image_for()`

**Files:**
- Modify: `src/Meta/TypeTemplates.php` (dos métodos; reutiliza el `stored()` privado)
- Test: `tests/Unit/Meta/TypeTemplatesTest.php`

**Interfaces:**
- Consumes: `TemplateDefaults::schema_type()` (Task 2); `stored( $post_type, $field )` (privado existente).
- Produces: `TypeTemplates::schema_type_for( string $post_type ): string` (guardado no-vacío, o el default automático — nunca `''`); `TypeTemplates::og_image_for( string $post_type ): string` (guardado o `''`).

- [ ] **Step 1: Escribir los tests que fallan**

En `tests/Unit/Meta/TypeTemplatesTest.php` añade:

```php
	public function test_schema_type_for_uses_stored_value(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'schema_type' => 'NewsArticle' ) ) )
		);

		$this->assertSame( 'NewsArticle', $this->type_templates()->schema_type_for( 'post' ) );
	}

	public function test_schema_type_for_falls_back_to_automatic_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'Article', $this->type_templates()->schema_type_for( 'post' ) );
		$this->assertSame( 'WebPage', $this->type_templates()->schema_type_for( 'page' ) );
		$this->assertSame( 'none', $this->type_templates()->schema_type_for( 'attachment' ) );
	}

	public function test_og_image_for_returns_stored_or_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'og_image' => 'https://x.test/a.jpg' ) ) )
		);

		$this->assertSame( 'https://x.test/a.jpg', $this->type_templates()->og_image_for( 'post' ) );
		$this->assertSame( '', $this->type_templates()->og_image_for( 'page' ) );
	}
```

- [ ] **Step 2: Correr los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/Unit/Meta/TypeTemplatesTest.php --filter "schema_type_for|og_image_for"`
Expected: FAIL con "Call to undefined method ...schema_type_for()".

- [ ] **Step 3: Implementar los métodos**

En `src/Meta/TypeTemplates.php`, antes del método privado `stored()`, añade:

```php
	/**
	 * Effective schema @type for a post type: the stored per-type value, or the
	 * automatic default. Never returns '' (callers branch on the concrete type).
	 *
	 * @param string $post_type Post type slug.
	 */
	public function schema_type_for( string $post_type ): string {
		$stored = $this->stored( $post_type, 'schema_type' );

		return '' !== $stored ? $stored : $this->defaults->schema_type( $post_type );
	}

	/**
	 * Stored per-type default social image, or '' when none (falls through to
	 * the global default in the Resolver).
	 *
	 * @param string $post_type Post type slug.
	 */
	public function og_image_for( string $post_type ): string {
		return $this->stored( $post_type, 'og_image' );
	}
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/Unit/Meta/TypeTemplatesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Meta/TypeTemplates.php tests/Unit/Meta/TypeTemplatesTest.php
git commit -m "feat(meta): effective per-type schema type and og image accessors"
```

---

## Task 4: Cascada `@type` en `Article` + wiring en `Plugin`

**Files:**
- Modify: `src/Schema/Pieces/Article.php` (constructor + `is_needed()` + `type()` → `effective_schema_type()`)
- Modify: `src/Plugin.php:146` (pasar `$type_templates` a `Article`)
- Test: `tests/Unit/Schema/Pieces/ContentPiecesTest.php`

**Interfaces:**
- Consumes: `TypeTemplates::schema_type_for()` (Task 3); `TypeTemplates` ya existe como `$type_templates` en `Plugin::modules()` (Plugin.php:131).
- Produces: `Article::__construct( Resolver $resolver, Options $options, TypeTemplates $type_templates )`.

- [ ] **Step 1: Actualizar la construcción de Article en ContentPiecesTest y añadir tests de cascada**

En `tests/Unit/Schema/Pieces/ContentPiecesTest.php`:

a) Añade un helper junto a `resolver()`:

```php
	private function article(): Article {
		$options  = new Options();
		$defaults = new TemplateDefaults();
		return new Article( $this->resolver(), $options, new TypeTemplates( $options, $defaults ) );
	}
```

b) Reemplaza **todas** las apariciones de `new Article( $this->resolver(), new Options() )` por `$this->article()` en los métodos: `test_article_needed_for_default_post`, `test_article_honors_explicit_type_override`, `test_article_suppressed_for_none_and_webpage_types`, `test_article_suppressed_for_pages_by_default`, `test_article_not_needed_when_not_singular`. **Ojo:** `test_article_suppressed_for_none_and_webpage_types` tiene **dos** instanciaciones (líneas 124 y 129) — reemplaza ambas, o quedará un `ArgumentCountError` y la suite en rojo en este commit.

c) Añade un test del nivel de tipo:

```php
	public function test_article_type_from_per_type_default(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'review' );
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'review' => array( 'schema_type' => 'NewsArticle' ) ) )
		);

		$piece = $this->article();

		$this->assertTrue( $piece->is_needed() );
		$this->assertSame( 'NewsArticle', $piece->data()['@type'] );
	}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit tests/Unit/Schema/Pieces/ContentPiecesTest.php`
Expected: FAIL — `Article::__construct()` espera 2 args pero recibe 3 (ArgumentCountError), o el nuevo test falla.

- [ ] **Step 3: Refactorizar `Article` para la cascada override→tipo→default**

En `src/Schema/Pieces/Article.php`:

a) Añade el import y el tercer parámetro al constructor:

```php
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Schema\Ids;
use OpenSEO\Schema\Piece;
use OpenSEO\Settings\Options;
```

```php
	public function __construct(
		private readonly Resolver $resolver,
		private readonly Options $options,
		private readonly TypeTemplates $type_templates
	) {}
```

b) Reemplaza `is_needed()` por:

```php
	public function is_needed(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		return in_array( $this->effective_schema_type(), self::ARTICLE_TYPES, true );
	}
```

c) En `data()`, cambia la línea del `@type`:

```php
			'@type'            => $this->type(),
```

d) Reemplaza el método privado `type()` y añade `effective_schema_type()`:

```php
	/**
	 * Resolve the effective schema type: per-entry override → per-type default →
	 * automatic default. Shared by is_needed() and type() to avoid drift.
	 */
	private function effective_schema_type(): string {
		$id       = get_queried_object_id();
		$override = (string) get_post_meta( $id, '_openseo_schema_type', true );

		if ( '' !== $override ) {
			return $override;
		}

		return $this->type_templates->schema_type_for( (string) get_post_type( $id ) );
	}

	/**
	 * The Article @type to emit (only reached when is_needed() is true).
	 */
	private function type(): string {
		$effective = $this->effective_schema_type();

		return in_array( $effective, self::ARTICLE_TYPES, true ) ? $effective : 'Article';
	}
```

- [ ] **Step 4: Pasar `TypeTemplates` a `Article` en `Plugin`**

En `src/Plugin.php:146`, cambia:

```php
				new Article( $resolver, $options, $type_templates ),
```

- [ ] **Step 5: Correr tests + análisis**

Run: `vendor/bin/phpunit tests/Unit/Schema/Pieces/ContentPiecesTest.php`
Expected: PASS.
Run: `composer analyze`
Expected: PHPStan sin errores nuevos (verifica la firma de `Article` en `Plugin`).

- [ ] **Step 6: Commit**

```bash
git add src/Schema/Pieces/Article.php src/Plugin.php tests/Unit/Schema/Pieces/ContentPiecesTest.php
git commit -m "feat(schema): per-type schema default feeds Article @type cascade"
```

---

## Task 5: Capa `og_image` por tipo en `Resolver::social_image()`

**Files:**
- Modify: `src/Meta/Resolver.php` (`social_image()`, líneas 399-419)
- Test: `tests/Unit/Meta/ResolverTest.php`

**Interfaces:**
- Consumes: `TypeTemplates::og_image_for()` (Task 3); `$this->type_templates` ya inyectado (Resolver.php:42).

- [ ] **Step 1: Escribir el test que falla**

En `tests/Unit/Meta/ResolverTest.php` añade (ajusta el helper de construcción del Resolver al que ya use el archivo; el patrón estándar es `new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) )`):

```php
	public function test_social_image_uses_per_type_default_before_global(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 9 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );          // sin og_image de entrada
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' ); // sin destacada
		Functions\when( 'get_option' )->justReturn(
			array(
				'post_types'       => array( 'post' => array( 'og_image' => 'https://x.test/type.jpg' ) ),
				'og_default_image' => 'https://x.test/global.jpg',
			)
		);

		$options  = new Options();
		$defaults = new TemplateDefaults();
		$resolver = new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) );

		$this->assertSame( 'https://x.test/type.jpg', $resolver->social_image() );
	}

	public function test_social_image_featured_wins_over_per_type_default(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 9 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://x.test/featured.jpg' );
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'og_image' => 'https://x.test/type.jpg' ) ) )
		);

		$options  = new Options();
		$defaults = new TemplateDefaults();
		$resolver = new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) );

		$this->assertSame( 'https://x.test/featured.jpg', $resolver->social_image() );
	}
```

- [ ] **Step 2: Correr los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/Unit/Meta/ResolverTest.php --filter social_image`
Expected: FAIL — `test_social_image_uses_per_type_default_before_global` devuelve `https://x.test/global.jpg` en vez del de tipo.

- [ ] **Step 3: Insertar la capa de tipo en `social_image()`**

En `src/Meta/Resolver.php`, dentro de `social_image()`, reemplaza el bloque `is_singular()` + return final (líneas 411-418) por:

```php
		if ( is_singular() ) {
			$featured = (string) get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
			if ( '' !== $featured ) {
				return $featured;
			}

			$type_image = $this->type_templates->og_image_for( (string) get_post_type( get_queried_object_id() ) );
			if ( '' !== $type_image ) {
				return $type_image;
			}
		}

		return (string) $this->options->get( 'og_default_image' );
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/Unit/Meta/ResolverTest.php`
Expected: PASS (incluidos los tests existentes de social/portada).

- [ ] **Step 5: Commit**

```bash
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php
git commit -m "feat(meta): per-type default social image layer in resolver"
```

---

## Task 6: Módulo `Frontend\AttachmentRedirect`

**Files:**
- Create: `src/Frontend/AttachmentRedirect.php`
- Modify: `src/Plugin.php` (import + registro en módulos always-on, ~línea 157)
- Test: `tests/Unit/Frontend/AttachmentRedirectTest.php`

**Interfaces:**
- Consumes: `Options::get('attachment_redirect')`, `Options::get('attachment_redirect_orphan')`.
- Produces: `AttachmentRedirect::should_redirect(): bool` y `AttachmentRedirect::target(): string` (puros/testeables, sin `exit`); `maybe_redirect()` (efecto: `wp_safe_redirect` + `exit`).

- [ ] **Step 1: Escribir el test que falla**

Crea `tests/Unit/Frontend/AttachmentRedirectTest.php`:

```php
<?php
/**
 * Unit tests for the attachment → parent redirect decision.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\AttachmentRedirect;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class AttachmentRedirectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_attachment' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 10 );
		Functions\when( 'home_url' )->justReturn( 'https://x.test/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function module( array $stored ): AttachmentRedirect {
		Functions\when( 'get_option' )->justReturn( $stored );
		return new AttachmentRedirect( new Options() );
	}

	public function test_should_redirect_when_attachment_and_enabled(): void {
		Functions\when( 'is_attachment' )->justReturn( true );

		$this->assertTrue( $this->module( array( 'attachment_redirect' => '1' ) )->should_redirect() );
	}

	public function test_no_redirect_when_disabled(): void {
		Functions\when( 'is_attachment' )->justReturn( true );

		$this->assertFalse( $this->module( array( 'attachment_redirect' => '' ) )->should_redirect() );
	}

	public function test_no_redirect_when_not_attachment(): void {
		$this->assertFalse( $this->module( array( 'attachment_redirect' => '1' ) )->should_redirect() );
	}

	public function test_target_is_parent_permalink(): void {
		Functions\when( 'get_post_field' )->justReturn( 42 ); // post_parent = 42
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 42 === $id ? 'https://x.test/parent/' : 'https://x.test/attachment/'
		);

		$this->assertSame( 'https://x.test/parent/', $this->module( array() )->target() );
	}

	public function test_target_falls_back_to_orphan_url(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 ); // sin padre
		Functions\when( 'get_permalink' )->justReturn( 'https://x.test/attachment/' );

		$this->assertSame(
			'https://x.test/orphan/',
			$this->module( array( 'attachment_redirect_orphan' => 'https://x.test/orphan/' ) )->target()
		);
	}

	public function test_target_falls_back_to_home_for_orphan_without_url(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( 'https://x.test/attachment/' );

		$this->assertSame( 'https://x.test/', $this->module( array() )->target() );
	}

	public function test_target_anti_identity_falls_back_to_home(): void {
		Functions\when( 'get_post_field' )->justReturn( 10 ); // padre = el propio adjunto
		Functions\when( 'get_permalink' )->justReturn( 'https://x.test/attachment/' );

		$this->assertSame( 'https://x.test/', $this->module( array() )->target() );
	}
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `vendor/bin/phpunit tests/Unit/Frontend/AttachmentRedirectTest.php`
Expected: FAIL con "Class OpenSEO\Frontend\AttachmentRedirect not found".

- [ ] **Step 3: Crear el módulo**

Crea `src/Frontend/AttachmentRedirect.php`:

```php
<?php
/**
 * Redirects attachment pages to their parent post.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * When attachment redirection is enabled in Titles & Meta, send attachment
 * page requests to their parent post (301), or to the configured orphan URL /
 * homepage when there is no usable parent. Runs after the explicit redirect
 * engine (Dispatcher@5) so manual rules win, and before redirect_canonical@10.
 */
final class AttachmentRedirect implements Hookable {

	/**
	 * Initializes the module with settings.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Hook the redirect on template_redirect at priority 6.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 6 );
	}

	/**
	 * Perform the redirect when the current request is a redirectable attachment.
	 */
	public function maybe_redirect(): void {
		if ( ! $this->should_redirect() ) {
			return;
		}

		wp_safe_redirect( $this->target(), 301 );
		exit;
	}

	/**
	 * Whether the current request is an attachment page with redirection on.
	 * Pure decision (no side effects) so it is unit-testable without exit().
	 */
	public function should_redirect(): bool {
		return is_attachment() && '1' === (string) $this->options->get( 'attachment_redirect' );
	}

	/**
	 * Resolve the redirect destination: parent permalink, else the configured
	 * orphan URL, else the homepage. Guards against a parent that resolves back
	 * to the attachment's own URL (corrupt data) to avoid a redirect loop.
	 */
	public function target(): string {
		$id     = get_queried_object_id();
		$parent = (int) get_post_field( 'post_parent', $id );
		$url    = $parent > 0 ? (string) get_permalink( $parent ) : '';

		if ( '' === $url || $url === (string) get_permalink( $id ) ) {
			$orphan = (string) $this->options->get( 'attachment_redirect_orphan' );
			$url    = '' !== $orphan ? $orphan : home_url( '/' );
		}

		return $url;
	}
}
```

- [ ] **Step 4: Registrar el módulo en `Plugin`**

En `src/Plugin.php`, añade el import junto a los demás `Frontend\` (tras la línea 29 `use OpenSEO\Frontend\ArchiveRedirect;`):

```php
use OpenSEO\Frontend\AttachmentRedirect;
```

Y en la lista de `$modules` always-on (junto a `new ArchiveRedirect( $options ),`, línea 157), añade:

```php
			new AttachmentRedirect( $options ),
```

- [ ] **Step 5: Correr test + análisis**

Run: `vendor/bin/phpunit tests/Unit/Frontend/AttachmentRedirectTest.php`
Expected: PASS.
Run: `composer analyze`
Expected: sin errores nuevos.

- [ ] **Step 6: Commit**

```bash
git add src/Frontend/AttachmentRedirect.php src/Plugin.php tests/Unit/Frontend/AttachmentRedirectTest.php
git commit -m "feat(frontend): redirect attachment pages to parent post"
```

---

## Task 7: Sanitize de campos ricos por tipo + claves de adjuntos

**Files:**
- Modify: `src/Settings/Options.php` (`sanitize()` checkbox/url loops; llamadas a `sanitize_template_map`; firma + cuerpo + docblock de `sanitize_template_map`)
- Test: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Consumes: `PostMeta::SCHEMA_TYPES` (whitelist de `schema_type`).
- Produces: `sanitize_template_map( mixed $input_map, array $current, array $allowed, bool $allow_rich = false )`; las entradas de `post_types` aceptan `schema_type`/`og_image`; `attachment_redirect` (checkbox) y `attachment_redirect_orphan` (URL) saneados.

- [ ] **Step 1: Escribir los tests que fallan**

En `tests/Unit/OptionsTest.php` añade:

```php
	public function test_sanitize_stores_per_type_schema_and_og_image(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_post_types' )->justReturn(
			array( 'post' => (object) array( 'name' => 'post', 'labels' => (object) array( 'name' => 'Posts' ) ) )
		);

		$clean = ( new Options() )->sanitize(
			array(
				'post_types' => array(
					'post' => array(
						'title'       => '',
						'description' => '',
						'schema_type' => 'NewsArticle',
						'og_image'    => 'https://x.test/a.jpg',
					),
				),
			)
		);

		$this->assertSame( 'NewsArticle', $clean['post_types']['post']['schema_type'] );
		$this->assertSame( 'https://x.test/a.jpg', $clean['post_types']['post']['og_image'] );
	}

	public function test_sanitize_rejects_invalid_schema_type(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_post_types' )->justReturn(
			array( 'post' => (object) array( 'name' => 'post', 'labels' => (object) array( 'name' => 'Posts' ) ) )
		);

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'schema_type' => 'Bogus', 'og_image' => 'https://x.test/a.jpg' ) ) )
		);

		$this->assertArrayNotHasKey( 'schema_type', $clean['post_types']['post'] );
		$this->assertSame( 'https://x.test/a.jpg', $clean['post_types']['post']['og_image'] );
	}

	public function test_sanitize_attachment_redirect_keys(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'attachment_redirect'        => '1',
				'attachment_redirect_orphan' => 'https://x.test/orphan/',
			)
		);

		$this->assertSame( '1', $clean['attachment_redirect'] );
		$this->assertSame( 'https://x.test/orphan/', $clean['attachment_redirect_orphan'] );
	}
```

> Nota: `schema_type`/`og_image` se incluyen en `$entry` **solo cuando no están vacíos** (mapa lean); un `schema_type` inválido (`'Bogus'`) se ignora y no se persiste.

- [ ] **Step 2: Correr los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/Unit/OptionsTest.php --filter "per_type_schema|invalid_schema|attachment_redirect_keys"`
Expected: FAIL — `schema_type`/`og_image` ausentes en el resultado; `attachment_redirect` no saneado.

- [ ] **Step 3: Añadir `attachment_redirect` al loop de checkboxes y `attachment_redirect_orphan` al de URLs**

En `src/Settings/Options.php`, en el loop de checkboxes (línea 157), añade `'attachment_redirect'` al array de claves:

```php
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled', 'capitalize_titles', 'home_robots_custom', 'author_robots_custom', 'author_archives', 'date_archives', 'noindex_search', 'noindex_paginated', 'noindex_paginated_singular', 'noindex_password_protected', 'attachment_redirect' ) as $key ) {
```

En el loop de URLs (línea 176), añade `'attachment_redirect_orphan'`:

```php
		foreach ( array( 'og_default_image', 'schema_logo', 'local_url', 'home_og_image', 'attachment_redirect_orphan' ) as $key ) {
```

- [ ] **Step 4: Pasar `$allow_rich` en las llamadas a `sanitize_template_map`**

En `src/Settings/Options.php`, en el bloque de líneas 220-238, añade el cuarto argumento `true` solo a la llamada de `post_types`:

```php
			if ( isset( $input['post_types'] ) ) {
				$clean['post_types'] = $this->sanitize_template_map(
					$input['post_types'],
					is_array( $clean['post_types'] ?? null ) ? $clean['post_types'] : array(),
					$content_types->post_type_slugs(),
					true
				);
			}
```

(La llamada de `taxonomies` se deja igual: `$allow_rich` toma su default `false`.)

- [ ] **Step 5: Extender `sanitize_template_map` con los campos ricos**

En `src/Settings/Options.php`, actualiza la firma y el docblock de `sanitize_template_map` (líneas 267-279) para añadir el parámetro y el nuevo array-shape:

```php
	/**
	 * Sanitize one nested template map (post_types or taxonomies) slug-by-slug.
	 *
	 * Conservation of unsent slugs comes from $current already holding the stored
	 * map (sanitize() starts from all()); this is NOT a PHP deep merge. Per slug:
	 * whitelist, merge per field, and unset when every field ends up empty. The
	 * rich fields (schema_type, og_image) are only processed for post_types
	 * ($allow_rich) and are omitted from the entry when empty (lean map).
	 *
	 * @param mixed                                                                                                          $input_map  Raw submitted map for the group.
	 * @param array<string, array{title:string,description:string,robots?:array<string,string>,schema_type?:string,og_image?:string}> $current    Stored map for this group.
	 * @param array<int, string>                                                                                             $allowed    Whitelisted slugs.
	 * @param bool                                                                                                           $allow_rich Whether to accept schema_type/og_image (post types only).
	 * @return array<string, array{title:string,description:string,robots?:array<string,string>,schema_type?:string,og_image?:string}>
	 */
	private function sanitize_template_map( mixed $input_map, array $current, array $allowed, bool $allow_rich = false ): array {
```

Dentro del loop, **antes** del bloque `if ( '' === $title && '' === $description && empty( $robots ) )` (línea 311), calcula los campos ricos:

```php
			$schema_type = '';
			$og_image    = '';
			if ( $allow_rich ) {
				if ( array_key_exists( 'schema_type', $fields ) ) {
					$candidate   = (string) $fields['schema_type'];
					$schema_type = in_array( $candidate, PostMeta::SCHEMA_TYPES, true ) ? $candidate : '';
				} else {
					$schema_type = (string) ( $current[ $slug ]['schema_type'] ?? '' );
				}

				$og_image = array_key_exists( 'og_image', $fields )
					? esc_url_raw( wp_unslash( (string) $fields['og_image'] ) )
					: (string) ( $current[ $slug ]['og_image'] ?? '' );
			}
```

Reemplaza la condición de "unset cuando vacío" y el ensamblado de `$entry` (líneas 311-324) por:

```php
			if ( '' === $title && '' === $description && empty( $robots ) && '' === $schema_type && '' === $og_image ) {
				unset( $current[ $slug ] );
				continue;
			}

			$entry = array(
				'title'       => $title,
				'description' => $description,
			);
			if ( ! empty( $robots ) ) {
				$entry['robots'] = $robots;
			}
			if ( '' !== $schema_type ) {
				$entry['schema_type'] = $schema_type;
			}
			if ( '' !== $og_image ) {
				$entry['og_image'] = $og_image;
			}

			$current[ $slug ] = $entry;
```

Añade el import de `PostMeta` al principio de `Options.php` si no está (junto a los `use` existentes del namespace `OpenSEO\Settings`):

```php
use OpenSEO\Meta\PostMeta;
```

- [ ] **Step 6: Correr los tests + análisis**

Run: `vendor/bin/phpunit tests/Unit/OptionsTest.php`
Expected: PASS (incluidos los tests existentes de `sanitize_template_map`: preserva slugs, unset vacío, merge por campo).
Run: `composer analyze`
Expected: sin errores nuevos (array-shapes y el import de `PostMeta`).

- [ ] **Step 7: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(settings): sanitize per-type schema/og image + attachment redirect keys"
```

---

## Task 8: Admin React — `TypePanel` enriquecido + `defaultSchemaType` en el bootstrap

**Files:**
- Modify: `src/Admin/Assets.php` (`content_type_entries()` + `bootstrap()`)
- Modify: `assets/src/admin/views/Titles.js` (`TypePanel` + sub-componente compartido)
- Test: gates `composer analyze`, `npm run lint:js`, `npm run test:js`, `npm run build` (Assets/Titles no tienen arnés unitario; son data-shaping/presentacionales)

**Interfaces:**
- Consumes: `TemplateDefaults::schema_type()` (Task 2); `setTemplateField(map, slug, field, value)` (genérico, `assets/src/admin/templateFields.js`); `RobotsFields`, `TemplateField`, `MediaField` (existentes).
- Produces: cada item de `window.openseoAdmin.contentTypes.postTypes` lleva `defaultSchemaType`; `TypePanel` muestra "Tipo de schema por defecto" + "Imagen social por defecto" solo para `post_types`.

- [ ] **Step 1: Añadir `defaultSchemaType` al bootstrap (PHP)**

En `src/Admin/Assets.php`, sustituye `content_type_entries()` (líneas 118-128) para aceptar el flag de schema (nota: la closure pasa de `static fn` a `fn` para poder usar `$this->defaults`):

```php
	/**
	 * Decorate slug/label entries with the per-surface default templates.
	 *
	 * @param array<int, array{slug:string, label:string}> $types               Slug/label pairs.
	 * @param string                                       $default_title       Default title template.
	 * @param string                                       $default_description Default description template.
	 * @param bool                                         $with_schema         Add defaultSchemaType (post types only).
	 * @return array<int, array<string, string>>
	 */
	private function content_type_entries( array $types, string $default_title, string $default_description, bool $with_schema = false ): array {
		return array_map(
			fn( array $type ): array => array(
				'slug'               => $type['slug'],
				'label'              => $type['label'],
				'defaultTitle'       => $default_title,
				'defaultDescription' => $default_description,
			) + ( $with_schema ? array( 'defaultSchemaType' => $this->defaults->schema_type( $type['slug'] ) ) : array() ),
			$types
		);
	}
```

Y en `bootstrap()` (línea 144), pasa `true` en la llamada de `postTypes`:

```php
				'postTypes'  => $this->content_type_entries(
					$this->content_types->post_types(),
					$this->defaults->singular_title(),
					$this->defaults->singular_description(),
					true
				),
```

- [ ] **Step 2: Verificar PHP (lint + analyze)**

Run: `composer lint && composer analyze`
Expected: sin errores.

- [ ] **Step 3: Enriquecer el `TypePanel` (JS)**

En `assets/src/admin/views/Titles.js`:

a) Define las opciones de schema cerca de `TWITTER_CARD_OPTIONS` (tras la línea 32):

```js
// Order kept consistent with the editor's SCHEMA_OPTIONS (Task 10).
const SCHEMA_TYPE_OPTIONS = [
	{ label: 'Article', value: 'Article' },
	{ label: 'BlogPosting', value: 'BlogPosting' },
	{ label: 'NewsArticle', value: 'NewsArticle' },
	{ label: 'WebPage', value: 'WebPage' },
	{ label: __( 'None', 'openseo' ), value: 'none' },
];
```

b) Reemplaza el cuerpo de `TypePanel` (líneas 386-430) para añadir los campos ricos solo cuando `mapKey === 'post_types'`:

```js
function TypePanel( { type, mapKey, scope, values, change } ) {
	const map = values[ mapKey ] ?? {};
	const entry = map[ type.slug ] ?? {};
	const isPostType = mapKey === 'post_types';

	const setField = ( field, value ) =>
		change( mapKey, setTemplateField( map, type.slug, field, value ) );

	return (
		<>
			<TemplateField
				label={ __( 'Title', 'openseo' ) }
				value={ entry.title ?? '' }
				placeholder={ type.defaultTitle }
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) => setField( 'title', v ) }
			/>
			<TemplateField
				label={ __( 'Description', 'openseo' ) }
				value={ entry.description ?? '' }
				placeholder={ type.defaultDescription }
				multiline
				scope={ scope }
				catalog={ catalog }
				onChange={ ( v ) => setField( 'description', v ) }
			/>
			<h3>{ __( 'Robots', 'openseo' ) }</h3>
			<RobotsFields
				robots={ entry.robots }
				onChange={ ( nextRobots ) =>
					change( mapKey, {
						...map,
						[ type.slug ]: { ...entry, robots: nextRobots },
					} )
				}
			/>
			{ isPostType && (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Default schema type', 'openseo' ) }
						value={ entry.schema_type ?? '' }
						options={ [
							{
								/* translators: %s: automatic schema type for this content type. */
								label: sprintf(
									__( 'Automatic (%s)', 'openseo' ),
									type.defaultSchemaType ?? ''
								),
								value: '',
							},
							...SCHEMA_TYPE_OPTIONS,
						] }
						onChange={ ( v ) => setField( 'schema_type', v ) }
					/>
					<MediaField
						label={ __(
							'Default social image for this content type.',
							'openseo'
						) }
						value={ entry.og_image ?? '' }
						onChange={ ( url ) => setField( 'og_image', url ) }
					/>
				</>
			) }
		</>
	);
}
```

c) Asegúrate de que `sprintf` esté importado de `@wordpress/i18n` (línea 7):

```js
import { __, sprintf } from '@wordpress/i18n';
```

- [ ] **Step 4: Verificar JS (lint + test + build)**

Run: `npm run lint:js`
Expected: sin errores (Prettier/jsdoc/jsx-a11y).
Run: `npm run test:js`
Expected: PASS.
Run: `npm run build`
Expected: build OK; `assets/build/` regenerado.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Assets.php assets/src/admin/views/Titles.js
git commit -m "feat(admin): per-type schema type and social image fields in Titles panels"
```

---

## Task 9: Admin React — `AttachmentsPanel` + ruteo

**Files:**
- Modify: `assets/src/admin/views/Titles.js` (`AttachmentsPanel` + `renderPanel`)
- Test: gates `npm run lint:js`, `npm run test:js`, `npm run build`

**Interfaces:**
- Consumes: `values.attachment_redirect`, `values.attachment_redirect_orphan`; `TypePanel` (Task 8).
- Produces: tab `pt:attachment` se renderiza con `AttachmentsPanel` (toggle de redirect + URL de huérfanos; oculta plantillas cuando el redirect está ON).

- [ ] **Step 1: Importar `Notice` en Titles.js**

En `assets/src/admin/views/Titles.js`, añade `Notice` a la importación de `@wordpress/components` (líneas 1-5):

```js
import {
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
```

- [ ] **Step 2: Añadir `AttachmentsPanel`**

En `assets/src/admin/views/Titles.js`, tras la función `TypePanel`, añade:

```js
function AttachmentsPanel( { type, values, change } ) {
	const redirect = values.attachment_redirect === '1';

	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Redirect attachment pages to the parent post',
					'openseo'
				) }
				help={ __(
					'Recommended: attachment pages are thin content. When on, their SEO templates below are disabled.',
					'openseo'
				) }
				checked={ redirect }
				onChange={ ( on ) =>
					change( 'attachment_redirect', on ? '1' : '' )
				}
			/>
			{ redirect ? (
				<>
					<TextControl
						__nextHasNoMarginBottom
						type="url"
						label={ __(
							'Fallback URL for attachments with no parent',
							'openseo'
						) }
						help={ __(
							'Used when an attachment has no parent post. Defaults to the homepage.',
							'openseo'
						) }
						value={ values.attachment_redirect_orphan ?? '' }
						onChange={ ( v ) =>
							change( 'attachment_redirect_orphan', v )
						}
					/>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Attachment SEO templates are disabled while redirection is on.',
							'openseo'
						) }
					</Notice>
				</>
			) : (
				<TypePanel
					type={ type }
					mapKey="post_types"
					scope="singular"
					values={ values }
					change={ change }
				/>
			) }
		</>
	);
}
```

- [ ] **Step 3: Rutear `pt:attachment` a `AttachmentsPanel`**

En `renderPanel()` (líneas 432-446), dentro del bloque `if ( tab.startsWith( 'pt:' ) )`, enruta el adjunto antes del `TypePanel` genérico:

```js
	if ( tab.startsWith( 'pt:' ) ) {
		const type = contentTypes.postTypes.find(
			( t ) => t.slug === tab.slice( 3 )
		);
		if ( ! type ) {
			return null;
		}
		if ( type.slug === 'attachment' ) {
			return (
				<AttachmentsPanel
					type={ type }
					values={ values }
					change={ change }
				/>
			);
		}
		return (
			<TypePanel
				type={ type }
				mapKey="post_types"
				scope="singular"
				values={ values }
				change={ change }
			/>
		);
	}
```

- [ ] **Step 4: Verificar JS (lint + test + build)**

Run: `npm run lint:js`
Expected: sin errores.
Run: `npm run test:js`
Expected: PASS.
Run: `npm run build`
Expected: build OK.

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/views/Titles.js
git commit -m "feat(admin): Attachments panel with redirect-to-parent toggle"
```

---

## Task 10: Editor — `schemaTypeDefault` + `SCHEMA_OPTIONS` dinámico

**Files:**
- Modify: `src/Admin/Editor/EditorPanel.php` (bootstrap)
- Modify: `assets/src/editor/index.js` (import `sprintf` + `SchemaField`)
- Test: gates `composer analyze`, `npm run lint:js`, `npm run test:js`, `npm run build`

**Interfaces:**
- Consumes: `TypeTemplates::schema_type_for()` (Task 3; `EditorPanel` ya tiene `$this->type_templates`).
- Produces: `window.openseoEditor.schemaTypeDefault`; el selector de schema del editor muestra "Automatic (X)".

- [ ] **Step 1: Añadir `schemaTypeDefault` al bootstrap del editor (PHP)**

En `src/Admin/Editor/EditorPanel.php`, dentro del array de `wp_add_inline_script` (líneas 77-86), añade tras `'descriptionTemplate' => ...`:

```php
					'schemaTypeDefault'   => $this->type_templates->schema_type_for( $post_type ),
```

- [ ] **Step 2: Verificar PHP**

Run: `composer lint && composer analyze`
Expected: sin errores.

- [ ] **Step 3: Construir `SCHEMA_OPTIONS` dinámicamente (JS)**

En `assets/src/editor/index.js`:

a) Importa `sprintf` (línea 19):

```js
import { __, sprintf } from '@wordpress/i18n';
```

b) Quita la opción `''` de la constante `SCHEMA_OPTIONS` (líneas 113-120), dejando solo los tipos concretos:

```js
const SCHEMA_OPTIONS = [
	{ label: 'Article', value: 'Article' },
	{ label: 'BlogPosting', value: 'BlogPosting' },
	{ label: 'NewsArticle', value: 'NewsArticle' },
	{ label: 'WebPage', value: 'WebPage' },
	{ label: __( 'None', 'openseo' ), value: 'none' },
];
```

c) Dentro de `SchemaField()`, construye las opciones con el label "Automatic (X)" y úsalas en el `SelectControl`. Reemplaza `options={ SCHEMA_OPTIONS }` (línea 163) por una variable local definida antes del `return`:

```js
	const schemaDefault = window.openseoEditor?.schemaTypeDefault ?? '';
	const schemaOptions = [
		{
			/* translators: %s: automatic schema type for this content type. */
			label: sprintf( __( 'Automatic (%s)', 'openseo' ), schemaDefault ),
			value: '',
		},
		...SCHEMA_OPTIONS,
	];
```

```js
				<SelectControl
					label={ __( 'Schema type', 'openseo' ) }
					value={ type }
					options={ schemaOptions }
					onChange={ setType }
				/>
```

- [ ] **Step 4: Verificar JS (lint + test + build)**

Run: `npm run lint:js`
Expected: sin errores.
Run: `npm run test:js`
Expected: PASS.
Run: `npm run build`
Expected: build OK.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Editor/EditorPanel.php assets/src/editor/index.js
git commit -m "feat(editor): surface per-type schema default as the automatic option"
```

---

## Task 11: i18n + gates finales

**Files:**
- Modify: `languages/openseo.pot` (regenerado)
- (Verificación de todos los gates)

**Interfaces:**
- Consumes: todas las cadenas `__()`/`sprintf` añadidas en Tasks 8-10.

- [ ] **Step 1: Correr la batería PHP completa**

Run: `composer check`
Expected: PHPCS + PHPStan + PHPUnit en verde.

- [ ] **Step 2: Correr la batería JS completa**

Run: `npm run lint:js && npm run test:js && npm run build`
Expected: todo en verde; `assets/build/` regenerado.

- [ ] **Step 3: Suite de integración (requiere Docker/wp-env) — no-regresión del grafo + redirect**

Run: `npm run env:start && npm run test:integration`
Expected: PASS — en particular `SchemaTest` confirma que el grafo JSON-LD sigue idéntico (post→Article, page→WebPage, none→sin Article); si se añadió un test de integración para `AttachmentRedirect`, también verde. (Si Docker no está disponible, documentar que se cubrió con el smoke manual del Step 5.)

- [ ] **Step 4: Regenerar el `.pot` (requiere wp-env corriendo)**

Run: `npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo languages/openseo.pot`
Expected: `languages/openseo.pot` actualizado con las cadenas nuevas ("Redirect attachment pages to the parent post", "Fallback URL for attachments with no parent", "Attachment SEO templates are disabled while redirection is on.", "Default schema type", "Default social image for this content type.", "Automatic (%s)").

- [ ] **Step 5: Smoke test manual (wp-env)**

Verifica en `http://localhost:8888/wp-admin`:
- **OpenSEO → Titles & Meta**: aparece la pestaña **Attachments** en "Content types"; el toggle de redirect está ON por defecto y muestra el campo de URL de huérfanos + el aviso.
- En **Entradas/Páginas**: aparecen "Default schema type" (con "Automatic (Article)"/"Automatic (WebPage)") e "Default social image".
- Publica una entrada sin imagen destacada y con `og_image` de tipo configurada → ver el código fuente: `og:image` usa la imagen de tipo. Con imagen destacada, gana la destacada.
- Con redirect ON, visitar el permalink de un adjunto → 301 a la entrada padre (`curl -sI`).

- [ ] **Step 6: Commit**

```bash
git add languages/openseo.pot
git commit -m "chore(i18n): regenerate .pot for content-types & taxonomies parity"
```

- [ ] **Step 7: Actualizar el changelog/readme (default-ON)**

Documenta en el readme/changelog del plugin: "Attachment pages now redirect to their parent post by default; disable in OpenSEO → Titles & Meta → Attachments." Commitea junto con el resto de la documentación de release cuando proceda.

---

## Self-Review (rellenado por el autor del plan)

- **Cobertura del spec:** §1 (Task 1, 7) · §2 schema cascade (Task 2, 3, 4) · §3 social (Task 5) · §4 AttachmentRedirect (Task 6) · §5 sanitize (Task 7) · §6 UI (Task 8, 9) · §7 editor (Task 10) · criterios i18n/changelog/gates (Task 11). Sin huecos.
- **Sin placeholders:** todos los steps de código muestran el código real; comandos con salida esperada.
- **Consistencia de tipos:** `schema_type_for()`/`og_image_for()` (Task 3) se consumen con esas firmas exactas en Tasks 4, 5, 10; `Article::__construct(Resolver, Options, TypeTemplates)` (Task 4) coincide con el wiring de `Plugin` y los tests; `sanitize_template_map(..., bool $allow_rich=false)` (Task 7) coincide con sus dos call-sites; `setTemplateField(map, slug, field, value)` genérico se usa en Task 8; `defaultSchemaType`/`schemaTypeDefault` consistentes entre PHP (Tasks 8, 10) y JS.
