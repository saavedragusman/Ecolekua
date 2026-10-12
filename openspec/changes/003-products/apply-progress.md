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
