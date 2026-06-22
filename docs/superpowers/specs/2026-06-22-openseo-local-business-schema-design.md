# Diseño — SEO Local (2b-i: LocalBusiness campos + schema)

- **Fecha:** 2026-06-22
- **Estado:** Aprobado para planificación
- **Área:** Titles & Meta → SEO Local (admin React) + Schema (pieza Organization/Person) + Settings
- **Tipo:** Sub-proyecto 2b-i de la consolidación de Titles & Meta — primera de dos sub-fases de "SEO Local 2b" (2b-i campos+schema → 2b-ii shortcode)

## Contexto

2a estableció la identidad básica de SEO Local (persona/empresa, nombre/nombre-alt/logo/url/email) como
fuente única, con `Schema\Pieces\Organization`/`Person` emitiendo `name`/`url`/`email`/`logo`. **2b**
añade el subsistema LocalBusiness; por su tamaño se divide en dos sub-fases (brainstorming 2026-06-22):

- **2b-i (este spec):** los campos LocalBusiness (dirección, horarios/teléfonos/info-adicional
  repetibles, tipo de negocio, geo, price range, descripción) y su traducción a JSON-LD
  `LocalBusiness` (+ `PostalAddress`/`GeoCoordinates`/`openingHoursSpecification`/`contactPoint`),
  más el componente de UI repetible reutilizable.
- **2b-ii (futuro):** el shortcode `[openseo_contact_info]` que muestra esa info en la página.

### Estado verificado de OpenSEO

- `Schema\Pieces\Organization::data()` (needed si `schema_site_type` != Person): `@type Organization`,
  `name`, `url` (= `local_url`/`home_url`), `email` (= `local_email`), `logo`/`image` (= `schema_logo`).
- `Schema\Pieces\Person::data()` (needed si == Person): igual sin `logo` (usa `image`); sin `telephone`.
- Ambas piezas se construyen con `$options` en `Plugin.php` (líneas 140-142). El `@graph` se imprime con
  `Graph` (`wp_json_encode` + `JSON_HEX_TAG`). No existe nodo `Place` ni helper de LocalBusiness.
- `assets/src/admin/views/Titles.js` → `SeoLocalPanel` renderiza los 7 campos de identidad (2a). No hay
  componente de grupo repetible. `Admin\Assets::bootstrap()` ya pasa `contentTypes`/`variables` a
  `window.openseoAdmin`.

### Decisiones congeladas (brainstorming 2026-06-22)

1. **Alcance:** dos sub-fases; **2b-i campos+schema primero**, 2b-ii shortcode después.
2. **Tipo de negocio:** subset **curado (~30)** de subtipos LocalBusiness con `SelectControl` (no la
   jerarquía completa de Schema.org).
3. **D1** — `address`/`geo` **inline** en el nodo Organization (forma recomendada por Google para
   LocalBusiness), **sin** nodo `Place` separado.
4. **D2** — `local_business_type` vacío ⇒ nodo `Organization` sin `geo`/`openingHoursSpecification`/
   `priceRange`; con business type ⇒ subtipo LocalBusiness con todas las props locales.
5. **D3** — Helper puro `Schema\LocalBusiness::build(Options): array` (mantiene `Organization` lean y la
   lógica unit-testeable sin WordPress).
6. **D4** — Componente reutilizable `RepeatableGroup` + helper puro `repeatable.js` para los 3 repetibles.
7. **D5** — Fuera de 2b-i: **shortcode** (→ 2b-ii), **Google Maps embed / API key** (descartado: se
   guarda `geo` para el schema, sin iframe), **páginas About/Contact** (descartado, YAGNI).

## Objetivo

Permitir configurar los datos de un negocio local (dirección, geo, horarios, teléfonos, tipo, price
range, descripción, datos legales) en la pestaña SEO Local y emitir el JSON-LD `LocalBusiness` correcto
en el `@graph`, reutilizando la identidad de 2a.

## No-objetivos

- Shortcode `[openseo_contact_info]` (2b-ii).
- Embed de Google Maps / `maps_api_key` (descartado).
- Páginas About/Contact enlazadas al schema (descartado).
- Jerarquía completa de tipos de negocio de Schema.org (se usa subset curado).
- Nodo `Place` separado (address/geo van inline — D1).
- Multi-ubicación / KML (Pro de Rank Math; fuera de alcance).
- **Person sin campos LocalBusiness (L1):** Person solo recibe `local_phone` → `telephone`; no
  dirección/geo/horarios (YAGNI; revisitar si hay demanda).
- **Sin etiquetas "Cerrado"/"24h" explícitas:** los horarios se modelan como `{day, opens, closes}`; un
  día sin fila = cerrado ese día; 24h se expresa como `00:00`-`23:59`. Decisión consciente.
- `local_address.country` como `SelectControl` de países ISO (se deja texto libre con `help` — M4).

## Arquitectura

### 1. Modelo de datos (`Settings\Options`)

Keys nuevas en `defaults()` (org salvo `local_phone`, que es el **teléfono principal de ambos tipos**):

```php
'local_business_type'   => '',          // '' o valor curado (whitelist)
'local_description'      => '',          // textarea
'local_price_range'      => '',          // p. ej. "$$"
'local_geo'              => '',          // "lat,lng" normalizado o ''
'local_phone'            => '',          // telephone primario (Person Y Organization, H1)
'local_address'          => array(
    'street'      => '',
    'locality'    => '',
    'region'      => '',
    'postal_code' => '',
    'country'     => '',
),
'local_opening_hours'    => array(),     // [ { day, opens, closes } ]
'local_phone_numbers'    => array(),     // [ { type, number } ]
'local_additional_info'  => array(),     // [ { type, value } ]
```

**Sanitización — extraída a `Settings\LocalSeoSanitizer` (nuevo, testeable).** `Options::sanitize()`
delega los `local_*` de 2b-i a `LocalSeoSanitizer::sanitize(array $input, array $current): array`.

> **Contrato de merge parcial (M3):** `Options::sanitize()` parte de `$clean = $this->all()` (las keys
> no enviadas conservan su valor guardado). El sanitizer **solo procesa las `local_*` presentes en
> `$input`** (`array_key_exists`); por cada presente valida/reemplaza, las ausentes **no se tocan** (las
> conserva `$current`/`$clean`). `Options::sanitize` invoca el sanitizer solo si
> `array_intersect_key($input, $local_keys)` no está vacío y mergea su retorno sobre `$clean`. Es el
> mismo patrón ya probado de `sanitize_template_map`/`sanitize_advanced_*`. Para los repetibles y
> `local_address` un POST de la pestaña SEO Local envía el **array completo** (reemplazo total de esa
> key); un POST de otra pestaña no envía ninguna `local_*` y por tanto las conserva intactas.

Reglas:
- `local_description` → `sanitize_textarea_field`. `local_price_range`, `local_phone` →
  `sanitize_text_field`.
- `local_business_type` → whitelist `LocalChoices::business_type_values()`, fuera de lista → `''`.
- `local_geo` → parse `"lat,lng"`: dos floats con `lat∈[-90,90]`, `lng∈[-180,180]`; normaliza a
  `"lat,lng"` o `''` si inválido.
- `local_address` → array; cada subclave (`street/locality/region/postal_code/country`) por
  `sanitize_text_field`; subclaves desconocidas se ignoran. **(M4)** `country` se mantiene texto libre
  pero el `help` del campo pide **ISO 3166-1 alpha-2** ("US", "ES") — decisión consciente (un
  `SelectControl` de países queda fuera de 2b-i).
- `local_opening_hours` → por fila: `day` en `['Monday'..'Sunday']`; `opens`/`closes` con regex
  `^([01]\d|2[0-3]):[0-5]\d$` (o `''`); filas con day inválido o sin horas válidas se descartan.
- `local_phone_numbers` → por fila: `type` en whitelist de tipos de contacto; `number` por
  `sanitize_text_field`; filas con number vacío se descartan.
- `local_additional_info` → por fila: `type` en whitelist de tipos; `value` por `sanitize_text_field`;
  filas con value vacío se descartan. **(L4)** `foundingDate`: el `help` pide `YYYY` o `YYYY-MM-DD`
  (validación ligera opcional en el plan; texto libre aceptado). El **mapeo a schema** de
  `numberOfEmployees` lo hace `LocalBusiness::build` como `QuantitativeValue` (ver §4, M1), no el
  sanitizer.

### 2. Enumeraciones — `Schema\LocalChoices` (fuente única)

Clase nueva `Schema\LocalChoices` con métodos estáticos que devuelven `array<int, array{value,label}>`
(labels i18n por `__()`), más helpers `*_values(): array<int,string>` para los whitelists del
sanitizer:
- `business_types()` — subset curado **~30** subtipos LocalBusiness (valores = `@type` Schema.org):
  `LocalBusiness`, `Restaurant`, `CafeOrCoffeeShop`, `Bakery`, `BarOrPub`, `Store`, `ClothingStore`,
  `GroceryStore`, `ElectronicsStore`, `HardwareStore`, `ProfessionalService`, `LegalService`,
  `Attorney`, `AccountingService`, `FinancialService`, `InsuranceAgency`, `RealEstateAgent`,
  `MedicalBusiness`, `Dentist`, `Physician`, `Pharmacy`, `HealthAndBeautyBusiness`, `BeautySalon`,
  `HairSalon`, `HomeAndConstructionBusiness`, `Plumber`, `Electrician`, `Locksmith`,
  `AutomotiveBusiness`, `AutoRepair`, `LodgingBusiness`, `Hotel`, `TravelAgency`,
  `EntertainmentBusiness`, `SportsActivityLocation`, `ChildCare`, `FoodEstablishment`. (Lista exacta a
  fijar en el plan; orden alfabético con `LocalBusiness` primero como genérico.)
- `phone_types()` — valores `contactType` canónicos de Google (M2): `customer service`,
  `technical support`, `billing support`, `bill payment`, `sales`, `reservations`,
  `credit card support`, `emergency`, `package tracking`. (NO `customer support` — el valor de Google es
  `customer service`.)
- `additional_info_types()` — pares tipo→propiedad de Organization: `legalName`, `foundingDate`,
  `vatID`, `taxID`, `duns`, `leiCode`, `naics`, `iso6523Code`, `globalLocationNumber`,
  `numberOfEmployees`.
- `days()` — `Monday`..`Sunday` (valor = nombre inglés; el schema usa `https://schema.org/<day>`).

`Admin\Assets::bootstrap()` añade `localChoices => array( businessTypes, phoneTypes, additionalInfoTypes,
days )` a `window.openseoAdmin` (single-source; la UI no duplica las listas).

### 3. UI admin (React)

**`components/RepeatableGroup` (nuevo)** — editor genérico de filas:
- Props: `label`, `value` (array de filas), `columns` (`[{ key, label, control:'text'|'time'|'select',
  options? }]`), `emptyRow` (objeto fila vacía), `onChange(newRows)`, `addLabel`.
- Render: `<fieldset><legend>` + por fila una `Flex` con los controles de columna (`TextControl`,
  `TextControl type="time"`, `SelectControl`) + botón "Remove"; un botón "Add" al final.
- Usa el helper puro `repeatable.js`.
- **(M5) Key de React:** el índice de fila es aceptable como `key` aquí — los controles son
  totalmente controlados por `value` (sin estado local por fila) y todo el grupo se re-renderiza en cada
  cambio; la lógica de mutación vive en `repeatable.js` (puro/testeado), no en el render. Decisión
  consciente (no índice-como-key por descuido).

**`repeatable.js` (nuevo, puro, testeable):** `addRow(rows, emptyRow)`, `removeRow(rows, index)`,
`updateCell(rows, index, key, value)` — inmutables.

**`components/LocalBusinessFields` (nuevo)** — la sección org-local, para mantener `SeoLocalPanel`
enfocado. Renderiza (solo se monta cuando aplica, ver abajo):
- `SelectControl` business type (de `localChoices.businessTypes`, con opción vacía "— None").
- `TextareaControl` descripción → `local_description`.
- Dirección: 5 `TextControl` → subclaves de `local_address` (update inmutable anidado).
- `TextControl` price range → `local_price_range`; `TextControl` geo ("lat,lng") → `local_geo`.
- `RepeatableGroup` horarios (columnas: day select, opens time, closes time) → `local_opening_hours`.
- `RepeatableGroup` teléfonos (type select, number text) → `local_phone_numbers`.
- `RepeatableGroup` info adicional (type select, value text) → `local_additional_info`.

**`SeoLocalPanel` (mod, en `views/Titles.js`):** tras los 7 campos de identidad,
- un `TextControl` "Phone" → `local_phone`, visible para **ambos** tipos (teléfono principal; H1).
- si `values.schema_site_type === 'Organization'` → `<LocalBusinessFields … />` (la sección org-local
  completa, incluidos los teléfonos adicionales repetibles).

### 4. Schema — `Schema\LocalBusiness` + piezas

**`Schema\LocalBusiness::build(Options): array` (nuevo, puro/testeable)** devuelve las props locales a
mergear en el nodo Organization (sin `@type`/`@id`/`name`/`url`/`email`/`logo`, que los pone la pieza).
`is_local = '' !== local_business_type`. Emite cada clave solo cuando hay valor:
- **Siempre que haya valor (válido también en Organization):** `telephone` (de `local_phone` — el
  método de contacto principal recomendado por Google, H1); `description`; `address` (PostalAddress:
  `streetAddress/addressLocality/addressRegion/postalCode/addressCountry` desde `local_address`);
  `contactPoint[]` (de `local_phone_numbers`: `{ @type:'ContactPoint', telephone, contactType }`,
  números **adicionales** por propósito); y las props de `local_additional_info` directas en el nodo
  (`legalName`, `vatID`, `taxID`, `foundingDate`, … ). **(M1)** `numberOfEmployees` se emite como
  `{ @type:'QuantitativeValue', value:N }` (no valor plano), que es el *expected type* de schema.org.
- **Solo si `is_local`:** `geo` (`{ @type:'GeoCoordinates', latitude, longitude }` parseando
  `local_geo`); `openingHoursSpecification[]` (de `local_opening_hours`:
  `{ @type:'OpeningHoursSpecification', dayOfWeek:'https://schema.org/<day>', opens, closes }`);
  `priceRange`.

> **Por qué esas tres son solo-LocalBusiness (H2):** `geo`, `openingHoursSpecification` y `priceRange`
> **no** son propiedades de `Organization` plana en schema.org — pertenecen a `Place`/`LocalBusiness`.
> Emitirlas en un nodo `Organization` (sin business type) produciría structured data inválido. Por eso
> el gating; un test (ver Testing) blinda que `priceRange`/`geo`/`openingHoursSpecification` **nunca**
> aparezcan cuando `local_business_type` está vacío, aunque sus campos tengan valor. `telephone`,
> `address`, `contactPoint`, `description` y las props de info-adicional **sí** son válidas en
> `Organization`, por eso van en el bloque "siempre".

**`Organization::data()` (mod):** `@type` = `local_business_type` (revalidado contra
`LocalChoices::business_type_values()`) si no vacío, si no `Organization`; luego
`$data = array_merge( $data, ( new LocalBusiness() )->build( $this->options ) );` tras añadir
email/logo (las claves no colisionan).

**`Person::data()` (mod):** añade `telephone` desde `local_phone` cuando no esté vacío.

## Componentes y responsabilidades

| Unidad | Responsabilidad | Depende de |
|---|---|---|
| `Schema\LocalChoices` (nuevo) | enums (business/phone/additional/days) + values() | — |
| `Settings\LocalSeoSanitizer` (nuevo) | sanea los `local_*` de 2b-i (puro, whitelists) | `LocalChoices` |
| `Settings\Options` (mod) | defaults + delega `local_*` al sanitizer | `LocalSeoSanitizer` |
| `Schema\LocalBusiness` (nuevo) | `build(Options): array` props locales (puro) | `Options`, `LocalChoices` |
| `Schema\Pieces\Organization` (mod) | `@type` LocalBusiness + merge `LocalBusiness::build` | `LocalBusiness`, `LocalChoices` |
| `Schema\Pieces\Person` (mod) | `telephone` de `local_phone` | `Options` |
| `Admin\Assets` (mod) | bootstrap `localChoices` | `LocalChoices` |
| `components/RepeatableGroup` (nuevo) | editor de filas genérico | `repeatable.js` |
| `repeatable.js` (nuevo) | add/remove/updateCell inmutables (puro) | — |
| `components/LocalBusinessFields` (nuevo) | sección org-local | `RepeatableGroup`, controles `@wordpress/components`, bootstrap `localChoices` |
| `views/Titles.js` (mod) | monta LocalBusinessFields/phone según tipo | `LocalBusinessFields` |

## Manejo de errores y casos límite

- **Geo inválido** ("abc", un solo número, fuera de rango): sanitize → `''`; `build` no emite `geo`.
- **Fila de horario incompleta** (day sin horas o hora mal formada): se descarta en sanitize.
- **`local_business_type` desconocido:** sanitize → `''`; el nodo queda `Organization` y no emite
  geo/hours/priceRange.
- **Sin ningún campo local:** `build` devuelve `[]`; el nodo Organization es idéntico al de 2a (sin
  regresión; `SitePiecesTest` de 2a sigue verde).
- **`additional_info` con tipo desconocido:** se descarta; tipos válidos → propiedad directa del nodo.
- **Person con campos org:** no se emiten (Person solo añade `telephone`); la UI solo muestra `phone`
  para Person.
- **PHPStan nivel 6:** `Options::get()` devuelve `mixed`; `LocalBusiness`/sanitizer leen con guards
  `is_array`/casts `(string)`/`(float)` y docblocks `array{...}`; sin baseline.
- **Seguridad/i18n:** sanitizar en entrada (todos los `local_*`), escapar en salida vía el
  `wp_json_encode(JSON_HEX_TAG)` ya existente del `@graph`; labels por `__()`; sin
  `dangerouslySetInnerHTML`.

## Testing

**PHP unit (Brain Monkey):**
- `LocalChoicesTest`: las listas tienen la forma `{value,label}`; `*_values()` coincide con los values;
  business types incluye `LocalBusiness`.
- `LocalSeoSanitizerTest`: geo válido/ inválido → `''`; address por subclave; business type whitelist;
  filas de horario inválidas descartadas; phone/additional rows vacías descartadas; tipos fuera de lista
  descartados. **(M3)** un `$input` sin ninguna `local_*` (POST de otra pestaña) **no** borra los
  `local_*` ya guardados (se conservan vía `$current`).
- `LocalBusinessTest` (build puro): `telephone` de `local_phone`; address PostalAddress; geo
  parse/omisión; openingHoursSpecification; contactPoint[]; props additional (legalName/vatID);
  `numberOfEmployees` como `QuantitativeValue` (M1); **gating (H2)**: con `local_business_type=''` y
  `local_price_range`/`local_geo`/`local_opening_hours` con valor, el resultado **no** contiene
  `priceRange`/`geo`/`openingHoursSpecification`, pero **sí** `description`/`address`/`contactPoint`/
  `telephone`; sin ningún campo → `[]`.
- `SitePiecesTest` (ampliar): `Organization` con business type → `@type` correcto + props locales
  mergeadas; sin business type → `Organization` + solo props válidas; `Person` con `local_phone` →
  `telephone`; **regresión**: con defaults vacíos el `@graph` de Organization/Person es idéntico a 2a.
- `OptionsTest` (ampliar): defaults incluyen las keys nuevas; `Options::sanitize` delega correctamente
  (un caso de smoke que confirme que un `local_*` enviado llega saneado).

**JS (Jest):** `repeatable.test.js`: `addRow`/`removeRow`/`updateCell` inmutables (no mutan, preservan
otras filas, índices correctos).

**Gates:** `composer lint`, `composer analyze` (PHPStan 6), `composer test:unit`, `npm run lint:js`,
`npm run lint:css` (si se añade SCSS para RepeatableGroup), `npm run test:js`, `npm run build`.

**Smoke test manual (wp-env):** en *SEO Local* con tipo "Organization", elegir business type
"Restaurant", rellenar dirección, un teléfono principal, una fila de horario y un teléfono adicional;
ver el código fuente → el `@graph` muestra el nodo con `@type:"Restaurant"`, `telephone`, `address`,
`openingHoursSpecification`, `contactPoint`. **(L3)** validar la URL en validator.schema.org y en el
Rich Results Test de Google → 0 errores.

## Criterios de aceptación

- En *SEO Local* (tipo Organization) aparece la sección LocalBusiness: tipo de negocio, descripción,
  dirección, price range, geo, y los 3 repetibles (horarios/teléfonos/info-adicional) con add/remove.
  Para Person, un campo de teléfono.
- Las keys nuevas se guardan y sanean (geo inválido→vacío; filas inválidas descartadas; business type y
  tipos whitelisted).
- El nodo de identidad emite `@type` LocalBusiness (subtipo) cuando hay business type, con `address`
  (PostalAddress inline), `geo`, `openingHoursSpecification[]`, `contactPoint[]`, `priceRange`,
  `description` y props de info-adicional, según lo configurado; Person emite `telephone`.
- Sin business type ⇒ nodo `Organization` sin geo/hours/priceRange (solo lo válido para Organization);
  sin campos locales ⇒ sin regresión frente a 2a.
- `RepeatableGroup` reutilizable cubre los 3 repetibles; helpers `repeatable.js` testeados.
- Gates verdes (lint/analyze/test:unit/lint:js/test:js/build).
