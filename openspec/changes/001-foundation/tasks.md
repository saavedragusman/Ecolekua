# Tasks: 001-foundation — Authentication, Users, Roles/Permissions, Authorization and Audit

> Source of truth for behavior: `docs/specs/001-foundation.md` v1.2 (FND-001..FND-026, E-01..E-30, DEC-001..DEC-021, all `Confirmada`).
> Plan: `openspec/changes/001-foundation/design.md` (the project's only plan artifact). This file breaks the design's **7 PR-slice outline** (`design.md` → "Migration / Rollout" → "PR slice outline") into smaller, independently reviewable work units, because slices 1, 3, 4, and 5 individually exceed the 400-line review budget.
> Naming: `Phase N` = one design slice (1, 2, 3, 4, 4b→**Phase 5**, 5→**Phase 6**, 6→**Phase 7**). Each phase is split into lettered PR work units (`1a`, `1b`, …) that are the actual chained-PR candidates. Task numbering (`N.M`) is continuous within a phase across its PR work units.
> Test-first: `strict_tdd: true` (`openspec/config.yaml`). Every behavior task pairs a RED test (named after its scenario/decision ID) → a GREEN implementation task → a REFACTOR task. Pure structural migrations with no directly testable behavior yet are the one documented exception (Phase 1, see note there); their correctness is proven by the first consuming feature test in a later phase.
> Test runner: `./vendor/bin/sail artisan test` (full suite) / `./vendor/bin/sail pest --filter=<ScenarioOrDecisionID>` (scoped).

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~4,450 authored lines total (additions + deletions, generated Wayfinder files excluded per design.md) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | 15 chained PR work units across 7 phases (see table below; 3d added by the navigation amendment, design Decision 20) |
| Delivery strategy | ask-on-risk |
| Chain strategy | feature-branch-chain (user, 2026-09-28): PR #1 targets tracker branch `feat/001-foundation`; each child PR targets the previous PR branch; only the tracker merges to `main` |

Decision needed before apply: No (resolved: chained PRs, feature-branch-chain)
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

Three work units (3b, 6a, 7) land close to or slightly over 400 lines by design because splitting them further would separate a RED test from the GREEN code that makes it pass within the same behavior (e.g. throttle + login flow, or last-administrator protection + its three call sites). Treat these as `size:exception` candidates only if their real diff exceeds ~450 lines; otherwise keep them as single PRs per the "don't split artificially" rule.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1a | Audit trail foundation: `audit_logs` migration + triggers, model guard, MySQL trigger-privilege fix | PR 1 | `sail pest --filter=E-29` | `sail mysql -e "SHOW TRIGGERS LIKE 'audit_logs'"` confirms both triggers | Revert migration 000003, `AuditLog` model, `AuditLogIsImmutable`, `compose.yaml` |
| 1b | Users/roles/permissions schema, models, enums | PR 2 | `sail pest tests/Unit/PermissionNameTest.php tests/Unit/AuditActionTest.php` | `sail artisan migrate:fresh` completes clean | Revert migrations 000001/000002/000004, `Role`/`Permission`/`User` model changes, both enums |
| 1c | Audit logger, redaction, origin, seeders, factories, test infra | PR 3 | `sail pest --filter=AuditRedactor,AuditOrigin,FoundationSeeder` | `sail artisan db:seed --class=FoundationSeeder` twice is idempotent | Revert `RecordAuditEvent`, `AuditRedactor`, `AuditOrigin`, seeders, factories, `Pest.php`/`TestCase.php`/`phpunit.xml` changes |
| 2a | Design tokens, blade shell, locale | PR 4 | `sail pnpm types:check && sail pnpm build` | N/A — shell has no standalone route until Phase 3 | Revert `app.css`, `app.blade.php`, `app.ts`, locale config, new `lang/es/*` |
| 2b | Layouts, base components, Forbidden page | PR 5 | `sail pnpm types:check` | N/A — first real render is Phase 3's login page | Revert new components/layouts/Forbidden page/`Welcome.vue` deletion |
| 3a | Protected-by-default middleware + public route list + route cleanup | PR 6 | `sail pest --filter=E-30` | `sail artisan route:list` shows only `login`/`login.store` unauthenticated | Revert `bootstrap/app.php` middleware line, `PublicRoutes`, `RequireAuthentication`, config changes, placeholder routes |
| 3b | Login, logout, throttle, session pinning | PR 7 | `sail pest --filter=Auth` | `sail artisan route:list --name=login` confirms `guest`-gated routes | Revert session config, `LoginThrottle`, `AttemptLogin`, `LoginController`/Request, `SessionInvalidator`, `EnsureUserIsActive`, `Logout`, routes, `lang/es/auth.php` |
| 3c | Home page, forced/own password change | PR 8 | `sail pest --filter=Password` | `sail artisan route:list` shows `password.edit`/`password.update`/`home` | Revert `HomeController`/`Home.vue`, `EnsurePasswordIsChanged`, password Action/Request/Controller/routes, `AppServiceProvider` password policy, `Login.vue`/`ChangePassword.vue` |
| 3d | Navigation redesign: grouped `NAV_ENTRIES`, "Más" bottom sheet, user menu, image logo (amendment, design Decision 20) | PR 8b | `sail pnpm types:check && sail pnpm exec vp lint` | `sail pnpm build`; manual browser check (375/1280 px, both themes, keyboard) | Revert `resources/js/navigation.ts`, `useNavigation.ts`, `MoreSheet.vue`, `UserMenu.vue`, `SideNav`/`BottomNav`/`AppLayout` edits, move the logo back |
| 4a | User policy, `CreateUser`, `UpdateUser` | PR 9 | `sail pest --filter=CreateUserTest,UpdateUserTest` | `sail artisan route:list --name=users` | Revert `UserRules`, `UserPolicy`, FormRequests, `CreateUser`/`UpdateUser` Actions, `UserController`, the 5 new routes |
| 4b | Status, password reset, role assignment, denial auditing | PR 10 | `sail pest --filter=Users,Authorization,Passwords` | N/A — fully covered by HTTP feature tests | Revert render hooks, `ActivateUser`/`DeactivateUser`/`ResetUserPassword`/`SyncUserRoles`, their Requests/Controllers/routes, `BusinessRuleViolation` |
| 4c | Users frontend pages | PR 11 | `sail pnpm types:check` | N/A — pages consume already-tested routes | Revert the four `users/*.vue` pages |
| 5 | First-administrator console command | PR 12 | `sail pest --filter=CreateFirstAdministrator` | `sail artisan users:create-administrator` run once against `migrate:fresh --seed` | Revert `CreateFirstAdministrator` Action + Command (the nullable-actor `CreateUser` signature ships with PR 9 and stays) |
| 6a | Roles CRUD, permission sync, last-administrator protection, self-action guards | PR 13 | `sail pest --filter=Roles,AdministrativeProtections` | N/A — fully covered by HTTP feature tests | Revert `RolePolicy`, Role Requests/Actions/Controllers/routes, `EnsureAdministrationIsPreserved`, the self-action guard additions |
| 6b | Roles frontend pages | PR 14 | `sail pnpm types:check` | N/A | Revert the four `roles/*.vue` pages |
| 7 | Audit query in the operating timezone | PR 15 | `sail pest --filter=Audit` | `sail artisan route:list --name=audit` shows only `audit.index` | Revert `OperatingTime`, `AuditLogPolicy`, `AuditLogIndexRequest`, `AuditLogController`, `audit/Index.vue`, `operating_timezone` config key |

---

## Phase 1: Schema & Domain Core (design slice 1)

Can start in parallel with Phase 2 (no shared files). Blocks every later phase.

### PR 1a — Audit trail foundation (migrations + triggers + model guard)

- [x] 1.1 **[infra, first task of the whole plan]** Apply the Sail MySQL trigger-privilege fix and verify it before any migration in this slice is authored. Edit `compose.yaml`: add `command: --skip-log-bin` to the `mysql` service (design.md Decision 11). Run `sail down && sail up -d`, then `sail mysql -e "SELECT @@log_bin, @@log_bin_trust_function_creators, CURRENT_USER()"` and confirm `@@log_bin = 0`. Document the observed output in the PR description.
- [x] 1.2 RED — write `tests/Feature/Audit/AuditImmutabilityTest.php` covering E-29(b) (`AuditLog::first()->update()` / `->delete()` throw `AuditLogIsImmutable`), E-29(c) (`DB::table('audit_logs')->update()` / `->delete()` throw `QueryException` with SQLSTATE `45000`), and E-29(d) (the row is unchanged afterward). Expected to fail: the table and model do not exist yet. [FND-024, E-29]
- [x] 1.3 GREEN — create `database/migrations/2026_09_28_000003_create_audit_logs_table.php`: the `audit_logs` table per the Interfaces/Contracts schema table (`id`, `created_at` timestamp(6) UTC, `actor_id` nullable FK→users restrict, `actor_email`, `action`, `entity_type`, `entity_id`, `ip_address` nullable, `old_values`/`new_values`/`context` json, indexes) plus the two `DB::unprepared` `BEFORE UPDATE` / `BEFORE DELETE` triggers (design.md Decision 11); `down()` drops the triggers before the table and refuses when `app()->isProduction()`. Run `sail artisan migrate` and confirm the E-29(c) DB-level assertions in 1.2 pass while the model-level (b) ones still fail. [FND-022, FND-023, FND-024]
- [x] 1.4 GREEN — create `app/Models/AuditLog.php` (`const UPDATED_AT = null`, `booted()` registers `updating`/`deleting` listeners throwing the new exception, `actor()` relation, JSON casts) and `app/Exceptions/AuditLogIsImmutable.php`. Run `tests/Feature/Audit/AuditImmutabilityTest.php` — fully GREEN except E-29(a) (route enumeration; deferred to Phase 7, task 7.6, once `audit.index` exists). [FND-024, E-29]
- [x] 1.5 REFACTOR — `sail pint` on the migration/model/exception files; confirm `sail artisan migrate:rollback --step=1` then `sail artisan migrate` round-trips cleanly (down() drops triggers first).

### PR 1b — Users/roles/permissions schema, models, enums

> **Documented Strict TDD exception**: tasks 1.6–1.8 are pure structural migrations with no directly testable behavior of their own (no route, no Action calls them yet). Per `openspec/config.yaml`'s rationale for non-TDD-covered work, their correctness is proven by the first consuming feature test in Phase 3 (E-01, E-05), Phase 4 (E-11, E-12), and Phase 6 (E-21..E-24). This is the only phase where a GREEN task has no preceding RED task.

- [x] 1.6 [schema, documented exception above] Create `database/migrations/2026_09_28_000001_update_users_table_for_foundation.php` (design.md Decision 13: add `first_name`, `last_name`, `is_active` default true indexed, `must_change_password` default false; backfill `first_name` from `name`, `last_name = ''`; drop `name`, `email_verified_at`, `remember_token`; keep `users_email_unique`). [FND-007, FND-008]
- [x] 1.7 [schema, documented exception above] Create `database/migrations/2026_09_28_000002_create_roles_and_permissions_tables.php` (`roles` unique `name` + `is_protected`; `permissions` unique `name`; `permission_role` PK(role_id,permission_id), FK role→cascade, FK permission→restrict; `role_user` PK(user_id,role_id), FK user→restrict, FK role→restrict). [FND-016, FND-017, FND-018]
- [x] 1.8 [schema, documented exception above] Create `database/migrations/2026_09_28_000004_create_login_throttles_table.php` (`email` PK, `failed_attempts` smallint default 0, `locked_until` nullable timestamp, `updated_at`) — schema prerequisite for Phase 3's `LoginThrottle`. [FND-003]
- [x] 1.9 GREEN — create `app/Models/Role.php` (`users()`, `permissions()`, `is_protected` cast) and `app/Models/Permission.php` (`roles()`). [FND-016, FND-017, FND-018]
- [x] 1.10 GREEN — modify `app/Models/User.php`: fillable/casts/hidden for the new columns, email lowercase+trim mutator, `roles(): BelongsToMany`, `hasPermission(PermissionName|string): bool` (single `EXISTS` query, never cached — design.md Decision 3), `permissionNames(): list<string>`, `$rememberTokenName = ''`. [FND-007, FND-008, FND-018]
- [x] 1.11 RED — write `tests/Unit/PermissionNameTest.php` asserting `PermissionName` has exactly the 9 cases of spec §9, each with a non-empty Spanish `description()`. [FND-017]
- [x] 1.12 GREEN — create `app/Enums/PermissionName.php` (9 cases: `UsersView`, `UsersCreate`, `UsersUpdate`, `UsersDeactivate`, `UsersResetPassword`, `UsersAssignRoles`, `RolesView`, `RolesManage`, `AuditView`). Run 1.11 — GREEN. [FND-017]
- [x] 1.13 RED — write `tests/Unit/AuditActionTest.php` asserting `AuditAction` contains all 18 events named in design.md's Enums contract, each with a non-empty Spanish `label()`. [FND-022]
- [x] 1.14 GREEN — create `app/Enums/AuditAction.php` per the contract in design.md. Run 1.13 — GREEN. [FND-022]
- [x] 1.15 REFACTOR — `sail pint`; confirm `sail artisan migrate:fresh` runs all 4 migrations together without error.

### PR 1c — Audit logger, redaction, origin, seeders, factories, test infrastructure

- [x] 1.16 RED — write `tests/Unit/AuditRedactorTest.php`: nested keys containing `password` (case-insensitive, any depth) or exactly `remember_token` are dropped; other keys survive. [FND-012, FND-023 — supports E-28]
- [x] 1.17 GREEN — create `app/Support/Audit/AuditRedactor.php`. Run 1.16 — GREEN.
- [x] 1.18 RED — write `tests/Unit/AuditOriginTest.php`: `AuditOrigin::fromRequest()` captures `$request->ip()` and empty context; `AuditOrigin::console($cmd)` sets `ip_address = null`, `context = {source: 'console', command, os_user, host}`.
- [x] 1.19 GREEN — create `app/Support/Audit/AuditOrigin.php`. Run 1.18 — GREEN. [groundwork for DEC-021]
- [x] 1.20 RED — write `tests/Feature/Audit/AuditRecordTest.php` (initial subset): `RecordAuditEvent::handle()` inserts one row inside the caller's transaction, applies `AuditRedactor` to `old_values`/`new_values`/`context`, and sets `actor_id`/`actor_email` per the actor/origin rules in design.md Decision 9. [FND-022, FND-023 — full E-27 content assertions extend this file once `UpdateUser` exists in Phase 4, task 4.4]
- [x] 1.21 GREEN — create `app/Actions/Audit/RecordAuditEvent.php`. Run 1.20 — GREEN.
- [x] 1.22 RED — write `tests/Feature/FoundationSeederTest.php`: after `FoundationSeeder` runs, exactly 7 roles and 9 permissions exist; exactly one role (`is_protected = true`) has all 9 permissions attached and every other role has none; running the seeder twice does not duplicate rows or reassign permissions. [FND-017]
- [x] 1.23 GREEN — create `database/seeders/PermissionCatalogSeeder.php` (idempotent upsert by `name`, never deletes/assigns), `database/seeders/RoleSeeder.php` (creates the 7 roles of spec §7 only if missing; attaches the 9 permissions to the protected role only when it creates that role), `database/seeders/FoundationSeeder.php`; modify `database/seeders/DatabaseSeeder.php` to call only `FoundationSeeder` and remove the starter test user. Run 1.22 — GREEN.
- [x] 1.24 GREEN — modify `database/factories/UserFactory.php` (first/last name, `is_active`, `must_change_password`; states `inactive()`, `mustChangePassword()`, `withPermissions(...)`); create `database/factories/RoleFactory.php`.
- [x] 1.25 [infra] Modify `phpunit.xml` (remove `SESSION_DRIVER=array` so tests use the pinned `database` driver); modify `tests/Pest.php` (helpers `userWithPermissions()`, `administrator()`, `loginWithRealSession()`, `requestWithSession()`, `RefreshDatabase` applied to Feature); modify `tests/TestCase.php` (`$seed = true`, `$seeder = FoundationSeeder::class`); delete `tests/Feature/ExampleTest.php` (asserts a public `/`, now false) and `tests/Unit/ExampleTest.php`.
- [x] 1.26 REFACTOR — `sail pint`; confirm `./vendor/bin/sail artisan test` (currently only Phase 1's tests) is green with the database session driver active.

**Phase 1 closing checks**: `./vendor/bin/sail artisan test`, `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer types:check`, `./vendor/bin/sail pnpm types:check`, `./vendor/bin/sail pnpm build`.

**Work-unit commits**: one commit per PR (1a, 1b, 1c), each bundling its migrations/models/tests together (never split a RED test from the GREEN commit that makes it pass across two commits).

---

## Phase 2: UI Foundation (design slice 2)

Can start in parallel with Phase 1 (no shared files). No JS test framework is configured (`openspec/config.yaml`); frontend correctness is verified by `vue-tsc` (`types:check`), `pnpm build`, and the design-system §11 checklist — not by Pest — so these tasks have no RED step.

### PR 2a — Design tokens, blade shell, locale

- [x] 2.1 GREEN — replace `resources/css/app.css` with the design-system §2 token block, removing the starter kit's `Instrument Sans` `@theme inline`.
- [x] 2.2 GREEN — modify `resources/views/app.blade.php`: `lang="es"`, `viewport-fit=cover`, remove the `dark` class binding (theme class is applied only by the UI-06 no-flash script, see 2.12), replace `@fonts` with the interim Google Fonts `<link>` tags (Outfit, Plus Jakarta Sans, Material Symbols Outlined — UI-03 interim, no new dependency).
- [x] 2.3 GREEN — modify `config/app.php`: default `locale` → `es` (leave `timezone` as `UTC`; the `operating_timezone` key is added in Phase 7 where it is first consumed). Create `lang/es/validation.php` (only the rules used in this change) and a `lang/es/auth.php` stub (filled with `auth.failed`/`auth.locked` in Phase 3, task 3.12).
- [x] 2.4 GREEN — modify `resources/js/app.ts`: progress-bar color uses a token-compatible value, not a Tailwind default palette color.
- [x] 2.5 [manual check] Verify against `docs/ui/design-system.md` §11 checklist: tokens, viewport, no `dark:` classes (dark mode = `.dark` token overrides only, UI-06; see 2.12).

- [x] 2.12 GREEN — dark mode foundation (UI-06, user decision 2026-09-28): in `resources/css/app.css` add `@custom-variant dark (&:where(.dark, .dark *));`, a `.dark { … }` block overriding every color token of §2 (matching the doc exactly) and `color-scheme` (`light` on `:root`, `dark` on `.dark`); in `resources/views/app.blade.php` add an inline `<script>` in `<head>` before `@vite` that reads `localStorage.getItem('appearance')` in try/catch and adds `dark` to `<html>` only when the value is `'dark'` (light by default, no `prefers-color-scheme`).

### PR 2b — Layouts, base components, Forbidden page

- [x] 2.6 GREEN — create `resources/js/types/auth.ts` (`AuthUser` DTO + `permissions: string[]`).
- [x] 2.7 GREEN — create components in `resources/js/components/`: `AppButton.vue`, `IconButton.vue`, `AppIcon.vue`, `AppInput.vue`, `AppSelect.vue`, `AppCheckbox.vue` (design-system §1.2/§7.4, not in §7's base list), `AppCard.vue`, `StatusBadge.vue` (active→`active`, inactive→`neutral`, always with text), `DataTable.vue`, `BottomNav.vue`, `SideNav.vue`, `AppPagination.vue`, `FlashMessage.vue`.
- [x] 2.8 GREEN — create `resources/js/layouts/AuthLayout.vue` (centered `AppCard`, used by login/forced change) and `resources/js/layouts/AppLayout.vue` (sidebar at `lg`+, `BottomNav` below `lg`, at most 5 destinations — Inicio, Usuarios, Roles, Auditoría, Cambiar contraseña — each shown only if its permission is in `auth.permissions`; Inicio and Cambiar contraseña always shown). _Superseded by PR 3d (design Decision 20): max 4 destinations + "Más" sheet; "Cambiar contraseña" moved to the user menu._
- [x] 2.9 GREEN — create `resources/js/pages/errors/Forbidden.vue` (403 page; wired to the exception render hook in Phase 4, task 4.8).
- [x] 2.10 [cleanup] Delete `resources/js/pages/Welcome.vue` (replaced by `Home.vue` in Phase 3).
- [x] 2.13 GREEN — create `resources/js/composables/useAppearance.ts` (reads/writes `localStorage` key `appearance`, toggles `.dark` on `<html>`; light default) and a theme toggle control in the ERP layout (`AppLayout.vue`) with a Spanish accessible label (`aria-label`, ≥44px target). No `dark:` classes (UI-06).
- [x] 2.11 [manual check] Verify against `docs/ui/design-system.md` §11 checklist: both themes checked (light default, dark via the 2.13 toggle) with no flash on load; 44px touch targets, ≥16px inputs, mobile-first breakpoints, accessibility rules §8, `rounded-lg` (UI-01) and success/warning tokens (UI-02) applied per their "Propuesta" recommendation.

**Phase 2 closing checks**: `./vendor/bin/sail artisan test`, `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer types:check`, `./vendor/bin/sail pnpm types:check`, `./vendor/bin/sail pnpm build`.

**Work-unit commits**: one commit per PR (2a, 2b).

---

## Phase 3: Authentication & Session (design slice 3)

Depends on Phase 1 (schema/models) and Phase 2 (layouts/components).

### PR 3a — Protected-by-default + public route declaration + route cleanup

- [x] 3.1 RED — write `tests/Feature/Authorization/ProtectedByDefaultTest.php` (E-30): enumerate `Route::getRoutes()`, skip names in `PublicRoutes::NAMES`, assert every other route returns 302 to `route('login')` for a guest; assert `PublicRoutes::NAMES === ['login', 'login.store']` and both names are registered. Expected RED: `home` (`GET /`) is currently unauthenticated and `login`/`login.store` don't exist. [FND-026, E-30]
- [x] 3.2 GREEN — create `app/Support/Http/PublicRoutes.php` (`NAMES = ['login', 'login.store']`, `contains(?string $routeName): bool`).
- [x] 3.3 GREEN — create `app/Http/Middleware/RequireAuthentication.php` (extends `Illuminate\Auth\Middleware\Authenticate`; skips the check only when `PublicRoutes::contains($request->route()?->getName())`).
- [x] 3.4 GREEN — modify `bootstrap/app.php`: remove `health: '/up'`; append `RequireAuthentication` to the `web` group; `redirectGuestsTo(fn () => route('login'))`.
- [x] 3.5 GREEN — modify `config/filesystems.php` (`local.serve => false`, removes the unauthenticated `GET|PUT /storage/{path}` routes) and `config/inertia.php` (`devtools.enabled => env('INERTIA_DEVTOOLS_ENABLED', false)`, removes `/_inertia/devtools/*` in `local`).
- [x] 3.6 GREEN — add placeholder `login` (GET) / `login.store` (POST) routes to `routes/web.php` returning a stub response (the real `LoginController` lands in PR 3b), so E-30's public-route assertions are meaningful against a non-trivial route table. Run 3.1 — GREEN.
- [x] 3.7 REFACTOR — `sail pint`; confirm `sail artisan route:list` shows no route outside the `web` group.

### PR 3b — Login, logout, throttle, session pinning

- [x] 3.8 [infra] Modify `config/session.php`: pin `driver => 'database'` and `lifetime => 120` as literals (design.md Decision 6).
- [x] 3.9 RED — write `tests/Feature/Auth/LoginTest.php`: E-01 (correct credentials authenticate an active user with no pending forced change), E-02 (wrong password → generic message), E-03 (unknown email → same generic message), E-04 (inactive user → same generic message), E-06 (5 failures then a 6th, even with the correct password, is rejected before 15 minutes and the lockout is audited; `travel(16)->minutes()` then a correct login succeeds), E-05 (login with ` Ana@Ecolekua.com ` identifies `ana@ecolekua.com`). [FND-001, FND-002, FND-003, FND-008; E-01..E-06]
- [x] 3.10 RED — write `tests/Feature/Auth/LoginThrottleTest.php`: DEC-017 (4 failures + `travel(30)->days()` + 1 failure → locked; 4 failures + success + 4 failures → not locked; 5 failures + `travel(15)->minutes()` + 4 failures → not locked, a 5th → locked again; attempts while locked don't extend `locked_until`) and DEC-018 (5th failure keeps the generic message; 6th returns the pluralized lock message with remaining minutes; message for a locked unknown email equals the message for a locked existing email with the same remaining time). [FND-003; DEC-017, DEC-018]
- [x] 3.11 GREEN — create `app/Support/Auth/LoginThrottle.php` (`lockedMinutesRemaining()` normalizes an expired lock then returns null/minutes; `recordFailure()`; `clear()` — design.md Decision 7).
- [x] 3.12 GREEN — fill `lang/es/auth.php` with `auth.failed` (generic, FND-002) and the pluralized `auth.locked` (`:minutes`, DEC-018) messages.
- [x] 3.13 GREEN — create `app/Actions/Auth/AttemptLogin.php` implementing the login flow from design.md's Data Flow section (`Succeeded`/`Failed`/`Locked` result value object; the transaction commits before the `ValidationException` is thrown).
- [x] 3.14 GREEN — create `app/Http/Requests/Auth/LoginRequest.php` (normalizes email in `prepareForValidation()`) and `app/Http/Controllers/Auth/LoginController.php`; replace the PR 3a placeholder routes in `routes/web.php` with the real `guest`-gated `login`/`login.store` routes. Run 3.9 and 3.10 — GREEN.
- [x] 3.15 RED — write `tests/Feature/Auth/LogoutTest.php` (E-07: logout then a protected operation is rejected and redirects to login) and `tests/Feature/Auth/SessionTest.php` (E-08: session inactive for >120 minutes via `travel()` is rejected on the next protected operation; E-09: an open session is rejected on the next request after the user is deactivated — this task proves it via direct `sessions`/`is_active` row manipulation; the full HTTP-driven path through `DeactivateUser` is re-verified in Phase 4, task 4.9). [FND-004, FND-005, FND-006; E-07, E-08, E-09]
- [x] 3.16 GREEN — create `app/Support/Auth/SessionInvalidator.php` (`forUser()` deletes the user's `sessions` rows), `app/Http/Middleware/EnsureUserIsActive.php` (web group, after `RequireAuthentication`: `$user->refresh()`, logout+invalidate+redirect if missing/inactive), `app/Actions/Auth/Logout.php`, `app/Http/Controllers/Auth/LogoutController.php`; add the authenticated `logout` route (`POST /logout`); append `EnsureUserIsActive` in `bootstrap/app.php`. Run 3.15 — GREEN.
- [x] 3.17 REFACTOR — `sail pint`; confirm the middleware order (`RequireAuthentication` → `EnsureUserIsActive`) matches design.md's Data Flow diagram.

### PR 3c — Home page, forced/own password change

- [x] 3.18 GREEN — create `app/Http/Controllers/HomeController.php` and `resources/js/pages/Home.vue` (minimal "Inicio": greeting + navigation limited to the user's permissions, no metrics — resolved Open Question in proposal.md); add the authenticated `home` route (`GET /`).
- [x] 3.19 RED — write `tests/Feature/Passwords/ForcedPasswordChangeTest.php` (E-14: a user with a pending forced change is rejected on any route not in `['password.edit', 'password.update', 'logout']` and redirected to `password.edit`). [FND-014, E-14]
- [x] 3.20 GREEN — create `app/Http/Middleware/EnsurePasswordIsChanged.php` (design.md Decision 8); append to `bootstrap/app.php` web group after `EnsureUserIsActive`. Also make `LoginController::store` redirect to `password.edit` when `must_change_password` is true (deferred from 3.14 because `password.edit` does not exist until this PR; cover it in 3.19/3.21). Run 3.19 — GREEN.
- [x] 3.21 RED — write `tests/Feature/Passwords/OwnPasswordChangeTest.php`: E-16 (own change with <10-char password rejected), E-17 (correct current password changes it; incorrect current password rejects it), E-28 (own-change audit row contains no password/hash values), FND-013 no-reuse (`NotCurrentPassword`), and `it('FND-012 ...')` asserting the stored `password` column is hashed (`Hash::check()` succeeds, the raw column never equals the plaintext value). [FND-012, FND-013, FND-015; E-16, E-17, E-28]
- [x] 3.22 GREEN — create `app/Rules/NotCurrentPassword.php`, `app/Actions/Auth/ChangeOwnPassword.php`, `app/Http/Requests/Auth/UpdatePasswordRequest.php` (`current_password` + `NotCurrentPassword` + `confirmed`), `app/Http/Controllers/Auth/PasswordController.php`; add `password.edit`/`password.update` authenticated routes; modify `app/Providers/AppServiceProvider.php` → `Password::defaults(fn () => Password::min(10))` in every environment (replacing the current production-only 12-char/complexity/`uncompromised()` rules). Run 3.21 — GREEN.
- [x] 3.23 GREEN — create `resources/js/pages/auth/Login.vue` and `resources/js/pages/auth/ChangePassword.vue`, wired to the controllers above and `AuthLayout`.
- [x] 3.24 GREEN — modify `app/Http/Middleware/HandleInertiaRequests.php` to share the `AuthUser` DTO (`id, first_name, last_name, email, must_change_password`), lazy `permissions` (flash uses Inertia v3's native flash, no custom shared prop: design Decision 19).
- [x] 3.25 [verify] Re-run `tests/Feature/Authorization/ProtectedByDefaultTest.php` (E-30) with the now-larger route table — must stay GREEN with only `login`/`login.store` public.
- [x] 3.26 REFACTOR — `sail pint`; confirm the full middleware order (`RequireAuthentication` → `EnsureUserIsActive` → `EnsurePasswordIsChanged` → `HandleInertiaRequests`).
- [x] 3.27 REFACTOR — in `resources/js/layouts/AppLayout.vue` replace the hardcoded `home` usage/`password.edit` URI in `DESTINATIONS` (Inicio, Cambiar contraseña) with the Wayfinder-generated helpers; keep `sail pnpm types:check` and `sail pnpm build` green. (design Decision 19; `DESTINATIONS` was replaced by `NAV_ENTRIES` in PR 3d, task 3.29/3.35)

### PR 3d — Navigation redesign (amendment, design Decision 20)

> User-approved UX/technical amendment (not a business decision): supersedes the 5-destination bar. Frontend only; there is no JS test framework and none is added, so no RED step is possible (same documented exception as Phase 2). No backend change. Route declaration: every task below is **direct inline** — the amendment was delegated to a single sdd-apply writer as one work unit; no further delegation.

- [x] 3.28 DOCS — record design Decision 20 (navigation model), update design-system §6.2 and §7.8, reconcile 2.8, 4.18, 6.12 and 7.8 (they now migrate `NAV_ENTRIES` in `resources/js/navigation.ts`), add this section. Route: direct inline. Check: `rg '5 destinos|máximo de 5' docs` returns nothing.
- [x] 3.29 GREEN — create `resources/js/navigation.ts` (`NAV_ENTRIES`, `NAV_GROUPS`, types; Inicio + Usuarios/Roles/Auditoría only; permissions verified against `App\Enums\PermissionName`; `home` via Wayfinder, other URIs with pointers to 4.18/6.12/7.8). Route: direct inline. Check: `sail pnpm types:check`.
- [x] 3.30 GREEN — create `resources/js/composables/useNavigation.ts` (`primary` max 4, `overflow` grouped, `grouped`, active state). Route: direct inline. Check: `sail pnpm types:check`.
- [x] 3.31 GREEN — move `logo-blanco.webp` to `resources/images/` and rework `SideNav.vue` (image logo, group sections, ungrouped first). Route: direct inline. Check: `sail pnpm build` emits the asset.
- [x] 3.32 GREEN — rework `BottomNav.vue` (`primary` + "Más" button only with overflow; items >= 48 px). Route: direct inline. Check: `types:check`, `vp lint`.
- [x] 3.33 GREEN — create `resources/js/components/MoreSheet.vue` (native `<dialog>`, `showModal()`, `aria-labelledby`, closes on backdrop and on navigation, grouped entries, >= 44 px, `pb-safe`, `motion-safe:`). Route: direct inline. Check: `types:check`, `vp lint`, `build`.
- [x] 3.34 GREEN — create `resources/js/components/UserMenu.vue` (initials avatar, `aria-haspopup="menu"`, `aria-expanded`, Cambiar contraseña, Cerrar sesión via POST `logout`, closes on Esc and outside click). Route: direct inline. Check: `types:check`, `vp lint`, `build`.
- [x] 3.35 REFACTOR — `AppLayout.vue`: remove `DESTINATIONS`, use `useNavigation()`, mount `UserMenu` beside the theme toggle. Route: direct inline. Check: `types:check`, `vp lint`, `build`.
- [x] 3.36 [verify] `sail pnpm types:check`, `sail pnpm exec vp lint`, `sail pnpm build`, `sail artisan test`, `sail pint --test`, `rg 'dark:|\[#' resources/js` (no matches). Manual browser check (375 px and 1280 px, both themes, keyboard) is the human's.

**PR 3d closing checks**: the five commands of 3.36. **Work-unit commit**: one commit for PR 3d (frontend only).

**Phase 3 closing checks**: `./vendor/bin/sail artisan test`, `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer types:check`, `./vendor/bin/sail pnpm types:check`, `./vendor/bin/sail pnpm build`.

**Work-unit commits**: one commit per PR (3a, 3b, 3c, 3d).

---

## Phase 4: Users Administration (design slice 4)

Depends on Phase 1 and Phase 3 (authorization stack, session invalidation).

### PR 4a — User policy, CreateUser, UpdateUser

- [x] 4.1 GREEN — create `app/Support/Users/UserRules.php` (shared identity validation: name lengths, email format, unique-after-normalization) — reused by `StoreUserRequest`, `UpdateUserRequest`, and Phase 5's console command.
- [x] 4.2 RED — write `tests/Feature/Users/CreateUserTest.php`: E-11 (create with first/last name, email, temporary password, ≥1 role → active + `must_change_password = true`), E-12/create (duplicate email rejected), E-13 (no roles rejected), E-16/create (<10-char password rejected), and `it('FND-007 ...')` asserting the persisted user has an id, first name, last name, email, hashed password, `is_active`, `must_change_password`, `created_at`, `updated_at`. [FND-007, FND-010, FND-014, FND-018; E-11, E-12, E-13, E-16]
- [x] 4.3 GREEN — create `app/Policies/UserPolicy.php` (`create` → `users.create`), `app/Http/Requests/Users/StoreUserRequest.php` (uses `UserRules`), `app/Actions/Users/CreateUser.php` (`handle(array $data, ?User $actor, ?AuditOrigin $origin = null)`; at-least-one-role rule; `users.created` audit), `app/Http/Controllers/Users/UserController.php` (`index`, `create`, `store`, `show`, `edit`); add `users.index`, `users.create`, `users.store`, `users.show`, `users.edit` routes. Run 4.2 — GREEN.
- [x] 4.4 RED — extend `tests/Feature/Users/UpdateUserTest.php`: E-12/update (update-to-duplicate-email rejected), E-27 (`users.updated` audit `old_values`/`new_values` contain only the changed fields among `first_name`/`last_name`/`email`). [FND-008, FND-010; E-12, E-27]
- [x] 4.5 GREEN — extend `UserPolicy::update` (→ `users.update`, no self-action guard per DEC-020), create `app/Http/Requests/Users/UpdateUserRequest.php`, `app/Actions/Users/UpdateUser.php`; extend `UserController::update`; add `users.update` route. Run 4.4 — GREEN.
- [x] 4.6 REFACTOR — `sail pint`; re-run `CreateUserTest.php` + `UpdateUserTest.php` together to confirm `UserRules` behaves identically for both operations.

### PR 4b — Status, password reset, role assignment, denial auditing

- [x] 4.7 RED — write `tests/Feature/Authorization/PermissionAuthorizationTest.php`: E-10 (a role loses a permission mid-session → the same operation is rejected without re-login), E-19 (no-permission user gets 403, the operation has no effect, and `authorization.denied` is audited with actor + route), E-20 (permitted user succeeds), E-21 (a user with two roles is authorized by either role's permissions). [FND-006, FND-019; E-10, E-19, E-20, E-21]
- [x] 4.8 GREEN — register the `AccessDeniedHttpException` render hook in `bootstrap/app.php` → `withExceptions` (audits `authorization.denied` with route name, method, route parameter ids, IP; renders `errors/Forbidden` at 403 / JSON 403); if the E-19 RED test shows Laravel 13 does not convert `AuthorizationException` to `AccessDeniedHttpException` before render hooks run, register the hook for `AuthorizationException` instead (design.md Decision 10 open follow-up — confirm and record the outcome in this task). Create `app/Exceptions/BusinessRuleViolation.php` and its render hook (redirect back with a flash error; 422 for JSON). Run 4.7 — GREEN. **Outcome (PR 4b)**: confirmed in Laravel 13 `Handler::render` (`prepareException` runs before `renderViaCallbacks`) and by the E-19 tests: a policy denial from a FormRequest or `Gate::authorize` reaches the `AccessDeniedHttpException` hook, so no `AuthorizationException` hook is needed. The hook bodies live in `App\Exceptions\RenderAccessDenied` and `RenderBusinessRuleViolation`, registered from `bootstrap/app.php`.
- [x] 4.9 RED — write `tests/Feature/Users/UserStatusTest.php`: E-18 (a deactivated user's prior audit records retain their identity reference) and the full HTTP-driven E-09 assertion (an administrator deactivates another user with an open session → that user's next request is rejected), completing the direct-manipulation version from Phase 3 task 3.15. [FND-006, FND-011; E-09, E-18]
- [x] 4.10 GREEN — create `app/Actions/Users/ActivateUser.php` and `DeactivateUser.php` (calls `SessionInvalidator`; the self-deactivation guard is added in Phase 6, task 6.8 — see cross-reference there), extend `UserPolicy::deactivate` (→ `users.deactivate`), `app/Http/Controllers/Users/UserStatusController.php`; add `users.activate`/`users.deactivate` routes. Run 4.9 — GREEN.
- [x] 4.11 RED — write `tests/Feature/Passwords/ResetPasswordTest.php`: E-15 (administrator reset invalidates the target's sessions and forces a change at next login), E-16/reset (<10-char password rejected), E-28/reset (reset audit row has no password/hash values). Also start `tests/Feature/AdministrativeProtections/SelfActionTest.php` with the DEC-020 subset: self-edit via `PUT /users/{self}` and self-reset via `PUT /users/{self}/password` are both allowed; a self-reset deletes the actor's own sessions and redirects to `login`; the next login lands on `password.edit`. [FND-013, FND-014; E-15, E-16, E-28; DEC-020]
- [x] 4.12 GREEN — create `app/Actions/Users/ResetUserPassword.php`, extend `UserPolicy::resetPassword` (→ `users.reset_password`, no self guard per DEC-020), `app/Http/Requests/Users/ResetPasswordRequest.php`, `app/Http/Controllers/Users/UserPasswordController.php` (after the Action returns, detects `$actor->is($user)` → explicit `Auth::logout()` + redirect to `login`); add `users.password.reset` route. Run 4.11 — GREEN.
- [x] 4.13 RED — extend `tests/Feature/Users/UserStatusTest.php` (or a new focused test in the same directory): assigning/removing roles via `PUT /users/{user}/roles` records `users.roles_assigned`/`users.roles_removed` with role ids and names.
- [x] 4.14 GREEN — create `app/Actions/Users/SyncUserRoles.php` (self-guard added in Phase 6, task 6.8), extend `UserPolicy::assignRoles` (→ `users.assign_roles`), `app/Http/Requests/Users/SyncUserRolesRequest.php`, `app/Http/Controllers/Users/UserRoleController.php`; add `users.roles.update` route. Run 4.13 — GREEN.
- [x] 4.15 REFACTOR — `sail pint`; confirm every Action above passes `AuditOrigin::fromRequest()` by default via `RecordAuditEvent`.

> **Cross-reference**: FND-021/E-26 (self-deactivation, self-role-change) is intentionally NOT added in PR 4b. It ships in Phase 6 (task 6.8) together with FND-020's last-administrator protection, because both guards share `EnsureAdministrationIsPreserved`'s lock/assert cycle built in that phase.

### PR 4c — Users frontend pages

- [x] 4.16 GREEN — create `resources/js/pages/users/Index.vue` (`DataTable` + `StatusBadge`), `Create.vue`, `Show.vue` (hosts the roles, reset, and activation forms), `Edit.vue`, wired to the Wayfinder-generated routes and `AppLayout`.
- [ ] 4.17 [manual check] Verify against `docs/ui/design-system.md` §11: 44px touch targets, `DataTable` responsiveness, action buttons gated by permission (UI-only convenience; the backend already authorizes per §7.1).
- [x] 4.18 REFACTOR — in `resources/js/navigation.ts` replace the hardcoded `/users` URI in `NAV_ENTRIES` (Usuarios) with the Wayfinder-generated `users.index` helper. (design Decisions 19 and 20)

- [x] 4.19 GREEN — add `resources/js/components/ConfirmDialog.vue` (design-system §7.10, design Decision 22, UI-07) and use it in `users/Show.vue` for "Desactivar usuario" and "Restablecer contraseña" (the form submits only after confirming; activating does not need it). Route: direct inline (component plus one page edit; already-understood pattern from `MoreSheet`). Checks: `sail pnpm types:check`, `sail pnpm exec vp lint`, `sail pnpm build`, `rg 'dark:|\[#' resources/js`; no RED possible (no JS test framework). Manual keyboard checks join 4.17.

**Phase 4 closing checks**: `./vendor/bin/sail artisan test`, `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer types:check`, `./vendor/bin/sail pnpm types:check`, `./vendor/bin/sail pnpm build`.

**Work-unit commits**: one commit per PR (4a, 4b, 4c).

---

## Phase 5: First Administrator Console Command (design slice 4b)

Depends on Phase 1 (protected role) and Phase 4 (`CreateUser`, `AuditOrigin`, `UserRules`). Must be merged before any shared or production deployment — no administrator can otherwise exist.

- [x] 5.1 RED — write `tests/Feature/Console/CreateFirstAdministratorTest.php` covering all DEC-021 sub-scenarios from design.md's "Non-obvious test designs": creates an active administrator with `Str::createRandomStringsUsing(fn () => 'Tmp-DEC021-abcdefgh')` (exit 0; active; only the protected role; `must_change_password = true`; `Hash::check()` succeeds); exactly one `users.created` row with `actor_id = null`, `ip_address = null`, `context.source = 'console'`, `context.command = 'users:create-administrator'`, and no password/hash in its `json_encode`; refuses (exit 1, nothing written) when an active user already holds the protected role; runs when only inactive protected-role holders exist; rejects an invalid or duplicate email (exit 1, nothing written); refuses non-interactive runs (`--no-interaction`, exit 1, nothing written); fails with a clear message when the protected role does not exist; the first login with the temporary password redirects to `password.edit`; `Log::spy()` receives no message containing the temporary password. [FND-014, FND-020, FND-022; DEC-021]
- [x] 5.2 GREEN — create `app/Actions/Users/CreateFirstAdministrator.php`: `DB::transaction` locks the protected role row `FOR UPDATE` (missing → `BusinessRuleViolation`); precondition — no active user holds the protected role (→ `BusinessRuleViolation`, nothing written); `Str::random(20)` temporary password; delegates to `CreateUser::handle([...], actor: null, origin: AuditOrigin::console('users:create-administrator'))`.
- [x] 5.3 GREEN — create `app/Console/Commands/CreateFirstAdministrator.php` (signature `users:create-administrator`): refuses non-interactive runs (exit 1); prompts first name, last name, email with Laravel Prompts `text()`; validates with `UserRules::identity()`; calls the Action; prints the generated temporary password once with a change-it warning, never logs/stores/audits it in plain text. Run 5.1 — GREEN.
- [x] 5.4 REFACTOR — `sail pint`; re-run `tests/Feature/Users/CreateUserTest.php` (Phase 4) to confirm `CreateUser`'s nullable-actor signature still behaves correctly for the HTTP path.

**Phase 5 closing checks**: `./vendor/bin/sail artisan test`, `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer types:check`, `./vendor/bin/sail pnpm types:check`, `./vendor/bin/sail pnpm build`.

**Work-unit commits**: one commit for PR 5 (Action + Command + tests together).

---

## Phase 6: Roles & Administrative Protections (design slice 5)

Depends on Phase 3 (authorization) and Phase 4 (`DeactivateUser`, `SyncUserRoles`, `UserPolicy`).

### PR 6a — Roles CRUD, permission sync, last-administrator protection, self-action guards

- [ ] 6.1 RED — write `tests/Feature/Roles/RoleManagementTest.php`: E-22 (a role with ≥1 user assigned cannot be deleted), E-23 (the Administrador/protected role cannot be deleted or renamed; its description is editable). [FND-016; E-22, E-23]
- [ ] 6.2 GREEN — create `app/Policies/RolePolicy.php` (`view` → `roles.view`; `create`/`update`/`delete` → `roles.manage`), `app/Http/Requests/Roles/StoreRoleRequest.php`, `UpdateRoleRequest.php`, `app/Actions/Roles/CreateRole.php`, `UpdateRole.php` (protected-role name/rename guard), `DeleteRole.php` (users-assigned guard + protected-role guard), `app/Http/Controllers/Roles/RoleController.php`; add `roles.index/create/store/show/edit/update/destroy` routes. Run 6.1 — GREEN.
- [ ] 6.3 RED — write `tests/Feature/Roles/PermissionCatalogTest.php`: E-24 (insert an unassigned `permissions` row `testing.e24`, register a test-only route with `Gate::define('testing.e24', fn (User $u) => $u->hasPermission('testing.e24'))`; an administrator gets 403 until the permission is attached to their role, then 200) plus full seed-content assertions (7 roles, 9 permissions, all 9 only on the protected role — re-confirming task 1.22 at the roles-permissions capability level). [FND-017, FND-018; E-24]
- [ ] 6.4 GREEN — create `app/Actions/Roles/SyncRolePermissions.php`, extend `RolePolicy`, `app/Http/Requests/Roles/SyncRolePermissionsRequest.php`, `app/Http/Controllers/Roles/RolePermissionController.php`; add `roles.permissions.update` route. Run 6.3 — GREEN.
- [ ] 6.5 RED — write `tests/Feature/AdministrativeProtections/LastAdministratorTest.php` (replace its three E-25 `->todo()` placeholders added in the PR 4b review, design Decision 21): E-25 — with a single active user holding both `users.assign_roles` and `roles.manage`, each of (a) deactivating them, (b) removing their roles, (c) removing those permissions from their role is rejected. [FND-020; E-25]
- [ ] 6.6 GREEN — create `app/Actions/Authorization/EnsureAdministrationIsPreserved.php` (`lock()` = `SELECT ... FOR UPDATE` on the two admin-permission rows; `assert()` = throws `BusinessRuleViolation` when 0 active users hold both — design.md Decision 12); wire `lock()`/`assert()` into `DeactivateUser`, `SyncUserRoles` (Phase 4), `SyncRolePermissions`, `DeleteRole`. Run 6.5 — GREEN.
- [ ] 6.7 RED — finish `tests/Feature/AdministrativeProtections/SelfActionTest.php` (replace its two E-26 `->todo()` placeholders added in the PR 4b review, design Decision 21): E-26 (self-deactivation and own-role changes are rejected), re-confirming the DEC-020 subset already covered in Phase 4 (self-edit/self-reset remain allowed). [FND-021; E-26]
- [ ] 6.8 GREEN — add the self-action guard clauses (`$actor->is($target)`) to `DeactivateUser` and `SyncUserRoles` (the only two self-guards per DEC-020/design.md Decision 18). Run 6.7 — GREEN.
- [ ] 6.9 REFACTOR — `sail pint`; re-run `tests/Feature/Users/UserStatusTest.php` and `tests/Feature/Authorization/PermissionAuthorizationTest.php` (E-10) to confirm no regression from the new guards.

### PR 6b — Roles frontend pages

- [ ] 6.10 GREEN — create `resources/js/pages/roles/Index.vue`, `Create.vue`, `Show.vue` (hosts the permissions form), `Edit.vue`.
- [ ] 6.11 [manual check] Verify against `docs/ui/design-system.md` §11 for the roles pages.
- [ ] 6.12 REFACTOR — in `resources/js/navigation.ts` replace the hardcoded `/roles` URI in `NAV_ENTRIES` (Roles) with the Wayfinder-generated `roles.index` helper. (design Decisions 19 and 20)

**Phase 6 closing checks**: `./vendor/bin/sail artisan test`, `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer types:check`, `./vendor/bin/sail pnpm types:check`, `./vendor/bin/sail pnpm build`.

**Work-unit commits**: one commit per PR (6a, 6b).

---

## Phase 7: Audit Query (design slice 6)

Depends on Phase 1 (`audit_logs`) and Phase 3 (authorization stack). Independent of Phases 4–6 otherwise.

- [ ] 7.1 [infra] Add the literal `'operating_timezone' => 'America/Caracas'` to `config/app.php` (`timezone` stays `UTC` — design.md Decision 16, DEC-019).
- [ ] 7.2 RED — write `tests/Feature/Audit/AuditQueryTest.php`: DEC-019 boundary scenarios (insert rows at `2026-03-10 03:30:00 UTC` and `2026-03-10 04:00:00 UTC`; `from=to=2026-03-09` returns only the first, `from=to=2026-03-10` returns only the second; the Inertia prop shows `occurred_at = '09/03/2026 23:30:00'`; `config('app.timezone') === 'UTC'` and the stored `created_at` is unchanged); FND-025's filters (by user, action, date range, each independently); and `it('FND-025 ...')` asserting `audit.view` is required (403 without it). [FND-023, FND-025; DEC-019]
- [ ] 7.3 GREEN — create `app/Support/Time/OperatingTime.php` (`format()` → `d/m/Y H:i:s` in `America/Caracas`; `dayStartUtc()` → inclusive UTC bound; `nextDayStartUtc()` → exclusive UTC bound).
- [ ] 7.4 GREEN — create `app/Policies/AuditLogPolicy.php` (`viewAny` only → `audit.view`), `app/Http/Requests/Audit/AuditLogIndexRequest.php` (`from`/`to` as `Y-m-d`, `to after_or_equal:from`), `app/Http/Controllers/Audit/AuditLogController.php` (half-open UTC range query; DTO mapping with `occurred_at = OperatingTime::format(...)`); add `audit.index` route. Run 7.2 — GREEN.
- [ ] 7.5 GREEN — create `resources/js/pages/audit/Index.vue` (filters by user/action/date-range; prints `occurred_at` as received, no client-side conversion).
- [ ] 7.6 RED/GREEN — complete E-29(a) in `tests/Feature/Audit/AuditImmutabilityTest.php` (route enumeration: no registered route other than `GET audit.index` targets `audit_logs`); expected already-GREEN once `audit.index` is the only audit-affecting route registered by task 7.4. [FND-024; E-29]
- [ ] 7.7 REFACTOR — `sail pint`; re-run `tests/Feature/Authorization/ProtectedByDefaultTest.php` (E-30) against the final, complete route table for the whole change.
- [ ] 7.8 REFACTOR — in `resources/js/navigation.ts` replace the hardcoded `/audit` URI in `NAV_ENTRIES` (Auditoría) with the Wayfinder-generated `audit.index` helper; after this, `NAV_ENTRIES` holds no hardcoded URI. (design Decisions 19 and 20)

**Phase 7 closing checks (also the final closing checks for the whole change)**: `./vendor/bin/sail artisan test`, `./vendor/bin/sail pint --test`, `./vendor/bin/sail composer types:check`, `./vendor/bin/sail pnpm types:check`, `./vendor/bin/sail pnpm build`.

**Work-unit commits**: one commit for PR 7 (backend + frontend + tests together, since it is under 400 lines as a single unit; split into 7a/7b only if the real diff drifts noticeably above ~450 lines).

---

## Coverage Matrix

### Requirements (FND-xxx)

| ID | Covering task(s) |
|---|---|
| FND-001 | 3.9 (RED), 3.13–3.14 (GREEN) |
| FND-002 | 3.9, 3.12–3.14 |
| FND-003 | 1.8 (schema), 3.9–3.14 |
| FND-004 | 3.15–3.16 |
| FND-005 | 3.15–3.16 |
| FND-006 | 3.15–3.16 (E-09 partial), 4.7–4.9 (E-10, E-09 full) |
| FND-007 | 1.6 (schema), 1.10 (model), 4.2 (`FND-007 ...` test) |
| FND-008 | 1.6, 1.10, 3.9 (E-05), 4.2, 4.4 (E-12) |
| FND-009 | 1.6 (schema), 3.9 (E-04) |
| FND-010 | 4.1–4.5, 4.10, 4.14 |
| FND-011 | 4.9 (E-18) |
| FND-012 | 1.16–1.17 (`AuditRedactor`), 3.21 (`FND-012 ...` test) |
| FND-013 | 3.21–3.22, 4.2 (E-16 create) |
| FND-014 | 3.19–3.20, 4.2 (E-11), 4.11–4.12 (E-15), 5.1–5.3 (DEC-021) |
| FND-015 | 3.21–3.22 (E-17) |
| FND-016 | 1.7 (schema), 6.1–6.2 (E-22, E-23) |
| FND-017 | 1.11–1.12, 1.22–1.23 (seeder), 6.3–6.4 (E-24) |
| FND-018 | 1.7, 1.10, 4.2 (E-13), 6.3 (E-24), 6.5–6.6 (E-25 union of roles context) — E-21 in 4.7 |
| FND-019 | 4.7–4.8 (E-19, E-20) |
| FND-020 | 6.5–6.6 (E-25), 5.2 (DEC-021 precondition) |
| FND-021 | 6.7–6.8 (E-26) |
| FND-022 | 1.13–1.14, 1.20–1.21, 4.7–4.9, 5.1–5.3 |
| FND-023 | 1.16–1.17, 4.4 (E-27), 7.2 (filters) |
| FND-024 | 1.2–1.5 (E-29 b,c,d), 7.6 (E-29 a) |
| FND-025 | 7.1–7.4 (`FND-025 ...` test + DEC-019 filters) |
| FND-026 | 3.1–3.7, 3.25, 7.7 (E-30) |

### Scenarios (E-xx)

| ID | Covering task(s) |
|---|---|
| E-01 | 3.9 |
| E-02 | 3.9 |
| E-03 | 3.9 |
| E-04 | 3.9 |
| E-05 | 3.9 |
| E-06 | 3.9–3.14 |
| E-07 | 3.15–3.16 |
| E-08 | 3.15–3.16 |
| E-09 | 3.15–3.16 (partial), 4.9 (full) |
| E-10 | 4.7–4.8 |
| E-11 | 4.2–4.3 |
| E-12 | 4.2–4.3 (create), 4.4–4.5 (update) |
| E-13 | 4.2–4.3 |
| E-14 | 3.19–3.20 |
| E-15 | 4.11–4.12 |
| E-16 | 3.21–3.22 (own), 4.2 (create), 4.11 (reset) |
| E-17 | 3.21–3.22 |
| E-18 | 4.9 |
| E-19 | 4.7–4.8 |
| E-20 | 4.7–4.8 |
| E-21 | 4.7–4.8 |
| E-22 | 6.1–6.2 |
| E-23 | 6.1–6.2 |
| E-24 | 6.3–6.4 |
| E-25 | 6.5–6.6 |
| E-26 | 6.7–6.8 |
| E-27 | 4.4–4.5 |
| E-28 | 3.21–3.22 (own), 4.11–4.12 (reset) |
| E-29 | 1.2–1.5 (b, c, d), 7.6 (a) |
| E-30 | 3.1–3.7, 3.25, 7.7 |

### Decisions (DEC-017..021)

| ID | Covering task(s) |
|---|---|
| DEC-017 | 3.10–3.11 |
| DEC-018 | 3.10–3.12 |
| DEC-019 | 7.1–7.4 |
| DEC-020 | 4.11–4.12, 6.7 |
| DEC-021 | 5.1–5.4 |

All 26 FND requirements, all 30 E scenarios, and all 5 confirmed decisions (DEC-017..021) are covered by at least one task above.
