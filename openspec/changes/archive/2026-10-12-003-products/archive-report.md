# Archive Report: 003-products

**Change**: 003-products — Product Catalog, Combinations, Combos, Selection Resolution, Visual Assets and Initial Load  
**Archived**: 2026-10-12  
**Archive location**: `openspec/changes/archive/2026-10-12-003-products/`  
**Branch**: `feat/003-products-26-closeout` (not pushed; no PR)

## Cycle Status

**Closed**: All apply phases complete. Verify verdict PASS WITH WARNINGS (0 CRITICAL). Human confirmed archiving after verify; the only accepted warning is W2 (E-29 deferred with a `todo` test).

### Phase Completion

| Phase | Status | Evidence |
|-------|--------|----------|
| Explore | ✓ Done | `openspec/changes/archive/2026-10-12-003-products/exploration.md` |
| Propose | ✓ Done | `openspec/changes/archive/2026-10-12-003-products/proposal.md` |
| Spec | ✓ Done | Delta merged (full spec) to `openspec/specs/products/spec.md`; 20 requirement headings (§4 permissions and PRD-001..PRD-020, no PRD-006), 70 scenarios (E-01..E-73 minus the gaps E-12, E-13, E-18), decisions DEC-PRD-01..DEC-PRD-105, DT-01..DT-03 |
| Design | ✓ Done | `openspec/changes/archive/2026-10-12-003-products/design.md` |
| Tasks | ✓ Done | `openspec/changes/archive/2026-10-12-003-products/tasks.md`; 162 checked, 1 unchecked (DoD item 5, see below) |
| Apply | ✓ Done | Work-unit commits on the branch; final spec-side commits 2cd1002 and 0faef80 |
| Verify | ✓ Done | Final re-verify at 0faef80, Engram #102; PASS WITH WARNINGS |
| Archive | ✓ This step | Change folder moved; main spec created; this report written |

## Sources Read

| Artifact | Locator | Authority |
|----------|---------|-----------|
| proposal | `openspec/changes/archive/2026-10-12-003-products/proposal.md` (read at its pre-archive path `openspec/changes/003-products/proposal.md`) | Scope and intent |
| spec (delta) | `openspec/changes/archive/2026-10-12-003-products/specs/products/spec.md` | Transcription of `docs/specs/003-products.md`; source file is the behavior authority |
| design | `openspec/changes/archive/2026-10-12-003-products/design.md` | Technical plan |
| tasks | `openspec/changes/archive/2026-10-12-003-products/tasks.md` | Task progress and Definition of Done |
| verify-report | Engram `sdd/003-products/verify-report`, observation #102 (2026-10-12 13:11:37); copied into `verify-report.md` of the archive | Intermediate snapshot at 0faef80 |

Observation IDs read for traceability: #102 (verify-report). Engram was not the artifact store for this change; the filesystem was used.

## Final-State Authority

Ranking applied: (1) persisted `tasks.md`, (2) launch prompt final-state facts, (3) intermediate snapshots (`verify-report`, `apply-progress`).

- Final test counts come from the verify report at 0faef80, the highest-ranked source covering them: 1642 tests, 1640 passed, 2 skipped. The earlier 25.1 and 25.5 figures (1641 / 1639 / 2) are superseded.
- W1 (delta vs source mismatch) is resolved per verify #102; the fix is in the later spec commits 2cd1002 and 0faef80.
- W2 (E-29 deferred) remains open by design. It is accepted by the human, and its `todo` test is the expected pending marker.
- The `apply-progress.md` snapshot describes the state at its write time. Its "pending" claims are not current state.

No unrankable contradictions were found.

## Verification Findings

Per `verify-report.md` (final re-verify at 0faef80):

| Gate | Result |
|------|--------|
| `sail artisan test` | 1642 tests: 1640 passed, 2 skipped (E-19 customers, E-29 products; both `todo`) |
| `sail pint --test` | passed |
| `composer types:check` (phpstan) | 0 errors |
| `pnpm types:check` | ok |
| `pnpm build` | ok |
| `pnpm check` | not run at 0faef80 (not in the verify report); last recorded result in 25.5: 92 files formatted, no lint errors |

| Warning | Status |
|---------|--------|
| W1 (delta and source spec mismatch on PRD-018, PRD-020, E-41, E-73) | Resolved (2cd1002, 0faef80) |
| W2 (E-29 deferred, `todo` test) | Accepted by the human; to be tested in 004, 006 and 008 |

## Spec Merge Summary

| Metric | Value |
|--------|-------|
| New domain | 1: `products` (first spec in this domain) |
| Merge type | Mechanical copy; delta is a full spec and no main spec existed |
| Header | Unchanged. The 002 precedent also kept the delta header identical to the main spec header |
| Validation | `diff -r` empty (see below) |

## Definition of Done (Mapped to spec §12)

Ticked in the archived `tasks.md` with the evidence below. Historical bytes of the rest of the file are unchanged.

| # | Criterion | Box | Evidence |
|---|-----------|-----|----------|
| 1 | Spec satisfied; every non-deferred scenario green (E-29 deferred) | ✓ ticked | Verify #102: 1640 passed, 2 skipped (`todo`); 25.1 coverage audit |
| 2 | Business rules and constitution respected | ✓ ticked | Full suite green including `NoManualForbiddenTest`; verify #102 found no contradictions among business-rules, source spec, delta and design |
| 3 | Backend authorization and audit | ✓ ticked | Authorization and audit tests of the listed phases run inside the green suite |
| 4 | Data integrity (transactions, DB constraints) | ✓ ticked | Tests of the listed tasks run inside the green suite; import all-or-nothing (Phases 23-24) |
| 5 | `sail artisan test`, `sail pint --test`, `sail pnpm build` pass (plus `composer types:check`, `pnpm check`, `pnpm types:check`) | ☐ **unticked** | All listed gates pass at 0faef80 except `pnpm check`, which is not re-observed at 0faef80. The last `pnpm check` result is from 25.5 at an earlier commit. A `sail pnpm check` run would close it. Not run during archive (no DB or extra gate requested). |
| 6 | `tasks.md` complete and `sdd-verify` with no blocking findings | ✓ ticked | Verify: 0 CRITICAL; all tasks ticked |
| 7 | Documentation updated | ✓ ticked | 6.2 (design-system components) and 25.2 (documentation review; `business-rules.md` §27 aligned 2026-10-12) |
| 8 | Archived with `sdd-archive` after human confirmation | ✓ ticked | Human confirmed archiving after verify; this archive |

## Artifacts Present in Archive

| Artifact | Notes |
|----------|-------|
| proposal.md | Present (moved, byte-identical) |
| exploration.md | Present |
| design.md | Present |
| tasks.md | Present; DoD items 1-4 and 6-8 ticked, item 5 unticked (see above) |
| apply-progress.md | Present; intermediate snapshot, not current state |
| specs/products/spec.md | Present; copy of the main spec |
| verify-report.md | Created from Engram #102 (written by this archive, not moved) |
| archive-report.md | This report |

**Missing artifacts**: none.

## Open Follow-Ups (Recorded, Not Blocking Archive)

| Item | Type | Next Step |
|------|------|-----------|
| `pnpm check` not re-run at 0faef80 | Unverified gate (DoD 5) | Run `sail pnpm check` on the branch |
| E-29 deferred | Deferred test (W2, accepted) | Test in 004, 006 and 008 |
| Manual 375/768/1280 px checklist | Recorded in 25.3 | Done by the human 2026-10-12; no findings |
| Deployment prerequisites | 25.4 notes | Confirm GD (JPEG, PNG, WebP), upload and memory limits, `migrate --force`, `FoundationSeeder`, one-time `catalog:import` before go-live |
| Business data dependency (R-7) | Risk | Real go-live depends on Ecolekua validating Anexo A and B, the fabric/color file and the Los Cedros codes |

## Mechanical Copy Readback

Verbatim `diff -r` output, run after each copy or move:

1. Main spec (delta vs. `openspec/specs/products/spec.md`): empty output.
2. Snapshot of the change folder taken before the move vs. the archived folder: empty output.
3. Archived tree after the DoD edit and the additive files: the only differences are `tasks.md` (DoD checkboxes), `verify-report.md` and `archive-report.md`, which are additive or intentional edits.

## Delivery State

- Commit made on `feat/003-products-26-closeout` as a Spanish conventional commit; no push, no PR.
- Delivery follows ordinary repository policy (AGENTS §11).
