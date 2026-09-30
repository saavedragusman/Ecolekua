# Archive Report: 001-foundation

**Date archived**: 2026-09-29  
**Branch**: feat/001-foundation-7b-audit-page (tip of feature-branch-chain)  
**Final commit**: d37fd60 `docs(001): regla de confirmación cubre la eliminación irreversible [UI-07]`

---

## Change Summary

**Change**: 001-foundation — Authentication, Users, Roles/Permissions, Authorization and Audit

**Scope**: Complete implementation of authentication (login/logout/session), user lifecycle (create/update/deactivate), roles and permission catalog, authorization (backend-driven Policies, protected-by-default routing), and audit trail (immutable logging of sensitive actions). All 30 scenarios (E-01..E-30), 26 requirements (FND-001..FND-026), and 23 design decisions (DEC-001..DEC-023) implemented and verified.

**Mode**: Hybrid (openspec + Engram for archive persistence).

---

## Final State (per Launch Prompt Facts)

- **Tasks**: 124/124 complete. Manual checks 2.11, 4.17, 6.11 confirmed by human on 2026-09-29.
- **Verification**: PASS WITH WARNINGS, 0 CRITICAL. Non-blocking warnings W1 (docs) and W2 (delta spec headers) fixed in commit 287a17f.
- **Test suite**: 221 passed, 0 skipped (final run on tip commit d37fd60; verify-report snapshot said 220 before the E-25 message fix in commit d5af481).
- **Quality gates**: `sail pint`, `sail composer types:check`, `sail pnpm types:check`, `sail pnpm build` all green.
- **Commit chain**: 7 phases, 16 work units across 15 PRs (tracker `feat/001-foundation`), all pushed to feature branch. Merge to `main` deferred to human decision per ordinary repository policy.

**Verify findings fixed in later commits**:
- W1 (uncommitted docs): fixed in 287a17f (apply-progress header, docs committed).
- W2 (delta spec headers citing v1.2): fixed in 287a17f (5 specs now cite v1.3; content unchanged).
- S6 (E-25 message using technical names): fixed in d5af481 (message now uses permission descriptions from `PermissionName::description()`; 221 tests passing).
- S7 (design-system §7.10 / UI-07 missing irreversible deletion coverage): resolved in d37fd60 (added text covering role deletion as irreversible).

**Remaining non-blocking follow-ups** (from verify-report, unblocking archive):
- S1: Replace `expect(true)->toBeTrue()` with `->not->toThrow()` in `LastAdministratorTest.php:126`.
- S2: Add FND-prefixed test names (or accept E-xx coverage for FND-001, 002, 003, 004, 005, 008, 009, 019, 020, 021, 024, 026).
- S3: No automated proof that passwords never reach technical logs (static evidence exists per spec §17).
- S4: Read-query logic in user and audit controllers (accepted by Decision 23).
- S5: Document the `fresh()` + `Auth::setUser` deviation from Decision 6 (used in `EnsureUserIsActive.php:22-33`).

Note: Commit a4f7835 (Adminer, chore) is outside spec scope and harmless.

---

## Specs Synced to Main

Seven domain specs created in `openspec/specs/` from delta specs. Each copy verified with empty `diff -r` output (byte-perfect):

| Domain | IDs | Action | Observation |
|--------|-----|--------|-------------|
| administrative-protections | FND-020, FND-021, E-25, E-26 | Created | `openspec/specs/administrative-protections/spec.md` |
| audit | FND-022, FND-023, FND-024, FND-025, E-27, E-28, E-29 | Created | `openspec/specs/audit/spec.md` |
| authentication | FND-001, FND-002, FND-003, FND-004, FND-005, FND-006, E-01..E-06 | Created | `openspec/specs/authentication/spec.md` |
| authorization | FND-019, FND-026, E-19, E-30 | Created | `openspec/specs/authorization/spec.md` |
| password-management | FND-012, FND-013, FND-014, FND-015, E-14, E-15, E-17 | Created | `openspec/specs/password-management/spec.md` |
| roles-permissions | FND-016, FND-017, FND-018, DEC-022, E-21, E-23, E-24 | Created | `openspec/specs/roles-permissions/spec.md` |
| user-management | FND-007, FND-008, FND-009, FND-010, FND-011, DEC-023, E-07..E-13, E-16, E-18, E-20, E-22, E-25, E-26 | Created | `openspec/specs/user-management/spec.md` |

**Delta → Main correspondence**: All delta specs in `openspec/changes/001-foundation/specs/` were full specs (not true deltas), copied mechanically to establish the source of truth in `openspec/specs/`.

---

## Archive Contents

All artifacts preserved at `openspec/changes/archive/2026-09-29-001-foundation/`:

- **proposal.md**: Scope, intent, in/out-of-scope boundary, constraints (v1.1, dated 2026-09-28).
- **design.md**: Architecture decisions 1–23, PR-slice outline, technical patterns, TDD evidence markers.
- **exploration.md**: Initial code reconnaissance and documentation scan.
- **apply-progress.md**: Commit trail and task-by-task evidence (header updated 2026-09-29 to reflect final 124/124 state).
- **verify-report.md**: Test coverage, quality gate results, warnings and follow-ups (observation 22 in Engram at verify time).
- **tasks.md**: 124 task checkboxes across 7 phases and 16 work units, with task ID references to FND/E/DEC IDs.
- **specs/**: 7 domain-specific delta specs (authentication, user-management, roles-permissions, password-management, authorization, administrative-protections, audit).

No artifacts are missing. No discrepancies between versioned files and apply-progress/verify-report snapshots; final-state facts from the launch prompt outrank intermediate snapshots and are reflected above.

---

## Decisions Added During Apply

During the apply and verify phases, 7 new design decisions (DEC-017..DEC-023) were captured in the design.md:

- **DEC-019**: Operating-timezone display (`America/Caracas`) confirmed via `OperatingTime` class and config; UTC storage confirmed.
- **DEC-020**: Navigation redesign (grouped `NAV_ENTRIES`, "Más" bottom sheet, image logo) added as PR 3d (design amendment by the navigation work, phase 3d task 3.25).
- **DEC-021**: Audit console-command origin detection (`AuditOrigin::console(...)` captures OS user and command).
- **DEC-022**: Permission coherence check in role-sync: all current members must retain every permission when syncing new assignments.
- **DEC-023**: Users index filter by `status` (active/inactive) and role-based `counts` subquery in controller (acceptable read-query).
- Plus UI-07 (design-system §7.10 row): coverage of irreversible deletion (role deletion example).

All decisions are `Confirmada` and implemented with test coverage.

---

## Verify Warnings Resolution

Per Final-State Authority (launch prompt facts override intermediate snapshots):

- **W1** (uncommitted docs): Resolved in commits 287a17f (updated apply-progress header, committed docs).
- **W2** (delta spec headers): Resolved in commit 287a17f (5 specs updated from v1.2 to v1.3 headers; content unchanged).
- **S6** (E-25 message exposure): Fixed in commit d5af481 (message now built from `PermissionName::description()`; full suite 221 passed).
- **S7** (design-system missing irreversible deletion): Resolved in commit d37fd60 (added text to §7.10 and UI-07 row).

---

## Archived Folder Structure

```
openspec/changes/archive/2026-09-29-001-foundation/
├── proposal.md
├── design.md
├── exploration.md
├── apply-progress.md
├── verify-report.md
├── tasks.md
├── archive-report.md (this file)
└── specs/
    ├── administrative-protections/spec.md
    ├── audit/spec.md
    ├── authentication/spec.md
    ├── authorization/spec.md
    ├── password-management/spec.md
    ├── roles-permissions/spec.md
    └── user-management/spec.md
```

---

## Traceability

- **Source of truth**: `docs/specs/001-foundation.md` v1.3 (source spec, checked into repo).
- **Implementation evidence**: Branch `feat/001-foundation-7b-audit-page`, commit chain d37fd60 (latest) back to 75545f8 (phase 1a); 15 work-unit PRs, all pushed.
- **Verification snapshot**: Engram observation 22 (`sdd/001-foundation/verify-report` at verify time, authored by sdd-verify phase).
- **This archive**: Captures final state on 2026-09-29 per explicit launch prompt facts and actual repository commit evidence.

---

## Completion Status

This change is **archived and closed**. Implementation is complete with all 124 tasks verified. Non-blocking follow-ups and suggestions are documented in the Verify Report (S1–S5); they do not block merge or deployment under ordinary repository policy.

Merging the feature-branch-chain tracker to `main` is the human's decision per AGENTS.md §11 and the delivery strategy (feature-branch-chain).
