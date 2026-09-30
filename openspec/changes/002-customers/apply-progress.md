# Apply Progress: 002-customers

Mode: Strict TDD. Runner: `./vendor/bin/sail artisan test` / `./vendor/bin/sail pest --filter=...`.
Delivery: 10 PRs (one per phase), `feature-branch-chain`, tracker `feat/002-customers`. No commits made (AGENTS.md section 11).

## Phase 1 (PR 1): Permissions, audit cases and seeding matrix — COMPLETE (9/9 tasks)

Branch: `feat/002-customers-1-permissions`. Changes left in the working tree.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-------|-------------|----------|
| 1.1/1.2 | `tests/Unit/PermissionNameTest.php`, `tests/Unit/AuditActionTest.php` | Unit | existing 6 tests | 5 failed + 1 error (undefined `CustomersView`) | 7/7 pass | descriptions of all 7 perms asserted; 24 values/labels | none needed |
| 1.3 | `FoundationSeederTest`, `Roles/PermissionCatalogTest`, `Roles/PermissionCoherenceTest`, `LastAdministratorTest`, `Pest.php` docblock | Feature | full suite 238 pre-change green | PermissionCatalogTest failed (Administrador held `customers.portfolio` under old RoleSeeder) | pass after 1.5 | matrix per role asserted | none |
| 1.4/1.5 | `tests/Feature/Customers/PermissionMatrixSeedingTest.php` | Feature | n/a (new) | 4 failed + 1 error (class `InitialRolePermissions` missing) | 5/5 pass | fresh DB, 001 DB, no re-grant, renamed role, missing-role warning | none |
| 1.6/1.7 | `tests/Feature/Customers/CustomerPermissionCoherenceTest.php` | Feature (HTTP) | `PermissionCoherenceTest` green | 9 failed (302 instead of 422), 2 valid-path tests passed as expected | 11/11 pass | 6 dependents, existing-set replace, both-rules-in-one-422, valid set | two rule blocks left as-is (no helper worthwhile) |
| 1.8 | `NoManualForbiddenTest` | Feature | - | - | passes in full suite | - | - |
| 1.9 | all gates | - | - | - | see below | - | - |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test | `sail pest --filter="E-41\|DEC-CLI-32\|DEC-CLI-11"`: 17 passed, 103 assertions |
| Runtime harness | `migrate:fresh --seed` on the `testing` database (not the dev DB): `Permission::count()` = 16, `permission_role` rows = 25 (15 Administrador + 5 Gerente + 4 Asesora + 1 Finanzas) |
| Rollback boundary | `PermissionName`, `AuditAction`, `InitialRolePermissions`, `FoundationSeeder`, `RoleSeeder`, `PermissionCatalogSeeder` docblock, `SyncRolePermissions` rule, the 001 test edits and the new Customers tests together |

### Slice-close gate (observed)
- `sail artisan test`: 238 passed, 1393 assertions
- `sail pint --test`: passed
- `sail composer types:check`: phpstan passed, 0 errors
- `sail pnpm types:check`: vue-tsc clean
- `sail pnpm build`: built OK

### Deviations
- Design Decision 16 missed two literals: `tests/Feature/Roles/RoleManagementTest.php:151,:173` (`has(..., 9)`). Fixed and added to the Decision 16 table in `design.md`.
- `InitialRolePermissions::apply()` is static and takes a third optional `?Closure $warn`; `FoundationSeeder` passes `Laravel\Prompts\warning` (using `$this->command` fails PHPStan: non-nullable in Laravel's docblock).
- Real authored diff about 536 lines (201 tracked changes + 335 new files), mostly tests: over the ~450 threshold, `size:exception` recommended for PR 1.
