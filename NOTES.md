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
