# Apply Progress: 001-foundation

Mode: Strict TDD (openspec/config.yaml `strict_tdd: true`). Runner: `./vendor/bin/sail pest` / `./vendor/bin/sail artisan test`.
Delivery: feature-branch-chain (PR #1 targets tracker `feat/001-foundation`). No commits made (project rule).

## Completed tasks (26/102)

- [x] 1.1 Sail MySQL trigger-privilege fix
- [x] 1.2 RED E-29 (b)(c)(d) test
- [x] 1.3 GREEN audit_logs migration + triggers
- [x] 1.4 GREEN AuditLog model + AuditLogIsImmutable
- [x] 1.5 REFACTOR pint + migrate round-trip

- [x] 1.6 GREEN (documented exception) users table migration
- [x] 1.7 GREEN (documented exception) roles/permissions/pivots migration
- [x] 1.8 GREEN (documented exception) login_throttles migration
- [x] 1.9 GREEN Role + Permission models
- [x] 1.10 GREEN User model (fillable/casts/hidden, email mutator, roles(), hasPermission(), permissionNames(), $rememberTokenName='')
- [x] 1.11 RED PermissionNameTest
- [x] 1.12 GREEN PermissionName enum
- [x] 1.13 RED AuditActionTest
- [x] 1.14 GREEN AuditAction enum
- [x] 1.15 REFACTOR pint + migrate:fresh

- [x] 1.16 RED AuditRedactorTest
- [x] 1.17 GREEN AuditRedactor
- [x] 1.18 RED AuditOriginTest
- [x] 1.19 GREEN AuditOrigin
- [x] 1.20 RED AuditRecordTest (initial subset)
- [x] 1.21 GREEN RecordAuditEvent
- [x] 1.22 RED FoundationSeederTest
- [x] 1.23 GREEN PermissionCatalogSeeder, RoleSeeder, FoundationSeeder, DatabaseSeeder
- [x] 1.24 GREEN UserFactory states + RoleFactory
- [x] 1.25 infra: phpunit.xml, Pest.php, TestCase.php, ExampleTests deleted
- [x] 1.26 REFACTOR pint + full suite

E-29(a) intentionally deferred to task 7.6.

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | N/A (infra) | Runtime check | N/A | N/A | log_bin=0 observed | N/A | N/A |
| 1.2 | tests/Feature/Audit/AuditImmutabilityTest.php | Integration | 2/2 (ExampleTest x2) | 5 written, 5/5 failed: `Table 'testing.audit_logs' doesn't exist` | n/a | 5 cases (model update, model delete, DB update, DB delete, row unchanged) | n/a |
| 1.3 | same | Integration | N/A (new) | after migrate: 2 passed (E-29 c), 3 failed (`Class App\Models\AuditLog not found`) | DB-level GREEN | UPDATE and DELETE both | n/a |
| 1.4 | same | Integration | N/A (new) | n/a | 5/5 passed, 10 assertions | model update via save() and update(), delete | n/a |
| 1.5 | same | Integration | n/a | n/a | 5/5 passed after round trip | n/a | pint passed; rollback+migrate clean |
| 1.6-1.8 | N/A | Structural migration | 7/7 | documented exception (no RED) | migrate + migrate:fresh clean | N/A | n/a |
| 1.9-1.10 | none directly (User/Role/Permission behavior first consumed in Phase 3/4/6 tests) | Model | 7/7 | n/a | verified via tinker, see below | n/a | n/a |
| 1.11 | tests/Unit/PermissionNameTest.php | Unit | N/A (new) | 3 written, 3/3 errored: `Class "App\Enums\PermissionName" not found` | n/a | 3 cases (values, description non-empty, spec text) | n/a |
| 1.12 | same | Unit | N/A | n/a | 3/3 passed | done | n/a |
| 1.13 | tests/Unit/AuditActionTest.php | Unit | N/A (new) | 3 written, 3/3 errored: `Class "App\Enums\AuditAction" not found` | n/a | 3 cases (18 values, label non-empty, labels distinct) | n/a |
| 1.14 | same | Unit | N/a | n/a | 6/6 passed (both files), 61 assertions | done | n/a |
| 1.15 | both | Unit | n/a | n/a | full suite 13 passed, 73 assertions | n/a | pint passed |

### Test Summary (PR 1a)
- Tests written: 5 (all in AuditImmutabilityTest.php); passing: 5; layer: Integration (MySQL `testing` DB)
- Full suite after unit: `sail artisan test` -> 7 passed, 12 assertions.

## Observed evidence

- 1.1: `compose.yaml` mysql service gained `command: --skip-log-bin`. `sail up -d` recreated the mysql container (a `sail down` was denied by the permission system and was not needed). `sail mysql -e` prints nothing without a TTY in this shell, so the values were read via tinker on the same connection: `log_bin = 0`, `log_bin_trust_function_creators = 0`, `CURRENT_USER() = sail@%`.
- RED (1.2): `sail pest --filter=E-29` -> tests 5, passed 0, errors 5, `SQLSTATE[42S02] ... Table 'testing.audit_logs' doesn't exist`.
- 1.3: `sail artisan migrate` -> `2026_09_28_000003_create_audit_logs_table .. DONE`; E-29 -> passed 2, failed/errors 3 (only model-level, `Class "App\Models\AuditLog" not found`).
- GREEN (1.4): `sail pest --filter=E-29` -> passed 5, assertions 10.
- 1.5: `sail pint <4 files>` -> passed. `migrate:rollback --step=1` -> DONE; afterwards `SHOW TRIGGERS LIKE 'audit_logs'` = `[]` and `Schema::hasTable('audit_logs')` = false. `migrate` -> DONE; `SHOW TRIGGERS` -> `audit_logs_block_update UPDATE BEFORE`, `audit_logs_block_delete DELETE BEFORE`.

## Observed evidence (PR 1b)

- RED (1.11/1.13): `sail pest tests/Unit/PermissionNameTest.php tests/Unit/AuditActionTest.php` -> tests 6, passed 0, errors 6, `Class "App\Enums\PermissionName" not found` / `Class "App\Enums\AuditAction" not found`.
- GREEN (1.12/1.14): same command -> passed 6/6, 61 assertions.
- 1.6-1.8: `sail artisan migrate` on the dev DB (audit_logs already applied) -> 000001, 000002, 000004 DONE. Users columns via tinker: id, first_name, last_name, email, password, is_active, must_change_password, created_at, updated_at; `first_name` varchar(100) NOT NULL no default; `is_active` tinyint(1) default 1, indexed (MUL). Tables present: roles, permissions, permission_role, role_user, login_throttles; `SHOW TRIGGERS` still lists both audit_logs triggers.
- Rollback: `migrate:rollback --step=3` then `migrate` clean; `--step=4` restored the original users columns (name, email_verified_at, remember_token) and dropped the new tables; audit_logs down() dropped triggers first.
- 1.15: `sail artisan migrate:fresh --seed` -> all 7 migrations DONE, seeding done. `sail pint app/Enums app/Models database tests/Unit` -> passed. Full suite `sail artisan test` -> 13 passed, 73 assertions (7 before + 6 new).
- Model sanity (tinker, throwaway rows on the dev DB, wiped by the subsequent migrate:fresh): `hasPermission(PermissionName::UsersView)` true after attaching role+permission, `hasPermission('nope')` false, `permissionNames()` = ["users.view"], email ` A@B.com ` stored as `a@b.com`.

## Work Unit Evidence (PR 1b)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `./vendor/bin/sail pest tests/Unit/PermissionNameTest.php tests/Unit/AuditActionTest.php` -> passed 6/6, 61 assertions |
| Runtime harness | `sail artisan migrate:fresh --seed` completed clean with all 7 migrations; rollback --step=4 and re-migrate clean |
| Rollback boundary | Revert migrations 000001/000002/000004, `app/Models/{Role,Permission,User}.php`, `app/Enums/*`, `tests/Unit/{PermissionName,AuditAction}Test.php`, minimal `UserFactory`/`DatabaseSeeder` edits |

## Work Unit Evidence (PR 1a)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `./vendor/bin/sail pest --filter=E-29` -> passed 5/5, 10 assertions |
| Runtime harness | `SHOW TRIGGERS LIKE 'audit_logs'` (via tinker; `sail mysql` is silent without TTY) lists both BEFORE triggers; rollback/migrate round trip clean |
| Rollback boundary | Revert migration 000003, `app/Models/AuditLog.php`, `app/Exceptions/AuditLogIsImmutable.php`, `tests/Feature/Audit/AuditImmutabilityTest.php`, `compose.yaml` |

## Files changed

- Modified: `compose.yaml` (mysql `command: --skip-log-bin`)
- Created: `database/migrations/2026_09_28_000003_create_audit_logs_table.php`, `app/Models/AuditLog.php`, `app/Exceptions/AuditLogIsImmutable.php`, `tests/Feature/Audit/AuditImmutabilityTest.php`

## Deviations / notes

- No design deviation. `AuditLog` uses `$guarded = []` (rows are only created by the future `RecordAuditEvent`); `AuditLogIsImmutable` extends `LogicException` with a `forOperation()` factory.
- The audit migration (000003) FK-references `users`, which already exists from the starter migration; 000001 later alters `users` without affecting the FK.
- The dev database (`erp_...`) now has migration 000003 applied.

## PR 1b deviations / notes

- Deviation (required to keep the suite/seed working): minimal edit of `database/factories/UserFactory.php` (first_name/last_name/is_active/must_change_password; removed `name`, `email_verified_at`, `remember_token` and the `unverified()` state) and `database/seeders/DatabaseSeeder.php` (`name` -> first_name/last_name). Full factory states and seeder rewrite still land in 1.23/1.24 (PR 1c).
- `first_name`/`last_name` are added with a temporary `default('')` for the backfill and then `->change()` to drop the default, so the final schema is NOT NULL without default (matches design).
- `Role` mass-assignment: `is_protected` is not fillable (only seeders/factories set it explicitly); `User` fillable includes `is_active`/`must_change_password` (Actions and factories set them).
- `users.is_active` index is named by Laravel default `users_is_active_index`.
- Existing `tests/Feature/*ExampleTest` untouched (deleted in 1.25).

## Observed evidence (PR 1c)

- RED 1.16: `sail pest tests/Unit/AuditRedactorTest.php` -> tests 4, passed 0, errors 4, `Class "App\Support\Audit\AuditRedactor" not found`. GREEN 1.17: passed 4/4.
- RED 1.18: `sail pest tests/Unit/AuditOriginTest.php` -> tests 2, passed 0, errors 2, `Class "App\Support\Audit\AuditOrigin" not found`. GREEN 1.19: passed 2/2, 12 assertions.
- RED 1.20: `sail pest tests/Feature/Audit/AuditRecordTest.php` -> tests 7, errors 7, `Target class [App\Actions\Audit\RecordAuditEvent] does not exist.` GREEN 1.21 first run: 5/7; two failures were (a) `created_at` lost microseconds (`...59.000000`): fixed by `$dateFormat = 'Y-m-d H:i:s.u'` on `AuditLog`; (b) MySQL JSON reorders object keys: test assertion changed from `toBe` to `toEqual` for `new_values`. Then `sail pest tests/Feature/Audit` -> 12 passed, 42 assertions.
- RED 1.22: `sail pest tests/Feature/FoundationSeederTest.php` -> tests 3, errors 3, `Target class [Database\Seeders\FoundationSeeder] does not exist.` GREEN 1.23: passed 3/3, 16 assertions.
- 1.24 (no dedicated test; states exercised by later phases): tinker in a rolled-back transaction: `inactive()->mustChangePassword()->withPermissions(UsersView, "x.y")` -> is_active false, must_change_password true, permissionNames ["users.view","x.y"], hasPermission("x.y") true.
- 1.25/1.26: `sail pint app database tests` passed; `sail pint --test` passed; `sail artisan test` -> 27 passed, 135 assertions (13 - 2 deleted examples + 16 new). Session driver under tests resolves to `database` (phpunit.xml no longer forces `array`).
- Runtime harness: `migrate:fresh --seed` clean; `db:seed --class=FoundationSeeder` run twice -> DONE both; tinker: roles 7, permissions 9, permission_role rows 9 (idempotent).

## Work Unit Evidence (PR 1c)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `./vendor/bin/sail pest tests/Unit/AuditRedactorTest.php tests/Unit/AuditOriginTest.php tests/Feature/Audit/AuditRecordTest.php tests/Feature/FoundationSeederTest.php` -> all green; full suite 27 passed, 135 assertions |
| Runtime harness | `sail artisan db:seed --class=FoundationSeeder` twice -> 7 roles, 9 permissions, 9 permission_role rows |
| Rollback boundary | Revert `app/Actions/Audit/RecordAuditEvent.php`, `app/Support/Audit/*`, `database/seeders/*`, `database/factories/{RoleFactory,UserFactory}.php`, `app/Models/Role.php` HasFactory, `AuditLog::$dateFormat`, `phpunit.xml`, `tests/{Pest,TestCase}.php`, restore the two ExampleTest files |

## PR 1c deviations / notes

- `AuditLog` gained `$dateFormat = 'Y-m-d H:i:s.u'` (touches 1a file) so `created_at` keeps timestamp(6) precision.
- `RecordAuditEvent`: `actor_email` defaults to `$actor->email` when an actor exists and no `actorEmail` is passed (design only specifies the failed-login case); `entity_type` = `$entity->getMorphClass()` (full class name; design does not fix the format).
- `Role` gained `HasFactory` (needed for `RoleFactory`); `RoleFactory::protected()` state added.
- `withPermissions()` creates a dedicated role per user and `firstOrCreate`s permissions by name (allows ad-hoc names such as `testing.e24` for E-24).
- `RefreshDatabase` is applied to Feature via `tests/Pest.php`; the redundant `uses(RefreshDatabase::class)` was removed from the three Feature tests.
- `loginWithRealSession()` / `requestWithSession()` are implemented per design but NOT yet exercised: they need `POST /login`, which lands in PR 3b (task 3.14). Expect possible adjustment then.

## Next

Phase 2 (PR 2a), tasks 2.1..2.5.
