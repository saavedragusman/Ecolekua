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
