## Exploration: 003-products (catalog, combinations, combos, selection resolution, visual assets, initial load)

Date: 2026-10-06. Source of truth: `docs/specs/003-products.md` ("Lista para Design"). CodeGraph has no index for the repo; evidence gathered with Read/Grep.

### 1. Current state

- 001 (users, roles, permissions, audit) and 002 (customers, import command) are merged. No catalog code and no file-upload code exist (no `Storage::`, `UploadedFile`, `hasFile` or `forceFormData` in app, resources, routes, tests or database).
- Stack and gates (`composer.json:11-30`, `package.json:5-12`): PHP ^8.4 (Sail runs 8.5), Laravel 13, Inertia 3, Pest 4, Larastan, Pint. CI runs `ci:check` = `pnpm check` + `pnpm types:check` + `@test` (config:clear, pint --test, phpstan, artisan test) (`composer.json:62-67`). JS dependencies are minimal: no UI library, no sortable library, no color picker.
- `config/database.php:57` sets collation `utf8mb4_unicode_ci` (case- and accent-insensitive), which helps DT-01.

### 2. Reusable patterns (file:line evidence)

**Permissions and the initial matrix**
- `app/Enums/PermissionName.php:11-26` holds the cases, with Spanish descriptions in a `match` at `:33-50`. Add 6 `products.*` cases (catalog grows from 16 to 22).
- `database/seeders/PermissionCatalogSeeder.php:20-25` upserts every enum case; no change needed.
- `database/seeders/InitialRolePermissions.php:28-45` (`MATRIX`): add the spec §4 matrix — `products.view` for Administrador, Gerente, Asesora de Ventas, Finanzas, Supervisor de Producción and Responsable de Calidad; create/update/deactivate/catalog for Administrador, Gerente and Asesora de Ventas; delete for Administrador only. `apply()` (`:52-85`) grants a pair only when the permission was created in the current run (`:77-78`), so existing databases receive the matrix and later revocations are not re-granted. `database/seeders/FoundationSeeder.php:25-44` already snapshots permissions before and after; no change.
- DEC-PRD-23 coherence plugs into `ensureCoherent()` in `app/Actions/Roles/SyncRolePermissions.php:79-116`: add a block after `:111` rejecting create/update/deactivate/delete/catalog without view, with the same `ValidationException` on `permissions` and a Spanish message (`:113-115`). It runs before the "nothing changed" early return (`:48`). Update the class docblock (`:17-24`).
- 002 task 1.3 shows that adding permissions requires adapting tests that hard-code the permission count (`tests/Unit/PermissionNameTest.php`, `FoundationSeederTest`, `PermissionCatalogTest`, `LastAdministratorTest`, `tests/Pest.php`, `PermissionMatrixSeedingTest`). Prefer `PermissionName::cases()` over literals.
- Policies are permission-only (`app/Policies/CustomerPolicy.php:16-52`); `deactivate` governs activate and deactivate. 003 needs a `ProductPolicy`, probably a `ComboPolicy`, and a catalog policy for `products.catalog` abilities (categories, attributes, values, detail locations).
- Navigation: `resources/js/navigation.ts:52-60` — add `products` in group `Comercial` with `products.view`. `useNavigation.ts:8` caps the bottom bar at 4 destinations, so a third `Comercial` entry may move items to the "Más" sheet.
- `app/Http/Controllers/Customers/CustomerController.php` is the model for products and combos: `Gate::authorize('viewAny', …)` (`:31`), unknown status defaults to `active` (`:35`), the three tab counts use the same filters as the list (`:41-47`), `paginate(15)->withQueryString()` (`:54`), Actions for store/update/destroy, `Inertia::flash` messages. Routes (`routes/web.php:44-55`) declare `/create` before `/{id}`.
- Lifecycle pattern: `app/Actions/Customers/DeactivateCustomer.php:23-41` — `DB::transaction`, `lockForUpdate` (`:24`), no-op without audit when already in the target state (`:26-28`), audit with old and new `status`. PRD-013 requires that deactivating a product does not change the stored state of its combinations, so availability is computed (product, combination and category active), never cascaded.
- Restricted delete: `app/Actions/Customers/DeleteCustomer.php:22-54` — `ensureHasNoHistory()` hook (empty in 002), audit copy in `old_values`, no FK on `audit_logs.entity_id`. For products the hook starts with one condition (combo component references the product or combination); 004/006/008 add history conditions.
- Status enum: `app/Enums/CustomerStatus.php` (string-backed `Active|Inactive` with `label()`). Recommendation: one shared `CatalogStatus` enum for categories, attributes, values, detail locations, products, combinations and combos; add `BusinessLine`, `SupplyMode`, `AttributePresentation`, `AttributeRole`. `StatusBadge`: active → `done`, inactive → `neutral` (spec §8).

**Audit**
- `app/Actions/Audit/RecordAuditEvent.php:23-47` is the single entry point and opens no transaction, so the caller's transaction makes change and audit atomic.
- `AuditAction` (`app/Enums/AuditAction.php:28-33`, labels `:59-64`): one case per event with `module.event` values. Expect about 25-30 new cases (e.g. `products.created`, `catalog.value_updated`, `products.image_replaced`, `products.template_uploaded`, `catalog.fabric_colors_changed`). `AuditActionTest` asserts the case count and label uniqueness and needs updating.
- Import marking (CLI-018): origin `AuditOrigin::console('customers:import')` (`app/Actions/Customers/ImportCustomers.php:347`, `app/Support/Audit/AuditOrigin.php:26-34`) records source, command, OS user and host, null IP. Each create passes `auditContext: ['import' => true, 'import_row' => $line]` (`ImportCustomers.php:361`), merged and redacted at `RecordAuditEvent.php:45`. For products add an `import_file` key (three files).

**Action / FormRequest / Controller conventions**
- Thin controllers: FormRequest, Policy and Action in `app/Actions/<Module>/`, the Action owning the transaction. `NoManualForbiddenTest` forbids `abort(403)` in `app/`.
- Rules shared by HTTP and the import live in `app/Support/Customers/CustomerRules.php`, reused by the importer as `Validator::make($input, CustomerRules::customer())` (`ImportCustomers.php:138`). Mirror with `ProductRules` so PRD-018 reuses the create validation.
- Business-rule failures use `app/Exceptions/BusinessRuleViolation.php` with a renderer (vehicle for E-28 "blocked by combo, suggest deactivate").

**List UI**
- Existing components: `DataTable`, `SegmentedTabs` (backend counts), `ConfirmDialog` (native dialog, danger variant), `StatusBadge`, `AppCard`, `AppInput`, `AppSelect` (single value only, `AppSelect.vue:4-27`), `AppCheckbox`, `AppTextarea`, `AppPagination`, `IconButton`.
- Precedent pages: `resources/js/pages/customers/{Index,Show,Create,Edit}.vue`, `resources/js/components/customers/CustomerForm.vue`.

**Import command (reuse for PRD-018)**
- `app/Console/Commands/ImportCustomers.php:26-81`: thin command printing a `Fila|Columna|Motivo` table (`:54-57`), never printing cell values.
- The Action (`ImportCustomers.php:72-114`) has three phases: phase 0 author and file checks; phase 1 validate every row with the HTTP rules; phase 2 create everything through the real Actions inside one `DB::transaction` with the console origin (`:345-375`). A `ValidationException` during creation rolls back and is reported as an error row (`:377-392`).
- `app/Support/Customers/ImportReport.php` carries `problems`, `errors`, `warnings`, `imported`. `CustomerCsvReader.php` handles BOM, UTF-8, `,`/`;` detection and header validation; its `COLUMNS` (`:19-25`) is customer-specific. For three files, generalize to a reader parameterized by columns (small refactor of merged 002 code, protected by its tests) or copy it.
- Product-specific wrinkle: rows are not in the DB during phase 1, so cross-row checks (duplicate codes, axis overlap, color without tone, fabric not in catalog) must run on in-memory state. Put overlap and resolution logic in pure `Support` classes (no Eloquent) shared by the Action, the importer and unit tests.

### 3. Gaps and new dependencies

| Need | State today | Options | New dependency? |
|---|---|---|---|
| Image storage | `config/filesystems.php:33-49`: `local` disk private with `serve=false` (FND-026 removed the unauthenticated `/storage` route); `public` disk with `storage:link` | See DT-04 | No |
| Image optimization (JPG/PNG/WebP, variants) | Nothing in `composer.json`/`composer.lock` (only league/flysystem, mime-type-detection, symfony/mime). Sail 8.5 ships `php8.5-gd` and `php8.5-imagick` (`vendor/laravel/sail/runtimes/8.5/Dockerfile:39,58`); production hosting unknown | **A** native GD via a small `ImageVariants` class (decode, size cap, re-encode to WebP/JPEG, strip EXIF); **B** `intervention/image` (MIT); **C** `spatie/image` | A: none (declare `ext-gd` in `composer.json`; confirm in production). B/C need approval. Recommend A; re-encoding also neutralizes polyglot files |
| SVG sanitizer (DT-03) | Nothing in `composer.lock` | **A** `enshrined/svg-sanitize` (Packagist, fetched 2026-10-06: GPL-2.0-or-later, v1.0.0, ext-dom + ext-libxml, ~52M installs, 4 historical security advisories); **B** own DOM allowlist (contradicts DT-03, high risk); **C** reject any SVG with `style`, `use`, `image` (too restrictive) | Yes for A: needs user approval (AGENTS §2); the licence is an owner/legal decision (internal single-company software; obligations normally arise on distribution). Verify version and advisories in design |
| SVG/XML parsing for layers | `ext-dom` standard | `DOMDocument` with `LIBXML_NONET`, no entity expansion, size cap before parsing (XXE, billion laughs); detect layers on the sanitized output (E-39) | No |
| Color picker | None in `resources/js/components` | **A** `ColorPicker.vue` wrapping native `<input type="color">` + hex text field + swatch, returns `#rrggbb`, 44 px target; **B** a library | A: no. Recommend A; `docs/ui/design-system.md` §7 needs a subsection and checklist items |
| Multi-select | None (`AppSelect` single, `AppCheckbox` exists) | `AppCheckboxGroup` (checkbox list with optional filter) | No. Needed for combination axes, product allowed values, fabric offered colors, combo component values, product detail locations and customizations |
| Sorting | No drag-and-drop library | Up/down `IconButton`s + reorder endpoint (accessible) | No |
| File upload via Inertia | None | Multipart `useForm`; updates with files use POST + `_method` spoofing | No |

Further gaps:
- If portal images are public, a route must be declared in `app/Support/Http/PublicRoutes.php:14` (today only `login`, `login.store`).
- Laravel's `image`/`mimes` rules reject SVG by default; SVG validation must be content-based (parse, then sanitize).

New technical decisions to propose in design:
- **DT-04 — file serving.** Private disk + permission-aware controller streaming images with `X-Content-Type-Options: nosniff`; SVG templates served as `image/svg+xml` with `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'`, never injected raw with `v-html` unless sanitized. Alternative: public disk with unguessable names (leaks images of products not visible in the portal).
- **DT-05 — code registry** (section 4).
- **DT-06 — overlap strategy** (section 4).
- **DT-07 — limits.** Image size cap, variant widths, SVG size cap, custom-color note maximum length, hex format (`#RRGGBB`).

### 4. Data model sketch and hard DB constraints

Naming: do not name a model `Attribute` or a relation `attributes` (collides with `Eloquent\Casts\Attribute` and `$attributes`). Use `CatalogAttribute`, `AttributeValue`, `productAttributes`.

```text
product_categories(id, name, sort_order, status)
catalog_attributes(id, name UNIQUE, presentation[text|image|color], is_fabric bool,
                   fabric_flag GENERATED = IF(is_fabric,1,NULL) UNIQUE   -- E-45, at most one in DB
                   sort_order, status)
attribute_values(id, attribute_id FK, name, description, sort_order, status,
                 tone char(7) NULL, image_path NULL, svg_layer NULL,
                 UNIQUE(attribute_id,name), UNIQUE(id,attribute_id))
fabric_offered_colors(fabric_value_id FK, color_value_id FK, PK both)
detail_locations(id, name, status, image_path NULL, svg_layer NULL)
products(id, name, description, category_id FK restrict, business_line, supply_mode,
         min_stock smallint unsigned NULL, allows_custom_color bool, portal_visible bool default 1,
         main_image_path NULL, status,
         CHECK(min_stock IS NULL OR supply_mode='stock_with_minimum'),
         CHECK(allows_custom_color=0 OR supply_mode='on_demand'))
product_attributes(id, product_id cascade, attribute_id restrict, role[axis|order], sort_order,
                   UNIQUE(product_id,attribute_id))
product_attribute_values(product_attribute_id, attribute_value_id)   -- allowed values
product_detail_locations(product_id, detail_location_id)
product_customizations(product_id, service_product_id)               -- service mode, no self-reference (app-level)
product_templates(id, product_id cascade, gender_value_id NULL, svg_path, layers JSON,
                  UNIQUE(product_id, COALESCE(gender_value_id,0)) via generated column)
combinations(id, product_id, description NULL, status)
combination_axis_values(combination_id cascade, attribute_id, attribute_value_id)  -- multi-valued per axis; index (attribute_id, attribute_value_id)
combination_customizations(combination_id, service_product_id)
combos(id, name, portal_visible, status)
combo_components(id, combo_id cascade, product_id RESTRICT, quantity unsigned >= 1)
combo_component_values(component_id, attribute_id, attribute_value_id)  -- no rows for an axis = all active values
```

1. **Global code uniqueness across combinations and combos (DEC-PRD-01/02/14, E-08).**

| Option | How | Verdict |
|---|---|---|
| A. Registry table | `catalog_codes(code UNIQUE, combination_id NULL UNIQUE FK, combo_id NULL UNIQUE FK, CHECK exactly one non-null)`; code lives only here | **Recommended**, DB-guaranteed on MySQL 8.4 |
| B. Per-table unique + app check under lock | Two code columns | Not DB-guaranteed across tables; fails E-08 |
| C. Supertype `sellables` table | One table with `kind` | Heavier, rewrites the model |

2. **Text codes compared case-insensitively (DT-01).** Registry `code` is a string with an explicit `*_ci` collation (PAD SPACE: `110` and `110 ` collide; `088-1`, `001RN` stored as text). A MySQL Pest test proves `088-1` ≠ `88` and `001rn` collides with `001RN`.
3. **Overlap between active combinations (E-09/E-48)** cannot be a unique index. Two active combinations of the same product overlap iff they share at least one value on every axis. Enforcement (DT-06): the Action locks the product row (`lockForUpdate`) and one query counts, per other active combination, the distinct axes sharing a value (`GROUP BY combination_id HAVING COUNT(DISTINCT attribute_id) = :axisCount`); the same logic lives in a pure `Support` class for the importer. Optional DB backstop: a signature column (hash of sorted values, set only while active) with unique `(product_id, signature)` — blocks exact duplicates only. Triggers not recommended. A product without axes has one combination, also under the product lock. Spec §12 ("valores de eje por producto garantizada también en la base de datos") is only partially satisfiable (Q4).
4. **At most one fabric attribute (E-45):** generated-column unique index plus an Action check with a friendly error.
5. **At most one color-presentation attribute per product**, and fabric/color rules: app-level.
6. **Delete restrictions:** combo components reference products with `restrictOnDelete`; "combination part of a combo" is app-level (component = product + value subset). Later tables (quotations, orders, stock) reference combinations with `restrictOnDelete` (pattern in `database/migrations/2026_09_30_000001_create_customers_tables.php:12-16`).
7. **File cleanup** only after the transaction commits.

**Resolution and options (PRD-011, PRD-019):** one in-memory loader of the product's active combinations (tens to hundreds) and pure functions guarantee "the same criteria" and avoid N+1; cover with a query-count test. `ResolveSelection` has no price or stock dependency (DT-02).

### 5. Spec issues and open questions (no business behavior invented)

Numbering (not blocking): missing PRD-006 and E-12, E-13, E-18; DEC-PRD-31 listed after DEC-PRD-34. Keep IDs verbatim and note it in the proposal (AGENTS §4.3).

Questions to raise as `[DEC-PENDIENTE]` (AGENTS §6):
- **Q1. PRD-004 vs E-05 — color list when the product has fabric.** PRD-004: with fabric, "el producto no tiene lista propia"; E-05 declares Color "con sus valores admitidos" for a product with Tela. Does the product store allowed colors when it declares fabric (ignored, forbidden, required)? Affects PRD-011 rule 2.
- **Q2. Order-attribute allowed values: per product or per combination?** PRD-004/E-05 say per product; E-14/E-15 say "la combinación 184 con tallas admitidas 28 a 44"; PRD-018 puts order-attribute allowed values on each combination row. How does the importer reconcile rows of one product that differ?
- **Q3. Exactly one color-presentation attribute in the whole catalog?** PRD-002 limits it per product, but offered colors and detail colors (PRD-002, PRD-007) refer to "the" color attribute.
- **Q4. §12 DoD "unicidad de valores de eje por producto también en la base de datos"** is only partially enforceable (point 3). Accept product lock + signature backstop and amend the wording?
- **Q5. Reactivating a combination or product that would overlap an active one** — reject?
- **Q6. Changing a product's attribute structure with existing combinations** — order attribute becomes axis, axis becomes order attribute, or a new axis is added.
- **Q7. "Combinación que forma parte de un combo" (PRD-014, E-28)** — product is a component and its axis values intersect the component's allowed subset?
- **Q8. Combo shape** — must components belong to the pañales line? Does a combo have a business line? Are component order attributes (talla, color) chosen per component in PRD-011 rule 5?
- **Q9. Name uniqueness** for categories, products, detail locations and combos (only attributes and values are defined).
- **Q10. Minimum stock** — required in `stock_with_minimum`? What happens to it if the mode changes?
- **Q11. Customizations** — must an included customization be among the product's admitted ones? PRD-017 does not list detail locations, templates or product-attribute configuration (assumed covered by PRD-012/PRD-020).
- **Q12. History conditions** deferred to 004/006/008 (PRD-012, PRD-014): register E-29 as `todo` and keep the empty `ensureHasNoHistory()` hook, as in 002. Not blocking.
- **Data gates (PRD-018, not blocking Design):** Anexo A "Confirmar" rows, Anexo B normalizations, fabric/color file and Los Cedros codes are validated with Ecolekua before running the import. Tests use fictitious fixtures.

### 6. Slicing and size forecast

- Forecast: about 8,500-10,500 authored lines (tests included, generated Wayfinder files excluded), roughly 3x the 002 forecast. 400-line budget risk: high; chained PRs recommended. Delivery strategy ask-on-risk triggers: ask for `stacked-to-main` or `feature-branch-chain` (002 used feature-branch-chain).
- **Close gate for every slice** (lesson from 002): `sail pint --test`, `sail composer types:check`, `sail pnpm check`, `sail pnpm types:check`, `sail pnpm build`, `sail artisan test`. CI runs `composer ci:check`, so omitting `pnpm check` lets a slice pass locally and fail in CI. Run it on backend-only slices too.

| # | Work unit | Approx. lines |
|---|---|---|
| 1 | Permissions (6 cases), audit cases, initial matrix, DEC-PRD-23 coherence, adapted 001/002 tests (E-33) | ~400 |
| 2 | Enums, schema (categories, attributes, values, offered colors, detail locations), models, factories, fabric unique | ~450 |
| 3 | Categories (PRD-001) | ~350 |
| 4 | Attributes and values (PRD-002; E-36, E-45, E-46) | ~450 |
| 5 | Detail locations, catalog audit | ~250 |
| 6 | Catalog UI 1: `ColorPicker`, `AppCheckboxGroup`, categories and detail locations pages, nav entry | ~450 |
| 7 | Catalog UI 2: attributes and values pages (reorder, offered colors) | ~400 |
| 8 | Products schema, `ProductPolicy`, create/edit/status, supply mode, min stock (PRD-003, PRD-009, PRD-013; E-01..E-04, E-20, E-25, E-26) | ~450 |
| 9 | Product attributes and roles, allowed values, custom-color flag (PRD-004; E-05, E-06, E-24) | ~400 |
| 10 | Code registry, combinations, overlap check, duplicate (PRD-005; E-07..E-11, E-47, E-48) | ~450 |
| 11 | Detail locations and customizations on products and combinations (PRD-007, PRD-008; E-34) | ~250 |
| 12 | Combos (PRD-010; E-21, E-22) | ~350 |
| 13 | `ResolveSelection` (PRD-011; E-14..E-17, E-19, E-23, E-43, E-44, E-47, E-49, E-50) | ~450 |
| 14 | Available options (PRD-019; E-51..E-53) | ~350 |
| 15 | Restricted delete (PRD-014; E-27, E-28; E-29 `todo`) | ~250 |
| 16 | Product list, code search, detail page (PRD-015; E-30) | ~450 |
| 17 | Product edit UI: attributes, combination editor, combos editor (3 sub-units) | ~900 |
| 18 | Images (DT-04, GD variants; E-35, E-42) | ~400 |
| 19 | SVG templates, sanitizer, layer validation (E-37..E-41) — needs sanitizer approval | ~450 |
| 20 | Import: generalized CSV reader, product/combination/combo/color files (PRD-018; E-31, E-32) | ~600 (two units) |
| 21 | Close-out: docs, DoD, a11y at 375/768/1280 px | ~150 |

Images and SVG (18-19) go last: they carry the dependency approvals and the core catalog is usable without them. Alternative: two OpenSpec changes (core first; visual resources and import second); a single change is recommended (one spec, one ID space, AGENTS §11).

### 7. Risks

- **SVG XSS/XXE:** a sanitized SVG is still active content same-origin — serve with CSP and `nosniff` (DT-04), parse with `LIBXML_NONET` and a size cap, keep E-39. Sanitizer config must keep layer `id`s and drop `<style>`, `use`, `image`, remote references; pin the version (4 past advisories).
- **Image uploads:** validate by decoding (GD), cap dimensions (decompression bombs), strip metadata by re-encoding, generated names, check PHP `upload_max_filesize` (default 2M) against the cap.
- **Licence:** sanitizer is GPL-2.0-or-later — confirm before approving.
- **Concurrency:** overlap rule depends on the product-row lock; document and test the lock path.
- **PRD-019 performance:** bounded set, but assert eager loading with a query-count test.
- **Authorization:** `products.catalog` vs `products.update` overlap on images/templates — per-ability policies with HTTP tests (E-03, E-04, E-26, E-42); coherence tested for all five dependent permissions.
- **Data model:** Q1, Q2, Q3 and Q6 change the schema and importer; resolve before Design.
- **Review size:** ~9k lines; slicing required.
- **Importer reuse:** generalizing `CustomerCsvReader` touches merged 002 code; keep its tests green.
- **Navigation:** bottom bar capped at 4.

### 8. Recommendation

1. Single change `003-products`, feature-branch chain of ~20 slices; ask the user for the chain strategy (ask-on-risk).
2. Before propose/design, resolve Q1, Q2, Q3, Q5 and Q6 (one at a time) and record answers in `docs/specs/003-products.md` §9 (AGENTS §6).
3. Settle in design: DT-01, DT-02, DT-03, and new DT-04 (file serving), DT-05 (code registry), DT-06 (product lock + signature), DT-07 (limits).
4. Dependencies: user approves or rejects `enshrined/svg-sanitize` (GPL-2.0-or-later). Native GD for images, native `<input type="color">` wrapper, checkbox-group component.
5. SVG slice (19) waits for the approval; slices 1-17 proceed.

**Ready for Proposal:** yes, after the open questions and the dependency decision.
