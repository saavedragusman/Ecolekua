# Design: 003-products — Product Catalog, Combinations, Combos, Selection Resolution, Visual Assets and Initial Load

> Source of truth: `docs/specs/003-products.md` (status "Lista para Design"; PRD-001..PRD-020, E-01..E-72, DEC-PRD-01..DEC-PRD-53 all `Confirmada`, so the AGENTS.md §4.4 gate is open). DT-01, DT-02 and DT-03 are **confirmed here** (Decisions 4, 14 and 18).
> Inputs: `proposal.md`, `specs/products/spec.md`, `exploration.md` (its Q1–Q11 are answered by DEC-PRD-35..DEC-PRD-47 and are not reopened), the archived `001-foundation` and `002-customers` designs, `docs/constitution.md`, `docs/ui/design-system.md` and the current code (read directly: the repository has no `.codegraph/` index and this phase has no shell to create one).
> This file is the project's only plan artifact (`openspec/config.yaml` → `rules.design`). There is no separate `plan.md`.
> **Five business gaps** were found while designing (P-1..P-5 in Open Questions). All five are now **resolved** by DEC-PRD-49..DEC-PRD-53 and folded into the decisions below; no slice is gated by a pending decision.

## Technical Approach

003 extends the 001/002 pattern without new architecture: **thin controller → FormRequest (validation + `authorize()` through a Policy) → Action in `app/Actions/Products/` (business rules + `DB::transaction` + `RecordAuditEvent` in the same transaction)**. Pure, Eloquent-free logic that must be shared by several callers (HTTP Actions, the importer, the resolution engine, unit tests) lives in `app/Support/Products/`.

Seven design moves carry the change:

1. **Catalog as rows, one "allowed subset" shape.** Attributes and values are data. Every restriction in the spec (product allowed values, combination axis values, combination order restrictions, combo component restrictions, fabric offered colors) is stored as `(owner, attribute, value)` rows; "no rows for an attribute" means "no restriction" where the spec says so (DEC-PRD-36, DEC-PRD-44). No rules engine (constitution §11).
2. **Code space owned by one table.** `catalog_codes` holds every combination and combo code with a case-insensitive unique index (DT-01, E-08). Codes live only there.
3. **Non-overlap by lock + pure check + duplicate backstop** (DEC-PRD-39). Every write that can create an overlap locks the product row, runs `CombinationOverlap` (pure) against the other active combinations, and a generated `active_signature` unique index blocks exact duplicates even if the check is bypassed.
4. **Availability is computed, never cascaded** (PRD-013). Product, combination, category, value and location states are combined at read time; deactivating a product never touches combination rows.
5. **One selection engine** (DT-02). `CatalogSnapshotLoader` loads everything a product needs in a constant number of queries; `SelectionRules` (pure) answers both "which options exist" (PRD-019) and "is this selection valid" (PRD-011). `ResolveSelection` and `ListSelectionOptions` are thin Actions over it. No price or stock dependency.
6. **Assets on the private disk, served through authorized controllers.** Images are decoded, bounded and re-encoded with GD into WebP variants; SVG templates are sanitized with `enshrined/svg-sanitize` (approved, pinned) plus a project post-pass, then layers are detected on the sanitized bytes (DT-03, E-39).
7. **All-or-nothing console import** reusing the 002 pattern: validate three CSV files in memory with the same rules and pure classes, report `Fila | Columna | Motivo`, then create everything through the real Actions in one transaction with a console origin and an import marker (PRD-018, DEC-PRD-48).

Requirement to mechanism map:

| Requirement | Mechanism |
|---|---|
| §4, DEC-PRD-22/23 | 6 `PermissionName` cases, `InitialRolePermissions::MATRIX`, coherence block in `SyncRolePermissions::ensureCoherent()` (Decisions 6–7) |
| PRD-001 | `product_categories`, `CreateCategory`, `UpdateCategory`, `MoveCatalogItem`, `SetCatalogItemStatus` (Decision 9) |
| PRD-002 | `catalog_attributes` (`special_use`), `attribute_values`, `fabric_offered_colors`; uniqueness of color and of each special use by unique columns; in-use guards through `CatalogUsage` (Decisions 3, 9; DEC-PRD-49, DEC-PRD-51) |
| PRD-003, PRD-009, PRD-012 (product) | `products` with CHECK constraints, `CreateProduct`, `UpdateProduct` (mode change wipes minimums; cross-record edit guards), `SyncStockMinimumOverrides` keyed by the size-use attribute (Decisions 10, 13; DEC-PRD-52) |
| PRD-004 | `product_attributes` + `product_attribute_values`, `SyncProductAttributes` with role rule, freeze, in-use guards and size-removal cleanup (Decision 11; DEC-PRD-50, DEC-PRD-52) |
| PRD-005, PRD-012 (combination) | `catalog_codes`, `combinations`, `combination_values`, `CreateCombination`, `UpdateCombination`, `CombinationOverlap`, `AxisSignature` (Decisions 4, 5, 12) |
| PRD-007, PRD-008 | `product_detail_locations`, `product_customizations`, `combination_customizations` (Decision 10, 12) |
| PRD-010 | `combos`, `combo_components`, `combo_component_values`, `CreateCombo`, `UpdateCombo` (Decision 15) |
| PRD-011, PRD-019 | `CatalogSnapshotLoader`, `SelectionRules`, `ResolveSelection`, `ListSelectionOptions` (Decision 14) |
| PRD-013 | `Activate*` / `Deactivate*` Actions, computed availability (Decision 16) |
| PRD-014 | `DeleteProduct`, `DeleteCombination`, `DeleteCombo`, `ComboMembership`, empty `ensureHasNoHistory()` hooks (Decision 17) |
| PRD-015 | `ProductController@index/show`, `ComboController@index/show`, `ProductPresenter` (Decision 20) |
| PRD-016 | `ProductPolicy`, `CombinationPolicy`, `ComboPolicy`, `CatalogPolicy` (Decision 8) |
| PRD-017 | 24 new `AuditAction` cases, audit inside every Action transaction (Decision 6, Audit payloads) |
| PRD-018 | `catalog:import` → `ImportCatalog` → generalized `CsvReader` (Decision 19) |
| PRD-020 | `ImageProcessor` (GD), `SvgTemplateSanitizer`, `SvgLayerDetector`, `TemplateLayerRequirements`, `CatalogAssetController` (Decision 18; catalog layer guard DEC-PRD-53 in Decision 9) |

## Architecture Decisions

### Decision 1: Module layout mirrors 001/002

**Choice**: Controllers in `app/Http/Controllers/Products/` (products, combinations, combos, product assets) and `app/Http/Controllers/Catalog/` (categories, attributes, values, detail locations, catalog assets); FormRequests in `app/Http/Requests/Products/` and `app/Http/Requests/Catalog/`; Actions in `app/Actions/Products/` (one namespace for the whole spec, as AGENTS.md §8 and spec §12 require); pure logic in `app/Support/Products/` (`Selection/`, `Assets/`, `Import/` sub-namespaces). Reads call `Gate::authorize()` in the controller; writes authorize in `FormRequest::authorize()`, or in the controller before the Action for body-less endpoints (status, move, delete), exactly as `CustomerStatusController`.
**Alternatives considered**: A `Catalog` Actions namespace separate from `Products` (rejected: spec §12 names `app/Actions/Products/` for all creates, edits, deletes and the import). Repository/service layer (rejected: constitution simplicity, AGENTS.md §7.10).
**Rationale**: `openspec/config.yaml` `rules.design`; authorization runs before any transaction, so a denied request has no side effects and `RenderAccessDenied` audits it.

### Decision 2: Enums

**Choice** (string-backed, English values, Spanish `label()`, constitution §21.1):

| Enum | Cases (value → label) | Used by |
|---|---|---|
| `CatalogStatus` | `active` → "Activo", `inactive` → "Inactivo" | categories, attributes, values, detail locations, products, combinations, combos |
| `BusinessLine` | `uniforms` → "Uniformes", `diapers` → "Pañales" | products (DEC-PRD-26) |
| `SupplyMode` | `on_demand` → "Bajo pedido", `stock_with_minimum` → "Stock con mínimo", `stock_depletable` → "Stock agotable", `service` → "Servicio" | products (DEC-PRD-09) |
| `AttributePresentation` | `text` → "Texto", `image` → "Imagen", `color` → "Color" | attributes (DEC-PRD-30) |
| `AttributeRole` | `axis` → "Eje", `order` → "De pedido" | product attributes (DEC-PRD-03) |
| `AttributeSpecialUse` | `fabric` → "Tela" (DEC-PRD-32, now the use "Tela"), `size` → "Talla", `gender` → "Género" (DEC-PRD-49); a column value of `null` means no special use | attributes |

`StatusBadge`: active → `done`, inactive → `neutral` (spec §8). One shared `CatalogStatus` instead of seven identical enums.
**Rationale**: constitution §21.1, spec §5.

### Decision 3: Data model and database guarantees

**Choice**: five additive migrations (one per slice that first needs them; full schema in Interfaces). Model names avoid `Attribute` / `attributes` (collision with `Illuminate\Database\Eloquent\Casts\Attribute` and `$attributes`, exploration §4): `ProductCategory`, `CatalogAttribute`, `AttributeValue`, `DetailLocation`, `Product`, `ProductAttribute`, `CatalogCode`, `Combination`, `StockMinimumOverride`, `Combo`, `ComboComponent`, `ProductTemplate`. Pivot tables without models: `fabric_offered_colors`, `product_attribute_values`, `product_detail_locations`, `product_customizations`, `combination_values`, `combination_customizations`, `combo_component_values`.

What the database guarantees (MySQL 8.4) and what the Actions guarantee:

| Rule | Database | Action (friendly error) |
|---|---|---|
| Code unique across combinations and combos, case-insensitive (DEC-PRD-01/02/14, DT-01, E-08) | `catalog_codes.code` unique, collation `utf8mb4_0900_as_ci`; CHECK exactly one owner | `Rule::unique` on `code`; race → `UniqueConstraintViolationException` converted to the same error |
| Case-insensitive unique names (DEC-PRD-45, PRD-002) | unique index on `name` with `utf8mb4_0900_as_ci` (attribute values: `(catalog_attribute_id, name)`) | `Rule::unique` |
| At most one attribute per special use: Tela, Talla, Género (DEC-PRD-32, DEC-PRD-49, E-45) | `special_use` nullable single-column unique index (MySQL allows many `NULL`s, so only the three real uses are constrained; an attribute holds one use by construction) | Action check |
| Special roles: the Tela attribute only as axis, the color attribute only as order (DEC-PRD-50) | none (cross-table rule) | `ProductRules` in `SyncProductAttributes` |
| An attribute declared by products cannot change presentation or special use, nor be deactivated while an active product declares it (DEC-PRD-51) | none (cross-table rule; `product_attributes.catalog_attribute_id` FK restrict is only a delete backstop) | `CatalogUsage` check in `UpdateCatalogAttribute` / `DeactivateCatalogItem` |
| At most one color-presentation attribute (DEC-PRD-38, E-58) | stored generated `color_marker = IF(presentation='color',1,NULL)` unique | Action check |
| A product attribute appears once (E-06) | unique `(product_id, catalog_attribute_id)` | `SyncProductAttributes` |
| Axis/restriction value belongs to its attribute | composite FK `(attribute_value_id, catalog_attribute_id)` → `attribute_values(id, catalog_attribute_id)` on `combination_values` and `combo_component_values` | rules |
| No two active combinations overlap (DEC-PRD-39) | **exact duplicates only**: stored generated `active_signature` unique with `product_id` | product lock + `CombinationOverlap` |
| Minimum stock only in mode `stock_with_minimum`, mandatory there (DEC-PRD-46, E-20, E-65) | two CHECK constraints on `products` | rules |
| Custom color only in `on_demand` (DEC-PRD-34) | CHECK on `products` | rules |
| Combo component product cannot be deleted while referenced (E-28) | `combo_components.product_id` **restrict** | `DeleteProduct` check with combo name |
| Values, attributes, categories, locations are never deleted | FKs **restrict** toward them; no delete route exists | — |

CHECK constraints are added with `DB::statement('ALTER TABLE … ADD CONSTRAINT … CHECK (…)')` in the migration (MySQL-only, like the test database). Product aggregate rows (attributes, allowed values, details, customizations, combinations and their values, codes, overrides, templates) use `cascadeOnDelete()` toward their owner, but `DeleteProduct` / `DeleteCombination` still check, copy and audit first (Decision 17).

**Collation**: name and code columns use `utf8mb4_0900_as_ci` (case-insensitive, accent-sensitive). This is the literal DEC-PRD-45 / DT-01 rule; the table default `utf8mb4_unicode_ci` would also merge names that differ only by an accent, which no decision states. The list search expression applies `COLLATE utf8mb4_unicode_ci` so searching stays accent-insensitive, as in 002.

**Rule for later specs (recorded, not implemented)**: history tables of 004/006/008 reference `combinations.id` (and `combos.id`) with `restrictOnDelete()` and add their condition to the `ensureHasNoHistory()` hooks (PRD-014, DEC-PRD-21, spec §10).
**Alternatives considered**: separate axis and restriction tables (rejected: the role is fixed per product attribute while combinations exist, DEC-PRD-41, so one `combination_values` table plus the product attribute role is enough, and "value in use" becomes one query, DEC-PRD-37). MySQL triggers for overlap (rejected by DEC-PRD-39).
**Rationale**: spec §5, §12, DEC-PRD-39, constitution §11.

### Decision 4: DT-01 confirmed — code registry, text codes, case-insensitive

**Choice**: DT-01 is **confirmed**. `catalog_codes(id, code varchar(30) utf8mb4_0900_as_ci UNIQUE, combination_id NULL UNIQUE FK cascade, combo_id NULL UNIQUE FK cascade, CHECK ((combination_id IS NULL) <> (combo_id IS NULL)))`. The code is stored exactly as typed after trimming outer whitespace (E-11: `088-1`, `001RN` keep zeros and suffixes) and compared case-insensitively by the collation (`001rn` collides with `001RN`; `088-1` ≠ `88`). Validation: required, string, 1–30 characters, no internal whitespace (`regex:/^\S+$/`). `Combination` and `Combo` expose `code` through a `hasOne(CatalogCode)` relation and an accessor; the create/update Actions write the registry row in the same transaction.
**Alternatives considered**: (B) a code column on each table plus an application check (not database-guaranteed across tables; fails E-08's second clause). (C) a `sellables` supertype table (rewrites the model for one constraint).
**Rationale**: DEC-PRD-01, DEC-PRD-02, DEC-PRD-14, E-08, E-11.

### Decision 5: DEC-PRD-39 — overlap enforcement

**Choice**:
- `App\Support\Products\CombinationOverlap::firstOverlap(array $candidateAxes, iterable $others): ?int` — pure. Two combinations overlap when, for **every** axis attribute of the product, their value sets intersect. A product without axes therefore allows one active combination (vacuous truth); see Open Questions note N-2.
- `AxisSignature::of(array $axes): string` — SHA-256 of the sorted `attributeId:valueId` list; `combinations.axis_signature` is written by the Actions; `active_signature` is `STORED AS IF(status='active', axis_signature, NULL)` with unique `(product_id, active_signature)`.
- Every write that can create an overlap runs inside `DB::transaction` and first takes `Product::query()->lockForUpdate()->findOrFail($productId)`: create, update and **activate** combination (DEC-PRD-40, E-59). `SyncProductAttributes` takes the same lock, so structure changes and combination writes serialize per product.
- Error: `ValidationException` on `axes` ("Coincide con la combinación activa «110» en todos sus ejes.") for create/update; `BusinessRuleViolation` with the same text for activation (body-less endpoint).
- The importer runs the same pure class over the in-memory rows of each product.
**Alternatives considered**: one SQL query with `GROUP BY combination_id HAVING COUNT(DISTINCT attribute) = :axes` (equivalent, but not reusable by the importer and unit tests; the in-memory set per product is tens to hundreds of rows).
**Rationale**: DEC-PRD-39, DEC-PRD-40, E-09, E-48, E-59; spec §12 DoD wording.

### Decision 6: Permissions and audit catalog

**Choice**:
- `PermissionName` gains `ProductsView`, `ProductsCreate`, `ProductsUpdate`, `ProductsDeactivate`, `ProductsDelete`, `ProductsCatalog` (16 → 22) with the spec §4 Spanish descriptions. `PermissionCatalogSeeder` is unchanged.
- `InitialRolePermissions::MATRIX` gains the §4 table (Interfaces). The existing created-in-this-run rule applies it to fresh **and existing** databases on the next `FoundationSeeder` run, and never re-grants a later revocation (002 Decision 6, unchanged mechanism).
- `AuditAction` gains 24 cases (24 → 48; Interfaces). Catalog entities share five generic `catalog.*` cases because the audit screen already distinguishes the entity by `class_basename(entity_type)` (`AuditLogController:79-80`); products, combinations and combos keep per-entity cases like 001/002.
**Alternatives considered**: one audit case per catalog entity and event (≈17 cases for four entities with identical semantics). A "reordered" event (rejected: reordering is an edit; each moved row gets a `catalog.updated` row with its `sort_order`).
**Rationale**: spec §4, PRD-017, FND-017, FND-022.

### Decision 7: DEC-PRD-23 coherence

**Choice**: `SyncRolePermissions::ensureCoherent()` gains a third block after the `customers.*` one: if any of `products.create`, `products.update`, `products.deactivate`, `products.delete`, `products.catalog` is submitted without `products.view`, add "Los permisos de productos requieren también «Ver categorías, atributos, productos, combinaciones y combos»." to the same `ValidationException` on `permissions`. It runs inside the transaction before any write and before the "nothing changed" early return, so the role never changes and nothing is audited (E-33). The class docblock cites DEC-PRD-23. The initial matrix complies.
**Rationale**: DEC-PRD-23, E-33; same placement as DEC-022 and DEC-CLI-32.

### Decision 8: Authorization

**Choice**: permission-only policies; no role names, no `Gate::before`, no `abort(403)` (`NoManualForbiddenTest` guards `app/`).

| Policy | Ability | Permission |
|---|---|---|
| `ProductPolicy` (auto-discovered) | `viewAny`, `view` | `products.view` |
| | `create` | `products.create` |
| | `update` (incl. structure, details, customizations, stock parameters, main image, templates) | `products.update` |
| | `deactivate` (activate and deactivate) | `products.deactivate` |
| | `delete` | `products.delete` |
| `CombinationPolicy` (auto-discovered) | `create` / `update` / `deactivate` / `delete` | `products.create` / `.update` / `.deactivate` / `.delete` |
| `ComboPolicy` (auto-discovered) | `viewAny`, `view`, `create`, `update`, `deactivate`, `delete` | same mapping as products |
| `CatalogPolicy` (registered with `Gate::policy()` in `AppServiceProvider` for `ProductCategory`, `CatalogAttribute`, `AttributeValue`, `DetailLocation`) | `manage` (screens, create, edit, order, status, offered colors, images, layers) | `products.catalog` |
| | `viewAsset` (serve a value/location image) | `products.view` |

Spec §8 makes the attribute catalog screen visible with `products.catalog`; `products.view` users still see categories, attributes and values inside product pages, list filters and resolution output (spec §4 "Ver categorías, atributos…"). E-35 needs both `products.update` (product image) and `products.catalog` (value image); E-42 checks `products.update` on product image and template routes.
**Rationale**: PRD-016, PRD-020, spec §4, §8, AGENTS.md §7.2–§7.3.

### Decision 9: Catalog Actions (categories, attributes, values, detail locations)

**Choice** (`app/Actions/Products/`, each in `DB::transaction`, audited):
- `CreateCategory`, `UpdateCategory` (name); `CreateCatalogAttribute(name, presentation, special_use)`, `UpdateCatalogAttribute`; `CreateAttributeValue(attribute, name, description, tone, svg_layer)`, `UpdateAttributeValue`; `CreateDetailLocation(name, svg_layer)`, `UpdateDetailLocation`.
- `MoveCatalogItem::handle(Model $item, 'up'|'down', User)`: locks the item and its neighbor in the same scope (categories; attributes; values of one attribute), swaps `sort_order`, audits `catalog.updated` for both. New rows get `max(sort_order) + 1`. Detail locations have no order in the spec and are listed by name.
- `ActivateCatalogItem` / `DeactivateCatalogItem`: same no-op rule as 001/002 (already in state → no write, no audit).
- `SyncFabricOfferedColors::handle(AttributeValue $fabricValue, list<int> $colorIds, User)`: the value must belong to the fabric attribute; added colors must be **active** values of the color attribute (PRD-002); removed colors only affect new selections; audits `catalog.fabric_colors_updated` with added/removed names (E-46).
- Validation highlights (rules class `CatalogRules`, shared with the importer):
  - tone: required when the attribute presentation is `color`, prohibited otherwise; `regex:/^#[0-9A-Fa-f]{6}$/`; stored uppercase (E-36).
  - `special_use` is one of `fabric` | `size` | `gender` | null (DEC-PRD-49), editable with `products.catalog`. `special_use = fabric` requires presentation ≠ `color` (a fabric whose offered colors come from itself is incoherent with DEC-PRD-32).
  - a second attribute with the same special use, or a second color-presentation attribute (create or change) → error on `special_use` / `presentation` (E-45, E-58); the unique indexes are the backstop.
  - changing an attribute's presentation **to** `color` requires every value to have a valid tone (PRD-002 rejects color values without tone).
  - `svg_layer`: nullable, `regex:/^[a-z0-9]+(-[a-z0-9]+)*$/`, max 64; the reserved layer names `cuerpo` and `sombras` (PRD-020 preview semantics) are rejected.
- **Attributes in use (DEC-PRD-51, E-69)**, resolved with `App\Support\Products\CatalogUsage` (queries only, returns names for the error message):
  - `UpdateCatalogAttribute`: changing `presentation` or `special_use` (assigning, changing or removing) is rejected while `CatalogUsage::productsDeclaring($attribute)` (any product, any status) is not empty; the error on the field names the products.
  - `DeactivateCatalogItem` for an attribute: rejected while an **active** product declares it, naming them. An inactive attribute is not selectable for new declarations (`SyncProductAttributes`, Decision 11); a product that still declares it is not selectable (Decision 14, rule 1). Reactivating the attribute restores selectability, because nothing is cascaded (Decision 16).
  - The guard needs `product_attributes`, created in slice 9. Slice 4 ships the call site as an empty `ensureNotDeclaredByProducts()` hook (like `ensureHasNoHistory()`) and slice 9 fills it with E-69.
- **Layer guard (DEC-PRD-53, E-72)**: `CreateAttributeValue` / `UpdateAttributeValue` / `UpdateDetailLocation` reject assigning or changing `svg_layer` to a non-null layer when `CatalogUsage::productsMissingLayer(entity, layer)` is not empty. That query takes the products that admit the value (`product_attribute_values`) or the location (`product_detail_locations`) and have at least one `product_templates` row whose `layers` JSON lacks the layer (pure check `TemplateLayerRequirements::missing()`, the same class `UploadProductTemplate` uses). The error on `svg_layer` names the products. Clearing the layer is allowed. Products without templates are not affected. The hook exists in slices 4–5 and is filled in slice 22, when templates exist.
- A value rename is a normal `catalog.updated` with old/new `name`; combinations reference values by id, so the new name appears everywhere (PRD-002).
**Rationale**: PRD-001, PRD-002, PRD-007, DEC-PRD-17, DEC-PRD-32, DEC-PRD-38, DEC-PRD-49, DEC-PRD-51, DEC-PRD-53, E-36, E-45, E-46, E-58, E-64, E-69, E-72.

### Decision 10: Product create and update

**Choice**:
- `CreateProduct::handle(array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): Product`: name, category (must be active when chosen), business line, supply mode, optional description, `portal_visible` (default true, DEC-PRD-19), `min_stock_default` (required integer 0–9999 in `stock_with_minimum`, prohibited otherwise; the form proposes 6, DEC-PRD-46), `allows_custom_color` (prohibited unless `on_demand`; the form defaults it to true for `on_demand`, DEC-PRD-34). Created `active`; audit `products.created` (E-01, E-02, E-20, E-64).
- `UpdateProduct::handle(Product, array $data, User)`: locks the product; same rules; **detail locations** (`detail_location_ids`, active locations when added) and **admitted customizations** (`customization_ids`: products in mode `service`, active when added, never the product itself) are part of the general edit payload with full-replace semantics. Mode change away from `stock_with_minimum` sets `min_stock_default = null` and deletes every `StockMinimumOverride` of its combinations in the same transaction; mode change away from `on_demand` sets `allows_custom_color = false`; the audit row carries the previous default and the deleted overrides (E-66). Returning to `stock_with_minimum` requires a new default and finds no overrides (DEC-PRD-46). Changed-fields-only audit `products.updated`; nothing changed → no audit (E-68).
- Custom color is **effective** only when `supply_mode = on_demand`, `allows_custom_color = true` and the product declares the color attribute; the effective flag is computed (`Product::admitsCustomColor()`), never stored.
- PRD-020 guard on detail locations: adding a location whose `svg_layer` is missing from any template of the product is rejected with the missing layer (spec PRD-020 last bullet).
- **Cross-record edit guards (DEC-PRD-52, E-70)**, inside the same locked transaction, before any write, using `CatalogUsage`:
  - changing `business_line`, or `supply_mode` to `service`, of a product that is a `combo_components.product_id` → `ValidationException` on the changed field naming the combos ("Es componente de los combos «…»").
  - changing `supply_mode` **away from** `service` while the product appears in `product_customizations.service_product_id` (admitted by other products) or `combination_customizations.service_product_id` (included by combinations) → `ValidationException` on `supply_mode` naming those products and each combination code with its product, e.g. "110-4 (Camisa corporativa)" (DEC-PRD-57). `DeleteProduct` applies the same lookup (Decision 17).
  - Combo and customization tables arrive in slices 13 and 11, so slice 8 ships both guards as empty hooks that those slices fill.
  - Removing a size from a product's allowed values is not an `UpdateProduct` edit; it goes through `SyncProductAttributes` (Decision 11).
**Rationale**: PRD-003, PRD-007, PRD-008, PRD-009, PRD-012, DEC-PRD-08, DEC-PRD-19, DEC-PRD-34, DEC-PRD-46, DEC-PRD-47, DEC-PRD-52, E-01, E-02, E-20, E-64, E-66, E-68, E-70.

### Decision 11: Product structure — `SyncProductAttributes`

**Choice**: one endpoint replaces the whole structure, so the UI edits it as a list: `PUT /products/{product}/attributes` with `attributes: [{attribute_id, role, allowed_value_ids[]}]` in display order (axis order = relative order of the axis entries, PRD-004 / PRD-019). In one transaction with the product lock:
1. Each attribute once (E-06, plus the DB unique); a **newly declared** attribute must be active (an inactive attribute cannot be declared, DEC-PRD-51, E-69) and newly added allowed values must be active (PRD-004 "al menos un valor activo").
2. Allowed values belong to the attribute; at least one active allowed value per attribute, **except** the color attribute of a product that declares the fabric attribute, which must have **no** allowed values (DEC-PRD-35, E-05). A product with color and without fabric needs its own list (PRD-004).
3. **Role rule (DEC-PRD-50)**: the attribute with special use `fabric`, if declared, must have role `axis`; the attribute with presentation `color`, if declared, must have role `order`. Any other role → `ValidationException` on `attributes.{i}.role`. `ProductRules::roleViolation()` is pure and shared with the importer. This also covers the earlier structural consequence of DEC-PRD-35 (a color without allowed values cannot be an axis).
4. **Freeze while combinations exist** (any status, DEC-PRD-41, E-60): no new axis, no role change, no axis removal → `ValidationException` on `attributes` ("El producto tiene combinaciones: para cambiar sus ejes, cree un producto nuevo y desactive el anterior."). Adding/removing order attributes and reordering axes stay allowed.
5. **In-use guard** (PRD-004, DEC-PRD-37, E-24, E-57): removing an allowed value (or a whole order attribute) that appears in `combination_values` of this product or in `combo_component_values` for this product is rejected, naming the combination codes / combo names.
6. **Layer guard** (PRD-020, E-40): a newly allowed value whose `svg_layer` is missing from any template of the product is rejected, naming the layer (`TemplateLayerRequirements::missing()`).
7. **Size removal cleanup (DEC-PRD-52, E-71)**: after guards 4–6 pass, values removed from the product's allowed values of the attribute with special use `size` delete the `stock_minimum_overrides` rows of this product's combinations keyed by those values, in the same transaction. A size still used by a combination restriction was already rejected in step 5 (DEC-PRD-37). An empty `ensureNoStockForRemovedSizes()` hook is called before the deletion; `008` fills it to block removal while stock exists (§10 of the spec).
8. Audit `products.attributes_updated` with the full before/after structure (attribute names, roles, value names); `old_values` also carries `stock_minimum_overrides` (the deleted `{code, size, minimum}` rows) when step 7 deleted any.
**Alternatives considered**: per-attribute endpoints (more routes and partial states; the freeze and in-use rules are easier to reason about on a whole-structure diff).
**Rationale**: PRD-004, PRD-012, PRD-020, DEC-PRD-35, DEC-PRD-37, DEC-PRD-41, DEC-PRD-50, DEC-PRD-51, DEC-PRD-52, E-05, E-06, E-24, E-40, E-57, E-60, E-69, E-71.

### Decision 12: Combinations

**Choice**:
- `CreateCombination::handle(Product, array $data, User, ?AuditOrigin, array $auditContext): Combination` and `UpdateCombination::handle(Combination, array $data, User)`; payload `code`, `description` (nullable, max 255), `axes: {attributeId: [valueIds]}`, `restrictions: {attributeId: [valueIds]}`, `included_customization_ids: []`.
- Rules (`CombinationRules` + Action checks, product locked):
  - every axis of the product has ≥ 1 value, each among the product's allowed values for that axis (E-10 → `axes.{attributeId}`); several values per axis allowed (DEC-PRD-33, E-47);
  - `restrictions` only for order attributes, each a non-empty subset of the product's allowed values (E-56 → `restrictions.{attributeId}`); not allowed for the color of a product with fabric (DEC-PRD-35/36);
  - included customizations: products in mode `service`, independent of the admitted ones (DEC-PRD-47, E-67). A customization being **added** (every one on create; only those not already included on update) must also be active; ones already included in the combination are kept even if the service was deactivated later (DEC-PRD-56);
  - code (Decision 4) and overlap (Decision 5).
- New combinations are `active`. `UpdateCombination` keeps the private `ensureEditableWithoutHistory()` hook (empty in 003; 004/006/008 make code and axis values immutable with history, DEC-PRD-21).
- **Duplicate** (spec §8): `GET /products/{product}/combinations/create?from={combination}` pre-fills axes, restrictions, description and included customizations with an empty code; saving is a normal create.
- Descriptive name (spec §5): `ProductPresenter::descriptiveName(Product, axes)` → product name + axis values in axis order joined by " · ", several values joined by " o " (E-07).
- Audit `products.combination_created` / `_updated` with code, description, axes and restrictions by name.
**Rationale**: PRD-005, PRD-008, PRD-012, DEC-PRD-04, DEC-PRD-21, DEC-PRD-33, DEC-PRD-36, DEC-PRD-47, DEC-PRD-56, E-07..E-11, E-47, E-48, E-54..E-56, E-67, E-68.

### Decision 13: Minimum stock without the 008 stock-item entity

**Choice**: `products.min_stock_default` (Decision 10) plus `stock_minimum_overrides(id, combination_id FK cascade, size_value_id NULL FK restrict → attribute_values, minimum smallint unsigned, size_key STORED AS IFNULL(size_value_id, 0), UNIQUE(combination_id, size_key))`. A stock article is the combination, plus the size value when the product declares, as an order attribute, the attribute whose special use is `size` (DEC-PRD-10, DEC-PRD-49); `size_value_id` references a value of that attribute. `StockMinimum::for(Combination $combination, ?int $sizeValueId): ?int` returns the override or the product default (E-65). `SyncStockMinimumOverrides::handle(Product, list<{combination_id, size_value_id, minimum}>, User)` replaces the set (product in `stock_with_minimum` only; when the product declares the size-use attribute as an order attribute, `size_value_id` is required and must be one of its allowed values and inside the combination restriction, otherwise it must be null), audit `products.stock_minimums_updated`. Overrides are deleted automatically when a size leaves the product's allowed values (Decision 11, step 7, E-71) and on mode change (Decision 10). 008 will migrate these rows to its stock items keyed the same way.
**Recognition of "Talla"**: the attribute with special use `size` (DEC-PRD-49, formerly P-1); no name matching.
**Rationale**: DEC-PRD-10, DEC-PRD-46, DEC-PRD-49, DEC-PRD-52, E-65, E-66, E-71.

### Decision 14: DT-02 confirmed — one selection engine for PRD-011 and PRD-019

**Choice**: DT-02 is **confirmed**, with the engine split into a loader and pure rules:
- `App\Support\Products\Selection\CatalogSnapshotLoader::forProduct(int): ?ProductSnapshot` and `forProducts(list<int>): array<int, ProductSnapshot>` (combos). **Constant query count** (≤ 10, independent of the number of combinations): product + category; product attributes + allowed values; attribute values used by the product (with attribute data); active combinations + codes; `combination_values`; included customizations; fabric offered colors for the allowed fabrics; admitted detail locations; admitted customizations; templates. The palette (active values of the color attribute, for detail colors) is one extra query.
- `ProductSnapshot` and its children are `readonly` DTOs (Interfaces). Only **active** combinations are loaded; availability flags (product, category, values, locations, service products) are data in the snapshot.
- `SelectionRules` (pure, unit-tested) implements: `reachableCombinations(snapshot, chosenAxes, ?componentRestriction)` (active combination compatible with the chosen axes and with at least one active, allowed and component-admitted value on every axis), `nextAxisOptions(...)`, `orderOptions(snapshot, combination, ?componentRestriction)`, and `validate(snapshot, selection, ?componentRestriction): ResolvedProductSelection|ErrorBag`.
- `ResolveSelection::handle(array $input): ResolvedSelection` (Action, DT-02 name kept): throws `ValidationException` with per-field keys when invalid (spec: "devuelve errores por campo"), so 004/005/006 controllers can return it unchanged. Rules in PRD-011 order:
  1. product (or combo) active, category active, and every attribute the product declares active (DEC-PRD-51; for a combo, of every component's product) → otherwise `product` / `combo` error "La selección no está disponible." (E-17);
  2. each axis and order attribute has an allowed, active value (E-16 → `order.{attributeId}`); color: offered by the chosen fabric value (E-43, E-46, E-47) or allowed by the product (E-44); `custom` only when `Product::admitsCustomColor()` and `custom_color.tone` is a valid hex; note optional, max 100 chars (E-49, E-50) — errors on `order.{colorAttributeId}`;
  3. exactly one active combination whose axis values include the chosen ones (E-14, E-15, E-17, E-24, E-25); normalized selection keeps the chosen value per axis; order values inside the combination restriction (E-54) and, in a combo, inside the component restriction (E-63);
  4. each detail location admitted and active, its color an active palette value; `custom` rejected on details (E-19, E-50);
  5. each extra customization admitted by the product and active (E-34, E-67); included customizations of the combination are returned separately;
  6. combo: active combo; each component resolved with rules 1–5 plus its subset; an axis or order attribute left with exactly one admitted active value is applied automatically (PRD-010); errors prefixed `components.{componentId}.` (E-23, E-63).
- `ListSelectionOptions::handle(array $input): SelectionOptions` (PRD-019): input `product_id` or `combo_id` + `component_id`, and chosen axes, which must be a prefix of the axis order (otherwise `ValidationException`). Missing axes → the next axis with its reachable values (E-51, E-53). All axes chosen → code, combination id, order options (colors with tone and `custom` flag; other order attributes after product, combination and component restrictions — E-52, E-54, E-55, E-63), admitted detail locations with the palette, admitted customizations, and the matching template for the preview (E-41: the variant of the chosen value of the product's gender-use attribute, whether axis or order, DEC-PRD-49; a product with only a general template uses it). A product with an inactive declared attribute is not offered (DEC-PRD-51). Each option carries id, name, sort order, description, image URLs, tone and layer.
- No price, cost or stock class is referenced (an architecture test asserts `app/Support/Products/Selection` and the two Actions import nothing from future pricing/stock namespaces and no stock tables are queried).
- **No HTTP route in 003**: no 003 screen needs resolution; 004/005/006 add their own authorized controllers over these Actions. Tests call the Actions directly.
**Alternatives considered**: separate implementations for options and validation (rejected: DT-02 and PRD-019 "mismos datos y criterios"). An Eloquent-heavy resolver (N+1 risk; harder to share with the importer).
**Rationale**: PRD-010, PRD-011, PRD-019, DT-02, DEC-PRD-31, DEC-PRD-33..36, DEC-PRD-44.

### Decision 15: Combos

**Choice**: `combos(id, name as_ci unique, portal_visible default true, status)`, code in `catalog_codes`; `combo_components(id, combo_id cascade, product_id restrict, quantity smallint unsigned, sort_order)`; `combo_component_values(combo_component_id cascade, catalog_attribute_id, attribute_value_id)` — no rows for an attribute = no restriction (DEC-PRD-44). `CreateCombo` / `UpdateCombo` (components full-replace), rules:
- ≥ 1 component; product in line `diapers` (DEC-PRD-43, E-62 → `components.{i}.product_id`), mode ≠ `service` (E-22), quantity integer 1–999 (E-22);
- restriction attributes declared by the product (axis or order); values among the product's allowed values; for the color of a product with fabric, among the colors offered by its allowed fabric values (E-22 → `components.{i}.values.{attributeId}`);
- code (Decision 4) and case-insensitive unique name (E-64).
The combo stores no business line (DEC-PRD-43). The same product may appear in two components; the spec does not forbid it (note N-3). A combo with a component that cannot reach an active combination is simply not offered (computed by the engine).
**Rationale**: PRD-010, DEC-PRD-12..14, DEC-PRD-43..45, E-21, E-22, E-62, E-64.

### Decision 16: Lifecycle

**Choice**: `ActivateProduct` / `DeactivateProduct`, `ActivateCombination` / `DeactivateCombination` (activation runs the overlap check under the product lock, E-59), `ActivateCombo` / `DeactivateCombo`: lock, no-op without audit when already in state, update `status`, audit `{status}` before/after. Deactivating a product never changes its combinations' `status`; reactivating it restores each combination's own state because nothing was cascaded (E-25). Deactivation uses `ConfirmDialog` in the UI (spec PRD-013); reactivation does not. Attribute lifecycle follows the same computed-availability rule: deactivating an attribute is allowed only when no active product declares it (Decision 9), and an inactive attribute simply makes the products that still declare it unselectable (Decision 14, rule 1); no row is cascaded and reactivating the attribute restores them. Reactivating a product that declares an inactive attribute is not rejected by the spec; the product stays unselectable (note N-7).
**Rationale**: PRD-013, DEC-PRD-40, E-25, E-26, E-59.

### Decision 17: Restricted delete

**Choice**:
- `DeleteCombination`: lock product; `ComboMembership::includes(component, combinationAxes, productAxisIds)` (pure: for every axis, unrestricted **or** shares a value with the component subset, DEC-PRD-42) against every component of its product → `BusinessRuleViolation` "La combinación forma parte del combo «…». Puede desactivarla." (E-61); empty `ensureHasNoHistory()`; copy (code, description, axes, restrictions, included customizations, overrides); delete (registry row and values cascade); audit `products.combination_deleted`.
- `DeleteProduct`: lock; any `combo_components.product_id = product` → violation naming the combo and suggesting deactivation (E-28); a product in mode `service` that other products admit (`product_customizations`) or combinations include (`combination_customizations`) → violation naming those products and combination codes (DEC-PRD-52, E-70, via `CatalogUsage::productsUsingService()`); empty history hook; copy of the whole aggregate (product fields, structure, details, customizations, combinations with codes, overrides, template file names); delete; audit `products.deleted` (E-27). Stored files are deleted with `DB::afterCommit()`.
- `DeleteCombo`: empty history hook; copy; delete; audit `products.combo_deleted`.
- E-29 is a Pest `->todo('E-29 … se prueba en 004, 006 y 008')`, as E-19 in 002.
- The `restrict` FKs on `product_customizations.service_product_id` and `combination_customizations.service_product_id` remain the database backstop; the Action check above produces the friendly error first.
**Rationale**: PRD-014, DEC-PRD-21, DEC-PRD-42, DEC-PRD-52, E-27, E-28, E-29, E-61, E-70, AGENTS.md §7.9.

### Decision 18: DT-03 confirmed — images and SVG templates

**Choice**: DT-03 is **confirmed**.
- **Storage**: the private `local` disk (`storage/app/private`, `serve = false`, FND-026) under `catalog/products/{id}/`, `catalog/values/{id}/`, `catalog/locations/{id}/`, `catalog/templates/{productId}/`, with random ULID file names. Paths are stored on the owning row (`image_display_path`, `image_thumb_path`; templates `svg_path`).
- **Serving** (`CatalogAssetController`, `ProductAssetController`): authorized with `products.view`; responses stream from the disk with `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=86400`, and the stored content type. SVG adds `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:` and is rendered in the ERP only through `<img src>` (scripts never run in an image context; never `v-html`). 005 will add its public routes to `PublicRoutes` for portal-visible assets.
- **Images** (`ImageProcessor`, native GD; `ext-gd` declared in `composer.json`): accept JPG, PNG, WebP by **decoding** (`getimagesize` header check first, then `imagecreatefromstring`), never by extension; reject other types (E-35 PDF). Bounded **before** decoding to avoid decompression bombs. Two re-encoded WebP variants (EXIF and any appended payload are dropped by re-encoding): `display` (long side ≤ 1200 px, quality 82) and `thumb` (long side ≤ 320 px, quality 80); alpha preserved. The original upload is not kept.
- **SVG** (`SvgTemplateSanitizer`): pre-checks (≤ 2 MB, valid UTF-8 XML, no `<!DOCTYPE` / `<!ENTITY` → rejects XXE and entity expansion), then `enshrined\svgSanitize\Sanitizer` with `removeRemoteReferences(true)` and a project `AllowedTags` that excludes `script` and `foreignObject`; then a DOM post-pass (`LIBXML_NONET`, no entity substitution) that removes any `on*` attribute, any `href`/`xlink:href` not starting with `#`, any `<style>` text or `style` attribute containing `@import` or a non-fragment `url(`, and fails closed if the document root is not `<svg>`. `SvgLayerDetector` reads every `<g>` with an `id` **from the sanitized bytes** (E-39) and stores them as a sorted JSON list.
- **Template rules** (`UploadProductTemplate`): requires layer `cuerpo`, the layer of every allowed value with `svg_layer` and of every admitted detail location (E-37, E-38 → error lists missing layers); general template **or** one per allowed value of the attribute with special use `gender` (DEC-PRD-49; a variant for a value the product does not allow, or for a product without that attribute, is rejected, E-41); the required-layer computation is the pure `TemplateLayerRequirements`, shared with the structure guard (Decision 11) and the catalog layer guard (Decision 9, DEC-PRD-53); a new upload for the same variant replaces the previous one (old file deleted after commit). `RemoveProductTemplate` deletes one. Audits `products.template_uploaded` / `products.template_removed` (file name, variant, layers).- **File/transaction ordering**: the new file is written first; the DB transaction stores its path; on any exception the new file is deleted; the replaced file is deleted with `DB::afterCommit()`. A crash between write and commit can leave an orphan file in a private directory (accepted; never served because no row points to it).
- **Dependency**: `enshrined/svg-sanitize` pinned to an exact version in `composer.json` (the exploration observed 1.0.0 on 2026-10-06; the apply phase pins the current release, checks `composer audit` and records the version). Owner-approved (GPL-2.0-or-later, internal use, no distribution).
**Alternatives considered**: public disk with unguessable names (leaks images of products that are not portal-visible, before 005 defines visibility). `intervention/image` (MIT; new unapproved dependency for what GD covers). Own regex sanitizer (forbidden by DT-03).
**Rationale**: PRD-020, DT-03, DEC-PRD-20, DEC-PRD-29, DEC-PRD-49, DEC-PRD-53, E-35, E-37..E-42, E-72, FND-026.

### Decision 19: PRD-018 initial load

**Choice**:
- Command `catalog:import {--combinations=} {--combos=} {--colors=} {--author=}` (thin; prints `Fila | Columna | Motivo` with the file name; never prints cell values). All three options are required; `--combos` and `--colors` may contain only a header.
- `App\Support\Csv\CsvReader` is extracted from `CustomerCsvReader` (columns passed as a parameter; same BOM, UTF-8, `,`/`;` detection, header and multiline handling; `allowEmpty` flag). `CustomerCsvReader` keeps its public API and `InvalidCustomerCsv` by delegating, so 002 tests stay green unchanged.
- **Phase 0**: author must be an existing active internal user (as 002); the catalog must be empty (no category, attribute, product or combo) — a one-time load on a clean catalog; files readable with exact headers.
- **Phase 1** (in memory, `Import\CatalogImportPlan`): parse and validate every row with `CatalogRules`, `ProductRules`, `CombinationRules`, `ComboRules` and the pure `CombinationOverlap`; cross-row checks: repeated codes across both files (case-insensitive, DEC-PRD-02), repeated product names, same product with different category/line/mode/axis order, Tela listed in `pedido` or Color listed in `ejes` (role rule, DEC-PRD-50, via `ProductRules::roleViolation()`), disagreeing `stock_minimo` (DEC-PRD-48: the later row reports "Difiere del stock mínimo de la fila N"), colors without tone, fabrics in the colors file that do not exist as Tela values in the combinations file (PRD-018), restriction subsets.
- **Phase 2**: one `DB::transaction`, creating through the real Actions with `AuditOrigin::console('catalog:import')` and `auditContext: ['import' => true, 'import_file' => 'combinaciones'|'combos'|'colores', 'import_row' => N]`: categories → attributes (Tela with `special_use = fabric`, Género with `gender`, Talla with `size` as the initial load assigns the uses, DEC-PRD-49; Modelo, Manga and any other named attribute as `text` without use; Color as `color` from the colors file) → values (order of first appearance) → palette → offered colors → products → structure (allowed axis values = union of the product's rows; order allowed values = union; Color declared without values when the product declares Tela) → combinations (a row whose order values are a strict subset of the product's union becomes that combination's restriction, DEC-PRD-36) → combos. Any exception rolls everything back and is reported.
- Column definitions: Interfaces → CSV files. Real files are never versioned; documented location `storage/app/private/imports/` (as 002). Tests build fictitious files at runtime.
**Rationale**: PRD-018, DEC-PRD-24, DEC-PRD-36, DEC-PRD-46, DEC-PRD-48, E-31, E-32, CLI-018 pattern.

### Decision 20: Frontend

**Choice**:
- Pages (TypeScript, Wayfinder helpers for every URL): `resources/js/pages/products/{Index,Show,Create,Edit,Structure}.vue`, `products/combinations/{Create,Edit}.vue`, `combos/{Index,Show,Create,Edit}.vue`, `catalog/{Categories,Attributes,AttributeShow,DetailLocations}.vue`. Shared forms in `resources/js/components/products/` (`ProductForm`, `StructureEditor`, `CombinationForm`, `ComboForm`, `ImageUploader`, `TemplateUploader`) and `components/catalog/` (`ValueForm`, `OfferedColorsEditor`). Types in `resources/js/types/products.ts`.
- New generic components (no new npm dependency): `ColorPicker.vue` (native `<input type="color">` + hex text field + swatch; emits `#RRGGBB`; 44 px targets; the tone is applied as an inline style, which spec §8 allows) and `AppCheckboxGroup.vue` (labelled checkbox list with optional filter; used for allowed values, axis values, restrictions, offered colors, details, customizations, component values). `docs/ui/design-system.md` §7 gains both subsections and their checklist items.
- Index pages follow 002: `SegmentedTabs` (Activos / Inactivos / Todos, backend counts), search `AppInput` with icon, `AppSelect` filters (category, line, mode), `DataTable` + `AppPagination`, `StatusBadge` (active → `done`, inactive → `neutral`). Show page: `AppCard` sections in spec §8 order with the "Admite color personalizado" indicator. Delete rejection shows the backend reason and offers "Desactivar" (spec §8).
- Ordering uses up/down `IconButton`s calling the move endpoint (accessible; no drag-and-drop library).
- Uploads use Inertia `useForm` with `forceFormData`; endpoints are `POST`, so no method spoofing is needed.
- Navigation (`resources/js/navigation.ts`, group `Comercial`): "Productos" (`inventory_2`, `products.view`, priority 20), "Combos" (`deployed_code`, `products.view`, priority 25), "Catálogo" (`category`, `products.catalog`, priority 30). The bottom bar keeps its 4-destination cap (design system §7.8): Inicio, Clientes, Productos, Combos; Catálogo goes to the "Más" sheet. No change to `useNavigation.ts`.
**Rationale**: spec §8, design system §7, §11, constitution §14.

### Decision 21: Tests updated in 001/002, and why

Requirements do not change; only literals that encode the previous catalog size or the "non-customers = 001" assumption.

| Test | Current assertion | Change | Reason |
|---|---|---|---|
| `tests/Unit/PermissionNameTest.php` | 16 cases | 22, list extended with `products.*` | catalog grows (FND-017) |
| `tests/Unit/AuditActionTest.php` | 24 cases / 24 labels | 48, list extended | PRD-017 (FND-022 allows it) |
| `tests/Feature/FoundationSeederTest.php` `foundationPermissionNames()` | excludes `customers.` only | also exclude `products.` | otherwise the "9 permissions of 001" assertions count `products.*` |
| `tests/Feature/Customers/PermissionMatrixSeedingTest.php:50-53` | same filter | also exclude `products.` | same |
| `tests/Feature/Roles/PermissionCatalogTest.php:43-67` | Administrador = 001 + customers set; others = customers set | add each role's `products.*` set from §4 | §4 matrix is a FND-017 "unless the spec states otherwise" case |
| `tests/Pest.php` `administrator()` docblock | "every permission except customers.portfolio" | unchanged wording still true; mention products.* | documentation accuracy |

Any other literal found while running the suite in slice 1 is updated the same way and recorded in `tasks.md`.

## Data Flow

Combination create (web):

```
POST /products/{product}/combinations
  StoreCombinationRequest  authorize: can('create', Combination) ── denied → 403 audited
                           rules: CombinationRules (code, axes, restrictions, customizations)
  CreateCombination
    DB::transaction {
      lock products row (lockForUpdate)                ← serializes overlap-sensitive writes
      check axes ⊆ allowed, restrictions ⊆ allowed (order attrs only)
      CombinationOverlap::firstOverlap(candidate, other active)  ─overlap→ ValidationException(axes)
      insert combinations (axis_signature) + combination_values + catalog_codes
         └ duplicate code / exact duplicate → UniqueConstraintViolation → ValidationException
      RecordAuditEvent(products.combination_created)
    }
  → 302 products.show (flash success)
```

Resolution and options (called by 004/005/006 later; by tests in 003):

```
ResolveSelection(input) / ListSelectionOptions(input)
  CatalogSnapshotLoader ── ≤10 queries ──→ ProductSnapshot (readonly, active combinations only)
  SelectionRules (pure)
     options:  reachableCombinations → next axis values | order options + template
     resolve:  rules 1..6 → ResolvedSelection | ValidationException(field keys)
  (no price, cost or stock access)
```

Seeding (fresh or existing database): unchanged 002 flow; `InitialRolePermissions::apply()` grants the `products.*` pairs only in the run that creates those permissions.

Template upload:

```
POST /products/{product}/templates (multipart: file, variant_value_id?)
  authorize update(product) → size/UTF-8/no DOCTYPE → write tmp → enshrined sanitize → post-pass
  → detect <g id> on sanitized bytes → required layers present? ─no→ ValidationException(file: missing)
  → store sanitized file (new ULID) → DB::transaction { upsert product_templates, audit }
  → afterCommit: delete replaced file
```

Import:

```
catalog:import --combinations --combos --colors --author
  phase 0: author active? catalog empty? 3 readable files with exact headers?   ─fail→ report, exit 1
  phase 1: CatalogImportPlan (in memory): rows → rules → cross-row checks       ─errors→ report, exit 1
  phase 2: DB::transaction { categories → attributes → values → palette → offered colors
                             → products → structure → combinations (+restrictions) → combos }
           every create via its Action, origin console, context {import, import_file, import_row}
  → "Se importaron …", exit 0
```

## File Changes

Generated Wayfinder files (`resources/js/actions/**`, `resources/js/routes/**`) are excluded from the authored-line count.

| File | Action | Description |
|---|---|---|
| `database/migrations/2026_10_07_000001_create_catalog_tables.php` | Create | categories, attributes, values, offered colors, detail locations |
| `database/migrations/2026_10_07_000002_create_products_tables.php` | Create | products (+CHECKs), product attributes, allowed values, details, customizations |
| `database/migrations/2026_10_07_000003_create_combinations_tables.php` | Create | catalog codes, combinations, combination values, included customizations, stock overrides |
| `database/migrations/2026_10_07_000004_create_combos_tables.php` | Create | combos, components, component values (codes FK) |
| `database/migrations/2026_10_07_000005_create_product_templates_table.php` | Create | templates |
| `app/Enums/CatalogStatus.php`, `BusinessLine.php`, `SupplyMode.php`, `AttributePresentation.php`, `AttributeRole.php`, `AttributeSpecialUse.php` | Create | Decision 2 |
| `app/Enums/PermissionName.php`, `app/Enums/AuditAction.php` | Modify | +6 / +24 cases |
| `database/seeders/InitialRolePermissions.php` | Modify | §4 matrix |
| `app/Actions/Roles/SyncRolePermissions.php` | Modify | DEC-PRD-23 block + docblock |
| `app/Models/{ProductCategory,CatalogAttribute,AttributeValue,DetailLocation,Product,ProductAttribute,CatalogCode,Combination,StockMinimumOverride,Combo,ComboComponent,ProductTemplate}.php` | Create | Casts, relations, scopes (`search`, `withStatus`) |
| `database/factories/*Factory.php` (one per model) | Create | Fictitious data; states `inactive()`, `fabric()`, `color()`, `withAxes()`… |
| `app/Policies/ProductPolicy.php`, `CombinationPolicy.php`, `ComboPolicy.php`, `CatalogPolicy.php` | Create | Decision 8 |
| `app/Providers/AppServiceProvider.php` | Modify | `Gate::policy()` for the four catalog models |
| `app/Actions/Products/*` | Create | Decisions 9–19 (≈ 35 small Actions; list in Interfaces) |
| `app/Support/Products/{CatalogRules,ProductRules,CombinationRules,ComboRules,ProductPresenter,CombinationOverlap,AxisSignature,ComboMembership,StockMinimum,CatalogUsage}.php` | Create | Shared rules, pure logic and the "who uses it" queries (DEC-PRD-51..53) |
| `app/Support/Products/Selection/*` | Create | Loader, snapshots, `SelectionRules`, results |
| `app/Support/Products/Assets/{ImageProcessor,SvgTemplateSanitizer,SvgLayerDetector,TemplateLayerRequirements,TemplateAllowedTags,AssetLimits}.php` | Create | Decision 18 |
| `app/Support/Products/Import/{CatalogImportPlan,CatalogImportColumns}.php` | Create | Decision 19 |
| `app/Support/Csv/CsvReader.php`, `InvalidCsv.php` | Create | Extracted reader |
| `app/Support/Customers/CustomerCsvReader.php` | Modify | Delegates to `CsvReader` (public API unchanged) |
| `app/Http/Controllers/Products/*`, `app/Http/Controllers/Catalog/*` | Create | Thin controllers incl. asset streaming |
| `app/Http/Requests/Products/*`, `app/Http/Requests/Catalog/*` | Create | Validation + authorize |
| `app/Console/Commands/ImportCatalog.php` | Create | `catalog:import` |
| `routes/web.php` | Modify | Routes (Interfaces), protected by default |
| `composer.json`, `composer.lock` | Modify | `enshrined/svg-sanitize` (exact pin), `ext-gd`, `ext-dom`, `ext-libxml` |
| `lang/es/validation.php` | Modify | Attribute names and messages |
| `resources/js/pages/products/**`, `pages/combos/**`, `pages/catalog/**` | Create | Decision 20 |
| `resources/js/components/products/**`, `components/catalog/**` | Create | Forms and uploaders |
| `resources/js/components/ColorPicker.vue`, `AppCheckboxGroup.vue` | Create | New generic components |
| `resources/js/types/products.ts`, `resources/js/navigation.ts` | Create / Modify | DTO types; three nav entries |
| `docs/ui/design-system.md` | Modify | §7 subsections and §11 items for the two components |
| `tests/Unit/Products/*`, `tests/Feature/Products/*`, `tests/Feature/Catalog/*`, `tests/Feature/Console/ImportCatalogTest.php` | Create | Testing Strategy |
| Tests in Decision 21 | Modify | Literals and filters |

## Interfaces / Contracts

### Schema

| Table | Columns | Keys / constraints |
|---|---|---|
| `product_categories` | `id`; `name` varchar(100) as_ci; `sort_order` int unsigned; `status` varchar(20) default `active`; timestamps | unique `name` |
| `catalog_attributes` | `id`; `name` varchar(60) as_ci; `presentation` varchar(10); `special_use` varchar(10) null; `color_marker` tinyint STORED `IF(presentation='color',1,NULL)`; `sort_order`; `status`; timestamps | unique `name`; unique `special_use` (values `fabric`, `size`, `gender`; null = none, DEC-PRD-49); unique `color_marker` |
| `attribute_values` | `id`; `catalog_attribute_id` FK restrict; `name` varchar(100) as_ci; `description` varchar(255) null; `sort_order`; `status`; `tone` char(7) null; `svg_layer` varchar(64) null; `image_display_path`, `image_thumb_path` varchar(255) null; timestamps | unique `(catalog_attribute_id, name)`; unique `(id, catalog_attribute_id)` (composite FK target) |
| `fabric_offered_colors` | `fabric_value_id` FK restrict; `color_value_id` FK restrict | PK both |
| `detail_locations` | `id`; `name` varchar(100) as_ci; `status`; `svg_layer` null; image paths null; timestamps | unique `name` |
| `products` | `id`; `name` varchar(150) as_ci; `description` text null; `product_category_id` FK restrict; `business_line` varchar(20); `supply_mode` varchar(30); `min_stock_default` smallint unsigned null; `allows_custom_color` bool default false; `portal_visible` bool default true; `status`; image paths null; timestamps | unique `name`; index `(status, name)`; CHECK `min_stock_default IS NULL OR supply_mode='stock_with_minimum'`; CHECK `supply_mode<>'stock_with_minimum' OR min_stock_default IS NOT NULL`; CHECK `allows_custom_color=0 OR supply_mode='on_demand'` |
| `product_attributes` | `id`; `product_id` FK cascade; `catalog_attribute_id` FK restrict; `role` varchar(10); `sort_order`; timestamps | unique `(product_id, catalog_attribute_id)` |
| `product_attribute_values` | `product_attribute_id` FK cascade; `attribute_value_id` FK restrict | PK both |
| `product_detail_locations` | `product_id` cascade; `detail_location_id` restrict | PK both |
| `product_customizations` | `product_id` cascade; `service_product_id` → products restrict | PK both |
| `catalog_codes` | `id`; `code` varchar(30) as_ci; `combination_id` null; `combo_id` null; timestamps | unique `code`; unique `combination_id`; unique `combo_id`; FKs cascade; CHECK exactly one owner |
| `combinations` | `id`; `product_id` FK cascade; `description` varchar(255) null; `status`; `axis_signature` char(64); `active_signature` char(64) STORED `IF(status='active', axis_signature, NULL)`; timestamps | unique `(product_id, active_signature)`; index `(product_id, status)` |
| `combination_values` | `combination_id` cascade; `catalog_attribute_id`; `attribute_value_id` | PK `(combination_id, attribute_value_id)`; composite FK restrict → `attribute_values(id, catalog_attribute_id)`; index `(catalog_attribute_id, attribute_value_id)` |
| `combination_customizations` | `combination_id` cascade; `service_product_id` restrict | PK both |
| `stock_minimum_overrides` | `id`; `combination_id` cascade; `size_value_id` null restrict; `minimum` smallint unsigned; `size_key` STORED `IFNULL(size_value_id,0)`; timestamps | unique `(combination_id, size_key)` |
| `combos` | `id`; `name` varchar(150) as_ci; `portal_visible` bool default true; `status`; timestamps | unique `name` |
| `combo_components` | `id`; `combo_id` cascade; `product_id` restrict; `quantity` smallint unsigned; `sort_order`; timestamps | index `product_id` |
| `combo_component_values` | `combo_component_id` cascade; `catalog_attribute_id`; `attribute_value_id` | PK `(combo_component_id, attribute_value_id)`; composite FK restrict |
| `product_templates` | `id`; `product_id` cascade; `variant_value_id` null restrict; `variant_key` STORED `IFNULL(variant_value_id,0)`; `svg_path`; `original_name` varchar(255); `layers` json; timestamps | unique `(product_id, variant_key)` |

`as_ci` = `utf8mb4_0900_as_ci`. Timestamps in UTC, displayed with `OperatingTime::format()`.

### Permissions and initial matrix

```php
case ProductsView = 'products.view';             // 'Ver categorías, atributos, productos, combinaciones y combos'
case ProductsCreate = 'products.create';         // 'Registrar productos, combinaciones y combos'
case ProductsUpdate = 'products.update';         // 'Editar productos, combinaciones, combos, detalles, personalizaciones, parámetros de stock, imagen y plantillas del producto'
case ProductsDeactivate = 'products.deactivate'; // 'Desactivar y reactivar productos, combinaciones y combos'
case ProductsDelete = 'products.delete';         // 'Eliminar productos, combinaciones y combos sin historial'
case ProductsCatalog = 'products.catalog';       // 'Gestionar categorías, atributos y sus valores, y ubicaciones de detalle, incluidos sus tonos, imágenes y capas'
```

| Permission | Roles (`InitialRolePermissions::MATRIX`) |
|---|---|
| `products.view` | Administrador, Gerente, Asesora de Ventas, Finanzas, Supervisor de Producción, Responsable de Calidad |
| `products.create`, `products.update`, `products.deactivate`, `products.catalog` | Administrador, Gerente, Asesora de Ventas |
| `products.delete` | Administrador |

### Audit actions (+24; 24 → 48)

| Value | Label |
|---|---|
| `catalog.created` / `catalog.updated` / `catalog.deactivated` / `catalog.activated` | "Elemento del catálogo creado / modificado / desactivado / reactivado" |
| `catalog.fabric_colors_updated` | "Colores de una tela modificados" |
| `products.created` / `.updated` / `.deactivated` / `.activated` / `.deleted` | "Producto creado / modificado / desactivado / reactivado / eliminado" |
| `products.attributes_updated` | "Atributos de un producto modificados" |
| `products.stock_minimums_updated` | "Stock mínimo por artículo modificado" |
| `products.template_uploaded` / `products.template_removed` | "Plantilla de producto subida / retirada" |
| `products.combination_created` / `_updated` / `_deactivated` / `_activated` / `_deleted` | "Combinación creada / modificada / desactivada / reactivada / eliminada" |
| `products.combo_created` / `_updated` / `_deactivated` / `_activated` / `_deleted` | "Combo creado / modificado / desactivado / reactivado / eliminado" |

### Audit payloads

| Action | `old_values` | `new_values` | `context` |
|---|---|---|---|
| `catalog.created` | — | all fields of the entity (values: attribute name, tone, layer) | import: `import`, `import_file`, `import_row` + console origin |
| `catalog.updated` | changed fields only (incl. `sort_order`, `image`, `svg_layer`, `name` on rename) | same keys | — |
| `catalog.fabric_colors_updated` | `{removed: [names]}` | `{added: [names]}` | — |
| `products.created` | — | all product fields + `category_name` | import marker |
| `products.updated` | changed fields; `detail_locations`/`customizations` as name lists when changed; on mode change `min_stock_default` and `stock_minimum_overrides` (deleted rows) | same keys | — |
| `products.attributes_updated` | full previous structure `[{attribute, role, values[]}]`; `stock_minimum_overrides` (deleted `{code, size, minimum}` rows) when a size was removed (DEC-PRD-52) | full new structure | import marker |
| `products.stock_minimums_updated` | previous overrides `[{code, size, minimum}]` | new overrides | — |
| `products.template_uploaded` / `_removed` | previous `{original_name, variant, layers}` or — | new or — | — |
| `products.combination_*` | create: —; update: changed fields (`code`, `description`, `axes`, `restrictions`, `included_customizations` by name); status: `{status}`; delete: full copy | | import marker on create |
| `products.combo_*` | same shape (`code`, `name`, `portal_visible`, `components[{product, quantity, values}]`) | | import marker on create |
| `products.*deactivated/activated` | `{status}` | `{status}` | — |
| `products.deleted` | full aggregate copy | — | — |

### Key PHP contracts

```php
// app/Support/Products
final class CombinationOverlap {
    /** @param array<int, list<int>> $candidateAxes attributeId => valueIds
     *  @param iterable<int, array<int, list<int>>> $others combinationId => axes */
    public static function firstOverlap(array $candidateAxes, iterable $others): ?int;
}
final class AxisSignature { /** @param array<int, list<int>> $axes */ public static function of(array $axes): string; }
final class ComboMembership {
    /** DEC-PRD-42: every axis unrestricted or intersecting the component subset. */
    public static function includes(array $componentRestrictions, array $combinationAxes, array $axisAttributeIds): bool;
}
final class StockMinimum { public static function for(Combination $combination, ?int $sizeValueId): ?int; }
final class ProductRules {
    /** DEC-PRD-50: fabric-use attribute must be axis, color-presentation attribute must be order. Returns the offending attribute id or null. */
    public static function roleViolation(array $declared): ?int;   // list of {attributeId, role, specialUse, presentation}
}
final class CatalogUsage {   // queries only; every method returns names for friendly errors
    public function productsDeclaring(int $attributeId, bool $activeOnly = false): array;        // DEC-PRD-51
    public function combosUsingProduct(int $productId): array;                                   // DEC-PRD-52
    public function productsUsingService(int $serviceProductId): array;                          // DEC-PRD-52 (admitted + included)
    public function productsMissingLayer(AttributeValue|DetailLocation $entity, string $layer): array; // DEC-PRD-53
}
// app/Support/Products/Assets
final class TemplateLayerRequirements {
    /** @param list<string> $templateLayers @param list<string> $requiredLayers @return list<string> missing */
    public static function missing(array $templateLayers, array $requiredLayers): array;
}

// app/Support/Products/Selection (readonly DTOs, no Eloquent inside)
final readonly class ProductSnapshot {
    public function __construct(
        public int $id, public string $name, public bool $active, public bool $categoryActive,
        public SupplyMode $supplyMode, public bool $admitsCustomColor,
        /** @var list<AttributeSnapshot> */ public array $attributes,      // id, name, active, role, sortOrder, presentation, specialUse, allowed list<int>
        /** @var array<int, ValueSnapshot> */ public array $values,         // id, attributeId, name, description, sortOrder, active, tone, layer, imageUrls
        /** @var array<int, list<int>> */ public array $fabricColors,       // fabric value id => active color value ids
        /** @var list<CombinationSnapshot> */ public array $combinations,   // active only: id, code, axes, restrictions, included
        /** @var list<LocationSnapshot> */ public array $detailLocations,   // admitted and active
        /** @var list<ServiceSnapshot> */ public array $customizations,     // admitted and active
        /** @var list<TemplateSnapshot> */ public array $templates,
    ) {}
}
final class CatalogSnapshotLoader {
    public function forProduct(int $productId): ?ProductSnapshot;
    /** @param list<int> $productIds @return array<int, ProductSnapshot> */
    public function forProducts(array $productIds): array;
    /** @return list<ValueSnapshot> active palette */
    public function palette(): array;
}

// app/Actions/Products (selection)
ResolveSelection::handle(array $input): ResolvedSelection;      // throws ValidationException (field keys)
ListSelectionOptions::handle(array $input): SelectionOptions;   // throws ValidationException for an invalid prefix
```

Selection input shape (both Actions):

```php
[
  'product_id' => int,                 // or 'combo_id' => int (+ 'component_id' => int for options)
  'axes' => [attributeId => valueId],
  'order' => [attributeId => valueId|'custom'],
  'custom_color' => ['tone' => '#7A9A3B', 'note' => '…'] | null,   // note ≤ 100 chars
  'details' => [['location_id' => int, 'color_value_id' => int]],
  'customizations' => [serviceProductId, …],
  'components' => [componentId => ['axes' => [...], 'order' => [...]]],  // combos only
]
```

`ResolvedSelection` (array-serializable): `kind` (`product`|`combo`), `code`, `combination_id`, `product {id, name}`, `descriptive_name` (chosen values), `axes [{attribute_id, attribute, value_id, value}]`, `order [{attribute_id, attribute, value_id, value, tone?}]` with color `{custom: true, tone, note}` when custom, `details [{location_id, location, color {id, name, tone}}]`, `customizations [{id, name}]`, `included_customizations [{id, name}]`, `requires_advisor` (bool), and for combos `components [{component_id, quantity, resolution}]`.

Product Actions (signatures follow 002: `handle(..., User $actor, ?AuditOrigin $origin = null, array $auditContext = [])` on creates): `CreateCategory`, `UpdateCategory`, `CreateCatalogAttribute`, `UpdateCatalogAttribute`, `CreateAttributeValue`, `UpdateAttributeValue`, `CreateDetailLocation`, `UpdateDetailLocation`, `MoveCatalogItem`, `ActivateCatalogItem`, `DeactivateCatalogItem`, `SyncFabricOfferedColors`, `SetCatalogImage`, `RemoveCatalogImage`, `CreateProduct`, `UpdateProduct`, `SyncProductAttributes`, `SyncStockMinimumOverrides`, `SetProductImage`, `RemoveProductImage`, `UploadProductTemplate`, `RemoveProductTemplate`, `ActivateProduct`, `DeactivateProduct`, `DeleteProduct`, `CreateCombination`, `UpdateCombination`, `ActivateCombination`, `DeactivateCombination`, `DeleteCombination`, `CreateCombo`, `UpdateCombo`, `ActivateCombo`, `DeactivateCombo`, `DeleteCombo`, `ResolveSelection`, `ListSelectionOptions`, `ImportCatalog`.

### Technical limits (defaults chosen here)

| Limit | Value | Business decision? |
|---|---|---|
| Image upload | JPG/PNG/WebP, ≤ 2 MB, long side ≤ 1920 px (images are exported at that size before upload; phone photos must be resized first) | Confirmed by the owner (2026-10-07) |
| Image variants | WebP `display` 1200 px q82, `thumb` 320 px q80; original discarded | Technical |
| SVG template | ≤ 2 MB, UTF-8, no DOCTYPE/ENTITY | Technical |
| Custom color note | ≤ 100 characters (PRD-004 delegates it to design) | Technical default |
| Tone | `#RRGGBB`, stored uppercase | Technical |
| Code | 1–30 chars, no whitespace, stored as typed | Technical (DT-01) |
| Names | category/location 100, attribute 60, value 100, product/combo 150; descriptions 255 (combination, value), product description 5000 | Technical |
| Min stock / quantities | integer 0–9999 / combo quantity 1–999 | Technical upper bounds; lower bounds are the spec's |
| Lists | `paginate(15)`; search `q` trimmed, max 100 | Technical (as 002) |

PHP `upload_max_filesize`/`post_max_size` in production must be ≥ 4 MB and `memory_limit` ≥ 128 MB for 1920 px decoding (Migration / Rollout).

### Routes and authorization

All routes are protected by default (`RequireAuthentication`); none is added to `PublicRoutes`. `/create` routes are declared before `/{id}` routes.

| Method | URI | Name | Ability → permission |
|---|---|---|---|
| GET | `/products` | `products.index` | viewAny → view |
| GET / POST | `/products/create`, `/products` | `products.create` / `.store` | create |
| GET | `/products/{product}` | `products.show` | view |
| GET / PUT | `/products/{product}/edit`, `/products/{product}` | `products.edit` / `.update` | update |
| GET / PUT | `/products/{product}/structure`, `/products/{product}/attributes` | `products.structure` / `products.attributes.update` | update |
| PUT | `/products/{product}/stock-minimums` | `products.stock-minimums.update` | update |
| POST | `/products/{product}/activate`, `/deactivate` | `products.activate` / `.deactivate` | deactivate |
| DELETE | `/products/{product}` | `products.destroy` | delete |
| POST / DELETE | `/products/{product}/image` | `products.image.store` / `.destroy` | update |
| GET | `/products/{product}/image/{variant}` | `products.image.show` | view |
| POST | `/products/{product}/templates` | `products.templates.store` | update |
| DELETE | `/products/{product}/templates/{template}` | `products.templates.destroy` | update |
| GET | `/products/{product}/templates/{template}/svg` | `products.templates.show` | view |
| GET / POST | `/products/{product}/combinations/create`, `/products/{product}/combinations` | `products.combinations.create` / `.store` | Combination create |
| GET / PUT | `…/combinations/{combination}/edit`, `…/combinations/{combination}` | `.edit` / `.update` | Combination update |
| POST | `…/combinations/{combination}/activate`, `/deactivate` | `.activate` / `.deactivate` | Combination deactivate |
| DELETE | `…/combinations/{combination}` | `.destroy` | Combination delete |
| GET | `/combos`, `/combos/create`, `/combos/{combo}`, `/combos/{combo}/edit` | `combos.index/create/show/edit` | view / create / view / update |
| POST / PUT / DELETE | `/combos`, `/combos/{combo}` | `combos.store/update/destroy` | create / update / delete |
| POST | `/combos/{combo}/activate`, `/deactivate` | `combos.activate/deactivate` | deactivate |
| GET / POST / PUT | `/catalog/categories`, `/catalog/categories/{category}` | `catalog.categories.index/store/update` | Catalog manage |
| GET / POST / PUT | `/catalog/attributes`, `/catalog/attributes/{attribute}` | `catalog.attributes.index/show/store/update` | Catalog manage |
| POST / PUT | `/catalog/attributes/{attribute}/values`, `/catalog/values/{value}` | `catalog.values.store/update` | Catalog manage |
| PUT | `/catalog/values/{value}/offered-colors` | `catalog.values.offered-colors.update` | Catalog manage |
| GET / POST / PUT | `/catalog/detail-locations`, `/catalog/detail-locations/{location}` | `catalog.detail-locations.index/store/update` | Catalog manage |
| POST | `/catalog/{type}/{id}/move`, `/activate`, `/deactivate` (type ∈ categories, attributes, values, detail-locations; explicit routes per type) | `catalog.{type}.move/activate/deactivate` | Catalog manage |
| POST / DELETE | `/catalog/values/{value}/image`, `/catalog/detail-locations/{location}/image` | `catalog.*.image.store/destroy` | Catalog manage |
| GET | `/catalog/values/{value}/image/{variant}`, `/catalog/detail-locations/{location}/image/{variant}` | `catalog.*.image.show` | Catalog viewAsset → view |

Route parameters use `scopeBindings()` for nested combinations and templates. Console: `catalog:import`, restricted by shell access and its own author check.

### CSV files (PRD-018)

Common: UTF-8 (BOM allowed), `,` or `;`, exact header in any order and case; list cells use `|` between attributes and `,` between values.

`combinaciones.csv` — one row per combination:

| Column | Meaning | Example |
|---|---|---|
| `categoria` | category name | `Camisas` |
| `producto` | product name | `Camisa corporativa` |
| `linea` | `uniformes` \| `pañales` | `uniformes` |
| `modo` | `bajo_pedido` \| `stock_con_minimo` \| `stock_agotable` \| `servicio` | `bajo_pedido` |
| `stock_minimo` | product default (DEC-PRD-48); blank unless `stock_con_minimo` | `6` |
| `codigo` | combination code, as in the list | `110-1` |
| `ejes` | axis values, in axis order | `Tela=ALG-OXF Pima\|Modelo=Columbia especial\|Manga=Manga corta\|Género=Dama` |
| `pedido` | order attributes and the row's allowed values; `Color=` empty for a product with Tela | `Talla=XS,S,M,L,XL\|Color=` |
| `descripcion` | original description of the list | `CAMISA CORP / ALG-OXF PIMA / …` |

`combos.csv` — one row per component: `codigo`, `nombre` (equal on all rows of a code), `producto`, `cantidad`, `valores` (restriction, same syntax as `pedido`; blank = no restriction).

`colores.csv` — one row per palette color: `color`, `tono` (`#RRGGBB`), `telas` (fabric value names offering it, separated by `|`; blank = palette only).

Error report columns: `Archivo | Fila | Columna | Motivo`.

### Inertia props (summary)

- `products/Index`: `filters {status, q, category, line, mode}`, `counts`, `products` (paginated rows: id, name, category, line, mode labels, combinations count, status), `options {categories, lines, modes}`.
- `products/Show`: `product` (general data, `admits_custom_color`, structure with roles and value names, combinations `{id, code, descriptive_name, axes, restrictions, status}`, details, customizations, stock default and overrides, images and templates with layers), `can {update, deactivate, delete, createCombination}`.
- `products/Create|Edit|Structure`, `combinations/Create|Edit`, `combos/*`, `catalog/*`: form DTOs and option lists only for the abilities the user holds.

## Testing Strategy

Pest on the MySQL `testing` database with `RefreshDatabase`; `TestCase` seeds `FoundationSeeder`; authorization tests call HTTP endpoints; every scenario test name starts with its ID (`it('E-48 …')`); decision-only tests start with the decision ID. **Strict TDD**: each test is written RED before its implementation. Scenario clauses that need a later slice (e.g. E-25 "no se resuelve", E-46 resolution) get a second test with the same ID in that slice. Each slice closes with: `./vendor/bin/sail pint --test`, `sail composer types:check`, `sail pnpm check`, `sail pnpm types:check`, `sail pnpm build`, `sail artisan test` (CI runs `composer ci:check`; backend-only slices run the JS gates too). Pages rendered by a slice's tests ship in the same slice.

| Layer | What to test | Approach |
|---|---|---|
| Unit | `CombinationOverlap` (multi-valued axes, axis-less, disjoint on one axis), `AxisSignature` (order-independent), `ComboMembership`, `SelectionRules` on hand-built snapshots, enums, catalog/audit enum lists | Plain Pest in `tests/Unit/Products/` |
| Integration (HTTP) | Every scenario except console ones and Action-level resolution; policies and coherence; matrix seeding | `assertInertia`, `assertSessionHasErrors`/`assertJsonValidationErrors`, DB and audit assertions |
| Integration (Action) | PRD-011 / PRD-019 scenarios; query-count bound | `app(ResolveSelection::class)->handle([...])`; `DB::enableQueryLog()` with 3 vs 30 combinations → same count |
| Integration (DB) | E-08 database clause, DT-01 case rules, E-45/E-58 generated uniques, CHECK constraints, exact-duplicate signature | Raw inserts expecting `UniqueConstraintViolationException` / `QueryException` |
| Integration (Console) | E-31, E-32, DEC-PRD-48 | `$this->artisan('catalog:import', …)` with runtime fictitious CSVs |
| Architecture | no `abort(403)`; routes protected; selection code has no price/stock dependency | existing tests + one new arch test |
| E2E | — | Not available; design-system §11 checklist at 375/768/1280 px |

Scenario → test file map:

| Scenario / decision | Test file |
|---|---|
| DEC-PRD-22 (fresh DB; existing 002 DB gets `products.*` matrix; revocation not re-granted) | `tests/Feature/Products/ProductPermissionMatrixSeedingTest.php` |
| E-33 (each of the 5 dependents without view → 422, role unchanged, no audit) | `tests/Feature/Products/ProductPermissionCoherenceTest.php` |
| E-64 (category), PRD-001 order and status | `tests/Feature/Catalog/CategoryTest.php` |
| E-36, E-45, E-46 (catalog part), E-58, DEC-PRD-49 (uses Talla and Género, editable), E-69 (deactivate, remove use, change presentation), E-72 (value layer) | `tests/Feature/Catalog/AttributeCatalogTest.php` |
| E-64 (location), E-72 (location layer) | `tests/Feature/Catalog/DetailLocationTest.php` |
| E-08 (DB clause), DT-01, E-45/E-58 and DEC-PRD-49 (DB clauses: second attribute with the same use), CHECKs | `tests/Feature/Products/CatalogSchemaTest.php` |
| E-01, E-02, E-03, E-64 (product), E-68 (product part) | `tests/Feature/Products/ProductWriteTest.php` |
| E-20, E-65, E-66, E-71 | `tests/Feature/Products/StockMinimumTest.php` |
| E-05, E-06, E-24 (removal clause), E-57, E-60, DEC-PRD-50 (fabric as order and color as axis rejected), E-69 (inactive attribute cannot be declared) | `tests/Feature/Products/ProductStructureTest.php` |
| E-70 (line, service mode, leaving service mode, delete) | `tests/Feature/Products/ProductCrossReferenceTest.php` |
| E-07, E-08, E-09, E-10, E-11, E-48, E-56, E-59, E-67 (save clause), E-68 (combination part), duplicate | `tests/Feature/Products/CombinationTest.php` |
| E-21, E-22, E-62, E-64 (combo) | `tests/Feature/Products/ComboTest.php` |
| E-14, E-15, E-16, E-17, E-19, E-23, E-24, E-25 (resolution clause), DEC-PRD-51 (product with an inactive attribute is not selectable), E-34, E-43, E-44, E-46 (resolution), E-47, E-49, E-50, E-54 (resolve), E-63 (resolve), E-67 (resolve) | `tests/Feature/Products/ResolveSelectionTest.php` |
| E-51, E-52, E-53, E-54 (options), E-55, E-63 (options), DT-02 query bound | `tests/Feature/Products/SelectionOptionsTest.php` |
| E-25, E-26, PRD-013 no-op | `tests/Feature/Products/ProductStatusTest.php` |
| E-27, E-28, E-61, E-29 (`->todo`) | `tests/Feature/Products/DeleteProductTest.php` |
| E-04, E-30, filters and counts | `tests/Feature/Products/ProductListTest.php` |
| E-35, E-42 (image) | `tests/Feature/Products/ImageTest.php` |
| E-37, E-38, E-39, E-40, E-41, E-42 (template) | `tests/Feature/Products/ProductTemplateTest.php` |
| E-31, E-32, DEC-PRD-48 | `tests/Feature/Console/ImportCatalogTest.php` |

Non-obvious test designs:
- **E-08**: create `110` via HTTP, then a combo with `110` → error on `code`; then raw `DB::table('catalog_codes')->insert(['code' => '110', …])` → `UniqueConstraintViolationException`.
- **DT-01**: `001RN` exists → `001rn` rejected; `088-1` and `88` coexist; shown exactly as typed (E-11).
- **E-48**: `159-1` with Tela {Drill, Gabardina} + Caballero; new {Drill} + Caballero → 422 on `axes` naming `159-1`; new {Gabardina} + Dama → accepted.
- **E-59**: `110-4` inactive; an active combination with the same axes → `POST …/activate` → error flash naming the other code; `110-4` still inactive; no audit row.
- **E-60**: three attempts (add axis, Talla order→axis, Manga axis→order) each → 422 on `attributes`; structure unchanged; no audit.
- **E-61**: component restricts Talla to {2XG}; delete 2XG combination → violation naming the combo; delete 3XG → gone, audit copy present.
- **E-66**: overrides deleted and listed in `products.updated.old_values`; switching back without default → 422 on `min_stock_default`.
- **E-69**: active "Camisa corporativa" declares Tela; three requests (deactivate, clear `special_use`, presentation change) → 422 naming the product, attribute unchanged, no audit; a request that declares an inactive attribute on another product → 422; after the product is deactivated, deactivating the attribute succeeds while changing presentation or use is still rejected (any product declares it).
- **E-70**: diaper that is a combo component → business line change and mode change to `service` rejected naming the combo; service "Bordado pequeño" admitted by the camisa and included by a combination → leaving `service` and deletion rejected naming the product and the combination code; nothing changes in any case.
- **E-71**: product in `stock_with_minimum` with size attribute (use `size`) as order attribute and an override 4 on size 38; removing 38 from the allowed values succeeds, the override row is gone and `products.attributes_updated.old_values.stock_minimum_overrides` holds it; a second case where a combination restriction uses 38 is rejected first (E-57) and the override survives.
- **E-72**: "Manga 3/4" with layer `manga-3-4` admitted by a product whose template lacks `manga-tres-cuartos` → 422 on `svg_layer` naming the product, value unchanged; a value admitted only by products without templates, or clearing the layer, is accepted.
- **E-39**: SVG with `<script>`, `onclick`, `<foreignObject>`, `xlink:href="http://…"` and `<style>@import url(http://…)</style>` → stored file contains none of them; layers detected equal the sanitized `<g id>` set; a `<!DOCTYPE` file is rejected.
- **DT-02 query bound**: `forProduct` query count with 3 combinations equals the count with 30.
- **E-31**: one product with fabric, one with own colors, one diaper product with size axis, one combo; every created row has an audit row with `context.import = true`, `context.import_file`, `context.source = 'console'`, `actor_id = author`.
- **E-32**: one file per failure class (invalid row, repeated code across files, color without tone, unknown fabric, disagreeing minimum) → exit 1, zero catalog rows, report row/column/reason.

## Threat Matrix

The change adds HTTP routes (protected by default; the 001 route-enumeration test covers them), multipart uploads that are parsed as data, and a console command that reads operator-chosen local files. It has no shell, subprocess, VCS/PR automation, executable-file classification or process-integration boundary.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: uploads and CSVs are parsed as data, never classified or executed | — | — |
| Git repository selection | N/A: no VCS automation | — | — |
| Commit state | N/A: no VCS automation | — | — |
| Push state | N/A: no VCS automation | — | — |
| PR commands | N/A: no PR automation | — | — |

Upload security is covered as product behavior instead (Decision 18): RED tests for E-35 (PDF and oversize rejected), E-39 (script, event attribute, foreignObject, remote reference, CSS import), DOCTYPE/entity rejection, a PNG with appended bytes stored only as a re-encoded WebP, serving headers (`nosniff`, SVG CSP) and 403 on asset routes without `products.view`.

## Migration / Rollout

1. **Migrations** (new, additive, each with `down()` in reverse order): the five files in File Changes. Locally `./vendor/bin/sail artisan migrate`; rollback `./vendor/bin/sail artisan migrate:rollback --step=N`. No 001/002 migration is edited.
2. **Composer**: `enshrined/svg-sanitize` exact pin, `ext-gd`, `ext-dom`, `ext-libxml`. Production hosting must provide GD with JPEG, PNG and WebP support (verify `gd_info()` before deploy), `upload_max_filesize`/`post_max_size` ≥ 4 MB and `memory_limit` ≥ 128 MB.
3. **Seeding** (same deploy step as 001/002): `php artisan db:seed --class=FoundationSeeder --force` creates the 6 `products.*` permissions and grants the §4 matrix once.
4. **Go-live import (once)**: after Ecolekua validates Anexo A, Anexo B, the fabric/color file and the Los Cedros codes (PRD-018); put the three files in `storage/app/private/imports/`; run `php artisan catalog:import --combinations=… --combos=… --colors=… --author=<email>`; fix and re-run until clean; delete the files.
5. **No feature flags.**

### PR slice outline (for `sdd-tasks`; delivery strategy `ask-on-risk`, ~400 authored lines per slice)

Estimated total **~10,300–10,800** authored lines (DEC-PRD-49..53 add about 260: special uses, usage guards, size cleanup and the catalog layer guard). Each slice is green on its own and runs the full close gate. No slice is gated by a pending business decision any more; guards whose target tables arrive later ship as empty hooks and are filled in the slice that creates those tables. The chain strategy (`stacked-to-main` or `feature-branch-chain`) is the user's decision before apply.

| # | Slice | Scenarios / decisions | Depends on | Gated by | Est. lines |
|---|---|---|---|---|---|
| 1 | Permissions, 24 audit cases, matrix, DEC-PRD-23, 001/002 test updates | E-33, DEC-PRD-22 | — | — | 400 |
| 2 | Enums, catalog migration, catalog models/factories, `CatalogPolicy` | E-45/E-58 and DEC-PRD-49 DB clauses | 1 | — | 450 |
| 3 | Categories backend | E-64 (category) | 2 | — | 300 |
| 4 | Attributes, values, offered colors backend, special uses; empty `ensureNotDeclaredByProducts()` and layer-guard hooks | E-36, E-45, E-46 (catalog), E-58, DEC-PRD-49 | 2 | — | 450 |
| 5 | Detail locations backend (layer-guard hook) | E-64 (location) | 2 | — | 200 |
| 6 | Catalog UI 1: `ColorPicker`, `AppCheckboxGroup`, categories and locations pages, "Catálogo" nav, design-system update | page GET tests | 3, 5 | — | 450 |
| 7 | Catalog UI 2: attributes/values pages, offered colors editor | page GET tests | 4, 6 | — | 450 |
| 8 | Products migration, `ProductPolicy`, create/update/lifecycle, mode and default minimum | E-01..E-03, E-20, E-25, E-26, E-64, E-66 (default), E-68 (product) | 2 | — (cross-record edit guards are empty hooks, filled in 11 and 13) | 500 |
| 9 | Combinations migration + `SyncProductAttributes` (role rule, freeze, in-use, inactive attribute), `CatalogUsage::productsDeclaring`, attribute in-use guard | E-05, E-06, E-24 (removal), E-57, E-60, E-69, DEC-PRD-50, DEC-PRD-51 | 8 | — | 550 |
| 10 | Codes, combinations Actions, overlap, restrictions, duplicate, reactivation | E-07..E-11, E-48, E-56, E-59, E-68 (combination) | 9 | — | 500 |
| 11 | Detail locations and customizations on products; included customizations; service-users guard on `UpdateProduct` | E-67 (save), E-70 (leaving service mode) | 10 | — | 300 |
| 12 | Stock minimum overrides keyed by the size-use attribute; size-removal cleanup | E-65, E-71 | 10 | — | 350 |
| 13 | Combos migration and Actions; combo-component guard on `UpdateProduct` | E-21, E-22, E-62, E-64 (combo), E-70 (line, service mode) | 10 | — | 480 |
| 14a | Snapshot loader, `SelectionRules`, `ResolveSelection` (products; inactive declared attribute) | E-14..E-17, E-19, E-24, E-25, E-34, E-43, E-44, E-46, E-47, E-49, E-50, E-54, E-67, DEC-PRD-51 | 11 | — | 450 |
| 14b | Combo resolution | E-23, E-63 (resolve) | 13, 14a | — | 250 |
| 15 | `ListSelectionOptions`, query bound, arch test | E-51..E-55, E-63 (options) | 14b | — | 400 |
| 16 | Restricted delete (incl. service referenced elsewhere) | E-27, E-28, E-61, E-29 todo, E-70 (delete) | 13 | — | 330 |
| 17 | Product list and detail UI, nav entries | E-04, E-30 | 10 | — | 450 |
| 18 | Product forms and structure editor UI | GET tests | 17 | — | 450 |
| 19 | Combination editor UI (duplicate) | GET tests | 18 | — | 400 |
| 20 | Combos UI | GET tests | 13, 17 | — | 400 |
| 21 | Images: `ImageProcessor`, serving, product/value/location images | E-35, E-42 (image) | 17 | — | 450 |
| 22 | SVG templates: dependency, sanitizer, layers, required layers, per-gender variants; fills the catalog layer guard | E-37..E-42 (template), E-72, DEC-PRD-53 | 21 | — | 550 |
| 23 | `CsvReader` extraction, import of colors and combinations | E-32 (partial) | 10, 11 | — | 450 |
| 24 | Import of combos, full E-31/E-32 | E-31, E-32, DEC-PRD-48 | 13, 23 | — | 350 |
| 25 | Close-out: docs, DoD, a11y at 375/768/1280 px | — | all | — | 150 |

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

## Open Questions

### Business gaps found in design (AGENTS.md §6 format) — all resolved

All five answers are recorded in `docs/specs/003-products.md` §9 (2026-10-07) and are folded into the decisions above. No `[DEC-PENDIENTE]` remains open.

- **P-1** (PRD-009, PRD-020; how Talla and Género are recognized). Resolved: DEC-PRD-49, **A**. A special use on the attribute (Tela | Talla | Género), at most one attribute per use, assigned by the initial load and editable with `products.catalog` (Decisions 2, 3, 9, 13, 18, 19).
- **P-2** (PRD-004, PRD-019; roles of fabric and color). Resolved: DEC-PRD-50, **A**. The fabric attribute, if declared, is an axis; the color attribute, if declared, is an order attribute; other roles are rejected (Decision 11, step 3).
- **P-3** (PRD-002; attributes in use). Resolved: DEC-PRD-51, **A with adjustment**. Deactivation is rejected while an active product declares the attribute; an inactive attribute cannot be declared and a product declaring one is not selectable; presentation and special-use changes are rejected while any product declares it (Decisions 9, 11, 14, 16).
- **P-4** (PRD-012, PRD-014; edits that invalidate other records). Resolved: DEC-PRD-52. **A** (reject and name the users) for combo-component line or service-mode changes and for admitted or included services; **B** for removing a size (own minimums are deleted with audit; `008` adds the stock block) (Decisions 10, 11, 17).
- **P-5** (PRD-020; layer changes in the catalog). Resolved: DEC-PRD-53, **A**. The catalog change is rejected, naming the products whose templates lack the layer (Decisions 9, 18).

### Non-blocking notes (interpretations taken in this design)

- **N-1** Names and codes compare case-insensitively but accent-sensitively (literal DEC-PRD-45 / DT-01). Switching to accent-insensitive is a collation change in the migration if Ecolekua prefers it.
- **N-2** "Un producto sin ejes tiene una sola combinación" is enforced through the overlap rule (at most one **active** combination), which keeps the DEC-PRD-21 "create new + deactivate old" path available.
- **N-3** A combo may contain the same product in two components (the spec does not forbid it).
- **N-4** The initial load requires an empty catalog (it is a one-time load, PRD-018).
- **N-5** Templates can be removed by `products.update` ("gestiona … las plantillas del producto").
- **N-6** DEC-PRD-52 names the removal of one size from a product's allowed values. Removing the whole size-use attribute from a product removes all its sizes, so the same cleanup (delete the size-keyed own minimums, with audit) is applied; the spec does not mention this case separately.
- **N-7** DEC-PRD-51 makes a product that declares an inactive attribute unselectable but does not say whether reactivating that product must be rejected. The design does not reject it: the product stays unselectable until the attribute is reactivated (availability is computed, Decision 16).
- **N-8** Source spec §5 (conceptual model) still lists "atributo de tela (sí | no)" and does not show the special uses of DEC-PRD-49; PRD-002 is authoritative and states that the fabric mark is the use "Tela". Worth aligning in the spec; no design impact.
- **N-9** The unique-violation backstop in `CatalogRules` identifies the violated index by parsing the MySQL error message (`for key '...'`). Accepted because the project is MySQL-only (AGENTS §2, tests run on MySQL); revisit if the database engine ever changes.

### Technical follow-ups (apply phase)

- [ ] Pin the exact `enshrined/svg-sanitize` release, run `composer audit`, record version and advisories in `tasks.md`; confirm its default allowlist and the project `AllowedTags` remove `foreignObject`.
- [ ] Confirm `DB::afterCommit()` callbacks run under `RefreshDatabase` as expected (Laravel's test transaction manager).
- [ ] Confirm production GD supports WebP; otherwise the variant format falls back to JPEG/PNG in `ImageProcessor` (technical change only).
- [ ] `composer.json` declares `"license": "MIT"` (starter kit); the project owner may want `"proprietary"` now that a GPL dependency is included (internal use, no distribution).
- [ ] Documentation drift reported by the proposal: `docs/business/business-rules.md` §27 still marks DEC-PRD-11 as pending (not changed by this design).
