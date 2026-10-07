# Verify report — 002-customers

Date: 2026-10-06. Mode: Strict TDD. Artifact store: openspec.

## Verdict

**PASS WITH WARNINGS** — 0 CRITICAL, 3 WARNING, 3 SUGGESTION.

## Gates (`./vendor/bin/sail …`)

| Command | Result |
|---|---|
| `artisan test` | PASS — 682 tests: 681 passed, 1 todo (E-19), 3468 assertions (run twice, same result) |
| `pint --test` | PASS |
| `composer types:check` | PASS — phpstan 0 errors |
| `pnpm types:check` | PASS |
| `pnpm check` | **FAIL** — formatting in `resources/js/components/customers/CustomerForm.vue` (committed, pre-existing) and `resources/js/pages/customers/Show.vue` (uncommitted arrow_back tweak) |
| `pnpm build` | PASS |

## Findings

### CRITICAL

None.

### WARNING

- **W1** — `pnpm check` fails on formatting in two files. `CustomerForm.vue` predates the Show.vue tweak. `design.md` lists `pnpm check` as a frontend gate; the slice-close gate in `tasks.md` and AGENTS §12 do not. Fix: `sail pnpm check:fix` on both files.
- **W2** — The Show.vue arrow_back tweak (user request, 2026-10-06) is uncommitted and has no automated test (no frontend test runner); covered by types:check, build and the user's manual check.
- **W3** — Client-side payload shaping in `CustomerForm.vue` has no automated test; accepted in task 10.4 (f), closed by the user's manual browser check (items 6-9 passed, 2026-10-06).

### SUGGESTION

- **S1** — `openspec/config.yaml` says PHP 8.3; `composer.json` requires ^8.4 and governs.
- **S2** — Add `pnpm check` to the slice-close gate of future changes.
- **S3** — Commit the tweak with the repository's Spanish conventional commit style.

## Scenario coverage (002 test locations only)

- E-01..E-41: each has at least one test whose name starts with its ID, all passing, except E-19.
- E-19: single `todo`, deferred by the spec (history comes with 004/006).
- CLI-007 and CLI-013: requirement-named tests present and passing.
- Method: regex over test names excluding 001 test files (spec 001 reuses E-nn IDs); not a per-ID `--filter` run.

## Strict TDD

`apply-progress.md` records RED/GREEN/REFACTOR per phase. Historical RED was not re-derived from git; evidence is as recorded by apply.

## Static rules

No `abort(403)`, `abort_if`, `abort_unless`, `Gate::before`, `hasRole(` or `tenant` in `app/`. `NoManualForbiddenTest` passes. No `.env`, CSV or dump in the working tree.

## Definition of Done

| Item | Evaluation |
|---|---|
| Spec satisfied, every scenario green (E-19 todo) | Satisfied |
| Business rules and constitution respected | Satisfied |
| Backend authorization and audit | Satisfied |
| Data integrity (transactions, constraints, all-or-nothing import) | Satisfied |
| `artisan test`, `pint --test`, `pnpm build` (+ types checks) | Satisfied (W1 concerns `pnpm check`, not listed in this item) |
| `tasks.md` complete and verify without blocking findings | Satisfied — all numbered tasks checked, no CRITICAL |
| Documentation updated | Satisfied (10.2), apart from S1 |
| Archived after human confirmation | Not evaluated — pending human confirmation |

## Next

Resolve W1 (format both files), commit the tweak, then `sdd-archive` after human confirmation.

## Post-verify resolution

- W1 resolved: `sail pnpm check:fix` (formatting only, no behavior change); then `sail pnpm check` PASS (61 files formatted, 0 lint errors), `sail pnpm types:check` PASS, `sail pnpm build` PASS.
- W2: the tweak is committed together with this report.
