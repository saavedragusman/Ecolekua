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
