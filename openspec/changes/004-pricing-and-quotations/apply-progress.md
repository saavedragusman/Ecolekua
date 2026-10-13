# Apply progress: 004-pricing-and-quotations

Mode: Strict TDD. Runner: `./vendor/bin/sail artisan test` (scoped `sail pest --group=cot --filter=...`).

## Phase 1 (PR 1, `feat/004-pricing-and-quotations-1-permissions`): tasks 1.1 to 1.9 done; 1.10 gate green, commit pending (needs user authorization)

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 1.1 | `tests/Pest.php` (group `cot`) | Config | N/A | n/a | `--group=cot` runs 14 tests | single | n/a |
| 1.2/1.3 | `tests/Unit/PermissionNameTest.php`, `tests/Unit/AuditActionTest.php` | Unit | 2 files green before | 5 failed + 2 errors (28/74 cases missing, `QuotationsView` undefined, `pricing.scheme_created` invalid backing value) | 10/10 passed | descriptions (6) and labels (26) asserted one by one | none needed |
| 1.4 | FoundationSeeder, Customers matrix, PermissionCatalog tests | Integration | baseline 1644 tests, 3 failed once catalog grew (21 vs 27, 15 vs 9, catalog set) | RED observed in the full run | green after 1.6 | n/a | n/a |
| 1.5/1.6 | `tests/Feature/Pricing/PricingPermissionsTest.php` (DEC-COT-25) | Integration | N/A (new) | 5 failed (matrix absent) | 5/5 passed | fresh DB, existing 003 DB, revoked not re-granted, renamed role, manage holders | none needed |
| 1.7/1.8 | same file (E-47, DEC-COT-52) | Integration (HTTP) | 12 existing coherence/architecture tests green | 6 failed (302 instead of 422), 3 valid cases passed | 9/9 passed | create/negotiate/cancel, manage, replace set, several rules together, valid sets, no products.view dependency | 1.9 below |
| 1.9 | same + ProductPermissionCoherenceTest + NoManualForbiddenTest | Refactor | 12/12 | n/a | 12/12 after `dependentsOf()` helper | n/a | helper shared by products and quotations blocks |

Note: `ProductPermissionMatrixSeedingTest` needed no edit (it filters with `like 'products.%'`, not by exclusion).

### Gate (2026-10-13)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.38s)
- `sail artisan test`: passed, 1658 tests (1656 passed, 2 skipped, 9523 assertions)
- Harness `db:seed --class=FoundationSeeder` then `Permission::count()`: 28

### Files
Modified: `app/Enums/PermissionName.php`, `app/Enums/AuditAction.php`, `database/seeders/InitialRolePermissions.php`, `app/Actions/Roles/SyncRolePermissions.php`, `tests/Pest.php`, `tests/Unit/PermissionNameTest.php`, `tests/Unit/AuditActionTest.php`, `tests/Feature/FoundationSeederTest.php`, `tests/Feature/Customers/PermissionMatrixSeedingTest.php`, `tests/Feature/Roles/PermissionCatalogTest.php`, `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `tests/Feature/Pricing/PricingPermissionsTest.php`, this file.

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest --group=cot --filter='E-47 \|DEC-COT-25'`: passed |
| Runtime harness | seeder on local DB, `Permission::count()` = 28 |
| Rollback boundary | revert enums, `InitialRolePermissions`, `SyncRolePermissions` block, Pest group and test edits together |

### Remaining
Task 1.10 commit (user authorization). Phases 2 onward not started.
