# Apply Progress: 001-foundation

Mode: Strict TDD (openspec/config.yaml `strict_tdd: true`). Runner: `./vendor/bin/sail pest` / `./vendor/bin/sail artisan test`.
Delivery: feature-branch-chain (PR #1 targets tracker `feat/001-foundation`). No commits made (project rule).

## Completed tasks (5/102)

- [x] 1.1 Sail MySQL trigger-privilege fix
- [x] 1.2 RED E-29 (b)(c)(d) test
- [x] 1.3 GREEN audit_logs migration + triggers
- [x] 1.4 GREEN AuditLog model + AuditLogIsImmutable
- [x] 1.5 REFACTOR pint + migrate round-trip

E-29(a) intentionally deferred to task 7.6.

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | N/A (infra) | Runtime check | N/A | N/A | log_bin=0 observed | N/A | N/A |
| 1.2 | tests/Feature/Audit/AuditImmutabilityTest.php | Integration | 2/2 (ExampleTest x2) | 5 written, 5/5 failed: `Table 'testing.audit_logs' doesn't exist` | n/a | 5 cases (model update, model delete, DB update, DB delete, row unchanged) | n/a |
| 1.3 | same | Integration | N/A (new) | after migrate: 2 passed (E-29 c), 3 failed (`Class App\Models\AuditLog not found`) | DB-level GREEN | UPDATE and DELETE both | n/a |
| 1.4 | same | Integration | N/A (new) | n/a | 5/5 passed, 10 assertions | model update via save() and update(), delete | n/a |
| 1.5 | same | Integration | n/a | n/a | 5/5 passed after round trip | n/a | pint passed; rollback+migrate clean |

### Test Summary
- Tests written: 5 (all in AuditImmutabilityTest.php); passing: 5; layer: Integration (MySQL `testing` DB)
- Full suite after unit: `sail artisan test` -> 7 passed, 12 assertions.

## Observed evidence

- 1.1: `compose.yaml` mysql service gained `command: --skip-log-bin`. `sail up -d` recreated the mysql container (a `sail down` was denied by the permission system and was not needed). `sail mysql -e` prints nothing without a TTY in this shell, so the values were read via tinker on the same connection: `log_bin = 0`, `log_bin_trust_function_creators = 0`, `CURRENT_USER() = sail@%`.
- RED (1.2): `sail pest --filter=E-29` -> tests 5, passed 0, errors 5, `SQLSTATE[42S02] ... Table 'testing.audit_logs' doesn't exist`.
- 1.3: `sail artisan migrate` -> `2026_09_28_000003_create_audit_logs_table .. DONE`; E-29 -> passed 2, failed/errors 3 (only model-level, `Class "App\Models\AuditLog" not found`).
- GREEN (1.4): `sail pest --filter=E-29` -> passed 5, assertions 10.
- 1.5: `sail pint <4 files>` -> passed. `migrate:rollback --step=1` -> DONE; afterwards `SHOW TRIGGERS LIKE 'audit_logs'` = `[]` and `Schema::hasTable('audit_logs')` = false. `migrate` -> DONE; `SHOW TRIGGERS` -> `audit_logs_block_update UPDATE BEFORE`, `audit_logs_block_delete DELETE BEFORE`.

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

## Next

PR 1b, tasks 1.6..1.15.
