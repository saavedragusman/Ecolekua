# Exploration: 001-foundation (Auth, Users, Roles/Permissions, Authorization, Audit)

> Mirror of Engram `sdd/001-foundation/explore` (project `ecolekua`). Source spec: `docs/specs/001-foundation.md` v1.1.

## Current State

The repository is an unmodified `laravel/blank-vue-starter-kit` (Laravel 13 / PHP 8.3, Inertia v3, Vue 3, TS, Tailwind 4, Sail, MySQL, Pest v4). It has no authentication scaffolding:

- No Fortify/Breeze/Jetstream, no login/logout controllers, no auth routes (`routes/web.php` only has `GET /` → `Welcome`).
- `users` migration: `id, name, email, email_verified_at, password, remember_token, timestamps` — single `name` field (spec needs first/last name), no `is_active`, no `must_change_password`.
- `User` model: no roles/permissions relations; casts `password => hashed`.
- No `roles`, `permissions`, pivot or `audit_logs` tables. No permissions package. No Policies.
- `config/session.php`: lifetime 120 min (matches FND-005); `database` driver.
- `config/auth.php`: standard `web` guard. No login throttling (FND-003 needs a manual `RateLimiter`).
- `app/Providers/AppServiceProvider.php`: `Password::defaults()` requires 12 chars + mixed case + numbers + symbols + `uncompromised()` only in production; no rule elsewhere. Conflicts with FND-013 (see Contradictions #3).
- CSRF already covered by the default `web` middleware group.
- Frontend: only `resources/js/pages/Welcome.vue`; no layouts or components.

## Requirements Inventory

26 requirements (FND-001..FND-026) and 30 scenarios (E-01..E-30), no gaps.

| ID | Summary |
|---|---|
| FND-001 | Login with email + password for active users |
| FND-002 | Same generic error for wrong password / unknown email / inactive user |
| FND-003 | Lock an email for 15 min after 5 consecutive failures; success resets counter |
| FND-004 | Logout invalidates access to protected operations |
| FND-005 | Session expires after 120 min inactivity |
| FND-006 | Deactivation invalidates open sessions immediately; role/permission changes apply on next request |
| FND-007 | Minimum user fields: id, first name, last name, email, password, status, must-change-password flag, timestamps |
| FND-008 | Email unique across active+inactive, normalized (trim+lowercase), not reusable |
| FND-009 | Active/inactive status; inactive cannot log in or use protected ops |
| FND-010 | User ops gated by `users.view/create/update/deactivate/reset_password/assign_roles`; no physical deletion; new users active, temp password, ≥1 role |
| FND-011 | Deactivation preserves audit history |
| FND-012 | Passwords never stored/logged in plaintext; secure hashing |
| FND-013 | Password ≥10 chars; cannot reuse current password |
| FND-014 | Admin-set/reset password is temporary and forces change; meanwhile only change-password/logout allowed; reset invalidates sessions |
| FND-015 | Self password change requires current password |
| FND-016 | Roles managed via `roles.view`/`roles.manage`; unique name; cannot delete role with users; Administrador protected; role name never grants access |
| FND-017 | Permission catalog defined in code (`module.action`), not editable via UI; new permissions not auto-assigned |
| FND-018 | Active user needs ≥1 role; effective permissions = union; no implicit total access |
| FND-019 | Server-side auth+permission checks; unauthenticated → login; unauthorized → denied with no side effects |
| FND-020 | Never leave zero active users holding both `users.assign_roles` and `roles.manage` |
| FND-021 | Users cannot deactivate themselves or change their own roles |
| FND-022 | Minimum audited events (auth, users, passwords, roles, role assignment, authorization denials) |
| FND-023 | Audit content: actor/attempted email, action, entity+id, timestamp, IP, before/after; never passwords/hashes |
| FND-024 | Audit records immutable, retained indefinitely |
| FND-025 | `audit.view` required to query audit log, filterable by user/action/date |
| FND-026 | Protected by default; only explicitly declared public ops are public |

## Decisions Status (spec §16)

All 16 decisions (DEC-001..DEC-016) are `Confirmada`. Design gate (AGENTS.md §4.4) is open.

## Contradictions (reported, not resolved)

1. `docs/constitution.md` §9 lists "aislamiento de los inquilinos" (tenant isolation) as a required control, contradicting §2.2's explicit ban on tenant concepts. Level-1 documentation defect; not blocking for 001.
2. `docs/business/business-rules.md` (retail tier 1–7 pieces) vs `docs/business/glossary.md` (1–6 pieces) disagree on the 50% deposit threshold. Out of scope for 001; relevant to future pricing/order specs.
3. `AppServiceProvider` production-only password rule (12 chars, complexity, breach check) contradicts FND-013 (≥10 chars, no complexity rules) and is inconsistent across environments. Code (level 5) must align with the spec (level 3).

## Affected Areas

- `database/migrations/` — new migrations: extend `users` (first/last name, `is_active`, `must_change_password`); `roles`, `permissions`, pivots; append-only `audit_logs`. Never edit the existing users migration.
- `app/Models/User.php` — roles relation, effective permissions, casts.
- `app/Http/Controllers/` — thin controllers (session, users, roles, own password, audit).
- `app/Actions/<Module>/` — business logic + transactions.
- `app/Policies/` — permission-based, no `Gate::before`.
- `app/Http/Middleware/` — protected-by-default, forced password change, session invalidation, login throttling.
- `routes/web.php` — explicit public-route declaration for E-30.
- `resources/js/pages/`, `resources/js/layouts/` — login, users, roles, audit screens; authenticated shell.
- `tests/Feature/` — scenario-named Pest tests E-01..E-30, including route-enumeration test (E-30).
- `database/seeders/` — role/permission catalog seeder.

## Approaches

### A — Roles & permissions

1. **Custom minimal schema** (recommended): no new dependency; exact fit for FND-017 (code-defined catalog) and FND-006 (no cross-request caching). Effort: medium.
2. **`spatie/laravel-permission`**: mature, but needs dependency justification (AGENTS.md §2), explicit cache invalidation for FND-006, and FND-017 would be convention-only. Effort: low-medium.

### B — Session/login flow

1. **Plain `Auth` facade + Actions** (recommended): no dependency, manual `RateLimiter` for FND-003. Effort: low-medium.
2. **Laravel Fortify**: battle-tested, but mostly-disabled feature set and extra dependency. Effort: medium.

### C — Audit trail

1. **Custom `audit_logs` + logger Action** (recommended): covers non-model events (failed login, authorization denials) uniformly; written inside the audited transaction.
2. **`owen-it/laravel-auditing`**: observer-based; doesn't cleanly cover failed-login/denial events; needs password redaction config.

## Risks

- Password-policy contradiction could break E-16 unless resolved.
- FND-006 forbids cross-request permission caching; easy to break E-10/E-24.
- E-30 route enumeration needs a deliberate public-route mechanism that future public specs (015/016) can extend.
- New dependencies require explicit user confirmation (AGENTS.md §2).
- `users.name` split requires a new migration.
- Constitution §9 tenant bullet should be corrected by maintainers.

## Ready for Proposal

Yes.
