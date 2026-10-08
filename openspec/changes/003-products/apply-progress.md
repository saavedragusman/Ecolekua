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
