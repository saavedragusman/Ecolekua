# Apply progress: 003-products

Mode: Strict TDD. Runner: `./vendor/bin/sail artisan test` (scoped `sail pest <path>`). Chain: feature-branch-chain, PR 1 targets `feat/003-products`.

## Phase 1: Permissions, audit cases and seeding matrix (COMPLETE, tasks 1.1-1.9)

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1/1.2 | `tests/Unit/PermissionNameTest.php`, `AuditActionTest.php` | Unit | 304/304 on affected suites | 5 failed + 1 error (22 permissions, 48 audit cases missing) | 8/8 | 22 values, descriptions of spec 4, 48 unique labels | None needed |
| 1.3 | `FoundationSeederTest`, `Customers/PermissionMatrixSeedingTest`, `Roles/PermissionCatalogTest`, `tests/Pest.php` docblock | Feature | included above | 3 failures with the larger catalog before 1.5 (expected 9, got 15; 21 vs 15; products.* missing) | green after 1.5 | per-role sets for 5 roles | None needed |
| 1.4/1.5 | `tests/Feature/Products/ProductPermissionMatrixSeedingTest.php` | Feature | N/A (new) | 15 failures across new matrix and coherence tests | 5/5 matrix tests passing | fresh DB, existing 002 DB, revoked grant, renamed role, Operario/delete holders | None needed |
| 1.6/1.7 | `tests/Feature/Products/ProductPermissionCoherenceTest.php` | Feature (HTTP) | N/A (new) | 302 instead of 422 for products.* without view | 11 coherence tests passing | 5 dependents, replace set, revoke view, valid, view alone, 3-rule combination | None needed |
| 1.8 | architecture suite | Feature | n/a | n/a | full suite green incl. NoManualForbiddenTest | n/a | Helper not extracted: three checks differ in message and set; extraction would not be smaller |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products tests/Feature/FoundationSeederTest.php tests/Feature/Customers tests/Feature/Roles tests/Unit`: 470 tests, 469 passed, 1 skipped (pre-existing) |
| Runtime harness | `sail artisan migrate:fresh --seed` then `Permission::count()` prints 22 |
| Rollback boundary | `PermissionName`, `AuditAction`, `InitialRolePermissions`, `SyncRolePermissions` block and the test edits |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass. `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 698 tests, 697 passed, 1 skipped.

### Notes

- Audit enum case names (not fixed by design): `CatalogCreated|Updated|Deactivated|Activated|FabricColorsUpdated`, `ProductCreated|...|TemplateRemoved`, `CombinationCreated|...|Deleted`, `ComboCreated|...|Deleted`. Reactivation labels use "reactivado/a" per design.
- No deviations from design. No `abort(403)` introduced.

## Phase 2: Enums, catalog schema, catalog models and `CatalogPolicy` (COMPLETE, tasks 2.1-2.8)

Chain: PR 2, branch `feat/003-products-2-catalog-schema`, targets `feat/003-products-1-permissions`. No commit made (not requested).

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 2.1/2.2 | `tests/Unit/Products/EnumsTest.php` | Unit | 698 tests green at Phase 1 close | 6 errors (classes not found) | 6/6 (24 assertions) | values, order and Spanish labels of 6 enums | None needed |
| 2.3/2.4 | `tests/Feature/Products/CatalogSchemaTest.php` | Feature (DB) | N/A (new) | 14 failed (tables missing) | 15/15 (32 assertions) | E-45, DEC-PRD-49 (size, gender), many NULL uses, E-58 + non-color presentations free, case-insensitive names x4 + accent significant + value names per attribute, composite key via `Schema::getIndexes`, PK of `fabric_offered_colors`, FK restrict (attribute with values, value used as offered color/fabric), models/factories smoke | Test helper switched from `information_schema` to `Schema::getIndexes` |
| 2.5/2.6 | `tests/Feature/Products/CatalogPolicyTest.php` | Feature | N/A (new) | 3 failed + 2 errors (no policy) | 7/7 (41 assertions) | manage class and instance, viewAsset, holder of every other permission, no roles, Administrador loses `products.catalog`, no `Gate::before`/`hasRole` | Pint reordered imports |
| 2.7 | n/a | n/a | n/a | n/a | `composer types:check` 0 errors | n/a | Docblocks aligned by Pint |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Unit/Products tests/Feature/Products/CatalogSchemaTest.php tests/Feature/Products/CatalogPolicyTest.php`: 28 tests green |
| Runtime harness | `sail artisan migrate:fresh`, `migrate:rollback --step=1`, `migrate`: migration `2026_10_07_000001_create_catalog_tables` DONE all three times |
| Rollback boundary | Migration 1, 6 enums, 4 models, 4 factories, `CatalogPolicy`, `registerCatalogPolicy()` in `AppServiceProvider`, and the 3 new test files |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass. `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 726 tests, 725 passed, 1 skipped (pre-existing).

### Notes

- Design "Schema" has no `sort_order` on `detail_locations`, while task 6.1 expects a `sort_order` in location rows. Implemented per the design table; Phase 5/6 must decide (additive migration or drop the field from the 6.1 props).
- "Category cannot be deleted while referenced" (task 2.3) has no referencing table until Phase 8 (`products`); it is covered by task 8.1 (FK restrict on category).
- `AttributeValue` relation to its attribute is `catalogAttribute()` (the name `attribute` is reserved by Eloquent); `CatalogAttribute::values()` is ordered by `sort_order`.
- `CatalogPolicy` methods take an untyped optional subject so they authorize both a model instance and the class.

## Phase 3: Categories backend (COMPLETE, tasks 3.1-3.4)

Chain: PR 3, branch `feat/003-products-3-categories`, targets `feat/003-products-2-catalog-schema`. No commit made (orchestrator commits).

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-----|-----|-----|
| 3.1/3.2 | `tests/Feature/Catalog/CategoryTest.php` | Feature (HTTP) | 726 tests green at Phase 2 close | 22 failed (all 404, routes missing) | 22/22 (117 assertions) | create (max+1, first = 1), E-64 case-insensitive on create/edit/own-name case change, name required/blank/101 chars, rename changed-fields audit, no-change no audit, move down/up swap with both audited, edge no-ops, invalid direction, deactivate/activate audit, repeat-state no-op, no DELETE route (405), 403 + `authorization.denied` for 5 write endpoints | PHPStan generics on shared Actions (`firstOrFail` via `newQuery()->whereKey()`), Pint import order |
| 3.3 | `NoManualForbiddenTest`, `ProtectedByDefaultTest` | Architecture | n/a | n/a | full suite green | n/a | Controller thin; Actions hold transaction + audit |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Catalog/CategoryTest.php`: 22 passed, 117 assertions |
| Runtime harness | `sail artisan route:list --name=catalog.categories`: 5 routes (store, update, move, activate, deactivate), no delete |
| Rollback boundary | 5 Actions (`CreateCategory`, `UpdateCategory`, `MoveCatalogItem`, `ActivateCatalogItem`, `DeactivateCatalogItem`), 3 requests, `CategoryController`, routes block in `routes/web.php`, `category_name_unique` in `lang/es/validation.php`, `CategoryTest` |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass. `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 748 tests, 747 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: 456 production (Actions 280, controller 72, requests 104) + 259 test + 11 modified (routes 8, lang 3) = about 726 total. Over the 400 advisory because of docblocks and the 259-line test with 22 scenarios; no `size:exception` required beyond the planned estimate (design estimated ~300; real is higher).
- Write endpoints redirect back (`redirect()->back()`) since the categories page (Phase 6) does not exist yet; flash messages in Spanish.
- `MoveCatalogItem` neighbor lookup is global per model; Phase 4 must scope it for attribute values (by `catalog_attribute_id`). `MoveCatalogItemRequest` authorizes `manage` on `ProductCategory::class` (the policy ignores the subject).
- `DeactivateCatalogItem` carries no in-use hook yet (task 4.5 adds it).

## Phase 4: Attributes, values, special uses and offered colors (COMPLETE, tasks 4.1-4.7)

Chain: PR 4, branch `feat/003-products-4-attributes`, targets `feat/003-products-3-categories`. No commit made (orchestrator commits).

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 4.1/4.2 | `tests/Feature/Catalog/AttributeCatalogTest.php` | Feature (HTTP) + Action | 748 tests green at Phase 3 close; `tests/Feature/Catalog` 35 green before | 58 tests, 54 failed (404 routes, missing Actions); the 4 passing were absence checks (no delete route, 405) | 58/58 (320 assertions); `tests/Feature/Catalog` 93 green | E-36 (5 invalid tones, picker tone stored uppercase, non-color tone, edit), E-45 (create + switch), DEC-PRD-49 (size and gender, release/take), fabric vs color, E-58 (create, switch, keep own), switch-to-color needs tones, name unique ci / across attributes, 10 layer cases, rename audit, lifecycle no-ops, 10 denied writes, unique-index backstops (name, special use, color) | One failure on the backstop mapping (the MySQL message includes the SQL, so the violated key is parsed with `for key '...'`) |
| 4.1 (move scope) | same file | Feature + Action | n/a | values moved across attributes in the old global scope (tests written with the other 4.1 tests, RED because the routes did not exist) | green | neighbor inside the attribute, no-op edges with foreign rows around, ties inside scope, lock query has `catalog_attribute_id = ?` and `order by id`, attributes keep the whole table | Scope extracted to `MoveCatalogItem::scope()` |
| 4.3/4.4 | same file | Feature (HTTP) | 93 green | 10 failed (404 route) | 10/10; `tests/Feature/Catalog` 103 green (525 assertions) | add + remove with names, several names / empty set, no-change no audit (dup ids), inactive addition rejected but resubmitted inactive kept, non-fabric value, non-color id and unknown id, malformed payloads, 403 audited | None needed |
| 4.5 | same file | Feature | n/a | Triangulation/RED not applicable: the hooks are empty by design, tests pass because the behavior is unchanged (explicit requirement of task 4.5) | 2 tests (attribute deactivate/presentation edit; layer set/change/clear) | n/a | n/a |
| 4.6 | n/a | n/a | n/a | n/a | scope shared with categories | n/a | `composer types:check` 0 errors |

Note: `SyncFabricOfferedColors` was written before the 4.3 tests were run (no route existed, so the tests were still observed RED with 404 before the controller and route were added).

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Catalog`: 103 passed, 525 assertions (AttributeCatalogTest 68, CategoryTest 35) |
| Runtime harness | `sail artisan route:list --name=catalog.attributes` lists 6 routes (store, update, move, activate, deactivate, values.store); values and offered-colors routes under `catalog.values.*`; no DELETE route |
| Rollback boundary | 5 Actions (`CreateCatalogAttribute`, `UpdateCatalogAttribute`, `CreateAttributeValue`, `UpdateAttributeValue`, `SyncFabricOfferedColors`), `app/Support/Products/CatalogRules.php`, 5 requests, 3 controllers, routes block, `lang/es/validation.php` additions, the `scope()` in `MoveCatalogItem` and the hook in `DeactivateCatalogItem`, `AttributeCatalogTest` |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (61 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 830 tests, 829 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: about 960 production (5 Actions 440, `CatalogRules` 119, 5 requests 230, 3 controllers 190 incl. offered-colors, routes/lang/Move/Deactivate edits 72) plus 762 test lines, about 1,720 total. Well over the 400 advisory: the slice is five Actions, two controllers, five requests and a 68-test file. Recommend `size:exception` or splitting the PR along 4.1-4.2 and 4.3-4.4 if the reviewer wants a hard cap.
- Hooks left empty and documented: `UpdateCatalogAttribute::ensureNotDeclaredByProducts()` and `DeactivateCatalogItem::ensureNotDeclaredByProducts()` (task 9.6, E-69), `CreateAttributeValue::ensureNoProductMissesLayer()` and `UpdateAttributeValue::ensureNoProductMissesLayer()` (task 22.7, E-72).
- `UpdateAttributeValue` edits only the keys present in the request (PATCH-like semantics on PUT); the attribute edit requires `special_use` to be present (null clears it) so an omitted field cannot drop a use.
- `special_use = fabric` with presentation `color` is rejected on `special_use` (design Decision 9). Not covered by the spec text: a color attribute whose presentation changes away from `color` keeps the tones of its values (no rule says to clear them).
- Offered colors request field is `color_ids`; errors for non-fabric value, non-color id and inactive addition are all keyed on `color_ids`. Added colors must be active; already offered inactive colors may be resubmitted.
- Unique-index backstop errors are mapped by the violated key name (`special_use` or `color_marker`, otherwise `name`).

## Phase 5: Detail locations backend (COMPLETE, tasks 5.1-5.3)

Chain: PR 5, branch `feat/003-products-5-detail-locations`, targets `feat/003-products-4-attributes`. No commit made (orchestrator commits).

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 5.1/5.2 | `tests/Feature/Catalog/DetailLocationTest.php` | Feature (HTTP) + Action | 830 tests green at Phase 4 close | 35 tests, 0 passed (33 failed with 404, 2 errors: Actions missing) | 35/35 (204 assertions) | create with/without layer, E-64 create/rename/own-case change, name required/blank/101 (create and update), 11 layer cases on create and update (valid, malformed, reserved, 65 chars), rename audit, layer change and clear audit, omitted layer kept, no-change no audit, unique-index backstop in both Actions, deactivate/activate audit, repeat-state no-op, no DELETE route (405), 4 denied writes audited `authorization.denied`, layer hook call site neutral | Layer rule extracted to `CatalogRules::layerRules()` shared by values and locations |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Catalog/DetailLocationTest.php`: 35 passed, 204 assertions |
| Runtime harness | `sail artisan route:list --name=catalog.detail-locations`: 4 routes (store, update, activate, deactivate), no move and no delete |
| Rollback boundary | `CreateDetailLocation`, `UpdateDetailLocation`, 2 requests, `DetailLocationController`, routes block, `location_name_unique` in `lang/es/validation.php`, `CatalogRules::locationRules()`/`layerRules()` (values keep the same rule), `DetailLocationTest` |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass. `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 867 tests, 866 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: about 296 new production (2 Actions 157, controller 63, 2 requests 76) + 38 modified (CatalogRules 28, routes 8, lang 1) + 313 test = about 647. Over the 400 advisory because of the 35-test file (313 lines) and docblocks; production alone is about 334.
- Resolved the Phase 2 note: locations have no `sort_order` (design table, no move route). Phase 6 task 6.1 must drop `sort_order` from the location props (listed by name); no migration added.
- Create accepts an optional `svg_layer`; update edits only the keys sent (omitted layer kept, null clears), same semantics as `UpdateAttributeValue`. Create audit new_values: `name`, `svg_layer`, `status`.
- Hooks left empty and documented: `CreateDetailLocation::ensureNoProductMissesLayer()` and `UpdateDetailLocation::ensureNoProductMissesLayer()` (task 22.7, E-72, DEC-PRD-53).
- Route parameter is `{location}` (design API table); activate/deactivate reuse the generic `ActivateCatalogItem`/`DeactivateCatalogItem` with `Gate::authorize('manage')` in the controller.

## Phase 6: Catalog UI 1 — shared components, categories and locations pages (COMPLETE, tasks 6.1-6.5)

Chain: PR 6, branch `feat/003-products-6-catalog-ui-1`, targets `feat/003-products-5-detail-locations`. No commit made (orchestrator commits).

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 6.1/6.3 (categories) | `tests/Feature/Catalog/CategoryTest.php` | Feature (HTTP + Inertia props) | 867 tests green at Phase 5 close | 5 new tests, 0 passed (405: GET route missing; "Not a valid Inertia response") | 5 passed | out-of-order rows by `sort_order` with an inactive row in the middle, exact row shape, empty list, `sort_order` ties broken by id, 403 for `products.view` alone and for every product permission but catalog, `authorization.denied` audited with route `catalog.categories.index`, guest redirected to `/login`, `can.manage` | None needed |
| 6.1/6.3 (locations) | `tests/Feature/Catalog/DetailLocationTest.php` | Feature (HTTP + Inertia props) | same | 5 new tests, 0 passed (same 405) | 5 passed | listing by name case-insensitively (`pechera` after `Bolsillo`, `Manga`), exact row shape with null and set `svg_layer`, inactive included, empty list, same 403/audit/guest cases | None needed |
| 6.2 | no frontend test runner | n/a | n/a | n/a | `pnpm types:check` and `pnpm build` pass | n/a (components exercised in a later phase: ColorPicker by the value form, AppCheckboxGroup by the structure editors) | `pnpm check:fix` formatting only |

RED run: `sail pest tests/Feature/Catalog/CategoryTest.php tests/Feature/Catalog/DetailLocationTest.php --filter="page"`: 10 tests, 0 passed, 10 failed. GREEN run: same command, 10 passed, 94 assertions.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Catalog/CategoryTest.php tests/Feature/Catalog/DetailLocationTest.php --filter="page"`: 10 passed, 94 assertions |
| Runtime harness | `sail artisan route:list --name=catalog.categories.index` and `--name=catalog.detail-locations.index`: one `GET|HEAD` route each; `sail pnpm build`: built (pages compile). Browser check at 375/768/1280 px: PENDING (human) |
| Rollback boundary | `ColorPicker.vue`, `AppCheckboxGroup.vue`, `components/catalog/CatalogSections.vue`, `pages/catalog/Categories.vue`, `pages/catalog/DetailLocations.vue`, `types/products.ts`, the `index` actions and 2 GET routes, the "Catálogo" entry in `navigation.ts`, design-system §7.12, §7.13 and 2 checklist items, and the 10 page tests |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (67 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 877 tests, 876 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: about 727 new frontend lines (2 pages 445, 2 components 223, `CatalogSections` 31, types 28) + 203 modified/added in tracked files (controllers 62, routes 2, navigation 14, design-system 19, tests 106) = about 930. Over the 400 advisory: the slice ships two generic components, two full pages and 10 tests; production backend is only 62 lines.
- Props: categories `{id, name, status, status_label, sort_order}`; locations `{id, name, svg_layer, status, status_label}` (`svg_layer` added to the 6.1 list because the edit form needs it, PRD-007/DEC-PRD-29). `can` is `{manage}`, resolved with `Gate::allows('manage', ...)`: always true on a page that opened, it exists so the controls follow the policy.
- Write endpoints keep `redirect()->back()`: Inertia sends the Referer of the index page, so they return to it; no controller change was needed.
- The "Catálogo" entry points to the categories page (`products.catalog`, group Comercial, priority 30). `useNavigation.ts` is untouched (design), so the entry is highlighted only on `/catalog/categories`; `CatalogSections` (SegmentedTabs) switches between sections, and Phase 7 adds the attributes tab.
- Deactivating asks for `ConfirmDialog`; reactivating does not (design-system §7.10). Messages state only spec facts (inactive category not shown in the portal).
- `ColorPicker` text field emits what is typed (invalid tones reach the backend, E-36); the native picker emits `#RRGGBB` uppercase. Tone is an inline style on the swatch only.
- Not covered: DT-03 image upload on locations (Phase 21).

## Phase 7: Catalog UI 2 — attributes, values and offered colors (COMPLETE, tasks 7.1-7.5)

Chain: PR 7, branch `feat/003-products-7-catalog-ui-2`, targets `feat/003-products-6-catalog-ui-1`. No commit made (orchestrator commits).

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 7.1/7.2 | `tests/Feature/Catalog/AttributeCatalogTest.php` | Feature (HTTP + Inertia props) | 877 tests green at Phase 6 close | 11 new tests, 0 passed (405: GET routes missing; "Not a valid Inertia response") | 11 passed (the Vue pages had to exist first: Inertia asserts the component file, so the GREEN run was after 7.3) | list in sort order with inactive row and value counts, enum option labels, empty list, color attribute values (tone, description, layer, status, `offered_colors` null, empty palette), fabric attribute (offered colors in color order incl. an inactive offered one, active-only palette in order, plain fabric value with `[]`, no color attribute -> empty palette), 4 denied cases (index/show x `products.view` alone / all product permissions but catalog) audited with route names, guest redirect for both, unknown attribute 404 | `phpstan` generics: `array_values()` in `ProductPresenter::palette()` |
| 7.3 | no frontend test runner | n/a | n/a | n/a | `pnpm types:check` and `pnpm build` pass | n/a | `pnpm check:fix` formatting only |
| 7.4 | n/a | n/a | n/a | n/a | forms hold no rule: tone input shown by presentation only, no tone/layer validation in JS | n/a | n/a |
| Follow-up (b) | `tests/Feature/Catalog/DetailLocationTest.php` | Feature | page test green with old fixtures | n/a (fixture fix; test was green but could not detect a binary order) | green with `abertura`/`Bolsillo`/`Manga`/`pechera` (lowercase 'abertura' sorts first only with a case-insensitive order) | 4 rows | n/a |
| Follow-up (a), (c) | no frontend test runner | n/a | n/a | n/a | `pnpm types:check`, `pnpm build` pass | n/a | n/a |

RED run: `sail pest tests/Feature/Catalog/AttributeCatalogTest.php --filter="page|pages"`: 11 tests, 0 passed, 11 failed (405 / not a valid Inertia response). GREEN run: `sail pest tests/Feature/Catalog`: 161 passed, 965 assertions.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Catalog`: 161 passed, 965 assertions |
| Runtime harness | `sail artisan wayfinder:generate` produced `catalog.attributes.index/show`; `sail pnpm build`: built (`AttributeShow` and `Attributes` chunks). Browser check at 375/768/1280 px: PENDING (human) |
| Rollback boundary | `ProductPresenter`, `index`/`show` in `AttributeController`, 2 GET routes, `Attributes.vue`, `AttributeShow.vue`, `ValueForm.vue`, `OfferedColorsEditor.vue`, `ActionErrors.vue`, the "Atributos" tab in `CatalogSections.vue`, the attribute types in `products.ts`, the 8 new page tests; follow-ups: `ActionErrors` wiring in `Categories.vue`/`DetailLocations.vue`, `alsoActiveOn` in `navigation.ts`/`useNavigation.ts`, the `DetailLocationTest` fixture |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 888 tests, 887 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: about 892 new (presenter 114, 2 pages 574, 3 components 204) + about 326 in tracked files (tests 185, types 49, controller 49, other pages/nav/routes ~43) = about 1,220. Over the 400 advisory: two full pages, three components and a presenter; production backend is about 165 lines.
- Props. Index: `attributes[]` `{id, name, presentation, presentation_label, special_use, special_use_label, status, status_label, sort_order, values_count}`, `options {presentations[], special_uses[]}` (enum labels), `can`. Show: `attribute` (same shape), `values[]` `{id, name, description, tone, svg_layer, status, status_label, sort_order, offered_colors}` (`offered_colors` is an array on fabric values, `null` otherwise), `palette[]` of active colors `{id, name, tone, status}` (empty unless the attribute is the fabric), `can`.
- Offered colors editor lists the active palette plus colors already offered that were deactivated since (labelled "(inactivo)"), so saving does not drop them silently; the backend accepts resubmitting an already offered inactive color (Phase 4 rule).
- `ValueForm` drops `tone` from the payload for non-color attributes (the backend prohibits it) via `form.transform`; empty description/layer travel as `''` and become null through Laravel's middleware. The image slot is reserved (`<slot name="image" />`) for Phase 21.
- Follow-up (a): new `ActionErrors.vue` renders move/status validation messages (role=alert) on Categories, DetailLocations, Attributes and AttributeShow; the deactivate dialog now closes in `onFinish` (not only on success) so a rejection is visible. No backend path raises such errors yet (hooks of tasks 9.6 and 22.7 will), so this is covered by types and build only.
- Follow-up (b): fixed the `DetailLocationTest` ordering fixture (lowercase `abertura` first, `Bolsillo`, `Manga`, `pechera`).
- Follow-up (c): done minimally. `NavEntry.alsoActiveOn` (list of Wayfinder URLs matched by prefix) keeps "Catálogo" highlighted on `/catalog/attributes[/id]` and `/catalog/detail-locations`; `/catalog/categories` is its own href. No frontend runner, covered by `pnpm types:check`.
- Manual checks PENDING for the human at 375/768/1280 px: tabs wrap, DataTable card view of attributes/values, color swatch, ColorPicker in the value form, checkbox group filter in the offered colors editor, dialog close on a rejected deactivation, "Catálogo" highlight on every section.

## Phase 8: Products — schema, create, update and lifecycle (COMPLETE, tasks 8.1-8.10)

Chain: PR 8, branch `feat/003-products-8-products`, targets `feat/003-products-7-catalog-ui-2`. No commit made (orchestrator commits).

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 8.1/8.2 | `tests/Feature/Products/CatalogSchemaTest.php` (products part) | Feature (DB) | 15 existing catalog schema tests green | 23 tests, 8 failed (`testing.products` missing; `App\Models\Product` not found) | 23/23 (67 assertions) | 3 CHECKs accepted and rejected per mode (on_demand, stock_depletable, service, stock_with_minimum with and without default), case-insensitive name, FK restrict (category, attribute value, location, service product), unique `(product_id, catalog_attribute_id)`, cascade of attributes/allowed values/locations/customizations, factory states, `admitsCustomColor()` with and without the color attribute | None needed |
| 8.3/8.4 | `ProductWriteTest.php`, `ProductPolicyTest.php` | Feature (HTTP) | N/A (new files; `tests/Feature/Catalog` untouched) | 47 tests, 2 passed (the unknown-product 404 and a source check), 45 failed with 404 (no routes) / missing policy | 47/47 (205 assertions) | E-01 (valid + diaper/portal off), E-02 (supply mode missing + 7 invalid/missing fields), E-03 create and update (403 + `authorization.denied` with route), E-64 (case-insensitive, inactive product reserves name, own name case change), inactive category (create; update keeps own category, rejects another inactive), E-20 (3 modes reject, 6 and 0 accepted, 5 invalid values), DEC-PRD-34 (3 modes reject true and accept false; default true on_demand; opt-out), E-68 (changed-fields audit, category change audit, no-op, duplicate), status not editable by PUT, 404, 6 policy abilities x holder/outsider, no-role user, no role-name grant | Test fix: JSON column returns keys sorted, so the category-audit assertion uses `toEqual` |
| 8.5/8.6 | `ProductStatusTest.php` | Feature (HTTP) | N/A (new) | 7 tests, 6 failed (404), 1 passed trivially (the unknown product 404 passes until the route exists) | 7/7 (35 assertions) | deactivate then reactivate with before/after audit, other products untouched, 403 on both endpoints with audited denial, no-op for both repeated states (no audit, `updated_at` unchanged) | None needed |
| 8.7 | `ProductWriteTest.php` | Feature (HTTP) | 47 green | **No RED observed**: the clearing logic (`min_stock_default`, `allows_custom_color`) was already written in 8.4 because the three CHECK constraints would otherwise reject a mode change; the 8.7 tests were written afterwards and passed on first run | 46/46 in the file after the additions (256 assertions) | E-66 three target modes with previous default in `old_values`; keeping a minimum when leaving the mode is rejected and nothing changes; return to `stock_with_minimum` requires a new default and finds none; default change inside the mode; custom color cleared and defaulted on again; disabled custom color kept across edits; description and portal visibility kept when not sent | None needed |
| 8.8 | n/a | n/a | n/a | n/a | Two empty documented hooks in `UpdateProduct`; all E-66/E-68 edit tests exercise the hook call sites and stay green (the hooks cannot be observed from outside) | n/a | n/a |
| 8.9 | architecture suite | Feature | n/a | n/a | full suite green incl. `NoManualForbiddenTest` and `ProtectedByDefaultTest` | n/a | `composer types:check` 0 errors; Pint clean |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products`: green (CatalogSchemaTest 23, ProductWriteTest 46, ProductStatusTest 7, ProductPolicyTest 10 plus the earlier Phase 1/2 files); `sail pest --filter="E-01\|E-02\|E-03\|E-20\|E-25\|E-26\|E-66\|E-68"` covered by those files |
| Runtime harness | `sail artisan migrate:fresh`, `migrate:rollback --step=1`, `migrate`: migration `2026_10_07_000002_create_products_tables` DONE all three times; `sail artisan route:list --name=products`: 4 routes (store, update, activate, deactivate), no delete and no read pages yet |
| Rollback boundary | Migration 2, `Product`, `ProductAttribute`, `ProductFactory`, `ProductAttributeFactory`, `ProductPolicy`, `CreateProduct`, `UpdateProduct`, `ActivateProduct`, `DeactivateProduct`, `ProductAudit`, 2 requests, 2 controllers, the `/products` routes block, `CatalogRules::productRules()/productMessages()`, the product keys in `lang/es/validation.php`, and the 4 test files (3 new plus the products part of `CatalogSchemaTest`) |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 959 tests, 958 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: about 954 new production/factory/migration lines + 88 modified (`CatalogRules` 65, lang 13, routes 10) + 867 test lines (ProductWriteTest 543, ProductStatusTest 97, ProductPolicyTest 68, schema additions 159) = about 1,910. Over the 400 advisory (design estimate ~500): migration, two models, two factories, four Actions, a policy, two controllers, two requests and 100+ test cases; production alone is about 1,040. Recommend `size:exception` or no further split (the unit is cohesive).
- Redirects: `store` and `update` redirect to the path `/products/{id}` (tests assert that `Location`); the named route `products.show` is declared with its page in Phase 17. `activate`/`deactivate` use `redirect()->back()` (they will be called from the list and the detail page).
- Request fields: `name, description, product_category_id, business_line, supply_mode, min_stock_default, allows_custom_color, portal_visible`. `PUT` keeps `description` and `portal_visible` when they are not sent (same PATCH-like semantics as `UpdateAttributeValue`); an explicit `null` description clears it.
- Interpretations to confirm (not stated by the spec text): (1) when `allows_custom_color` is not sent for an `on_demand` product the backend stores `true` (DEC-PRD-34 says "MUST admit unless the team disables it"; the form default is the same); entering `on_demand` from another mode without an explicit value also stores `true`; staying in `on_demand` keeps the stored value. (2) `allows_custom_color = true` outside `on_demand` is rejected; `false` or `null` is accepted. (3) The category must be active when chosen, except that an edited product keeps its own category if it was deactivated afterwards (otherwise any edit of such a product would fail). (4) Editing the category of a product audits both `product_category_id` and `category_name`.
- Hooks left empty and documented: `UpdateProduct::ensureNotComboComponentChange()` (task 13.6, E-70) and `UpdateProduct::ensureNotServiceReferenced()` (task 11.2, E-70). `ensureNoDetailLocationLayerGap()` is added in Phase 11 as the task says. `ensureHasNoHistory()` is not applicable yet (no delete Action until Phase 16). The override-deletion clause of E-66 is completed in Phase 12 (the `stock_minimum_overrides` table arrives in migration 3).
- The task 2.3 note (category cannot be deleted while referenced) is covered by the FK restrict test on `products.product_category_id`. Deactivating a category that has products is not restricted (the spec does not say so).
- `product_attributes.catalog_attribute_id` and `product_attribute_values.attribute_value_id` use restrict; the composite FK to `(id, catalog_attribute_id)` is not used here (design reserves it for `combination_values` and `combo_component_values`).
- Manual browser checks: none (no UI in this phase).

## Phase 9: Product structure and attribute guards (COMPLETE, tasks 9.1-9.9)

Chain: PR 9, branch `feat/003-products-9-structure`, targets `feat/003-products-8-products`. Resumed after a rate-limit interruption; the working tree was reconciled and the tests re-run before closing.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-----------|-------------|----------|
| 9.1 | covered by 9.3 | schema | 959 tests green at Phase 8 | n/a (schema, test-backed by 9.3 and Phase 10) | `migrate:fresh`, `migrate:rollback --step=1`, `migrate`: DONE all three times | n/a | n/a |
| 9.2/9.4 | `tests/Unit/Products/ProductRulesTest.php` | Unit | n/a (new) | 5 tests, 0 passed (class `ProductRules` not found) | 5/5 | fabric as order, color as axis, first offender wins, valid mix, free roles and empty list | None needed |
| 9.3/9.4 | `tests/Feature/Products/ProductStructureTest.php` | Feature (HTTP) | n/a (new) | 28 tests, 1 passed (the unknown-product 404), 27 failed with 404 (route missing) | 28/28 (with the unit file: 33 tests, 128 assertions) | E-05, PRD-004 own color list, DEC-PRD-35, min one active value, no attributes, shape errors, E-06, foreign value, DEC-PRD-50 both roles, E-69 inactive attribute and value (kept when deactivated later), E-60 three freezes plus axis removal plus allowed edits plus pre-combination edit, E-24 removal, E-57 (restriction, whole attribute, other product, unused size), audit shape, no-op no audit, 403 audited, 404 | `phpstan` list typing in the Action and `CatalogUsage` |
| 9.5/9.6 | `tests/Feature/Catalog/AttributeCatalogTest.php` (E-69) | Feature (HTTP) | 161 catalog tests green | 9 tests, 2 passed (rename and inactive no-op, unchanged behavior), 7 failed | 9/9 (with DEC-PRD-51: 11 tests, 58 assertions) | deactivate naming the product only, clear/change use, change presentation, assign use, rename allowed, inactive product, no-op, alphabetical names | None needed |
| 9.7 | n/a | n/a | n/a | n/a | Two empty documented hooks in `SyncProductAttributes` (`ensureNoComboUsesRemovedValues` task 13.6, `ensureLayerGuard` task 22.7); call sites exercised by the structure tests | n/a | n/a |
| 9.8 | n/a | n/a | n/a | n/a | Role rule already lives only in `ProductRules` | n/a | `composer types:check` 0 errors |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Unit/Products/ProductRulesTest.php tests/Feature/Products/ProductStructureTest.php`: 33 passed; `sail pest --filter="E-69\|DEC-PRD-51"`: 11 passed |
| Runtime harness | migration 3 round trip (fresh, rollback 1, migrate) DONE; route `products.attributes.update` exercised over HTTP |
| Rollback boundary | migration 3, models `Combination`, `CatalogCode`, `StockMinimumOverride`, `Product::combinations()`, two factories, `ProductRules`, `CatalogUsage`, `SyncProductAttributes`, request, `ProductStructureController`, the route, validation strings, the two attribute-in-use guards, and the three test files |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass. `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1003 tests, 1002 passed, 1 skipped (pre-existing).

### Notes

- Errors: structure rules answer on `attributes` (freeze, values in use) or `attributes.{i}.attribute_id|role|allowed_value_ids`; attribute deactivation answers on `status`; presentation/use changes on their own field.
- `Combination` exposes the code through `catalogCode()` (the `code` accessor arrives with the Phase 10 Actions). `catalog_codes.combo_id` has no FK yet (Phase 13).
- Step 6 layer guard and the combo check of step 5 are hooks; step 7 (size cleanup, E-71) is Phase 12.
- Redirect after the structure update is `back()` (the structure page arrives in Phase 14).
- No UI in this phase; no manual checks.

## Phase 10: Codes and commercial combinations (COMPLETE, tasks 10.1-10.6)

Chain: PR 10, branch `feat/003-products-10-combinations`, targets `feat/003-products-9-structure`. Committed with the user's per-phase authorization; no push.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 10.1/10.2 | `tests/Unit/Products/CombinationOverlapTest.php`, `AxisSignatureTest.php` | Unit | n/a (new) | 11 errors (classes not found) | 11/11 (16 assertions) | intersection on every axis, E-48 multi-valued, disjoint axis, missing axis, vacuous overlap without axes (N-2), generator input and order, signature order independence, repeated value, empty axes | None needed |
| 10.3/10.4 | `tests/Feature/Products/CombinationTest.php` | Feature (HTTP) | 1003 tests green at Phase 9 | 42 tests, 1 passed, 41 failed (404 route missing / `CreateCombination` not found) | 43/43 after fixing two test-fixture assumptions (JSON column key order; inactive combos built with factories) | E-07 (+ " o " join), E-08 (+ raw insert), DT-01 (case, `088-1` vs `88`, whitespace, blank, 31 chars, 30 chars, trim), E-09, E-10 (missing, not admitted, foreign, non-axis), E-11, E-48 (+ accepted Gabardina/Dama), DEC-PRD-39 (inactive ignored, DB exact duplicate, duplicate-key mapping), N-2, E-56 (subset, outside, color of fabric product, axis, unknown attribute, empty list, own color list), E-68 (description, no-op, duplicate code, own code case change, axes+restrictions edit, overlap on edit, PRD-012 same rules), E-59 (+ edit while inactive), PRD-013 (status audit, no-op), PRD-016 (create/update/deactivate 403 audited), 404 scoped bindings | `phpstan` fixes (pivot access replaced with a query; list typing) |
| 10.5 | n/a | n/a | n/a | n/a | Code and axis/restriction validation were written directly in `CombinationRules` (pure, array input) so the importer can reuse them | `composer types:check` 0 errors | None needed |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products/CombinationTest.php tests/Unit/Products`: 65 passed |
| Runtime harness | `sail artisan route:list --name=products.combinations` lists store, update, activate, deactivate; all exercised over HTTP |
| Rollback boundary | `CombinationOverlap`, `AxisSignature`, `CombinationRules`, `ProductCombinations`, `CombinationAudit`, `ProductPresenter::descriptiveName/combinationName`, `Combination::code` accessor, `CombinationPolicy`, 4 Actions, 2 requests, `CombinationController`, 4 routes, validation strings, 3 test files |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass. `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1057 tests, 1056 passed, 1 skipped (pre-existing).

### Notes

- Extra helpers not listed in the design file table: `ProductCombinations` (declared structure, axes of a combination, overlap lookup, value sync) and `CombinationAudit` (audit snapshot by name), so Create/Update/Activate/Deactivate do not duplicate queries.
- `ProductPresenter::descriptiveName(Product, list<list<string>>)` takes value names per axis in axis order; `combinationName(Combination)` resolves them from the database (values of an axis in catalog order `sort_order, id`).
- Payload: `code`, `description`, `axes {attributeId: [valueIds]}`, `restrictions {attributeId: [valueIds]}`. An empty restriction list means no restriction. `included_customization_ids` is Phase 11 (the table exists but PRD-008 is out of this slice).
- Overlap on edit only runs while the combination is active; an inactive one is checked on activation (E-59).
- Audit JSON column does not keep key order (MySQL JSON), so tests compare `axes` with `toEqual`.
- The registry-only "combo with the same code" clause of E-08 is completed in Phase 13 (the registry already rejects it).
- `ensureEditableWithoutHistory()` hook is empty in `UpdateCombination` (DEC-PRD-21, 004/006/008).
- Redirect after store/update goes to `/products/{id}` (R-3 precedent); activate/deactivate go back.
- No UI in this phase; no manual checks.

## Phase 11: Product details and customizations (COMPLETE, tasks 11.1-11.3)

Chain: PR 11, branch `feat/003-products-11-details`, targets `feat/003-products-10-combinations`. Committed with the user's per-phase authorization; no push.

### Slices

The phase commit (+834/-15) was split into three chained work-unit commits to respect the 400-line review budget. `feat/003-products-11-details` is kept as the unsplit backup.

| Slice | Branch (builds on the previous) | Authored changed lines | Content |
|---|---|---|---|
| 11a | `feat/003-products-11a-detail-locations` (from `feat/003-products-10-combinations` plus the docs commit) | 237 added, 6 removed | Detail locations: `PUT /products/{id}` `detail_location_ids`, request rules and messages, name-list audit, empty layer-gap hook |
| 11b | `feat/003-products-11b-customizations` | 360 added, 18 removed | Admitted `customization_ids`, combination `included_customization_ids` (E-67 save), `Combination::customizations()`, `included_customizations` audit key |
| 11c | `feat/003-products-11c-service-guard` | about 270 including this table | `CatalogUsage::productsUsingService()`, filled `ensureNotServiceReferenced()` (E-70), messages, tasks 11.1-11.3 marked, this section |

The Phase 11 text below describes the three slices together. The final tree of 11c equals the unsplit commit except for this table.

**Decisions confirmed on 2026-10-08 (DEC-PRD-54..57)** resolved the interpretations listed in the Notes: an omitted list keeps what is stored while an empty list or `null` clears it (DEC-PRD-54); "active when added" is judged against what the product already holds (DEC-PRD-55); an included customization must be an active service when it is added and the ones already included are kept (DEC-PRD-56, behavior change); the E-70 rejection shows each combination as "code (product)" (DEC-PRD-57, behavior change). Three commits were added to the chain. The 11b fix pushed that slice to 446 changed lines, so on the user's request it became its own slice `feat/003-products-11b2-active-service` (78 changed lines) between 11b and 11c:

| Branch | Commit | Content |
|---|---|---|
| 11a | `docs(003): registrar decisiones de personalizaciones y mensaje E-70 [DEC-PRD-54..57]` | Spec §9 rows DEC-PRD-54..57 and design Decisions 12 and 11 (E-70 guard) |
| 11b2 | `fix(003): exigir servicio activo al agregar una personalización incluida [PRD-008, DEC-PRD-56]` | `CatalogRules::includedCustomizationRules()` takes the services already included; `CombinationRules::rules()` receives the edited combination; 3 `DEC-PRD-56` tests |
| 11c | `fix(003): mostrar el producto de cada código en el rechazo E-70 [E-70, DEC-PRD-57]` | `CatalogUsage::productsUsingService()` returns "code (product)"; E-70 tests tightened |

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 11.1/11.2 (details, admitted customizations) | `tests/Feature/Products/ProductWriteTest.php` | Feature (HTTP) | `sail pest tests/Feature/Products tests/Unit/Products`: 204 passed | 9 tests added, 7 failed (422 expected, 302 received; relation not replaced), 2 passed trivially (denied edit, omitted field keeps) | all green | replace with name-list audit, empty list clears, omitted keeps, inactive/unknown location rejected with a held inactive one kept, same set in another order or repeated writes no audit, customization rejected for non-service / inactive / itself / unknown, held deactivated service kept, malformed arrays, 403 | None needed |
| 11.1/11.2 (included customizations, E-67) | `tests/Feature/Products/ProductCrossReferenceTest.php` | Feature (HTTP) | same | new file: 5 tests failed with `Combination::customizations()` undefined, 4 failed with 302 instead of 422 | all green | E-67 (139-1 with vinyl while the product admits none), no ids sent audits `[]`, non-service and unknown id rejected on create and edit, malformed payload, edit with previous/new name lists, omitted keeps and `[]` clears | None needed |
| 11.1/11.2 (E-70 leaving service) | same | Feature (HTTP) + support | same | `CatalogUsage::productsUsingService()` undefined; the guard hook was empty so the 422 tests got 302 | all green | admitting product plus including combination, products only, combinations only, unreferenced service changes mode, referenced service keeps mode while other fields are edited, sorted lookup, 403, a non-service product unaffected | Message built with `match` (no `implode` of translator output: PHPStan) |

RED run: `sail pest tests/Feature/Products/ProductCrossReferenceTest.php tests/Feature/Products/ProductWriteTest.php`: 76 tests, 53 passed, 23 failed (14 failures + 9 errors). GREEN run: `sail pest tests/Feature/Products tests/Unit/Products tests/Feature/Catalog`: 402 passed, 2017 assertions.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products tests/Unit/Products tests/Feature/Catalog`: 402 passed, 2017 assertions |
| Runtime harness | N/A for a new route: no route was added; the new payload keys travel over the existing `PUT /products/{id}` and `POST|PUT /products/{id}/combinations` endpoints, all exercised over HTTP by the new tests |
| Rollback boundary | `UpdateProduct` (relations, E-70 guard, layer-gap hook), `CatalogRules::productRelationRules()/includedCustomizationRules()/relationMessages()`, `UpdateProductRequest`, `Combination::customizations()`, `CatalogUsage::productsUsingService()`, `ProductCombinations::syncCustomizations()`, `CombinationAudit` key `included_customizations`, `CombinationRules::rules()/messages()`, `CreateCombination`/`UpdateCombination` sync lines, the Phase 11 strings in `lang/es/validation.php`, `ProductCrossReferenceTest`, the 9 new `ProductWriteTest` tests and the one-line `CombinationTest` expectation |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1085 tests, 1084 passed, 1 skipped (pre-existing).

### Notes

- Payload keys: `PUT /products/{id}` accepts `detail_location_ids` and `customization_ids`; combination create and edit accept `included_customization_ids`. Each list replaces the stored set when sent (empty list or `null` clears) and keeps it when the key is omitted (same PATCH-like semantics as `description`, Phase 8). Duplicate ids are collapsed.
- Interpretations raised during apply, resolved by the user on 2026-10-08 as DEC-PRD-54..57 (points 2 and 3 below were changed: included customizations must be active when added, and the E-70 message shows each code with its product): (1) "active when added" is evaluated against the set the product already holds, so a location or service deactivated after being admitted can be resubmitted (same precedent as offered colors and the category); (2) included customizations require mode `service` only, as Decision 12 says, not active status; (3) the E-70 message names products and combination codes (codes are globally unique), not the product that owns each combination.
- Audit: `products.updated` carries `detail_locations` and `customizations` as name lists (sorted, case-insensitive) only when they changed; `products.combination_*` snapshots now always carry `included_customizations` (name list), so the Phase 10 E-07 expectation gained `included_customizations => []`.
- Validation of the lists lives in `CatalogRules` (rules on the form requests), the project precedent for the category. The `UpdateProduct` Action trusts the validated ids; a concurrent change of mode of a chosen service between validation and the write is not guarded (the restrict FKs only protect deletions).
- Hooks: `UpdateProduct::ensureNoDetailLocationLayerGap()` is added empty and called with the newly added location ids (filled in task 22.7, PRD-020). `ensureNotServiceReferenced()` is filled. `ProductPresenter` was not changed: nothing reads the new data until the pages of Phases 17 and 19.
- `CreateProduct` does not accept the lists: tasks 11.1/11.2 define them for the edit only.
- Authored lines: about 690 across 14 tracked files and the new test file (production about 230, tests about 540 including the 349-line `ProductCrossReferenceTest`). Over the 400 advisory because the slice has three behaviors (details, admitted and included customizations, E-70) with triangulated tests; no `size:exception` requested.
- Process deviation: the first block of 9 tests was appended to `ProductWriteTest.php` with a shell heredoc and its import was added with `sed`, against the "Edit/Write only" rule; all later edits used Edit/Write. The content is reviewed and committed as is.
- No UI in this phase; no manual checks.

## Phase 12: Stock minimum overrides (COMPLETE, tasks 12.1-12.3)

Chain: PR 12a then PR 12b, from 11c (`8e5849f`); see Slices. Committed with the user's per-phase authorization; no push.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 12.1/12.2 | `tests/Unit/Products/StockMinimumTest.php`, `tests/Feature/Products/StockMinimumTest.php` | Unit (DB) + Feature (HTTP) | 1088 tests green at Phase 11 close (1087 passed, 1 skipped) | 29 tests, 2 passed (E-65 default required on `PUT /products/{id}` and the unknown-product 404: both pass before the route exists), 24 failed (404 route / no deletion), 3 errors (`StockMinimum` not found) | 29/29 (118 assertions) | E-65 (own value 4 on 38, default 2 on 40 and on the restricted combination), E-65 default required, E-20 (3 modes reject, 6 accepted), replace set + audit rows `{code, size, minimum}`, empty list clears and same set writes no audit, size missing / not allowed / outside restriction / unexpected without size attribute, foreign combination, repeated article, 4 invalid minimums, list required, 403 audited, 404, E-66 (delete + audit old/new + new default on return), E-66 staying in mode keeps overrides, E-71 (delete + audit old_values; restriction rejects first and the override survives), N-6 whole attribute removal, structure edit without removal leaves overrides | A `match`-based data set replaced an unreadable nested-ternary mapping before GREEN |

RED run: `sail pest tests/Feature/Products/StockMinimumTest.php tests/Unit/Products/StockMinimumTest.php`: 29 tests, 2 passed, 24 failed, 3 errors. GREEN run: same command, 29 passed, 118 assertions.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products/StockMinimumTest.php tests/Unit/Products/StockMinimumTest.php`: 29 passed, 118 assertions |
| Runtime harness | New route `PUT /products/{product}/stock-minimums` (`products.stock-minimums.update`) exercised over HTTP by the Feature tests; mode change and size removal exercised through the existing `PUT /products/{id}` and `PUT /products/{id}/attributes` |
| Rollback boundary | `StockMinimum`, `SyncStockMinimumOverrides`, `SyncStockMinimumsRequest`, `ProductStockMinimumController`, the route and the `stock_minimum_*` strings in `lang/es/validation.php`; the override deletion and the `stock_minimum_overrides` snapshot key in `UpdateProduct`; step 7 and the `ensureNoStockForRemovedSizes()` hook in `SyncProductAttributes`; the 2 new test files |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1117 tests, 1116 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: about 755 added and 7 removed in the single commit `31f42cf` (production about 340, tests 421), over the 400 advisory, so it was split into the slices below. `feat/003-products-12-stock-minimums` stays at `31f42cf` as a backup.

### Slices

| Slice | Branch | Commits | Content | Lines |
|---|---|---|---|---|
| 12a | `feat/003-products-12a-overrides-core` (from `8e5849f`) | `ba90671` docs DEC-PRD-58..62; `a51e8cf` cleanup of overrides | `StockMinimum`, mode-change deletion (E-66), size-removal cleanup (E-71, N-6), unit and feature tests that seed overrides directly; audit action asserted per case | +403/-7 against `8e5849f` (docs 5, code and tests the rest); slightly over 400, not compressed |
| 12b | `feat/003-products-12b-overrides-endpoint` (from 12a) | `7d60ca2` endpoint | `SyncStockMinimumOverrides`, request, controller, route, lang, endpoint tests (E-20, E-65, validation, 403, `products.stock_minimums_updated`), tasks and this record | +428/-25 against 12a; about 380 are endpoint tests |

`size:exception` accepted by the user on 2026-10-08 for 12a (10 lines over, 5 of them docs) and 12b (mostly the endpoint's own tests; separating them would leave code without its tests).
- Payload: `PUT /products/{id}/stock-minimums` with `overrides: [{combination_id, size_value_id, minimum}]`, a full replace. `minimum` is an integer 0 to 9999. Errors: `overrides` (mode), `overrides.{i}.combination_id|size_value_id|minimum`.
- Audit: `products.stock_minimums_updated`, `products.updated` (mode change) and `products.attributes_updated` (size removal) use the key `stock_minimum_overrides` with rows `{code, size, minimum}` sorted by code and size (`size` null for an article without size). The `products.updated` snapshot now holds that key, so it shows (old = deleted rows, new = `[]`) only when the set changed.
- Interpretations confirmed by the user on 2026-10-08 and recorded in spec section 9 as DEC-PRD-58..62: (58) a non-empty list outside `stock_with_minimum` is rejected on `overrides`, an empty list is accepted as a no-op; (59) overrides are allowed on active and inactive combinations; (60) without the size as an order attribute the override has no size and a size is rejected; (61) the minimum range is 0 to 9999; (62) audit key `stock_minimum_overrides` with `{code, size, minimum}` rows, one event per origin. (Full replace by delete and insert is a technical choice.)
- Hook `SyncProductAttributes::ensureNoStockForRemovedSizes()` is empty (spec 008, spec section 10).
- No UI in this phase; no manual checks.

## Phase 13: Combos (COMPLETE, tasks 13.1-13.7)

Chain: PR 13, branch `feat/003-products-13-combos` (from 12b, `92cdd56`). One phase commit (see Slices for the proposed split). Committed with the user's per-phase authorization; no push.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 13.1/13.2 | `tests/Feature/Products/CatalogSchemaTest.php` (combos part) | Feature (DB) | `sail pest tests/Feature/Products tests/Unit/Products`: 264 passed | 28 tests, 23 passed (existing), 5 errors (`testing.combos` missing; `App\Models\Combo` not found) | 28/28 (94 assertions) | E-28 restrict then delete after the combo, DEC-PRD-45 case-insensitive name and significant accent, composite FK (value of another attribute rejected, repeated value rejected, value delete restricted, cascade with the component), E-08 registry (one code per combo, shared space case-insensitive, no owner / two owners / missing combo rejected, cascade with the combo), models and factories smoke | An index name over 64 characters failed the first GREEN run; named explicitly |
| 13.3/13.4 | `tests/Feature/Products/ComboTest.php` | Feature (HTTP + Action) | N/A (new) | 51 tests, 1 passed (the unknown-combo 404 passes before the routes exist), 28 failed (404 / 405), 22 errors (`CreateCombo` not found) | 52/52 (330 assertions; one test added after the first GREEN) | E-21 (create + audit shape, stored restrictions, N-3 same product twice, inactive product accepted at first; replaced by DEC-PRD-64 tests in the follow-up commit), E-22 (11 cases: quantity 0 / 1000 / 2.5 / missing, service, unknown product, value not admitted, value of another attribute, attribute not declared, color no fabric offers, color outside own list; fabric colors accepted by union; several components at once; empty / not a list / not an object), E-62 (create and edit, combo unchanged), E-64 (case, accent, own-name case change, missing / blank / 151 chars, duplicate-key backstop for name and code), E-08 both directions and case, DT-01 (space, blank, 31 chars, trim), PRD-012 (full replace + audit, rename + visibility, no-op without audit, portal visibility kept, failing edit changes nothing), PRD-013 (toggle audit, no-ops), PRD-016 (3 denials audited, ComboPolicy 6 abilities), 404 | `phpstan`: `array_values()` on a mapped collection |
| 13.5 | `tests/Feature/Products/ProductCrossReferenceTest.php` (E-70 line) | Feature (HTTP) | 18 existing tests in the file green | 5 tests, 1 passed (the "other fields" triangulation test passes before the guard exists), 3 failed (302 instead of 422), 1 error (`combosUsingProduct` undefined) | 23/23 in the file | line change, mode to service, both at once, sorted names without duplicates (two components, inactive combo), unrelated edits and products outside combos | None needed |
| 13.6 | `tests/Feature/Products/ProductStructureTest.php` (E-24 / E-57 combo) | Feature (HTTP) | 28 existing tests in the file green | 5 tests failed (302 instead of 422) | 33/33 in the file | size order value, fabric axis value, whole order attribute, color attribute of a fabric product (no allowed values), names sorted and other products ignored; each case also removes an unrestricted value and succeeds | None needed |

RED runs: `sail pest tests/Feature/Products/CatalogSchemaTest.php` (28 tests, 23 passed, 5 errors); `sail pest tests/Feature/Products/ComboTest.php` (51 tests, 1 passed, 28 failed, 22 errors); `sail pest tests/Feature/Products/ProductCrossReferenceTest.php` (23 tests, 19 passed, 3 failed, 1 error); `sail pest tests/Feature/Products/ProductStructureTest.php` (33 tests, 28 passed, 5 failed). GREEN runs: same commands, 28 / 52 / 23 / 33 passed; `sail pest tests/Feature/Products/ProductCrossReferenceTest.php tests/Feature/Products/ProductStructureTest.php`: 56 passed, 265 assertions.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products/CatalogSchemaTest.php tests/Feature/Products/ComboTest.php tests/Feature/Products/ProductCrossReferenceTest.php tests/Feature/Products/ProductStructureTest.php`: all green (28 + 52 + 23 + 33) |
| Runtime harness | `sail artisan migrate`, `migrate:rollback --step=1`, `migrate`: migration `2026_10_07_000004_create_combos_tables` DONE all three times; the 4 new routes (`combos.store|update|activate|deactivate`) are exercised over HTTP by `ComboTest` |
| Rollback boundary | Migration 4, `Combo`, `ComboComponent`, `CatalogCode::combo()`, `ComboFactory`, `ComboComponentFactory`, `CatalogCodeFactory::forCombo()`, `ComboRules`, `ComboComponents`, `ComboAudit`, `ComboPolicy`, 4 combo Actions, 2 requests, `ComboController`, the `/combos` routes block, `CombinationRules::codeRules()` (and `normalize()` made public), the `combo_*`, `product_in_combos_*` and `structure_value_in_combos` strings, `CatalogUsage::combosUsingProduct()/combosRestricting()`, the filled `UpdateProduct::ensureNotComboComponentChange()` and `SyncProductAttributes::ensureNoComboUsesRemovedValues()` (now with the removed attribute ids), and the new tests in the 4 test files |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1184 tests, 1183 passed, 1 skipped (pre-existing).

### Slices

The phase was far above the 400-line budget (see Notes), so it was split into four chained branches (feature-branch-chain), each from the previous one, each with its own tests and green on its own. `feat/003-products-13-combos` (`1fc3232`) is kept as the unsplit backup.

| Slice | Branch | Tasks | Commits | Content | Authored lines (added / removed) |
|---|---|---|---|---|---|
| 13a | `feat/003-products-13a-combos-schema` (from 12b, `92cdd56`) | 13.1-13.2 | `121a792` docs DEC-PRD-63..70; `8a082ff` schema | Migration 4, `Combo`, `ComboComponent`, `CatalogCode::combo()`, the 3 factories, schema tests | 461 / 6 (the whole branch, docs included; `size:exception` accepted by the user on 2026-10-08) |
| 13b | `feat/003-products-13b-combo-guards` | 13.5-13.6 | `e380d92` | `CatalogUsage` lookups, `UpdateProduct` and `SyncProductAttributes` guards, their lang strings and tests | 385 / 14 |
| 13cd | `feat/003-products-13cd-combo-create` | 13.3-13.4 (create) | `076d290` | `ComboRules`, `ComboComponents`, `ComboAudit`, `ComboPolicy`, `CreateCombo`, `StoreComboRequest`, `CombinationRules` changes, `ComboController::store` and its route, create and validation lang strings, shared combo test helpers in `tests/Pest.php`, `ComboTest` (create and validation tests) | 1,061 / 8 (`size:exception` accepted by the user on 2026-10-08) |
| 13ef | `feat/003-products-13ef-combo-lifecycle` | 13.3-13.4 (edit, lifecycle), 13.7 | `85c582e` | `UpdateCombo`, `ActivateCombo`, `DeactivateCombo`, `UpdateComboRequest`, the other 3 controller actions and routes, `ComboLifecycleTest`, tasks and progress notes | about 590 / 7 (`size:exception` accepted by the user on 2026-10-08) |

`ComboTest` of `1fc3232` was split by whole `it(...)` blocks, with names, bodies and assertions unchanged: create and validation tests stay in `ComboTest.php` (13cd); edit, activate, deactivate, policy mapping and 404 tests, plus the tests that edit a combo (`DEC-PRD-64` held and new-inactive cases, `E-62` and `E-64` on edit, `E-08` own code), moved to `ComboLifecycleTest.php` (13ef). The helpers (`comboCreator`, `comboEditor`, `comboDeactivator`, `comboCatalog`, `comboIds`, `comboComponent`, `comboPayload`, `postCombo`, `putCombo`, `makeCombo`, `comboAudit`, `expectNoComboWritten`) moved unchanged to `tests/Pest.php`, the repository's place for shared test helpers (Pest global functions cannot be declared twice); `comboEditor`, `comboDeactivator` and `putCombo` arrive with 13ef, which first needs them.

### Notes

- Authored lines: about 2,430 added/removed in one commit (production about 1,280 including docblocks; tests about 1,130). The forecast of about 480 undercounted the tests (`ComboTest` alone is 736 lines for 52 cases) and the docblocks; nothing was compressed. `size:exception` or the split above is the parent's call.
- Payload: `POST /combos` and `PUT /combos/{combo}` take `name`, `code`, `portal_visible` (optional, default true on create, kept on edit when not sent) and `components: [{product_id, quantity, values: {attributeId: [valueIds]}}]`. `PUT` replaces the components in full, but keeps the stored rows (and their ids) when the new ones are equal. Errors: `name`, `code`, `components`, `components.{i}.quantity|product_id|values.{attributeId}`.
- Audit: `products.combo_created|updated|activated|deactivated`; snapshot `{code, name, portal_visible, components[{product, quantity, values: {attribute: [value names]}}]}`; update carries only the changed keys; `status` is audited only by the lifecycle actions.
- Locking: the Actions lock the component products in id order (create) and the combo first (edit) inside the transaction, so product edits (line, mode, structure) serialize with the combo writes.
- `E-08` "combo code equals a combination code" is covered in both directions through the shared registry; the Phase 10 clause is now complete.
- Redirects: `store` and `update` go to the path `/combos/{id}` (the `combos.show` route arrives in Phase 20); activate and deactivate go back.
- Interpretations: all resolved by the user on 2026-10-08 (DEC-PRD-63..70 in `docs/specs/003-products.md` section 9):
  1. Combos named in the E-70 and structure guards appear as "code (name)" (DEC-PRD-63). Behavior changed in the follow-up commit below.
  2. A component product must be active when added; existing components are kept if the product is deactivated later (DEC-PRD-64). Behavior changed in the follow-up commit below.
  3. Restriction values only need to be admitted by the product, active or not; for the color of a fabric product, any color offered by any admitted fabric (DEC-PRD-65). Already implemented.
  4. Removing the attribute itself from a product is rejected, naming the combo, when a component restricts it (DEC-PRD-66). Already implemented.
  5. `portal_visible` is optional: visible by default on create, kept on edit when not sent (DEC-PRD-67). Already implemented.
  6. A value used by both a combination and a combo reports the combination error first (DEC-PRD-68). Already implemented.
  7. Quantity 1 to 999 (DEC-PRD-69). Already implemented.
  8. Retiring a fabric, or a color from a fabric, that a component restricts is not blocked; the combo resolves fewer options and stops being offered if none remain (DEC-PRD-70). No rule needed.

### Follow-up commit: DEC-PRD-63 and DEC-PRD-64

Docs commit `docs(003): registrar decisiones de combos [DEC-PRD-63..70]` records the decisions (and the matching wording of design Decisions 10, 11 and 15). The fix commit `fix(003): componentes activos al agregarse y combos como «código (nombre)» [PRD-010, E-70, DEC-PRD-63, DEC-PRD-64]` changes:

- `CatalogUsage::combosUsingProduct()/combosRestricting()` return "code (name)" sorted by code (shared private `comboLabels()`), so the E-70 line/mode guard and the structure guard name each combo that way.
- `ComboRules::validateComponents()` takes the products already held by the combo; a product that is not active is rejected on `components.{i}.product_id` (`validation.combo_component_inactive`) unless the combo already has it. `CreateCombo` holds none; `UpdateCombo` passes the stored components' products.
- The test "PRD-010 accepts an inactive product as a component" (interpretation 2) is replaced by three `DEC-PRD-64` tests (create rejected, held kept on edit, new inactive on edit rejected).
- RED (8 tightened expectations plus the 2 new rejections): `sail artisan test --filter='DEC-PRD-64|E-70|E-24 \(combo\)|E-57 \(combo\)'`: 17 tests, 7 passed, 10 failed (messages without codes, sort by name, inactive products accepted with 302). GREEN: `sail artisan test --filter='Combo|E-70|E-24|E-57|CrossReference|ProductStructure'`: 120 passed.
- `ComboMembership` and the combo deletion (E-28 with `DeleteProduct`, E-61, PRD-014) belong to Phase 16; only the database restrict (E-28 DB clause) exists here.
- No UI in this phase; no manual checks.

## Phase 14 — Unit 14a: Snapshot loader, `SelectionRules`, `ResolveSelection` for products (COMPLETE, tasks 14.1-14.7)

Chain: PR 14, branch `feat/003-products-14a-resolve-selection` (from 13ef, `07af742`). Committed with the user's per-phase authorization; no push. Combo resolution (14.8-14.10) is unit 14b and is not started.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 14.1/14.2 | `tests/Unit/Products/SelectionRulesTest.php` | Unit (no DB) | N/A (new files; 1186 tests green at the start) | 23 tests, 0 passed, 23 errors (`ValueSnapshot`, `AttributeSnapshot`, `CombinationSnapshot`, `SelectionRules` not found) | 22/23 on the first run, 23/23 after fixing a test-fixture bug (PHP array unpacking renumbers integer keys, so `[...$values, 44 => $x]` lost the ids; replaced by `+`) | reachable (partial axes, multi-valued axis E-47, inactive-only axis E-24, value no longer admitted, component restriction on one axis, restriction with no match, empty restriction), `autoApplied` (single value, restriction to one, to two, to none, color with own list, color with custom option), E-14 shape, E-47 chosen value kept, E-16 (missing size, bad size, missing axis, several at once), E-24, E-17 (product, category, attribute DEC-PRD-51, no matching combination), ambiguity, E-54, E-43, E-44, E-49 (shape, 4 invalid tones, note 100 vs 101, no note), E-50, details (valid, location, color, custom as detail color), E-34/E-67, malformed ids | Added the purity guard (no `Illuminate\`, `App\Models\` or `DB::` in `Selection/` except the loader); `phpstan` fine |
| 14.3/14.4 | `tests/Feature/Products/ResolveSelectionTest.php` | Feature (Action + DB) | same | 25 tests (this file, which also holds the 14.5 tests), 0 passed: 24 errors (`ResolveSelection` target class does not exist; `CatalogSnapshotLoader` not found) and 1 failure (messages missing in `lang/es`) | 25/25 (141 assertions) on the first GREEN run | E-14, E-15 (38 ok, 46 rejected), E-16 (size missing, color not offered, both missing, axis missing, axis value of another attribute), E-17 (inactive combination, product, category; unknown or missing product id), E-19 (valid, location not admitted, inactive location, inactive color, non-color value, two details), E-24 (deactivate and reactivate Microfibra, other combination unaffected), E-25 (inactive product, combination keeps own status), E-34 (admitted, not admitted, second of two, admitted but inactive), E-43, E-44, E-46 (added color, removed color, deactivated color), E-47 (Gabardina, Drill, color of the other fabric), E-49 (valid, no tone, bad tone, note 101, note 100), E-50 (stock with minimum, option off, detail, option on), E-54 (2XL rejected on 110-2, XL accepted, 2XL accepted on 110-1), E-67, DEC-PRD-51 (deactivate and reactivate the attribute), ambiguity (two active combinations written straight to the DB), message catalog, snapshot shape (inactive combination skipped, axes vs restrictions, included services, fabric colors, admitted active details and services), custom-color flag | None needed |
| 14.5 | same file | Feature | same | The two DT-02 tests were written in the same batch as 14.3 and observed RED in the same run (class missing). The loader was written with a constant query count from the start, so no separate RED existed for the bound itself | 25/25 | Mutation check: replacing the batched `includedServices()` call by a per-combination loop made both bound tests fail (`37` is not `10`; `38` is not `<= 10`); reverted. 3 versus 30 combinations give the same count; the Action with details runs at most 10 queries and none matches `price` or `stock` | None needed |
| 14.6 | `SelectionRulesTest` (purity guard) | Unit | n/a | n/a (guard added after GREEN; passes by construction) | 24/24 | n/a | `SelectionRules` has no Eloquent, no facade and no translator: it returns reason codes; the Action translates. `composer types:check` 0 errors |

RED runs: `sail pest tests/Unit/Products/SelectionRulesTest.php` (23 tests, 23 errors); `sail pest tests/Feature/Products/ResolveSelectionTest.php` (25 tests, 0 passed, 24 errors, 1 failure). GREEN runs: same commands, 24/24 (120 assertions) and 25/25 (141 assertions).

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products/ResolveSelectionTest.php tests/Unit/Products/SelectionRulesTest.php`: 49 passed (25 + 24) |
| Runtime harness | No route exists in 003 (Decision 14); the Action is called through the container (`app(ResolveSelection::class)->handle([...])`) in 25 tests against MySQL. Query bound observed with `DB::enableQueryLog()`: the snapshot costs the same number of queries for 3 and for 30 combinations (8 for a product with fabric, at most 10), and no statement mentions `price` or `stock` |
| Rollback boundary | `app/Support/Products/Selection/` (8 DTOs, `SelectionRules`, `CatalogSnapshotLoader`), `app/Actions/Products/ResolveSelection.php`, the `selection_*` strings in `lang/es/validation.php`, `SelectionRulesTest`, `ResolveSelectionTest`, and the Phase 14a marks in `tasks.md`. No existing production file was changed except the lang file |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1235 tests, 1234 passed, 1 skipped (pre-existing).

### Notes

- Authored lines: about 1,100 production (DTOs 224, `SelectionRules` 480, `CatalogSnapshotLoader` 332, Action 48, lang 14) plus 1,077 tests (378 unit, 699 feature), about 2,180 in total (code and tests; the `tasks.md` marks and this record come on top). The design estimated about 450: the forecast undercounted the 699-line scenario file (25 tests covering 18 scenarios plus the DT-02 bound) and the docblocks. Nothing was compressed. No cohesive split gets under 400: the smallest whole-file slices are (a) DTOs + `SelectionRules` + `SelectionRulesTest` (about 1,080) and (b) loader + Action + lang + `ResolveSelectionTest` (about 1,090). `size:exception` is recommended.
- Slices (split by whole files on 2026-10-08, `size:exception` accepted by the user for both): `feat/003-products-14a1-selection-rules` (from `07af742`; `ded7257` docs DEC-PRD-71..83, `078e178` DTOs + `SelectionRules` + `SelectionRulesTest`; +1,156, 1211 tests green, 1 skipped) and `feat/003-products-14a2-resolve-selection` (from 14a1; `4d3f5c9` loader + Action + lang + `ResolveSelectionTest` + tasks and this record; +1,165/-7, 1238 tests green, 1 skipped). The final tree equals the unsplit `8512c88` (kept on `feat/003-products-14a-resolve-selection` as backup) except this line.
- Design deviations: `SelectionRules::validate(snapshot, selection, palette)` returns `ResolvedSelection` or `array<string, string>` of reason codes by field key (the design named `ResolvedProductSelection|ErrorBag`); the Action translates the codes with `lang/es` and raises the `ValidationException`. `forProducts()` is not written: it belongs to 14.9. `ValueSnapshot` has no `imageUrls` (assets arrive in Phase 21) and `templates` is always `[]` (the table arrives in Phase 22, migration 5).
- Field keys: `product` (not available), `axes.{attributeId}`, `order.{attributeId}`, `details.{i}.location_id`, `details.{i}.color_value_id`, `customizations.{i}`. Reason codes and Spanish messages are in `lang/es/validation.php` (`selection_*`); only `selection_unavailable` comes from the spec text.
- Queries (product with fabric): product + category, attributes with admitted values, combinations with code and values, fabric colors, included services, admitted services, admitted locations, values. The palette is one more query and only when the selection has details. The templates query will make it 9 + 1.
- Interpretations resolved (user, 2026-10-08) as DEC-PRD-71..83 in `docs/specs/003-products.md` (commit `9472d90`); the numbers below are the original list. All were confirmed as written except (5) and (6), which changed: (5) is now DEC-PRD-77 and (6) is DEC-PRD-78, both rejections (see the commit below). Mapping: (1)=71, (2)=72, (3)=73, (4)=71, (7)=75, (8)=79, (9)=80, (10)=76, (11)=74, (12)=81, (14)=82, (15)=83. Original wording: (1) E-17 for an inactive combination or no matching combination answers on `product` with "La selección no está disponible."; the spec only says "un error indicando que la selección no está disponible", the design gives `product` only for rule 1. (2) A deactivated axis value (E-24) is a field error on `axes.{attributeId}`, not the "no disponible" message. (3) Two active combinations matching: defensive `product` error with a new message ("La selección coincide con más de una combinación."). (4) A missing or unknown `product_id` answers `product` unavailable. (5) [changed by DEC-PRD-77] Attributes sent in `axes` or `order` that the product does not declare were ignored; now `axes.{id}` / `order.{id}` answers `selection_attribute_not_declared` ("El producto no tiene este atributo."). (6) [changed by DEC-PRD-78] The same detail location or customization sent twice was echoed twice; now the repeated entry answers `details.{i}.location_id` with `selection_location_repeated` ("Esta ubicación ya fue elegida.") or `customizations.{i}` with `selection_customization_repeated` ("Esta personalización ya fue elegida."). (7) An included customization is returned as stored even if its service was deactivated later. (8) Custom color: tone is `#RRGGBB` (the catalog tone pattern), returned uppercase; the note is trimmed, counted in characters, blank becomes null, a non-text note is ignored; `custom_color` is ignored when the color is not `custom`; the custom row is `{value_id: null, value: null, custom: true, tone, note}`. (9) When the fabric axis is invalid its error stands alone and the offered-color check is skipped. (10) The combination restriction applies to the color of a product without fabric (DEC-PRD-36) but not to the color of a product with fabric nor to the custom color. (11) `descriptive_name` is the product name plus the chosen axis values joined by " · " (order values are not part of it). (12) `autoApplied()` (named by task 14.1) is a pure function of the snapshot and an optional component restriction: attribute allowed values that are active and inside the restriction; the color is left open when the product offers "Personalizado"; it is unit-tested but not wired to the Action (combos, 14b). (13) `reachableCombinations()` asks for one active, admitted (and component-admitted) value on every axis of the combination; it does not require the chosen value itself to be inside the component restriction (14b validates that). (14) Combinations without a code in the registry are not loaded. (15) `product_id` sent as a string of digits is accepted.
- Follow-up commit (DEC-PRD-77/78, strict TDD): RED = `sail pest SelectionRulesTest ResolveSelectionTest` with 5 failing tests (2 unit, 3 feature: the two new ones per file plus the message-catalog test extended with the 3 new codes; no new code existed); GREEN = 53/53. New tests: `DEC-PRD-77 ...` and `DEC-PRD-78 ...` in both files. No existing test asserted the old ignore/echo behavior; only the message-catalog test (`PRD-011 has a message in Spanish for every reason code...`) changed, by adding the 3 codes. Lines: `SelectionRules` (+`undeclared()`, repeat checks in `details()` and `customizations()`), 3 strings in `lang/es/validation.php`.
- Hooks: none added. No UI in this unit; no manual checks.

## Phase 14 — Unit 14b: Combo resolution (COMPLETE, tasks 14.8-14.10)

Chain: PR 14b, branch `feat/003-products-14b-resolve-combos` (from 14a2, `2fd6051`). Committed with the user's per-phase authorization; no push. Phase 15 is not started.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-----|-------------|----------|
| 14.8 (pure rules) | `tests/Unit/Products/SelectionRulesTest.php` | Unit (no DB) | 53/53 on this file and `ResolveSelectionTest` before any change | 4 new tests; 3 failed in the first run (E-63 restriction, DEC-PRD-81 auto-apply, `componentOffered` missing); the custom-color test was added after the first GREEN run | 4/4 | Restriction on an order attribute, an axis and the color of a product with fabric (DEC-PRD-65); a value the product does not admit keeps its own reason; auto-apply versus a product alone versus a value the client did send; `componentOffered` with inactive fabric, size admitted by none, color offered by no fabric, inactive product, no combinations, custom color with and without a color restriction | None needed |
| 14.8 (Action + loader) | `tests/Feature/Products/ResolveSelectionTest.php` | Feature (Action + DB) | same | 9 new tests plus the message-catalog test extended with 2 codes | 65/66 on the first GREEN pass, 66/66 after fixing two test-fixture bugs and one design point (below) | E-23 (4XG resolves to `11`/`20`/`30`, 2XG errors on the diaper only, every failing component under its own prefix, details of a component prefixed); E-63 (Absorbente restricted to Blanco rejects Azul; Pañal ecológico restricted to Crema rejects Blanco although Algodón offers it); DEC-PRD-81 (axis and order/color applied with no input; a different value sent is rejected, not replaced); not offered (inactive combo, inactive component product, component without active combination, restricted size deactivated, restricted color withdrawn from its fabric, unrelated combo still offered); unknown combo, non-digit id, digit string, component outside the combo | None needed |
| 14.9 | same files | Unit + Feature | same | n/a (production written after RED) | 66/66 (364 assertions) | n/a | `forProduct()` now delegates to `forProducts([$id])`; `composer types:check` 0 errors |

RED run: `sail pest tests/Feature/Products/ResolveSelectionTest.php tests/Unit/Products/SelectionRulesTest.php`: 65 tests, 52 passed, 13 failing (the Pest JSON summary counted `failed: 7` and `errors: 6`; the failure list has 13 entries): message catalog (2 new codes), E-23 resolves (error), E-23 reports (failure), E-63 Azul, E-63 fabric color, DEC-PRD-81 (error), not offered (error), DEC-PRD-70 (error), PRD-011 unknown combo/component, DT-02 combo bound (`forCombo()` missing), unit E-63, unit DEC-PRD-81, unit `componentOffered` (missing). GREEN run: same command, 66 passed, 364 assertions.

Mid-GREEN corrections (all inside the unit): (a) test fixture used `[...$components, 987654 => []]`, which renumbers integer keys (the same PHP gotcha recorded in 14a); replaced by `+`. (b) The bound test compared a combo without a fabric product against one with, which differ by one query; both now contain the eco diaper. (c) Design point: the fixture products admit the custom color, so a component that lists its colors still offered "Personalizado"; see interpretation 4.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products/ResolveSelectionTest.php tests/Unit/Products/SelectionRulesTest.php`: 66 passed (364 assertions) |
| Runtime harness | No route exists in 003 (Decision 14); the Action is called through the container against MySQL. Query bound observed with `DB::enableQueryLog()`: `forCombo()` costs the same for 2 and for 6 components (9: the combo, components and restrictions in one query, plus the 8 of the products), resolving a combo costs the same for both and at most 10, and `forProducts()` costs the same for 1 and for 6 products. No statement of the single-product bound test changed |
| Rollback boundary | `ComboSelectionRules`, `ComboSnapshot`, `ComponentSnapshot`, `ResolvedCombo` (new), the component restriction, `componentOffered`, `withAutoApplied` and `hasOptions` in `SelectionRules`, `forProducts()`/`forCombo()` and the batched private queries in `CatalogSnapshotLoader`, the combo branch in `ResolveSelection`, 2 strings in `lang/es/validation.php`, the new tests, and the 14.8-14.10 marks in `tasks.md` |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1252 tests, 1251 passed, 1 skipped (pre-existing; baseline 1239/1238/1).

### Notes

- Authored lines (code and tests, `tasks.md` and this record on top): about 640 production (additions 533, deletions 107: loader 181/71, `SelectionRules` 125/20, Action 71/16, four new files 154, lang 2) and about 294 tests (293 added). The forecast of about 250 undercounted the loader: `forProducts()` required batching every per-product query of 14a (the 14a loader was per product), and the combo needs its own DTOs and rules. Nothing was compressed. Proposed whole-file split: (a) `SelectionRules` + the four pure files + `lang` + `SelectionRulesTest` (about 360, under budget) and (b) loader + Action + `ResolveSelectionTest` + tasks and this record (about 575, `size:exception`).
- Design deviations: `ResolveSelection::handle()` returns `ResolvedSelection|ResolvedCombo` (the design named only `ResolvedSelection`); `ResolvedCombo` carries `kind: combo`, the combo id/name/code, `requires_advisor` (any component) and, per component, `component_id`, `quantity` and the same `selection` array as a product. `forCombo(int): ?ComboSnapshot` is added next to `forProducts()` (not named by the design); the design's `validate(snapshot, selection, ?componentRestriction)` returns reason codes as in 14a. Combo rules live in a new pure `ComboSelectionRules` so `SelectionRules` does not grow further.
- Input and keys: `combo_id` plus `components` (`componentId => {axes, order, custom_color, details, customizations}`). Errors: `combo` (not offered), `components.{componentId}` (a component the combo does not have) and `components.{componentId}.{field}` with the product keys of 14a. New reason codes: `selection_component_restricted` ("El valor elegido no está admitido por este componente del combo.") and `selection_component_not_in_combo` ("El componente no pertenece al combo.").
- Queries: `forCombo()` is 1 query for the combo, code, components and restrictions (a code-less combo is not loaded, as for combinations, DEC-PRD-82) plus `forProducts()` (8 for products with fabric, combinations and customizations). The palette adds one only when a component has details.
- Interpretations resolved (user, 2026-10-08) as DEC-PRD-84..89 in `docs/specs/003-products.md` (commit `7fa923b`): (1)=DEC-PRD-84, (2)=DEC-PRD-85, (3)=DEC-PRD-86, (4)=DEC-PRD-87, (5)=DEC-PRD-88, (6)=DEC-PRD-89; (7) stays with Phase 15 (PRD-019). Only (4) was extended: the custom color is not admitted in ANY combo component (combos have a closed price, the custom color goes to the advisor, and DEC-PRD-34 limits it to on-demand products while combos are stock), not only in a component that lists colors. The rest was confirmed as written. Design Decision 14 rule 6 mentions DEC-PRD-87/88. Original wording: (1) `components` keyed by component id, and `combo_id` present means a combo selection. (2) A component sent that the combo does not have is rejected (DEC-PRD-77 by analogy). (3) The combo is "offered" when it is active, has components, and every component has its product available (DEC-PRD-64), an active reachable combination under its restriction, and at least one option on every order attribute (DEC-PRD-70); otherwise the error is `combo` with "La selección no está disponible." (DEC-PRD-71 by analogy). (4) A component that lists colors does not admit the custom color. (5) A value the product does not admit keeps `selection_value_not_allowed`; a value the product admits but the component does not is `selection_component_restricted`. (6) E-63 names "protector de cama", which declares no attributes in the E-21 fixture; the test uses the absorbent, which declares Color. (7) The options half of E-63 ("las opciones devuelven solo Blanco") is PRD-019 and belongs to Phase 15.
- Follow-up commit (DEC-PRD-87, strict TDD): RED = `sail pest tests/Unit/Products/SelectionRulesTest.php tests/Feature/Products/ResolveSelectionTest.php`: 68 tests, 65 passed, 3 failing (2 unit: `DEC-PRD-87 validate ...` resolved a custom color in a component without restriction, and `DEC-PRD-87 autoApplied and componentOffered ...` did not apply the only color to a component; 1 feature: `DEC-PRD-87 does not admit the custom color in a component ...` resolved instead of erroring); GREEN = 68/68 (374 assertions). The custom color in a component answers `components.{id}.order.{colorAttributeId}` with the existing `selection_component_restricted` (the product's own `selection_custom_color_not_admitted` still comes first); no new string. Changed in `SelectionRules` only: `colorChoice` (rejects whenever a component restriction exists), `autoApplied` (keeps the color open for "Personalizado" only for a product alone) and `hasOptions` (no custom-color shortcut). Tests replaced: the unit test `E-63 validate does not admit the custom color in a component that lists its colors` (its second assertion expected an unrestricted component to resolve with a custom color) became `DEC-PRD-87 validate does not admit the custom color in any component, restricted or not`; the comment in `PRD-010 componentOffered ...` was updated and its assertions kept. No feature test asserted the old behavior. Added: unit `DEC-PRD-87 autoApplied and componentOffered ...` and feature `DEC-PRD-87 does not admit the custom color in a component, with or without a color restriction`.
- Hooks: none added. No UI in this unit; no manual checks.

## Phase 15 — `ListSelectionOptions` (COMPLETE, tasks 15.1-15.4)

Chain: PR 16, branch `feat/003-products-15-selection-options` (from 14b, `8bddb7f`). Committed with the user's per-phase authorization; no push.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 15.1/15.2 | `tests/Feature/Products/SelectionOptionsTest.php` | Feature (Action + DB) | 68/68 on `ResolveSelectionTest` and `SelectionRulesTest` before touching anything; 68/68 again after moving the shared fixtures to `tests/Pest.php` | 16 tests, 0 passed, 16 errors (`Target class [App\Actions\Products\ListSelectionOptions] does not exist`) | 12/16 on the first run (3 errors: `resMessage()` lives in the other test file, so the file got its own `optMessage()`; 1 failure: the combo bound test counted the query that finds the component id inside the measured callback, 11 instead of 10), 16/16 (103 assertions) after fixing those two test bugs | E-51 (Egyptian cotton path, nothing chosen, Gabardina, the gender step, the multi-valued fabric axis of 159-1 DEC-PRD-33), E-52 (110 with everything: code, id, sizes, colors with tones, custom flag, locations, palette, customizations; inactive color; custom off; product without fabric: cap, polo, inactive color, on-demand cap), E-53 (inactive combination, inactive value), E-54 (restricted 110-2 versus open 110-1), E-55 (110, product without axes, deactivated size), E-63 (restricted to Blanco, unrestricted, product alone with the custom flag; axis and color of a component with fabric, restricted and open), combo not offered (inactive combo, inactive product, unknown combo, non-digit id, unknown or missing component), prefix and value errors (out of order, skipped axis, non-axis attribute, value of another attribute, unknown id, no combination, deactivated value), unavailable product (missing id, unknown id, inactive attribute, product, category), option shape, DT-02 bound (3 versus 30 combinations at 0, 2 and 4 axes; 2 versus 6 components at 0 and 1 axis) | `chosenAxes()` and `sortedValues()` extracted from `options()`; `composer types:check` 0 errors |
| 15.3 | `tests/Feature/Architecture/SelectionHasNoPriceOrStockDependencyTest.php` | Architecture | n/a | The detector is exercised first on a fixture file with `use App\Models\StockMovement`, `use App\Support\Pricing\PriceList` and `DB::table('product_prices')` (comments ignored), so the guard cannot pass trivially; the guard itself passes by construction because Phase 14 and 15 import nothing of the sort | 3/3 (8 assertions) | n/a (structural) | Tokenizes with `PhpToken`: imports and string literals only, so docblocks that say "no price, no stock" do not trip it |

RED run: `sail pest tests/Feature/Products/SelectionOptionsTest.php`: 16 tests, 0 passed, 16 errors. GREEN run: same command, 16 passed (103 assertions).

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Feature/Products/SelectionOptionsTest.php`: 16 passed (103 assertions); `tests/Feature/Architecture/SelectionHasNoPriceOrStockDependencyTest.php`: 3 passed; `ResolveSelectionTest` and `SelectionRulesTest`: 68 passed (unchanged) |
| Runtime harness | No route exists in 003 (Decision 14); the Action is called through the container against MySQL. Query bound observed with `DB::enableQueryLog()`: options cost the same number of queries for 3 and for 30 combinations and at most 10 (a product with fabric with every axis chosen: the 8 of the snapshot plus the palette), and for a component, the same for 2 and for 6 components and at most 10; no statement mentions `price` or `stock` |
| Rollback boundary | `ListSelectionOptions`, `SelectionOptions`, `SelectionRules::options/nextAxisOptions/orderOptions` (+ private `chosenAxes`, `sortedValues`), `ComboSelectionRules::options`, the `selection_axis_out_of_order` string, `SelectionOptionsTest`, the architecture test, the shared fixtures that moved to `tests/Pest.php`, and the Phase 15 marks in `tasks.md`. No production behavior of Phase 14 changed |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test --parallel`: 1273 tests, 1272 passed, 1 skipped (pre-existing).

### Notes

- Authored lines (git diff vs `8bddb7f`, `tasks.md` and this record on top): production 359 added (`SelectionRules` 170, `SelectionOptions` 102, `ListSelectionOptions` 60, `ComboSelectionRules` 26, lang 1) and tests 511 added (`SelectionOptionsTest` 433, architecture test 78). In addition about 260 lines of fixtures moved verbatim from `ResolveSelectionTest.php` to `tests/Pest.php` (`resDeclare`, `resCombination`, `resAxesOf`, `resCatalog`, `resBulkProduct`, `resQueryCount`, `resKitCatalog`, `resComponentId`) because Pest global functions cannot be declared in two files; no body changed. Above the 400-line advisory; the correct slice is cohesive (the Action cannot ship without its DTO, rules or tests), so it was not split.
- Design deviations: `nextAxisOptions` and `orderOptions` return arrays of snapshots (`SelectionOptions` formats them); `orderOptions` takes the chosen axes as well, because the color of a product with fabric depends on the chosen fabric. `SelectionRules::options()` and `ComboSelectionRules::options()` (new, pure) hold the orchestration and take the palette as a closure, so it is read (one query) only when every axis is chosen. The template for the preview (E-41) is not part of the result yet: the table arrives in Phase 22, which extends `ListSelectionOptions` (task 22.6).
- Result shape (`SelectionOptions::toArray()`): `stage: axis` with `axis: {attribute_id, attribute, options}`, or `stage: order` with `code`, `combination_id`, `order: [{attribute_id, attribute, options, allows_custom_color}]`, `detail_locations: [{id, name, layer, image_urls}]`, `palette: [option]` and `customizations: [{id, name}]`. Option: `id`, `name`, `sort_order`, `description`, `image_urls`, `tone`, `layer`.
- Input and keys: `product_id`, or `combo_id` + `component_id`, and `axes` (`attributeId => valueId`). Errors: `product` / `combo` (not available), `component_id` (not in the combo), `axes.{attributeId}`. New reason code: `selection_axis_out_of_order` ("Elija los ejes en el orden del producto."). The others are the existing `selection_*` codes. Values are listed by sort order and then id.
- Interpretations resolved (user, 2026-10-08) as DEC-PRD-90..96 in `docs/specs/003-products.md`. Mapping: (1)=DEC-PRD-90, (2)=91, (3)=92, (4)=93, (5)=94, (6)=95, (7)=96. All confirmed as written, with two notes: (4) was documented only (`palette` stays once in the result, it is the palette for the details; no code change), and (5) was extended: each `order` group now carries `auto_applied`, true exactly when the criterion of DEC-PRD-94 holds (first drafted over `autoApplied()`; replaced by `singleListedValue`, see below). PRD-019 in the spec and in the delta spec cites DEC-PRD-93 and DEC-PRD-94. The original wording follows (item 8, the `sed -i` deviation, stays recorded as-is).
- Follow-up evidence (DEC-PRD-94): RED `sail pest tests/Feature/Products/SelectionOptionsTest.php`: 19 tests, 16 passed, 3 errors (`Undefined array key "auto_applied"`), the three new `DEC-PRD-94` tests (single size true and several false; color with Personalizado false with one color listed and true without it; E-63 component restricted to Blanco true, open component and product alone false). GREEN: same file plus the architecture test, 22 passed (124 assertions). The architecture test now removes its temp file in `try/finally`. Slice-close gate: `pint` and `pint --test` passed, `composer types:check` 0 errors, `pnpm build` built, `artisan test --parallel` 1276 tests, 1275 passed, 1 skipped (pre-existing).
- Risk noted (superseded by DEC-PRD-94 option B, below): `auto_applied` followed `autoApplied()`, which looked at the product-level values (and the component restriction) but not at a combination restriction (DEC-PRD-36) nor at the colors of a fabric (DEC-PRD-35).
- DEC-PRD-94 resolved as **B with an exception** (user, 2026-10-08), recorded first in `docs/specs/003-products.md` (row DEC-PRD-94, PRD-019 text), mirrored in the delta spec (PRD-019 bullet) and in `design.md` Decision 14. An order group whose listed options (product, component restriction, combination restriction, fabric colors) reduce to exactly one is listed and marked `auto_applied: true`, and the resolution applies that value when the client omits it; exception: the color group when `allows_custom_color` is true (DEC-PRD-34). Implementation: one pure function `SelectionRules::singleListedValue(listedIds, customColorOffered)`, used by `orderOptions` (the flag) and by `validate` (products alone and combo components), over the same private `listedOrderValues()` / `customColorOffered()` helpers, so options and resolution cannot disagree. `validate` now resolves the combination before the order attributes (an omitted one depends on it). `autoApplied()` was replaced by `autoAppliedAxes()` (axes only, semantics for axes unchanged: components only, DEC-PRD-81); no axis semantics changed, so no [DEC-PENDIENTE].
- DEC-PRD-94 evidence: RED (3 files, 93 tests): 84 passed, 3 failures + 6 errors (undefined `autoAppliedAxes` x2, undefined `singleListedValue`, and "Elija un valor." on the 3 new resolution tests; options flag false instead of true x2; unit `validate` returned errors). GREEN: same 3 files, 93 passed (521 assertions). Slice-close gate: `pint` and `pint --test` passed, `composer types:check` 0 errors, `pnpm build` built, `artisan test --parallel` 1282 tests, 1281 passed, 1 skipped (pre-existing). New tests: unit `singleListedValue` and `validate` with every restriction; options (combination restricts size to one; fabric with one color with and without Personalizado); resolution (omitted size restricted by combination; omitted fabric color alone, error kept with Personalizado; combo component color alone with and without fabric).
- Flipped tests: no existing test changed behavior, that is, none expected an error for an omitted single-value order attribute. Updated by design (the function was replaced): `PRD-010 autoApplied picks the attribute left with exactly one admitted active value` became `PRD-010 autoAppliedAxes picks the axis ...` (order attributes no longer in its result; asserts that explicitly); `PRD-010 autoApplied leaves the color to the client ...` was removed (its behavior is now `DEC-PRD-94 singleListedValue ...` and the `validate` unit test); `DEC-PRD-87 autoApplied and componentOffered ...` became `DEC-PRD-87 componentOffered ...` (the component color application is covered by the DEC-PRD-94 tests). The existing `DEC-PRD-94` options tests kept their expectations.
- Original interpretations (none blocked; each one is the simplest reading of PRD-019 and DEC-PRD-31/44/87, implemented and tested):
  1. Chosen axes that are not the first axes of the product in order answer on `axes.{attributeId}` with a new message ("Elija los ejes en el orden del producto."); an attribute that is not an axis of the product answers `selection_attribute_not_declared` (DEC-PRD-77 by analogy).
  2. A chosen value the product (or the component) does not admit, or that is inactive, answers on `axes.{attributeId}` with the resolution codes (`selection_value_not_allowed`, `selection_component_restricted`); chosen values that reach no active combination answer `product` "La selección no está disponible." (DEC-PRD-71 by analogy). No empty list is returned.
  3. The custom color is a flag `allows_custom_color` on the color attribute group, not an entry of `options`; it is true only for a product that admits it on its own (PRD-004) and never for a component of a combo (DEC-PRD-87).
  4. `image_urls` is `[]` for every option and location: the image routes arrive with Phase 21, which fills it. Locations carry `id`, `name`, `layer`, `image_urls` only (they have no sort order or description in the catalog).
  5. A component with an axis or order attribute left with one admitted value still lists that single option (DEC-PRD-81 applies it automatically on resolution, but the options show it so the quoter can display the step).
  6. In a combo query, `component_id` missing or not in the combo answers `component_id` "El componente no pertenece al combo." (DEC-PRD-85 by analogy), and a combo that is not offered answers `combo` (DEC-PRD-86).
  7. Customizations are the product's admitted active ones; the ones a combination already includes (DEC-PRD-47) are not added to or removed from that list.
  8. The one deviation from the tooling rules: the four `[x]` marks in `tasks.md` were written with `sed -i` instead of the Edit tool (same result as four Edit calls).
- Hooks: none added. No UI in this phase; no manual checks.

## Phase 16 — Restricted delete (COMPLETE, tasks 16.1-16.4)

Chain: PR "[16/26]", branch `feat/003-products-16-restricted-delete` (from `feat/003-products-15-selection-options`). Committed with the user's per-phase authorization; no push.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 16.1/16.2 (pure rule) | `tests/Unit/Products/ComboMembershipTest.php` | Unit (no DB) | n/a (new) | 7 tests, 0 passed, 7 errors (`Class App\Support\Products\ComboMembership not found`) | 7/7 | unrestricted axis (missing and empty entry), shared value on every restricted axis, disjoint on one axis, restricted plus unrestricted axis, restriction on an order attribute ignored, no value on a restricted axis, product without axes | None needed |
| 16.1/16.2 (Actions + HTTP) | `tests/Feature/Products/DeleteProductTest.php` | Feature (HTTP + Action + DB) | `ProductCrossReferenceTest`, `ComboLifecycleTest`, `CombinationTest` untouched | 20 tests (27 with the unit file), 0 passed: 16 failed with 405 (no DELETE route), plus 3 test-fixture errors (`Talla` created twice by mixing `resCatalog()` and `comboCatalog()`), fixed in the test before GREEN | 19 passed, 1 todo (26 passed and 1 todo with the unit file, 164 assertions) after the two audit assertions switched from `toBe` to `toEqual` (JSON column returns keys sorted) | E-27 (camisa: attributes, details, customizations, 5 combinations with codes, restrictions and included service in one delete; gorra with an own minimum copied and deleted; images deleted after commit), E-28 (JSON 422 with combo, flash redirect for non-JSON, file kept), E-70 delete (admitted + included, admitted only, included only, unused service deletes), E-61 (2XG rejected naming the combo, 3XG deleted with audit; component without restriction rejects every combination and names only that combo), combination own minimums in the copy, combination under another product 404, combo delete (components, values and code gone, products kept, audit copy), code and name freed, 403 x3 endpoints with `authorization.denied` audited, guest to `/login`, unknown ids 404, E-29 `todo` | `structure()` moved from `SyncProductAttributes` to `ProductAudit::structure()` (shared by the structure audit and the product copy); `Lang::string()` for the service message (PHPStan `array\|string`) |
| 16.3 | `NoManualForbiddenTest`, `ProtectedByDefaultTest` | Architecture | n/a | n/a | full suite green | n/a | `composer types:check` 0 errors; no abort(403) introduced; no history FK assumed |

RED run: `sail pest tests/Unit/Products/ComboMembershipTest.php tests/Feature/Products/DeleteProductTest.php`: 27 tests, 0 passed (16 failed with 405, 10 errors). GREEN run: same command, 26 passed, 1 skipped (E-29 todo), 164 assertions.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail pest tests/Unit/Products/ComboMembershipTest.php tests/Feature/Products/DeleteProductTest.php`: 26 passed, 1 todo (E-29), 164 assertions |
| Runtime harness | Real HTTP `DELETE /products/{id}`, `/products/{id}/combinations/{id}` and `/combos/{id}` against MySQL through the test client (JSON 422 and flash redirect observed); route names `products.destroy`, `products.combinations.destroy`, `combos.destroy`. Browser: N/A (no UI in this phase) |
| Rollback boundary | `ComboMembership`, `DeleteProduct`, `DeleteCombination`, `DeleteCombo`, `CatalogUsage::combosIncludingCombination()`, `ProductAudit::structure()` (and its call sites in `SyncProductAttributes`), the three `destroy` methods and routes, 5 `delete_*` strings in `lang/es/validation.php`, the two new test files and the Phase 16 marks in `tasks.md` |

### Slice-close gate

- `sail pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (72 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test --parallel`: 1309 tests, 1307 passed, 2 skipped (1 pre-existing + the E-29 todo; baseline 1282/1281/1).

### Notes

- Authored lines: about 319 new production (`DeleteProduct` 148, `DeleteCombination` 85, `DeleteCombo` 50, `ComboMembership` 36) + about 110 in tracked files (controllers 50, `CatalogUsage` 42, `ProductAudit` 27 with 29 removed from `SyncProductAttributes`, lang 7, routes 3) + 382 test lines (`DeleteProductTest` 345, `ComboMembershipTest` 37) = about 810. Above the 400 advisory; the unit is cohesive (three Actions share the rule, the usage query and the tests), so it was not split. Production alone is about 430.
- Rejections are `BusinessRuleViolation` (design Decision 17), rendered by the existing hook: error flash back, 422 for JSON. Messages (Spanish, in `validation.php`): product "es componente de los combos «code (name)»", combination "forma parte de los combos ...", service "lo admiten los productos ... y lo incluyen las combinaciones ...", all suggesting to deactivate. Combos are labelled "code (name)" as in DEC-PRD-63.
- Redirects: product delete goes to `/products` and combo delete to `/combos` (list pages arrive in Phases 17 and 20, 002 precedent); combination delete goes to `/products/{id}`.
- Audit copy of a product (`products.deleted` `old_values`): `ProductAudit::snapshot` fields, `detail_locations` and `customizations` names, `structure`, `combinations` (each `CombinationAudit::snapshot` with its code), `stock_minimum_overrides`, `template_files`. `template_files` is always `[]` for now because the templates table arrives in Phase 22; that phase must fill it (and delete the template files after commit). A combination copy adds its own `stock_minimum_overrides`; a combo copy is `ComboAudit::snapshot`.
- Stored files: only the product image paths exist today (private `local` disk), deleted in `DB::afterCommit()`; the test confirms they are deleted after a successful delete and kept on a rejection (afterCommit runs under `RefreshDatabase`, which resolves the open point of the design checklist for this case).
- `ensureHasNoHistory()` is empty and documented in the three Actions (004, 006, 008 add their condition and E-29 test). E-29 is `it('E-29 bloqueo por historial')->todo('se prueba en 004, 006 y 008')`.
- Interpretation (not stated by the spec text): a deleted product that is a combo component is blocked whatever the status of the combo and whichever combinations the product has (design Decision 17: "any `combo_components.product_id`"), as `CatalogUsage::combosUsingProduct()` already counts combos for E-70 edits. Deleting a combination checks only the axes (DEC-PRD-42); restrictions on order attributes of the component do not make a combination "part of" the combo.
- Hooks: none added besides the three `ensureHasNoHistory()`. No UI in this phase; no manual checks.

## Phase 17 — Product list and detail UI (COMPLETE except the manual responsive check, tasks 17.1-17.5)

Chain: PR "[17/26]", branch `feat/003-products-17-product-list` (from `feat/003-products-16-restricted-delete`). Committed with the user's per-phase authorization; no push.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 17.1/17.2 | `tests/Feature/Products/ProductListTest.php` | Feature (HTTP + Inertia + DB) | `CatalogSchemaTest` (scopeSearch) untouched | 27 tests, 0 passed (26 failed: 405 / "Not a valid Inertia response" because no `GET /products` route; 1 error: test fixture put a combo code on a combination, fixed in the test with `forCombo()` before GREEN) | 25/27 after backend (the 2 left asserted `component('products/...')`, which needs the Vue pages), 27/27 (372 assertions) after 17.3 | E-04 (index and show 403, guest to login), E-30 (code `147-12`, fragment and case, accent-insensitive name, product listed once for several matching codes, combo codes not searched), `q` trimmed / cut to 100 / non-string, `%` and `_` literal in names and codes, status default / unknown / array fallback, Inactivos and Todos, counts with the same filters whatever the view, category / line / mode filters with counts, nonexistent filter values ignored, filter options labelled, row shape, pagination 15 with query string, Show props (general, `admits_custom_color` true and false, structure with roles and `follows_fabric`, combinations with descriptive name / restrictions / included services / status, details, customizations, images, stock default and overrides, `can` flags for three permission sets, inactive product, 404) | `composer types:check` fixed 3 errors (union Collection param, list-typed `$axes`) |
| 17.3 | `resources/js/pages/products/{Index,Show}.vue`, `types/products.ts`, `navigation.ts` | Frontend | n/a | n/a (the two `component()` assertions of 17.1) | `sail pnpm types:check`, `pnpm check`, `pnpm build` pass | n/a | n/a |

RED run: `sail artisan test --filter=ProductListTest`: 27 tests, 0 passed. GREEN run: same command, 27 passed (372 assertions).

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused tests | `sail artisan test --filter=ProductListTest`: 27 passed (372 assertions) |
| Runtime harness | Real HTTP `GET /products` and `GET /products/{id}` against MySQL through the test client with `assertInertia`; Wayfinder regenerated (`sail artisan wayfinder:generate`) and `sail pnpm build` compiled both pages. Browser check at 375 / 768 / 1280 px: **PENDING for the human** (17.4) |
| Rollback boundary | `ProductController::index/show`, `Product::scopeSearchByNameOrCode`, `ProductPresenter::listRow/filterOptions/detail` (+ private `structure`, `combinationRows`, `references`), the two `GET` routes, `pages/products/{Index,Show}.vue`, `types/products.ts` additions, the "Productos" entry in `navigation.ts`, `ProductListTest`, and the Phase 17 marks in `tasks.md` |

### Slice-close gate

- `sail pint` then `pint --test`: passed. `sail composer types:check`: 0 errors. `sail pnpm check`: pass (74 files formatted, no lint warnings). `sail pnpm types:check`: pass. `sail pnpm build`: built. `sail artisan test`: 1336 tests, 1334 passed, 2 skipped (1 pre-existing + the E-29 todo).

### Notes

- Authored lines: about 1461 (additions + deletions, tracked and new files, excluding generated Wayfinder files and this record): production PHP about 300 (`ProductPresenter` 192, `ProductController` 85, `Product` 26, routes), frontend about 690 (`Index.vue` 238, `Show.vue` 342, types 102, navigation 10), tests 458. Above the 400 advisory; the unit is cohesive (a page cannot ship without its props, route and test), so it was not split: recommend `size:exception`.
- List contract: `GET /products?status=active|inactive|all&q=&category=&line=&mode=`; props `status`, `q`, `category` (int|null), `line`, `mode`, `counts {active, inactive, all}` (same `q` and filters), `filters {categories, lines, modes}`, `products` (paginated 15, query string kept; row: `id, name, category, business_line_label, supply_mode_label, status, status_label, combinations_count`). Unknown or malformed filter values are ignored (null). Name search is accent- and case-insensitive; the code search is a contains-match over the codes of the product's combinations (combo codes are not searched, a product is listed once).
- Show contract: `product {general fields, admits_custom_color, attributes[{id,name,role,role_label,values,follows_fabric}], combinations[{id,code,name,status,status_label,restrictions,included_customizations}], detail_locations, customizations, stock {default, overrides}, images {has_main, templates}}` and `can {update, deactivate, delete, createCombination}`. `images` exposes only `has_main` and an empty `templates` list: image URLs arrive with the upload slices (Phases 19 and 22) and raw storage paths are never exposed. `follows_fabric` (backend) lets the page say "Los colores salen de la tela elegida." for a color declared without values (DEC-PRD-35).
- Interpretations (no [DEC-PENDIENTE]): (1) the code search is a contains-match (consistent with the name search and the customers list); (2) the "Nuevo producto", "Editar" and "Nueva combinación" buttons are not rendered yet because their pages arrive in Phases 18 and 19 (the `can` flags are already in the props); (3) the delete rejection reason is the backend error flash already shared by the layout, and "Desactivar" is on the same page (customers precedent).
- Navigation: "Productos" (`inventory_2`, `products.view`, group Comercial, priority 20). "Combos" arrives with Phase 20.
- Hooks: none. Manual responsive check (17.4) pending for the human.
