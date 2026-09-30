# Proposal: 001-foundation — Authentication, Users, Roles/Permissions, Authorization and Audit

> Source of truth: `docs/specs/001-foundation.md` v1.1 (FND-001..FND-026, E-01..E-30, DEC-001..DEC-016, all `Confirmada`).
> Exploration: `openspec/changes/001-foundation/exploration.md` (Engram `sdd/001-foundation/explore`).
> Scope rule (`openspec/config.yaml` → `rules.proposal`): exactly what spec 001 defines; nothing extra; no tenant/multi-company abstractions.

## Intent

The repository is an unmodified `laravel/blank-vue-starter-kit`: there is no login, no user lifecycle, no roles or permissions, no authorization layer and no audit trail. Every later spec (002..017) depends on 001 (spec §15), so nothing functional can be built until Ecolekua staff can authenticate, be managed, be authorized by permission (never by role name) and leave an immutable audit trail of sensitive actions.

Success means: all scenarios E-01..E-30 have passing Pest tests named by scenario ID; no unauthenticated route exists outside the declared public list; the initial seed creates the seven roles of spec §7 and the nine permissions of spec §9 assigned to Administrador; and later specs can add their own permissions to the catalog without modifying this model.

## Scope

### In Scope

**Authentication and session (FND-001..FND-006)**
- Login with normalized email + password for active users; generic error for wrong password, unknown email and inactive user (FND-002).
- Session regeneration on login; logout that invalidates the session (FND-004).
- 120-minute inactivity expiry (FND-005). No "remember me" option, because a remember token would outlive the 120-minute inactivity rule.
- **Database-driven sessions** (`sessions` table with `user_id`, already created by the starter migration) pinned as the session driver, so that all sessions of a user can be deleted at once on deactivation (FND-006) and on administrator password reset (FND-014). A per-request check that the authenticated user is still active complements the deletion.
- Role/permission changes take effect on the next request: effective permissions are resolved from the database per request, with no cross-request cache (FND-006, E-10, E-24).
- **Login attempt limiting keyed by the normalized email, not by IP** (FND-003): 5 consecutive failures lock that email for 15 minutes; the sixth attempt is rejected even with the correct password (E-06); a successful login resets the counter; the lock is audited.

**Users (FND-007..FND-011)**
- `users` schema changes through **new migrations only** (the existing `0001_01_01_000000_create_users_table.php` is never edited): first name, last name, active flag, must-change-password flag; email unique at persistence level (FND-008, spec §12). The existing single `name` column is replaced/migrated in a new migration (detail in design).
- Email normalization (trim + lowercase) before storing and before comparing (FND-008, E-05).
- User list/detail, create, update (first name, last name, email), activate/deactivate, reset password, assign/remove roles — each gated by its `users.*` permission (FND-010). No physical deletion. New users are active, have a temporary password and at least one role.
- Deactivation preserves history and audit references (FND-011, E-18).

**Passwords (FND-012..FND-015)**
- Framework hashing only; passwords, temporary passwords and hashes never appear in audit or logs (FND-012, E-28).
- **Align `app/Providers/AppServiceProvider.php` `Password::defaults()` to FND-013**: minimum 10 characters, no additional complexity or breach rules, identical in all environments. Today it requires 12 chars + mixed case + numbers + symbols + `uncompromised()` in production only, which contradicts FND-013 (level 3 spec overrides level 5 code).
- New password cannot equal the current one (FND-013).
- Temporary password + forced change: while pending, the user may only change the password or log out; any other protected operation is rejected (FND-014, E-14). Administrator reset invalidates all sessions of the target user (E-15).
- Own password change requiring the current password, no extra permission (FND-015, E-17).

**Roles and permissions (FND-016..FND-018)**
- Custom tables `roles`, `permissions` and pivots (`permission_role`, `role_user`) with referential integrity (spec §12).
- Permission catalog declared in code in a single place (enum/catalog class + seeder), `module.action` names; not creatable/editable/deletable from the UI (FND-017). New permissions are never auto-assigned (E-24).
- Role CRUD and permission assignment behind `roles.view` / `roles.manage`; unique role name; role with users cannot be deleted (E-22); Administrador cannot be deleted or renamed (E-23).
- Effective permissions = union of all roles (FND-018, E-21).
- **No `Gate::before`, no superadmin, no implicit total access — not even for Administrador** (FND-018, DEC-003). Code checks permissions only, never role names (BR-FND-003).

**Authorization (FND-019, FND-026)**
- Every protected operation authorizes in the backend via Policies/Gates over permissions; unauthenticated → redirect to login; authenticated without permission → access denied with no side effects, and the denial is audited (E-19).
- **Protected by default with an explicit declared list of public routes** (FND-026). Spec 001 declares only the login operation public. A Pest test (E-30) iterates every registered route and fails if any route is reachable unauthenticated without being in the declared public list. The declaration mechanism must be extendable by specs 015/016 without changing this model.

**Administrative protections (FND-020, FND-021)**
- Reject any operation that would leave zero active users holding both `users.assign_roles` and `roles.manage` (deactivation, role removal, permission removal from roles) (E-25).
- Users cannot deactivate themselves or change their own roles (E-26).

**Audit (FND-022..FND-025)**
- Custom `audit_logs` table + a logger Action, written inside the same transaction as the audited change; covers non-model events (failed login, lock, authorization denial) uniformly (FND-022).
- Content: actor (or attempted email on failed login), action, entity + id, timestamp (UTC), source IP, before/after values; never passwords or hashes (FND-023, E-27, E-28).
- **Immutable at two levels** (FND-024, E-29): the Eloquent model refuses update/delete, and the database itself rejects `UPDATE`/`DELETE` on `audit_logs`. Proposed mechanism: MySQL `BEFORE UPDATE` / `BEFORE DELETE` triggers that `SIGNAL` an error, created by a migration. No UI or route exists to modify or delete records. Final mechanism details belong to design.
- Audit query screen behind `audit.view`, filterable by user, action and date range (FND-025).

**Seed data**
- Seeder creates the 7 roles of spec §7 (only Administrador with permissions) and the 9 permissions of spec §9 assigned to Administrador, plus a fictitious initial administrator for local use only (no real personal data, AGENTS.md §8).

**Frontend (internal ERP, Vue 3 + Inertia + TS)**
- Screens strictly needed by the requirements above: login, forced/own password change, users (list/detail/create/edit/roles/reset), roles (list/detail/edit/permissions), audit log query, and an authenticated layout shell.
- Must follow `docs/ui/design-system.md`: tokens of §2 in `resources/css/app.css` (replacing any parallel starter-kit color system), mobile-first, 44px touch targets, ≥16px inputs, bottom nav below `lg` / sidebar at `lg`, components of §7 implemented once in `resources/js/components/` (only those these screens need), accessibility rules of §8, light mode by default with an optional, manual dark mode for the internal ERP only (UI-06, status `Propuesta`: `localStorage` toggle, dark palette derived from the brand tokens and applied only through `.dark` token overrides, no `dark:` classes, public portal excluded). Decisions UI-01 and UI-02 (status `Propuesta`) apply their recommendation; UI-03 keeps the interim Google Fonts `<link>` because no new dependency is allowed.
- The frontend only displays backend results; hiding a control is never the authorization.

**Technical constraints (user-approved)**
- Plain Laravel `Auth` facade + Action classes (`app/Actions/<Module>/`); no Fortify/Breeze.
- Custom roles/permissions tables; no `spatie/laravel-permission`.
- Custom audit; no auditing package.
- No new Composer or npm dependencies.
- Thin controllers → FormRequest → Policy → Action; transactions for multi-entity operations.

### Out of Scope
- Self-service password recovery by email, 2FA, external identity providers, public registration of internal users (spec §4).
- Self-editing of personal data other than password (DEC-010).
- General system configuration (DEC-012).
- Any customer/product/inventory/pricing/order/production/portal/mobile functionality (spec §4), including portal visitor/customer authentication (specs 015/016, DEC-014).
- Deposit threshold discrepancy (1–6 vs 1–7 pieces) between business documents: out of scope for 001; belongs to pricing/order specs (004/005).
- Dashboard or management metrics on the post-login landing page (spec §4).
- Audit retention policy (FND-024: indefinite until a documented policy exists).
- New dependencies for fonts/icons (UI-03 remains pending).

## Capabilities

`openspec/specs/` is empty; all capabilities are new. Grouping mirrors the sections of spec 001 so each delta spec is a faithful transcription with original IDs.

### New Capabilities
- `authentication`: login, generic failure message, attempt limiting, logout, session expiry, session invalidation — FND-001..FND-006; E-01..E-10.
- `user-management`: user identity, email uniqueness/normalization, status, user administration, inactive-user history — FND-007..FND-011; E-11, E-12, E-13, E-18.
- `password-management`: password security, policy, temporary password and forced change, own password change — FND-012..FND-015; E-14, E-15, E-16, E-17.
- `roles-permissions`: roles, permission catalog, role assignment and effective permissions — FND-016..FND-018; E-21, E-22, E-23, E-24.
- `authorization`: backend authorization and protected-by-default / declared public operations — FND-019, FND-026; E-19, E-20, E-30.
- `administrative-protections`: last-administrator protection and self-action restrictions — FND-020, FND-021; E-25, E-26.
- `audit`: audited events, record content, immutability, audit query — FND-022..FND-025; E-27, E-28, E-29.

### Modified Capabilities
- None.

## Approach

1. **Data layer first** (new migrations only): alter `users`; create `roles`, `permissions`, `permission_role`, `role_user`; create `audit_logs` with foreign key to `users` restricted (never cascading) plus DB triggers rejecting UPDATE/DELETE.
2. **Permission catalog in code** (single enum/class) synced by a seeder; roles seeded per spec §7. `User` resolves effective permissions from the database per request (no cross-request cache); a Gate definition per catalog permission (or a permission-checking Policy helper) — never `Gate::before`.
3. **Auth via `Auth` facade + Actions**: `LoginAction` (normalize email, check lock via `RateLimiter` keyed by normalized email, attempt, reject inactive with the generic message, regenerate session, audit), `LogoutAction`. Middleware for: authenticated + still-active user, forced password change gate. Session driver pinned to `database`; deactivation/reset delete the user's `sessions` rows.
4. **Protected by default**: all routes under `auth` middleware except the declared public list (a single, extendable declaration — e.g. a named middleware group or route-name list); E-30 test enumerates `Route::getRoutes()` and asserts unauthenticated access is rejected for every undeclared route. Starter route `GET /` becomes protected and the framework health route `/up` is removed (resolved, see Open Questions).
5. **Actions per operation** (`app/Actions/Users`, `Roles`, `Auth`, `Audit`) own business rules (last-admin, self-action) inside DB transactions and call the audit logger in the same transaction. Authorization denials are audited centrally.
6. **`Password::defaults()`** → `Password::min(10)` in all environments.
7. **Frontend** Inertia pages + design-system components, built after backend behavior is green (Strict TDD, test names prefixed by scenario ID; authorization tests hit HTTP endpoints directly).

Delivery: the change will clearly exceed the 400-line review budget (delivery strategy `ask-on-risk`); `sdd-tasks` should plan chained PR slices along the capability boundaries above (e.g. schema + catalog → auth/session → users/passwords → roles + protections → audit → UI).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | New | users alteration, roles/permissions/pivots, `audit_logs` + immutability triggers |
| `database/seeders/` | New/Modified | permission catalog + roles seeder, fictitious initial admin |
| `app/Models/User.php` | Modified | roles relation, effective permissions, casts, email normalization |
| `app/Models/` (`Role`, `Permission`, `AuditLog`) | New | new models; `AuditLog` refuses update/delete |
| `app/Actions/{Auth,Users,Roles,Audit}/` | New | business logic and transactions |
| `app/Http/Controllers/`, `app/Http/Requests/` | New | thin controllers + FormRequests |
| `app/Policies/`, permission catalog enum | New | permission-based authorization, no `Gate::before` |
| `app/Http/Middleware/` | New | active-user check, forced password change |
| `app/Providers/AppServiceProvider.php` | Modified | `Password::defaults()` aligned to FND-013 |
| `bootstrap/app.php` | Modified | middleware registration; health route decision |
| `config/session.php` | Modified | pin `database` driver (lifetime already 120) |
| `routes/web.php` | Modified | public-route declaration + protected routes |
| `resources/css/app.css`, `resources/views/app.blade.php` | Modified | design-system tokens, viewport, `lang="es"` |
| `resources/js/{pages,layouts,components}/` | New | login, password, users, roles, audit pages; ERP layout; §7 components |
| `tests/Feature/`, `tests/Unit/` | New | E-01..E-30 Pest tests incl. route-enumeration test |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| MySQL may refuse `CREATE TRIGGER` for a non-SUPER user when binary logging is enabled (`log_bin_trust_function_creators`), in Sail or production | Med | Design verifies against Sail MySQL 8.4 and documents the requirement; keep model-level refusal regardless |
| Cross-request permission caching would break E-10/E-24 | Med | No cache; tests E-10/E-24 guard it |
| Starter routes `GET /` and `/up` are unauthenticated and not declared public by spec 001 → E-30 fails | Resolved | Option A: protect `/`, remove `/up` |
| Email-keyed lockout enables deliberate lockout of a known email (denial of service) | Med | Accepted by FND-003 as written; not mitigated with IP rules (would change behavior) |
| `users.name` → first/last name migration of existing rows | Low | Only local fictitious data exists; new migration handles it |
| Size well above 400-line budget | High | Chained PR slices planned in `sdd-tasks` |
| UI-03 (fonts/icons) pending: interim Google Fonts `<link>` is an external runtime dependency | Low | Documented interim in design-system §10; revisit when UI-03 is confirmed |
| `docs/business/glossary.md` "Usuario" definition ("authenticated person") is broader than DEC-014 | Low | Spec (DEC-014) governs 001; maintainers may align the glossary |
| Deposit threshold 1–6 vs 1–7 across business docs | Low for 001 | Out of scope; to be confirmed before specs 004/005 (working tree currently shows 1–7 in both files; confirm) |

## Open Questions (returned to orchestrator — not decided here)

**Resolved (2026-09-28, user): 001/FND-026, E-30 → Option A.** Protect `GET /` (unauthenticated → login) and remove the `/up` health route from `bootstrap/app.php`. No spec change; login remains the only declared public operation.

**Resolved (2026-09-28, user): 001/FND-001, E-01 → Option A.** After login, users land on a minimal "Inicio" page with a greeting and navigation limited to entries their permissions allow; no metrics. Applies equally to roles with no permissions yet.

## Rollback Plan

- All schema changes are additive new migrations with `down()` methods; roll back with `sail artisan migrate:rollback --step=N` locally (no shared environment exists yet). `down()` of the audit migration drops triggers before the table.
- Work lives on `feat/001-foundation`; nothing is merged without review. Each chained PR slice can be reverted independently with `git revert` of its merge commit.
- `AppServiceProvider` and `bootstrap/app.php` changes are small and revertible in one commit.

## Dependencies

- None on other specs (spec §15). No new Composer/npm packages.
- MySQL 8.4 (Sail) supporting triggers for the audit migration; MySQL `testing` database for Pest.

## Success Criteria

- [ ] E-01..E-30 each have at least one Pest test prefixed with its ID, all green via `./vendor/bin/sail artisan test`.
- [ ] E-30 route-enumeration test passes; the declared public list contains only login (unless a spec decision adds entries).
- [ ] Email unique at DB level; passwords absent in plaintext/hash from DB audit rows and logs.
- [ ] Direct SQL `UPDATE`/`DELETE` on `audit_logs` fails at the database level; no app operation modifies/deletes audit rows.
- [ ] No `Gate::before`, no role-name checks, no tenant concepts in the diff.
- [ ] Impossible to leave zero active users with `users.assign_roles` + `roles.manage`.
- [ ] Seed creates the 7 roles and 9 permissions, all 9 assigned to Administrador only.
- [ ] `Password::defaults()` enforces min 10 chars in all environments.
- [ ] `sail pint --test`, `sail pnpm build`, PHPStan and `vue-tsc` pass; design-system checklist (§11) satisfied for new UI.
