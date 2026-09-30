# Design: 001-foundation — Authentication, Users, Roles/Permissions, Authorization and Audit

> Source of truth: `docs/specs/001-foundation.md` v1.2 (FND-001..FND-026, E-01..E-30; DEC-001..DEC-021 all `Confirmada`, so the design gate in AGENTS.md §4.4 is open). DEC-017..DEC-021 resolved the five business questions this design had raised; see Resolved Decisions.
> Inputs: `proposal.md` (its open questions are resolved), `specs/*/spec.md`, `exploration.md`, `docs/constitution.md`, `docs/business/business-rules.md`, `docs/ui/design-system.md`, and the current code.
> This file is the project's only plan artifact (`openspec/config.yaml` → `rules.design`). There is no separate `plan.md`.

## Technical Approach

This is a small, conventional Laravel 13 design. It follows the pattern the project already requires: **thin controller → FormRequest (validation plus `authorize()` through a Policy) → Action (business rules plus `DB::transaction`) → audit logger in the same transaction**. It adds no new Composer or npm dependencies. The design keeps these four decisions the user already confirmed:

1. **Custom roles and permissions.** The permission catalog is a PHP enum (`App\Enums\PermissionName`). An idempotent seeder copies it into the `permissions` table. Code asks whether a user has a permission, never which role the user has, and no `Gate::before` exists. Effective permissions come from an `EXISTS` query each time they are checked, so nothing is cached between requests or even within one request.
2. **Plain `Auth` facade plus Actions** for login and logout. Login attempts are limited per normalized email, and the counter lives in a dedicated `login_throttles` table.
3. **Protected by default.** A middleware appended to the `web` group requires authentication for every route unless its name is in one declared list (`App\Support\Http\PublicRoutes`). Test E-30 walks every registered route to prove it.
4. **Custom audit trail.** An append-only `audit_logs` table and the `RecordAuditEvent` Action. The Eloquent model refuses updates and deletes, and MySQL `BEFORE UPDATE` / `BEFORE DELETE` triggers raise `SIGNAL` errors.

Sessions always use the database driver. Deactivating a user or resetting their password deletes that user's rows in `sessions`. The per-request `EnsureUserIsActive` middleware is a second safeguard and also makes the tests deterministic.

The frontend uses Inertia pages built on the design-system tokens and the §7 components. The frontend only displays what the backend returns; hiding a control is never the authorization.

Requirement to mechanism map:

| Requirement | Mechanism |
|---|---|
| FND-001, FND-002, FND-004 | `LoginController` + `AttemptLogin` / `Logout` Actions, `guest` middleware on the login routes |
| FND-003 (DEC-017, DEC-018) | `LoginThrottle` service over the `login_throttles` table, checked before credentials; specific lock message with remaining minutes |
| FND-005 | Database session driver with `lifetime = 120`, fixed in `config/session.php` |
| FND-006 | `SessionInvalidator` (deletes `sessions` rows) + `EnsureUserIsActive` + permission checks without cache |
| FND-007..FND-011 | `users` migration, `User` model, `app/Actions/Users/*`, `UserPolicy` |
| FND-012..FND-015 | `Password::defaults()` = min 10, `NotCurrentPassword` rule, `EnsurePasswordIsChanged` middleware, `ChangeOwnPassword` / `ResetUserPassword` Actions |
| FND-016..FND-018 | `roles`, `permissions`, pivot tables, `PermissionName` enum, `RolePolicy`, `app/Actions/Roles/*` |
| FND-019 | Policies over permissions, a render callback that audits access denials, and authorization done in FormRequest before any Action runs |
| FND-026 | `RequireAuthentication` middleware + `PublicRoutes` list + E-30 route-enumeration test |
| FND-020, FND-021 (DEC-020) | `EnsureAdministrationIsPreserved` (serialized row lock, then re-check after the change inside the transaction) + self-action checks in Actions (only the two FND-021 prohibitions) |
| FND-022..FND-025 (DEC-019) | `audit_logs` + `RecordAuditEvent` + `AuditOrigin` + `AuditRedactor` + model guard + DB triggers + `AuditLogController`; display and date filter in `America/Caracas` via `OperatingTime` |
| DEC-021 (FND-014, FND-020, FND-022) | `users:create-administrator` console command → `CreateFirstAdministrator` Action → reused `CreateUser` Action, audited with a console `AuditOrigin` |

## Architecture Decisions

### Decision 1: Module layout — Controller → FormRequest → Policy → Action

**Choice**: Controllers are one-liners that return an Inertia response or a redirect. A `FormRequest` validates input and its `authorize()` calls the Policy (`$this->user()->can('update', $this->route('user'))`). The Action receives validated data plus the actor, applies business rules, opens `DB::transaction`, and calls `RecordAuditEvent` inside it. Business-rule rejections throw `App\Exceptions\BusinessRuleViolation`, which renders as a redirect back with an error message (HTTP 422 for JSON).
**Alternatives considered**: Logic inside controllers, rejected by AGENTS.md §8 and the need for future API reuse. A service layer plus repositories, rejected as an artificial layer (constitution §13).
**Rationale**: This is the convention the project already mandates (`openspec/config.yaml` `rules.design`). Authorization runs before the Action starts, so a denied request has no side effects (FND-019, E-19).

### Decision 2: Permission catalog = PHP enum + idempotent seeder; roles hold no hard-coded meaning

**Choice**: `App\Enums\PermissionName` (string-backed) holds the 9 cases of spec §9, each with a Spanish `description()`. `PermissionCatalogSeeder` upserts every case by `name`: it inserts missing rows and updates descriptions. It **never deletes and never assigns**. `RoleSeeder` creates the 7 roles of spec §7 **only if they do not exist**, and attaches the 9 permissions only to Administrador and only when it creates that role. Administrador is identified by the `roles.is_protected` flag, never by its name. Later specs add enum cases (and assign them only if their spec says so, E-24).
**Alternatives considered**: `spatie/laravel-permission`, rejected by the user because it needs a dependency and cache invalidation. Seeding through data migrations: this runs automatically on deploy but mixes schema with data that the UI can edit. It is rejected; the seeders are listed as a deploy step in Migration / Rollout.
**Rationale**: One place declares the catalog (FND-017, AGENTS.md §8). Seeders that never delete and never assign cannot undo an administrator's edits, and never grant a new permission automatically.

### Decision 3: Permission resolution per check, no cache

**Choice**: `User::hasPermission(PermissionName|string $permission): bool` runs a single `EXISTS` query joining `role_user`, `permission_role` and `permissions`. `User::permissionNames(): list<string>` runs one query and is used only for Inertia shared props (navigation). Nothing is memoized on the model, in the container, or in the cache. Policies call `hasPermission`.
**Alternatives considered**: Loading permissions once per request onto the model. This is rejected because the test harness keeps the authenticated `User` instance across requests, so a memo would hide E-10 and E-24 regressions. Scoped container memoization is rejected because scoped instances are not flushed between requests inside one HTTP test.
**Rationale**: The data volume is tiny (7 roles, dozens of permissions, 1 to 3 checks per request). Correctness for FND-006, E-10 and E-24 matters more than saving about 1 ms.

### Decision 4: Policies only; no `Gate::before`, no role-name checks

**Choice**: `UserPolicy`, `RolePolicy` and `AuditLogPolicy` (auto-discovered), each ability mapped to exactly one permission (see Interfaces). There is no `Gate::before` and no `Gate::after`. No production code refers to a role name.
**Alternatives considered**: A `Gate::define` for each catalog permission. This duplicates the policies and is rejected. A `can:` middleware on routes is rejected because FormRequest `authorize()` already covers every write and the controllers cover reads.
**Rationale**: FND-018, DEC-003, BR-FND-003, AGENTS.md §7.2 and §7.3.

### Decision 5: Protected by default via a web-group middleware plus a declared public list

**Choice**: `App\Http\Middleware\RequireAuthentication` extends `Illuminate\Auth\Middleware\Authenticate`. It is appended to the `web` group and therefore runs for every web route. It skips the check only when `PublicRoutes::contains($request->route()?->getName())`. `PublicRoutes::NAMES = ['login', 'login.store']` (FND-026: login is the only public operation). Guests are redirected by `$middleware->redirectGuestsTo(fn () => route('login'))`. Because the class extends `Authenticate`, Laravel's middleware priority runs it before `SubstituteBindings`, so a guest never triggers a model lookup.

Routes registered outside the web group must not exist:
- remove `health: '/up'` from `bootstrap/app.php` (user decision);
- set `'serve' => false` on the `local` disk in `config/filesystems.php`. This removes the framework-registered unauthenticated `GET|PUT /storage/{path}` routes (`storage.local`). No spec needs file serving, and the E-30 test would fail if those routes stayed;
- add `'devtools' => ['enabled' => env('INERTIA_DEVTOOLS_ENABLED', false)]` to `config/inertia.php`. Inertia v3 otherwise registers unauthenticated `/_inertia/devtools/*` routes whenever `APP_ENV=local`. Turning it off keeps the route set identical in every environment. A developer can opt in locally.

E-30 (Testing Strategy) enumerates `Route::getRoutes()` and is the safety net for routes outside the web group.
**Alternatives considered**: (a) Wrapping routes in `Route::middleware('auth')->group(...)`. This is rejected because a route added outside the group would be public by accident. The middleware is protected by construction. (b) A global (kernel) middleware. This is not viable: the route is not resolved yet and the session has not started.
**Rationale**: FND-026, BR-FND-010, DEC-015. Specs 015 and 016 extend the list by adding route names with a comment citing their spec. The model itself does not change.

### Decision 6: Database sessions, pinned; invalidation by deleting rows plus a per-request active check

**Choice**: In `config/session.php`, set `'driver' => 'database'` and `'lifetime' => 120` as literals, not `env()`, because they are business values from FND-005 and FND-006. Remove `SESSION_DRIVER=array` from `phpunit.xml` so the tests exercise the real driver. `App\Support\Auth\SessionInvalidator::forUser(User $user)` runs `DB::table('sessions')->where('user_id', $user->id)->delete()` inside the caller's transaction. It is called by `DeactivateUser` and `ResetUserPassword`. The `EnsureUserIsActive` middleware (web group, after `RequireAuthentication`) calls `$user->refresh()`. If the user no longer exists or is inactive, it logs out, invalidates the session and redirects to login. No remember-me exists: the login form has no checkbox, `Auth::attempt(..., remember: false)`, and the `remember_token` column is dropped.
**Alternatives considered**: A `session_version` column compared on every request. It works with any driver but duplicates what deleting rows already does. `Auth::logoutOtherDevices()` is rejected because it needs the user's password and only works for the current user.
**Rationale**: Deleting rows invalidates sessions at once (E-09, E-15). The active check covers the in-memory guard in tests and any race in production. The DB handler already treats sessions with `last_activity` older than 120 minutes as empty (E-08).

### Decision 7: Login limiting in a dedicated `login_throttles` table keyed by normalized email

**Choice**: `App\Support\Auth\LoginThrottle` uses rows `(email PK, failed_attempts, locked_until)`, read with `lockForUpdate()` inside the login transaction. The counter has **no time window** (DEC-017): failures never expire by elapsed time.
- `lockedMinutesRemaining(email): ?int`: first normalizes an **expired** lock (`locked_until <= now()` → `failed_attempts = 0`, `locked_until = null`), which is the DEC-017 "counter returns to 0 when the 15-minute lock ends" reset. Then returns `null` when not locked, or the remaining minutes: `ceil(remaining seconds / 60)`, minimum 1. It is checked **before** credentials, so the 6th attempt is rejected even with the correct password (E-06).
- `recordFailure(email)`: increments the counter. When the counter reaches 5, it sets `locked_until = now() + 15 min` and returns `true` so the caller audits `auth.lockout`. The counter stays at 5 while locked and is reset by the normalization above when the lock ends.
- `clear(email)`: deletes the row after a successful login (FND-003 and DEC-017 reset).

Attempts made while locked neither count nor extend the lock. They are audited as `auth.login_failed` with `context.reason = 'locked'`, and they answer with the **specific lock message** (DEC-018), attached to the `email` field:

```php
// lang/es/auth.php
'locked' => '{1} Demasiados intentos fallidos. Intente de nuevo en :minutes minuto.|[2,*] Demasiados intentos fallidos. Intente de nuevo en :minutes minutos.',
// AttemptLogin: trans_choice('auth.locked', $minutes, ['minutes' => $minutes])
```

The lock is keyed only by the normalized email and is created for any email, existing or not, so the lock message never reveals whether an account exists (DEC-018). The attempt that **imposes** the lock (the 5th failure) is itself a wrong-credential attempt, so it still returns the generic FND-002 message; DEC-018 applies to attempts made while the lock is already in effect. Active, inactive and unknown emails produce byte-identical lock messages for the same remaining time.
**Alternatives considered**: `RateLimiter::hit($key, 900)`. This is rejected because its window starts at the **first** failure, so a lock triggered at minute 14 would last only 1 minute, which breaks "15 minutes". A cache-based custom counter is rejected because `cache:clear` or a store change would silently remove locks, and cache writes are not part of the audit transaction.
**Rationale**: The DB table gives an exact 15-minute lock from the moment it is imposed, atomicity with the audit row, determinism with `travel()` in tests, and no dependency on `CACHE_STORE`. Rows are transient security state, not history (the audit trail is the history), so deleting or resetting them is allowed. Resetting lazily on the next read (instead of a scheduled job) needs no scheduler and is exact for every observable behavior, because a locked row is only ever consulted by a login attempt. FND-003, E-06, DEC-017, DEC-018.

### Decision 8: Forced password change via middleware with a route-name allowlist

**Choice**: `EnsurePasswordIsChanged` (web group, after `EnsureUserIsActive`). If `must_change_password` is true and the route name is not in `['password.edit', 'password.update', 'logout']`, the request is rejected before any controller or authorization runs: redirect (302) to `password.edit` with a flash message. Forced change and own change (FND-015) use **the same operation** (`PUT /password`, current password required), and a success sets `must_change_password = false`. After login, a user with the flag set is redirected to `password.edit`.
**Alternatives considered**: Checking inside every controller or policy, which is easy to forget and duplicates code.
**Rationale**: FND-014, E-14. Since the operation is never executed, the rejection has no side effects. It is not a permission denial, so it is not audited as one.

### Decision 9: Audit logger as an Action with deny-list redaction, same transaction as the change

**Choice**: `App\Actions\Audit\RecordAuditEvent::handle(...)` inserts one `audit_logs` row. It never opens its own transaction. The caller's `DB::transaction` makes the change and its audit row atomic. When called outside a transaction (failed login, denial), it is a single insert. Before insert, `App\Support\Audit\AuditRedactor` walks `old_values`, `new_values` and `context` recursively and **drops** every key that contains `password` or equals `remember_token` (case-insensitive). Actions also pass only explicit field diffs, never whole models. Password events (`users.password_reset`, `auth.password_changed`) carry no values at all. Form fields that carry passwords are always named `password`, `password_confirmation` or `current_password`. These names are already in Laravel's `dontFlash` defaults and excluded from `TrimStrings`, so they are never flashed to the session.

**Actor and origin rules**: an authenticated actor → `actor_id` is set. A failed login or lockout → `actor_id = null`, `actor_email = attempted normalized email`. The origin is an `App\Support\Audit\AuditOrigin` value object:
- `AuditOrigin::fromRequest(Request)` (the default when the caller passes none): `ip_address = $request->ip()`, no extra context.
- `AuditOrigin::console(string $command)`: `ip_address = null` and `context.source = 'console'`, `context.command = <command name>`, `context.os_user = <effective OS user of the process, best effort>`, `context.host = gethostname()`. `actor_id` and `actor_email` stay `null`, because no authenticated user exists (DEC-021).

The console origin must be explicit. Laravel's console request (`SetRequestForConsole`) reports `127.0.0.1` as the client IP, and `runningInConsole()` is also true during HTTP feature tests, so neither can tell a console event apart from a web one.
**Alternatives considered**: `owen-it/laravel-auditing` (a dependency, observer-based, poor fit for failed logins and denials). Eloquent observers, rejected because they cannot see failed logins or authorization denials and would audit changes made outside Actions.
**Rationale**: FND-022, FND-023, FND-012, E-27, E-28. One way to record every event.

### Decision 10: Authorization-denial auditing in a single exception render hook

**Choice**: In `bootstrap/app.php` → `withExceptions`, register `$exceptions->render(function (AccessDeniedHttpException $e, Request $request) { ... })`. It records `authorization.denied` (actor, route name, HTTP method, route parameter ids in `context`, IP) and returns an Inertia `errors/Forbidden` page with status 403 (or JSON 403). Laravel's `prepareException` converts `AuthorizationException` (thrown by FormRequest `failedAuthorization()` and `Gate::authorize`) into `AccessDeniedHttpException` before render callbacks run. **The apply phase must confirm this ordering in Laravel 13's `Illuminate\Foundation\Exceptions\Handler::render` with the E-19 RED test.** If the order differs, register the callback for `AuthorizationException` instead.
**Alternatives considered**: `Gate::after`, rejected because it also fires for UI-only `can()` checks that are not attempted operations. Auditing inside every controller duplicates code and is easy to forget.
**Rationale**: FND-019, FND-022, E-19. Authorization always happens before an Action opens a transaction, so the denial audit row is never rolled back.

### Decision 11: Audit immutability at two levels — model guard plus MySQL triggers

**Choice**:
- **Model**: `AuditLog` has `const UPDATED_AT = null` and `booted()` registers `updating` and `deleting` listeners that throw `App\Exceptions\AuditLogIsImmutable`. No route, controller, Policy ability or UI updates or deletes audit rows (`AuditLogPolicy` has only `viewAny`).
- **Database**: migration `create_audit_logs_table` runs, via `DB::unprepared`, two single-statement triggers (no `DELIMITER` is needed through PDO):

```sql
CREATE TRIGGER audit_logs_block_update BEFORE UPDATE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
CREATE TRIGGER audit_logs_block_delete BEFORE DELETE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
```

  `down()` refuses to run when `app()->isProduction()`. Otherwise it drops both triggers, then the table.
- **Known limits**: triggers do not block `TRUNCATE` or `DROP` (DDL). The application never issues them. `DB::prohibitDestructiveCommands(isProduction)` already blocks `migrate:fresh`, `db:wipe` and `migrate:reset` in production, and the audit migration's `down()` refuses in production.

**Feasibility under Sail MySQL 8.4 (verified from files; not executed, because no containers are running in this phase)**:

| Fact | Evidence |
|---|---|
| Image `mysql:8.4` with no `command:` override | `compose.yaml` lines 27-38 |
| The app account is `MYSQL_USER=${DB_USERNAME}`. The official image grants it `ALL` on `${DB_DATABASE}.*`, and Sail's init script grants `ALL` on `` `testing%`.* `` | `compose.yaml`; `vendor/laravel/sail/database/mysql/create-testing-database.sh` |
| Database-level `ALL` includes the `TRIGGER` privilege but no global `SUPER` / `SET_ANY_DEFINER` | MySQL privilege model |
| MySQL 8.x enables binary logging by default, and `log_bin_trust_function_creators` defaults to `OFF`. With binlog on, `CREATE TRIGGER` by an account without `SUPER` fails with **ERROR 1419** | MySQL reference manual, "Stored Program Binary Logging" |
| `MYSQL_EXTRA_OPTIONS: '${MYSQL_EXTRA_OPTIONS:-}'` is exposed on the `mysql` service, but it is **not** a server option. The official `mysql` image entrypoint does not read it; Sail uses it only as extra **client** flags in `sail mysql` (`MYSQL_PWD=... mysql -u ${MYSQL_USER} ${MYSQL_DATABASE} ${MYSQL_EXTRA_OPTIONS}`) | `compose.yaml` line 38; `vendor/laravel/sail/bin/sail` line 583; `vendor/laravel/sail/stubs/mysql.stub` |

**Assumption**: with the current `compose.yaml`, the migration **will fail with ERROR 1419** unless `.env` uses an account with `SUPER`. `.env.example` could not be read (permission denied), so `DB_USERNAME` is unknown.

**Chosen local fix**: add `command: --skip-log-bin` to the `mysql` service in `compose.yaml`. A local dev database does not need binary logging, and without it `CREATE TRIGGER` needs only the `TRIGGER` privilege. The official image entrypoint turns arguments that start with `-` into `mysqld` flags. Apply it with `sail down && sail up -d`; the data volume survives. If `--skip-log-bin` is undesirable, `command: --log-bin-trust-function-creators=1` is an equivalent alternative.

**Rejected: passing `--skip-log-bin` through `MYSQL_EXTRA_OPTIONS` in `.env` / `.env.example`.** It looks simpler because it avoids editing `compose.yaml`, but it does not work: the variable never reaches `mysqld` (see the evidence table), so binary logging stays on and the trigger migration still fails with ERROR 1419. It would also break `sail mysql`, because the `mysql` client rejects the unknown option `--skip-log-bin`. The one-line `command:` in the versioned `compose.yaml` is the simpler option that works, and it applies to every developer without per-machine `.env` edits.

**Apply-phase verification (first task of the audit slice)**: run `sail mysql -e "SELECT @@log_bin, @@log_bin_trust_function_creators, CURRENT_USER()"`, then run the E-29 DB-level RED test. The migration must **fail loudly**, never skip the triggers silently.

**Production requirement** (to document in the deploy notes): the migration account needs `TRIGGER`, and one of these must hold: binlog disabled, `log_bin_trust_function_creators=1`, or `SUPER` / `SET_ANY_DEFINER`. Fallbacks if the host forbids all of them:
- F1: run only this migration with a privileged account.
- F2: replace the app account's database-wide `ALL` with per-table grants that give `audit_logs` only `SELECT, INSERT`.
- F3: model-level protection only. F3 **does not meet** the confirmed "DB level" decision and would need an explicit user decision.

**Alternatives considered**: Privilege-based immutability (F2) as the primary mechanism. It needs per-table grants for every table and ties the design to hosting setup. It is kept as a fallback.
**Rationale**: FND-024, E-29, DEC-011, and the user decision "immutable at model level AND DB level".

### Decision 12: Last-administrator protection = serialize, apply, re-check, roll back

**Choice**: `App\Actions\Authorization\EnsureAdministrationIsPreserved` has two methods:
- `lock()`: the first statement inside the transaction of every Action that can reduce administration (deactivate user, sync user roles, sync role permissions, delete role). It runs `SELECT ... FOR UPDATE` on the two `permissions` rows `users.assign_roles` and `roles.manage`, so concurrent operations that reduce administration run one at a time.
- `assert()`: called after the change is applied, still inside the transaction. It counts active users who hold **both** permissions through their roles. If the count is 0, it throws `BusinessRuleViolation` and the transaction rolls back.

**Alternatives considered**: Predicting the outcome before applying the change. That needs separate logic for each path and is easy to get wrong. Checking without the lock lets two administrators deactivate each other concurrently under REPEATABLE READ, and both commits would leave zero administrators.
**Rationale**: FND-020, BR-FND-006, E-25. One check covers the three paths the spec lists. Self-action checks (FND-021, E-26) are simple guard clauses at the top of `DeactivateUser` and `SyncUserRoles` (`$actor->is($target)`). They are the **only** self-action restrictions (DEC-020, Decision 18).

### Decision 13: `users` schema changes in a new migration; drop unused starter columns

**Choice**: A new migration adds `first_name`, `last_name`, `is_active` (default true, indexed) and `must_change_password` (default false). It fills `first_name` from `name` (and `last_name = ''`); only fictitious local data exists. Then it drops `name`, `email_verified_at` and `remember_token`. `User::$rememberTokenName = ''` makes `SessionGuard::logout()` skip cycling the token. The existing `users_email_unique` index stays. The email is normalized by a model mutator (`Str::lower(trim($value))`) **and** in `FormRequest::prepareForValidation()` before the `unique` rule runs. `password_reset_tokens` is left untouched: it is harmless and is not dropped, to avoid unrelated churn.
**Alternatives considered**: Editing `0001_01_01_000000` (forbidden by AGENTS.md §8). Keeping `name` as a computed field (two sources of truth).
**Rationale**: FND-007 and FND-008. Dropping `remember_token` makes the "no remember-me" rule structural.

### Decision 14: Password policy

**Choice**: `AppServiceProvider::configureDefaults()` sets `Password::defaults(fn () => Password::min(10))` in every environment. Every password-setting request uses `['required', 'string', Password::defaults()]`. Own change adds `current_password` (built-in rule) + `NotCurrentPassword` + `confirmed`. `confirmed` is a technical input-integrity choice to avoid locking yourself out through a typo; it adds no business rule. Administrator-set temporary passwords do not use `confirmed`.
**Alternatives considered**: Keeping the production-only 12-character complexity rules, which contradict FND-013 (a level 3 spec overrides level 5 code).
**Rationale**: FND-013, E-16, E-17.

### Decision 15: Frontend shell and design system

**Choice**:
- `resources/css/app.css` is replaced by the design-system §2 block (it removes the starter kit's `Instrument Sans` `@theme inline`), including `@custom-variant dark (&:where(.dark, .dark *));`, the `.dark { … }` override of every color token and `color-scheme` per theme (UI-06, optional dark mode, palette status `Propuesta`; `dark:` prefix remains forbidden in components).
- `resources/js/composables/useAppearance.ts` reads/writes `localStorage` key `appearance` and toggles `.dark` on `<html>`; a theme toggle with a Spanish `aria-label` lives in `AppLayout.vue` (PR 2b). Public portal excluded.
- `app.blade.php`:
  - `lang="es"`;
  - `viewport-fit=cover`;
  - remove the starter's `dark` class binding; the theme class is applied only by a tiny inline `<script>` in `<head>` before `@vite` that reads `localStorage.getItem('appearance')` in try/catch and adds `dark` to `<html>` when the value is `'dark'` (light default, no `prefers-color-scheme`, no flash) — UI-06;
  - replace `@fonts` with the interim Google Fonts `<link>` tags (Outfit, Plus Jakarta Sans, Material Symbols Outlined), following UI-03.
- `config/app.php` default locale is `es`. The new `lang/es/validation.php` and `lang/es/auth.php` cover only the rules and messages used here.
- Layouts:
  - `AuthLayout.vue`: login and forced change; a centred `AppCard`.
  - `AppLayout.vue`: a sidebar at `lg` and `BottomNav` below `lg`, with at most 5 destinations: Inicio, Usuarios, Roles, Auditoría, Cambiar contraseña. Each entry is shown only if the corresponding permission is in `auth.permissions`, and Inicio and Cambiar contraseña are always shown.
- Components (built once in `resources/js/components/`): `AppButton`, `IconButton`, `AppIcon`, `AppInput`, `AppSelect`, `AppCheckbox`, `AppCard`, `StatusBadge`, `DataTable`, `BottomNav`, `SideNav`, `AppPagination`, `FlashMessage`.
  - `AppCheckbox` is not in design-system §7. It follows §1.2 (44 px target including its label) and the §7.4 tokens.
  - UI-01 (success/warning tokens) and UI-02 (`rounded-lg` in the ERP) apply their "Propuesta" recommendation.
  - `StatusBadge` mapping: active → `active`, inactive → `neutral`, always with text.
- Routes on the frontend use the already-installed Wayfinder helpers. Shared props expose an explicit DTO, never the whole `User` model.
**Alternatives considered**: Keeping the starter kit's styles (two parallel colour systems, which design-system §2 forbids).
**Rationale**: design-system §1 to §8 and constitution §14. No new dependency.

### Decision 16: Operating timezone as a pinned config value; UTC storage; half-open UTC ranges for day filters (DEC-019)

**Choice**:
- `config/app.php` keeps `'timezone' => 'UTC'` (persistence, `now()`, Eloquent timestamps and the throttle all stay UTC). A new key `'operating_timezone' => 'America/Caracas'` is a **literal**, not `env()`, because it is a confirmed business value (DEC-019), in the same way the session lifetime is pinned (Decision 6).
- `App\Support\Time\OperatingTime` is the single place that converts between UTC and the operating timezone:
  - `format(CarbonInterface $utc): string` → the instant in `America/Caracas`, formatted `d/m/Y H:i:s`.
  - `dayStartUtc(string $date): CarbonImmutable` → `CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Caracas')->utc()` (local 00:00 of that day).
  - `nextDayStartUtc(string $date): CarbonImmutable` → the local 00:00 of the following day, in UTC.
- **Audit display (FND-025)**: `AuditLogController` maps each row to a DTO whose `occurred_at` is already `OperatingTime::format(...)`. The frontend prints that string and does no timezone conversion, so the result does not depend on the browser's timezone (backend authority, AGENTS.md §7.1).
- **Audit date filter (FND-025)**: `AuditLogIndexRequest` accepts `from` and `to` as calendar dates in the operating timezone (`nullable|date_format:Y-m-d`, `to` `after_or_equal:from`). The query uses a **half-open** UTC interval: `created_at >= dayStartUtc(from)` and `created_at < nextDayStartUtc(to)`. Example: `from = to = 2026-03-09` covers `[2026-03-09 04:00:00 UTC, 2026-03-10 04:00:00 UTC)`.

**Alternatives considered**: (a) Setting `config/app.php` `timezone` to `America/Caracas`. This is rejected because it would store local times, which AGENTS.md §8 forbids. (b) An `APP_DISPLAY_TIMEZONE` env variable. This is rejected because the value is a business decision, not an environment setting, and a missing env value would silently fall back to another zone. (c) Converting in the browser with `Intl`. This is rejected because it depends on the client's clock settings and moves a business rule to the frontend. (d) A closed interval ending at `23:59:59`. This is rejected because `created_at` has microsecond precision (`timestamp(6)`), so events in the last second would be lost.
**Rationale**: DEC-019, FND-025, AGENTS.md §8 (store UTC, display in the operating timezone). `America/Caracas` has had a fixed UTC−4 offset since 2016, but the conversion goes through the IANA zone, so a future offset change needs only a `tzdata` update and no code change.

### Decision 17: First administrator via an interactive console command that reuses `CreateUser` (DEC-021)

**Choice**: `App\Console\Commands\CreateFirstAdministrator` (signature `users:create-administrator`, auto-discovered from `app/Console/Commands`). It stays thin, like a controller:
1. Refuses to run when the input is not interactive (`--no-interaction` or no TTY): exit code 1, nothing written. DEC-021 requires an interactive command.
2. Prompts with Laravel Prompts `text()` (already bundled with the framework, no new dependency) for first name, last name and email.
3. Validates the three values with `App\Support\Users\UserRules::identity()`, the same rules `StoreUserRequest` uses (length limits, email format, unique after normalization). On failure it prints the Spanish validation messages and exits with code 1.
4. Calls `App\Actions\Users\CreateFirstAdministrator::handle(firstName, lastName, email, AuditOrigin::console('users:create-administrator'))`.
5. Prints the generated temporary password **once** on the console, with a warning that it must be changed at first login. The password is never logged, audited, stored in plain text or written to any file.

`CreateFirstAdministrator` Action:
- Opens `DB::transaction`. Its first statement locks the protected role row (`roles.is_protected = true`) with `lockForUpdate()`, so two concurrent runs cannot both pass the precondition. If no protected role exists, it throws `BusinessRuleViolation` ("run the foundation seeder first").
- **Precondition** (DEC-021): no user with `is_active = true` holds the protected role. The Administrador role is identified by `is_protected`, never by its name (Decision 2, AGENTS.md §7.3). If such a user exists, it throws `BusinessRuleViolation` and nothing is written. Inactive administrators do not block the command.
- Generates the temporary password with `Str::random(20)` (satisfies the FND-013 minimum of 10; tests can fix its value with `Str::createRandomStringsUsing()`).
- Calls the existing `CreateUser` Action with the protected role as its only role, `is_active = true`, the generated password and the console origin. `CreateUser` already sets `must_change_password = true` for any administrator-created password (FND-014), hashes it, and records `users.created` in the same transaction. Laravel runs the nested `DB::transaction` as a savepoint, so the whole operation stays atomic.
- Returns the created `User` and the plain temporary password to the command.

Audit: a single `users.created` row with `actor_id = null`, `actor_email = null`, `ip_address = null`, `context = {source: 'console', command: 'users:create-administrator', os_user, host}` and the usual `new_values` (names, email, `is_active`, role id and name). No new `AuditAction` case is needed: the event is a user creation, and `context.source` marks it as a console event (DEC-021, FND-022). No credentials exist in `.env` or in code.

`CreateUser` signature change: `handle(array $data, ?User $actor, ?AuditOrigin $origin = null)`. The HTTP path passes the authenticated actor and no origin (defaults to the request). Only `CreateFirstAdministrator` passes `null` as the actor.

**The fictitious `LocalAdministratorSeeder` is dropped.** It would keep an administrator password in code, which DEC-021 rules out. Local environments create their administrator with the same command after `migrate:fresh --seed`, which also exercises the production bootstrap path in development.
**Alternatives considered**: (a) A seeder reading `.env` credentials, rejected by DEC-021. (b) Manual SQL, which is not auditable. (c) A separate creation path inside the command, which would duplicate `CreateUser`'s rules, hashing, `must_change_password` and audit. (d) A new `auth.first_administrator_created` audit action, which is rejected because the event is a user creation and `context.source` already distinguishes it. (e) `Str::password()`, which cannot be fixed in tests.
**Rationale**: DEC-021, FND-014, FND-020 (BR-FND-006 can hold in production from the first deploy), FND-022. One creation path for every user.

### Decision 18: Self-actions through user management are allowed except the two FND-021 prohibitions (DEC-020)

**Choice**: `UserPolicy::update` and `UserPolicy::resetPassword` check only the permission (`users.update`, `users.reset_password`). They never compare the actor with the target. `UpdateUser` and `ResetUserPassword` have no self-action guard. The only self guards remain `DeactivateUser` and `SyncUserRoles` (FND-021, E-26).

A self password reset follows FND-014 exactly as for any other user: the password becomes temporary (`must_change_password = true`) and `SessionInvalidator` deletes all the actor's sessions, including the current one. `UserPasswordController` detects `$actor->is($user)` after the Action returns, calls `Auth::logout()`, invalidates the session and redirects to `login` with a flash message telling the user to sign in with the temporary password. The next login lands on `password.edit` (Decision 8). Without this explicit logout, the current request would end with the old session cookie pointing to a deleted row, which already behaves as a guest, but the redirect makes the outcome explicit and testable.

Editing one's own email changes the login identifier. The session is keyed by user id, so it stays valid.
**Alternatives considered**: Forbidding self-edit and self-reset in user management, rejected by DEC-020.
**Rationale**: DEC-020, FND-010, FND-021, DEC-010.

### Decision 19: Frontend shell follow-ups from the PR 2b review (user decisions)

**Choice**:
1. **`GET /` (`home`)** stays on the interim `Route::inertia('/', 'Welcome')` (page deleted in PR 2b) until PR 3c (`HomeController` + `Home.vue`). `home` is NOT a public route (FND-026): it stays protected by default and redirects a guest to `login` (E-30 already enforces this). No merge to `main` before PR 3c.
2. **Navigation URIs**: `AppLayout.vue` keeps hardcoded URIs in `DESTINATIONS` only until each route exists. In the PR that registers each route, `DESTINATIONS` switches to the Wayfinder-generated helpers (explicit tasks 3.27, 4.18, 6.12, 7.8).
3. **Label**: the fifth destination is **"Cambiar contraseña"** (target `password.edit`), not "Cuenta": the user can only change their own password (DEC-010, FND-015). No account/profile page exists.
4. **Flash**: use Inertia v3's native flash (`Inertia::flash([...])` on the backend, `usePage().flash` on the frontend) with shape `{ type: 'success' | 'error', message: string }`, typed through `InertiaConfig.flashDataType` in `resources/js/types/global.d.ts`. There is no custom shared `flash` prop. `FlashMessage.vue` reads `page.flash` itself and is mounted in `AppLayout` and `AuthLayout`.
**Alternatives considered**: a shared `flash` prop in `HandleInertiaRequests` (rejected: duplicates the native mechanism); a public `/` (rejected by FND-026).
**Rationale**: FND-026, DEC-010, FND-015, design-system §7.

## Data Flow

Request pipeline (web group, in order after Laravel's session and CSRF middleware):

```
HTTP ─→ StartSession/CSRF ─→ RequireAuthentication ──(guest & not public)──→ 302 /login
                                   │ public route → guest middleware → LoginController
                                   ↓ authenticated
                             EnsureUserIsActive ──(inactive/missing)──→ logout + 302 /login
                                   ↓
                             EnsurePasswordIsChanged ──(pending & not allowlisted)──→ 302 /password
                                   ↓
                             HandleInertiaRequests (lazy shared props: user DTO, permissions[])
                                   ↓
                             Controller → FormRequest(validate + authorize via Policy)
                                   │ denied → AccessDeniedHttpException → render hook
                                   │            → RecordAuditEvent(authorization.denied) → 403
                                   ↓ allowed
                             Action ─→ DB::transaction {
                                         EnsureAdministrationIsPreserved::lock()   (when applicable)
                                         guard clauses (self-action, protected role, ≥1 role…)
                                         mutate models / pivots
                                         SessionInvalidator::forUser()             (deactivate/reset)
                                         EnsureAdministrationIsPreserved::assert() (when applicable)
                                         RecordAuditEvent(...)
                                       }
                                   ↓
                             redirect / Inertia::render
```

Login flow (`POST /login`, public):

```
LoginRequest (normalize email) → AttemptLogin::handle(email, password, ip)
  DB::transaction {
    minutes = LoginThrottle.lockedMinutesRemaining(email)   ← row FOR UPDATE; expired lock → counter 0 (DEC-017)
    if minutes:  audit auth.login_failed{reason:locked}
                 result = Locked(minutes)                    ← lock message with minutes (DEC-018)
    else:
      ok = Auth::attempt([email, password, is_active => true], remember: false)
      if !ok:    audit auth.login_failed(actor_email=email)
                 if LoginThrottle.recordFailure(email) → audit auth.lockout
                 result = Failed                             ← same generic text for E-02/E-03/E-04 and the 5th failure
      else:      LoginThrottle.clear(email); session()->regenerate(); audit auth.login_succeeded
                 result = Succeeded
  }
  Locked → throw ValidationException(email: trans_choice('auth.locked', minutes))
  Failed → throw ValidationException(email: __('auth.failed'))
  → redirect password.edit if must_change_password else intended(home)
```

The failure audit rows must be committed even though the request ends in a validation error. `AttemptLogin` therefore commits the transaction and throws the `ValidationException` **after** `DB::transaction` returns. It returns a result value object (`Succeeded`, `Failed`, or `Locked` with the remaining minutes); it does not throw from inside the closure.

Audit query (`GET /audit`, DEC-019):

```
AuditLogIndexRequest (user_id?, action?, from?/to? as Y-m-d in America/Caracas)
  → AuditLogController: where created_at >= OperatingTime::dayStartUtc(from)
                        and created_at <  OperatingTime::nextDayStartUtc(to)
  → rows mapped to DTOs with occurred_at = OperatingTime::format(created_at)   ← frontend prints as-is
```

First-administrator bootstrap (console, DEC-021):

```
sail artisan users:create-administrator
  CreateFirstAdministrator command (interactive only)
    → prompts first name, last name, email → UserRules::identity() validation
    → CreateFirstAdministrator::handle(..., AuditOrigin::console(...))
        DB::transaction {
          lock protected role row FOR UPDATE (missing → BusinessRuleViolation)
          active user with protected role exists? → BusinessRuleViolation (nothing written)
          password = Str::random(20)
          CreateUser::handle([... roles: [protected role], password], actor: null, origin: console)
            → user (is_active, must_change_password = true) + users.created audit {source: console}
        }
    → print temporary password once; exit 0
```

## File Changes

Generated Wayfinder files (`resources/js/actions/**`, `resources/js/routes/**`) are regenerated by the build and are excluded from the authored-line count.

| File | Action | Description |
|---|---|---|
| `compose.yaml` | Modify | `mysql` service: `command: --skip-log-bin` (trigger feasibility, Decision 11; not via `MYSQL_EXTRA_OPTIONS`, which is a client-only variable) |
| `phpunit.xml` | Modify | Remove `SESSION_DRIVER=array` so tests use the pinned database driver |
| `bootstrap/app.php` | Modify | Remove `health: '/up'`; append `RequireAuthentication`, `EnsureUserIsActive`, `EnsurePasswordIsChanged` to `web` before `HandleInertiaRequests`; `redirectGuestsTo(login)` and `redirectUsersTo(home)`; render hooks for `AccessDeniedHttpException` (audit + 403 page) and `BusinessRuleViolation` |
| `config/session.php` | Modify | Pin `driver => 'database'` and `lifetime => 120` |
| `config/filesystems.php` | Modify | `local.serve => false` (removes public `/storage/{path}` routes) |
| `config/inertia.php` | Modify | `devtools.enabled => env('INERTIA_DEVTOOLS_ENABLED', false)` |
| `config/app.php` | Modify | Default locale `es`; `timezone` stays `UTC`; add literal `'operating_timezone' => 'America/Caracas'` (DEC-019, Decision 16) |
| `routes/web.php` | Modify | Public login routes (`guest`), all protected routes (listed in Interfaces) |
| `app/Providers/AppServiceProvider.php` | Modify | `Password::defaults(fn () => Password::min(10))` in all environments |
| `app/Http/Middleware/HandleInertiaRequests.php` | Modify | Share user DTO (`id, first_name, last_name, email, must_change_password`), lazy `permissions`; flash uses Inertia v3's native flash, no custom shared prop (Decision 19) |
| `app/Http/Middleware/RequireAuthentication.php` | Create | Protected by default (Decision 5) |
| `app/Http/Middleware/EnsureUserIsActive.php` | Create | Per-request active check (Decision 6) |
| `app/Http/Middleware/EnsurePasswordIsChanged.php` | Create | Forced password change (Decision 8) |
| `app/Support/Http/PublicRoutes.php` | Create | Declared public route names (FND-026) |
| `app/Support/Auth/LoginThrottle.php` | Create | Email-keyed lockout (Decision 7) |
| `app/Support/Auth/SessionInvalidator.php` | Create | Delete all sessions of a user |
| `app/Support/Audit/AuditRedactor.php` | Create | Recursive removal of password-like keys |
| `app/Support/Audit/AuditOrigin.php` | Create | Web (request IP) or console (source, command, OS user, host) origin of an audit event (Decision 9, DEC-021) |
| `app/Support/Time/OperatingTime.php` | Create | UTC ↔ `America/Caracas` formatting and day-boundary conversion (Decision 16, DEC-019) |
| `app/Support/Users/UserRules.php` | Create | Shared identity validation rules (names, normalized unique email) for `StoreUserRequest`, `UpdateUserRequest` and the console command |
| `app/Console/Commands/CreateFirstAdministrator.php` | Create | `users:create-administrator` interactive command (Decision 17, DEC-021) |
| `app/Actions/Users/CreateFirstAdministrator.php` | Create | Precondition, locking, temporary password, delegation to `CreateUser` (Decision 17) |
| `app/Enums/PermissionName.php` | Create | Catalog of spec §9 (+ `description()`) |
| `app/Enums/AuditAction.php` | Create | Audited event names (+ Spanish `label()`) |
| `app/Models/User.php` | Modify | New fillable/casts/hidden, email mutator, `roles()`, `hasPermission()`, `permissionNames()`, `$rememberTokenName = ''` |
| `app/Models/Role.php` | Create | `users()`, `permissions()`, `is_protected` cast |
| `app/Models/Permission.php` | Create | `roles()` |
| `app/Models/AuditLog.php` | Create | Append-only model, `actor()` relation, JSON casts |
| `app/Exceptions/BusinessRuleViolation.php` | Create | Domain rejection rendered as a form error |
| `app/Exceptions/AuditLogIsImmutable.php` | Create | Thrown by the model guard |
| `app/Rules/NotCurrentPassword.php` | Create | FND-013 no-reuse rule |
| `app/Policies/UserPolicy.php`, `RolePolicy.php`, `AuditLogPolicy.php` | Create | Permission-only abilities |
| `app/Actions/Auth/AttemptLogin.php`, `Logout.php`, `ChangeOwnPassword.php` | Create | FND-001..005, FND-014/015 |
| `app/Actions/Users/CreateUser.php`, `UpdateUser.php`, `ActivateUser.php`, `DeactivateUser.php`, `ResetUserPassword.php`, `SyncUserRoles.php` | Create | FND-010, FND-011, FND-014, FND-018, FND-020, FND-021; `CreateUser` accepts a nullable actor and an optional `AuditOrigin` (Decision 17); no self guard in `UpdateUser` / `ResetUserPassword` (DEC-020) |
| `app/Actions/Roles/CreateRole.php`, `UpdateRole.php`, `DeleteRole.php`, `SyncRolePermissions.php` | Create | FND-016, FND-020 |
| `app/Actions/Authorization/EnsureAdministrationIsPreserved.php` | Create | FND-020 (Decision 12) |
| `app/Actions/Audit/RecordAuditEvent.php` | Create | Audit logger (Decision 9) |
| `app/Http/Controllers/Auth/LoginController.php`, `LogoutController.php`, `PasswordController.php` | Create | Auth and own-password endpoints |
| `app/Http/Controllers/HomeController.php` | Create | Minimal "Inicio" (user decision) |
| `app/Http/Controllers/Users/UserController.php`, `UserStatusController.php`, `UserPasswordController.php`, `UserRoleController.php` | Create | Users administration |
| `app/Http/Controllers/Roles/RoleController.php`, `RolePermissionController.php` | Create | Roles administration |
| `app/Http/Controllers/Audit/AuditLogController.php` | Create | FND-025 query (index only) |
| `app/Http/Requests/Auth/*`, `Users/*`, `Roles/*`, `Audit/AuditLogIndexRequest.php` | Create | Validation + `authorize()` for each operation |
| `database/migrations/2026_09_28_000001_update_users_table_for_foundation.php` | Create | Decision 13 |
| `database/migrations/2026_09_28_000002_create_roles_and_permissions_tables.php` | Create | roles, permissions, permission_role, role_user |
| `database/migrations/2026_09_28_000003_create_audit_logs_table.php` | Create | Table + triggers (Decision 11) |
| `database/migrations/2026_09_28_000004_create_login_throttles_table.php` | Create | Decision 7 |
| `database/seeders/PermissionCatalogSeeder.php`, `RoleSeeder.php`, `FoundationSeeder.php` | Create | Catalog, 7 roles, test/production baseline. No administrator seeder: users are created by `users:create-administrator` (DEC-021) |
| `database/seeders/DatabaseSeeder.php` | Modify | Call `FoundationSeeder` only; remove the starter kit's test user |
| `database/factories/UserFactory.php` | Modify | first/last name, `is_active`, `must_change_password`; states `inactive()`, `mustChangePassword()`, `withPermissions(...)` |
| `database/factories/RoleFactory.php` | Create | Test roles |
| `lang/es/validation.php`, `lang/es/auth.php` | Create | Spanish messages for the rules used; `auth.failed` (generic, FND-002) and `auth.locked` (pluralized, `:minutes`, DEC-018) |
| `resources/css/app.css` | Modify | Design-system §2 tokens, `@custom-variant dark` and `.dark` color overrides (UI-06) |
| `resources/views/app.blade.php` | Modify | `lang="es"`, viewport, fonts link, no-flash theme script (UI-06) |
| `resources/js/app.ts` | Modify | Progress colour from a token-compatible value (no Tailwind default palette) |
| `resources/js/types/auth.ts` | Modify | `AuthUser` DTO + `permissions: string[]` |
| `resources/js/layouts/AppLayout.vue`, `AuthLayout.vue` | Create | Shells (design-system §6.2) |
| `resources/js/components/*.vue` (list in Decision 15) | Create | Design-system §7 components |
| `resources/js/pages/auth/Login.vue`, `auth/ChangePassword.vue`, `Home.vue`, `errors/Forbidden.vue` | Create | Auth, landing, 403 |
| `resources/js/pages/users/Index.vue`, `Create.vue`, `Show.vue`, `Edit.vue` | Create | Users UI (Show hosts roles, reset and activation forms) |
| `resources/js/pages/roles/Index.vue`, `Create.vue`, `Show.vue`, `Edit.vue` | Create | Roles UI (Show hosts the permissions form) |
| `resources/js/pages/audit/Index.vue` | Create | Audit query UI |
| `resources/js/pages/Welcome.vue` | Delete | Replaced by `Home.vue` |
| `tests/Pest.php` | Modify | `RefreshDatabase` for Feature; helpers `userWithPermissions()`, `administrator()`, `loginWithRealSession()`, `requestWithSession()` |
| `tests/TestCase.php` | Modify | `$seed = true`, `$seeder = FoundationSeeder::class` |
| `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | Delete | Starter placeholders (the Feature one asserts a public `/`, which is now false) |
| `tests/Feature/**`, `tests/Unit/**` | Create | See Testing Strategy |

## Interfaces / Contracts

### Schema

| Table | Columns | Keys / constraints |
|---|---|---|
| `users` (altered) | `id`, `first_name` varchar(100), `last_name` varchar(100), `email` varchar(255), `password`, `is_active` bool default 1, `must_change_password` bool default 0, `created_at`, `updated_at` | `users_email_unique` (existing), index `is_active` |
| `roles` | `id`, `name` varchar(100), `description` varchar(500) null, `is_protected` bool default 0, timestamps | unique `name` |
| `permissions` | `id`, `name` varchar(100), `description` varchar(255), timestamps | unique `name` |
| `permission_role` | `role_id`, `permission_id` | PK(`role_id`,`permission_id`); FK role → **cascade** on delete (role deletion removes its links; captured in the `roles.deleted` audit); FK permission → **restrict**; index `permission_id` |
| `role_user` | `user_id`, `role_id` | PK(`user_id`,`role_id`); FK user → **restrict**; FK role → **restrict** (the DB backs up E-22); index `role_id` |
| `audit_logs` | `id`, `created_at` timestamp(6) UTC, `actor_id` bigint null, `actor_email` varchar(255) null, `action` varchar(100), `entity_type` varchar(100) null, `entity_id` bigint unsigned null, `ip_address` varchar(45) null, `old_values` json null, `new_values` json null, `context` json null | FK `actor_id` → users **restrict** (FND-011, spec §12); indexes (`created_at`), (`actor_id`,`created_at`), (`action`,`created_at`), (`entity_type`,`entity_id`); triggers block UPDATE/DELETE |
| `login_throttles` | `email` varchar(255), `failed_attempts` smallint unsigned default 0, `locked_until` timestamp null, `updated_at` | PK `email` |

`ip_address` is nullable only for console-originated events (`users:create-administrator`, DEC-021); HTTP events always set it. Seeders write no audit rows. All timestamps are stored in UTC (`config/app.php` `timezone` stays `UTC`); they are displayed and filtered in `config('app.operating_timezone')` = `America/Caracas` (DEC-019).

### Enums

```php
enum PermissionName: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDeactivate = 'users.deactivate';
    case UsersResetPassword = 'users.reset_password';
    case UsersAssignRoles = 'users.assign_roles';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';

    public function description(): string; // Spanish text from spec §9
}

enum AuditAction: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case Lockout = 'auth.lockout';
    case Logout = 'auth.logout';
    case PasswordChanged = 'auth.password_changed';      // own change (FND-015)
    case UserCreated = 'users.created';
    case UserUpdated = 'users.updated';
    case UserActivated = 'users.activated';
    case UserDeactivated = 'users.deactivated';
    case UserPasswordReset = 'users.password_reset';
    case UserRolesAssigned = 'users.roles_assigned';
    case UserRolesRemoved = 'users.roles_removed';
    case RoleCreated = 'roles.created';
    case RoleUpdated = 'roles.updated';
    case RoleDeleted = 'roles.deleted';
    case RolePermissionsGranted = 'roles.permissions_granted';
    case RolePermissionsRevoked = 'roles.permissions_revoked';
    case AuthorizationDenied = 'authorization.denied';

    public function label(): string; // Spanish UI label
}
```

### Key PHP contracts

```php
// app/Models/User.php
public function roles(): BelongsToMany;
public function hasPermission(PermissionName|string $permission): bool; // EXISTS query, never cached
/** @return list<string> */
public function permissionNames(): array;

// app/Actions/Audit/RecordAuditEvent.php
public function handle(
    AuditAction $action,
    ?User $actor,
    ?Model $entity = null,
    array $oldValues = [],
    array $newValues = [],
    array $context = [],
    ?string $actorEmail = null,
    ?AuditOrigin $origin = null, // null → AuditOrigin::fromRequest(request())
): AuditLog; // caller owns the transaction; values pass through AuditRedactor; origin context merged into context

// app/Support/Audit/AuditOrigin.php
final readonly class AuditOrigin
{
    public static function fromRequest(Request $request): self; // ip = $request->ip()
    public static function console(string $command): self;     // ip = null; source, command, os_user, host
    public function ipAddress(): ?string;
    /** @return array<string, string> */
    public function context(): array;                           // [] for web
}

// app/Support/Time/OperatingTime.php  (zone = config('app.operating_timezone'))
public static function format(CarbonInterface $utc): string;          // 'd/m/Y H:i:s' in America/Caracas
public static function dayStartUtc(string $date): CarbonImmutable;     // Y-m-d local 00:00 → UTC (inclusive bound)
public static function nextDayStartUtc(string $date): CarbonImmutable; // next local 00:00 → UTC (exclusive bound)

// app/Actions/Users/CreateUser.php
public function handle(array $data, ?User $actor, ?AuditOrigin $origin = null): User;

// app/Actions/Users/CreateFirstAdministrator.php
/** @return array{user: User, temporaryPassword: string} */
public function handle(string $firstName, string $lastName, string $email, AuditOrigin $origin): array;

// app/Actions/Authorization/EnsureAdministrationIsPreserved.php
public function lock(): void;   // SELECT ... FOR UPDATE on the two admin permission rows
public function assert(): void; // throws BusinessRuleViolation when 0 active users hold both

// app/Support/Auth/LoginThrottle.php
public function lockedMinutesRemaining(string $normalizedEmail): ?int; // null = not locked; resets an expired lock (DEC-017)
public function recordFailure(string $normalizedEmail): bool;          // true when this failure imposed the lock
public function clear(string $normalizedEmail): void;

// app/Support/Http/PublicRoutes.php
final class PublicRoutes
{
    /** Route names declared public by a spec. Spec 001 / FND-026: login only. */
    public const NAMES = ['login', 'login.store'];
    public static function contains(?string $routeName): bool;
}
```

### Routes and authorization

| Method | URI | Name | Authorization |
|---|---|---|---|
| GET | `/login` | `login` | **public** (`guest`) |
| POST | `/login` | `login.store` | **public** (`guest`) |
| POST | `/logout` | `logout` | authenticated |
| GET | `/` | `home` | authenticated |
| GET | `/password` | `password.edit` | authenticated (FND-015) |
| PUT | `/password` | `password.update` | authenticated (FND-015) |
| GET | `/users` | `users.index` | `users.view` |
| GET | `/users/create` | `users.create` | `users.create` |
| POST | `/users` | `users.store` | `users.create` |
| GET | `/users/{user}` | `users.show` | `users.view` |
| GET | `/users/{user}/edit` | `users.edit` | `users.update` |
| PUT | `/users/{user}` | `users.update` | `users.update` |
| POST | `/users/{user}/activate` | `users.activate` | `users.deactivate` |
| POST | `/users/{user}/deactivate` | `users.deactivate` | `users.deactivate` |
| PUT | `/users/{user}/password` | `users.password.reset` | `users.reset_password` |
| PUT | `/users/{user}/roles` | `users.roles.update` | `users.assign_roles` |
| GET | `/roles` | `roles.index` | `roles.view` |
| GET | `/roles/create` | `roles.create` | `roles.manage` |
| POST | `/roles` | `roles.store` | `roles.manage` |
| GET | `/roles/{role}` | `roles.show` | `roles.view` |
| GET | `/roles/{role}/edit` | `roles.edit` | `roles.manage` |
| PUT | `/roles/{role}` | `roles.update` | `roles.manage` |
| DELETE | `/roles/{role}` | `roles.destroy` | `roles.manage` |
| PUT | `/roles/{role}/permissions` | `roles.permissions.update` | `roles.manage` |
| GET | `/audit` | `audit.index` | `audit.view` |

No route updates or deletes audit logs, and no route deletes users (E-29, FND-010).

Console (not an HTTP route, so outside E-30): `users:create-administrator`. It is not permission-gated, because it runs before any administrator exists; access is restricted by server shell access and by its own precondition (DEC-021).

### Business rules enforced in Actions (all → `BusinessRuleViolation`, no side effects)

| Rule | Action(s) | Source |
|---|---|---|
| At least 1 role on create; cannot remove the last role of an active user | `CreateUser`, `SyncUserRoles` | FND-010, FND-018, E-13 |
| Cannot activate a user with no roles (a consequence of "an active user has at least 1 role") | `ActivateUser` | FND-018 |
| Email unique (validation `unique:users,email` after normalization) + DB unique index | `CreateUser`, `UpdateUser` | FND-008, E-12 |
| No self-deactivation; no change to own roles (the only self restrictions; self-edit and self-reset are allowed) | `DeactivateUser`, `SyncUserRoles` | FND-021, E-26, DEC-020 |
| First administrator only when no active user holds the protected role; temporary password with forced change | `CreateFirstAdministrator` (+ `CreateUser`) | DEC-021, FND-014 |
| Last administrator | `DeactivateUser`, `SyncUserRoles`, `SyncRolePermissions`, `DeleteRole` | FND-020, E-25 |
| Role with users not deletable | `DeleteRole` (+ FK restrict) | FND-016, E-22 |
| Protected role (Administrador) not deletable or renamable; description editable | `DeleteRole`, `UpdateRole` | FND-016, E-23 |
| New password ≠ current | `ChangeOwnPassword` (`NotCurrentPassword`) | FND-013 |

### Audit payloads

- `users.updated`: `old_values` and `new_values` contain only the changed fields among `first_name`, `last_name`, `email` (E-27).
- `users.created`: `new_values` = names, email, `is_active`, role ids and names. From the console command: `actor_id = null`, `ip_address = null`, `context = {source: 'console', command, os_user, host}` (DEC-021).
- `auth.login_failed` while locked: `context.reason = 'locked'` (DEC-018).
- `users.activated` / `users.deactivated`: `{is_active}` before and after.
- `users.roles_assigned` / `users.roles_removed`: role ids and names added or removed.
- `roles.permissions_granted` / `roles.permissions_revoked`: permission names.
- `roles.updated`: changed `name` / `description`.
- `roles.deleted`: `old_values` = name, description, permission names.
- Password events: no values (E-28).
- `authorization.denied`: `context` = route name, method, route parameter ids.

## Testing Strategy

Test infrastructure:
- Pest with `RefreshDatabase` on MySQL `testing`. `TestCase` seeds `FoundationSeeder` once per migration.
- The database session driver is used, because `phpunit.xml` no longer overrides it.
- `travel()` is used for time.
- Authorization tests call HTTP endpoints directly.
- Test names start with the scenario ID.

Session scenarios (E-07, E-08, E-09, E-15) use the helpers `loginWithRealSession()`, which performs a real `POST /login` and captures the session cookie, and `requestWithSession()`, which calls `app('auth')->forgetGuards()` and resends the cookie. This exercises the real session mechanism instead of the guard's in-memory user.

| Layer | What to test | Approach |
|---|---|---|
| Unit | `AuditRedactor` (nested keys, case-insensitive), `PermissionName` catalog = spec §9, `PublicRoutes::NAMES === ['login','login.store']` | Plain Pest (`tests/Unit`). `OperatingTime` reads config, so its boundary tests live in `tests/Feature/Audit/AuditQueryTest.php` |
| Integration (Feature/HTTP) | Every E-01..E-30 scenario + FND-003 counter rules (DEC-017) + lock message (DEC-018) + FND-025 filters in the operating timezone (DEC-019) + self-actions (DEC-020) + seed contents | Pest + `pest-plugin-laravel`, `assertInertia`, DB assertions |
| Integration (Console) | `users:create-administrator` (DEC-021) | Pest `artisan()` with `expectsQuestion()` / `assertExitCode()`, DB and audit assertions |
| E2E | — | Not available (`openspec/config.yaml`); frontend is verified by `vue-tsc`, `vp check`, `pnpm build` and the design-system §11 checklist |

Scenario → test file map:

| Scenario | Test file |
|---|---|
| E-01, E-02, E-03, E-04, E-05, E-06 (+ lock expires after 15 min; lock audited) | `tests/Feature/Auth/LoginTest.php` |
| DEC-017 (no time expiry; reset on success; reset when the lock ends), DEC-018 (lock message with remaining minutes; identical for existing and unknown emails) | `tests/Feature/Auth/LoginThrottleTest.php` |
| E-07 | `tests/Feature/Auth/LogoutTest.php` |
| E-08, E-09 | `tests/Feature/Auth/SessionTest.php` |
| E-10, E-19, E-20, E-21 | `tests/Feature/Authorization/PermissionAuthorizationTest.php` |
| E-30 | `tests/Feature/Authorization/ProtectedByDefaultTest.php` |
| E-11, E-12 (create), E-13, E-16 (create) | `tests/Feature/Users/CreateUserTest.php` |
| E-12 (update), E-27 | `tests/Feature/Users/UpdateUserTest.php` |
| E-18 | `tests/Feature/Users/UserStatusTest.php` |
| E-14 | `tests/Feature/Passwords/ForcedPasswordChangeTest.php` |
| E-15, E-16 (reset), E-28 (reset) | `tests/Feature/Passwords/ResetPasswordTest.php` |
| E-16 (own change), E-17, E-28 (own change), FND-013 no reuse | `tests/Feature/Passwords/OwnPasswordChangeTest.php` |
| E-22, E-23 | `tests/Feature/Roles/RoleManagementTest.php` |
| E-24, seed (7 roles, 9 permissions only on Administrador) | `tests/Feature/Roles/PermissionCatalogTest.php` |
| E-25 (deactivate, remove roles, revoke permissions) | `tests/Feature/AdministrativeProtections/LastAdministratorTest.php` |
| E-26, DEC-020 (self-edit and self-reset allowed) | `tests/Feature/AdministrativeProtections/SelfActionTest.php` |
| DEC-021 (first-administrator command) | `tests/Feature/Console/CreateFirstAdministratorTest.php` |
| E-27, E-28 (content-level assertions, incl. failed-login actor email) | `tests/Feature/Audit/AuditRecordTest.php` |
| E-29 | `tests/Feature/Audit/AuditImmutabilityTest.php` |
| FND-025 (filter by user, action, date range; `audit.view` required), DEC-019 (display and day boundaries in `America/Caracas`) | `tests/Feature/Audit/AuditQueryTest.php` |

Non-obvious test designs:
- **E-06**: 5 failed posts for ` Ana@Ecolekua.com `, then a 6th post with the correct password is rejected and not authenticated. Assert an `audit_logs` row with `action = auth.lockout` and `actor_email = ana@ecolekua.com`. Then `travel(16)->minutes()` and a correct login succeeds.
- **DEC-017** (test names start with `DEC-017`):
  - 4 failures, `travel(30)->days()`, 1 more failure → locked (failures never expire by time).
  - 4 failures, a successful login, 4 more failures → not locked (success resets).
  - 5 failures (locked), `travel(15)->minutes()`, 4 failures → not locked; a 5th → locked again (the counter restarted at 0 when the lock ended).
  - Attempts while locked do not extend `locked_until`.
- **DEC-018** (test names start with `DEC-018`):
  - The 5th failure returns the generic `auth.failed` message; the 6th returns `Demasiados intentos fallidos. Intente de nuevo en 15 minutos.`
  - After `travel(10)->minutes()` the message says `5 minutos`; after a total of 14 minutes 30 seconds (`travel(14)->minutes()`, then `travel(30)->seconds()`) it says `1 minuto`.
  - The lock message for a locked unknown email equals the one for a locked existing email with the same remaining time (no account enumeration).
- **DEC-019** (test names start with `DEC-019`): insert an audit row at `2026-03-10 03:30:00 UTC` (= `09/03/2026 23:30:00` in Caracas) and one at `2026-03-10 04:00:00 UTC` (= `10/03/2026 00:00:00`). Filter `from = to = 2026-03-09` returns only the first; `from = to = 2026-03-10` returns only the second. The Inertia props show `occurred_at = '09/03/2026 23:30:00'` for the first row. Assert `config('app.timezone') === 'UTC'` and that the stored `created_at` is unchanged.
- **DEC-020** (test names start with `DEC-020`): an administrator updates their own first name and email through `PUT /users/{self}` → 302, change persisted, `users.updated` audited with the actor as entity. The same administrator resets their own password through `PUT /users/{self}/password` → redirect to `login`, own `sessions` rows deleted, `must_change_password = true`, and the next login lands on `password.edit`. E-26 still rejects self-deactivation and own-role changes.
- **DEC-021** (test names start with `DEC-021`; `FoundationSeeder` already ran):
  - Creates an active administrator: answer the three prompts, with `Str::createRandomStringsUsing(fn () => 'Tmp-DEC021-abcdefgh')`. Exit code 0; the user is active, holds only the protected role, has `must_change_password = true`, `Hash::check('Tmp-DEC021-abcdefgh', $user->password)`; the output contains the temporary password.
  - Audits a console event: exactly one `users.created` row with `actor_id = null`, `ip_address = null`, `context.source = 'console'`, `context.command = 'users:create-administrator'`; `json_encode` of the row contains neither the temporary password nor the hash.
  - Refuses when an active user holds the protected role: exit code 1, user count and audit count unchanged.
  - Runs when the only protected-role holders are inactive.
  - Rejects an invalid or duplicate email (after normalization): exit code 1, nothing written.
  - Refuses non-interactive runs (`--no-interaction`): exit code 1, nothing written.
  - Fails with a clear message when the protected role does not exist (seeder not run).
  - The first login with the temporary password redirects to `password.edit` (FND-014).
  - `Log::spy()` receives no message containing the temporary password.
- **E-09 / E-15**: log in with a real session, then act as an administrator through HTTP. Assert `sessions` has 0 rows for the target, and `requestWithSession()` returns a 302 to `/login`. For E-15, also assert the next login lands on `password.edit`.
- **E-10**: a user whose role has `users.view` gets 200 on `GET /users`. Revoke the permission (through the Action or the DB). The same test then gets 403 on `GET /users` without logging in again.
- **E-24**: insert a `permissions` row `testing.e24` (not assigned) and register a test-only route `Route::middleware('web')->get('/_test/e24', ...)` with `Gate::define('testing.e24', fn (User $u) => $u->hasPermission('testing.e24'))`. The administrator gets 403. After the permission is attached to Administrador, the administrator gets 200.
- **E-19**: a user without `users.update` sends `PUT /users/{id}` and gets 403. The target row is unchanged, and an `authorization.denied` row names the actor and route.
- **E-29**:
  - (a) Enumerate routes: none other than `GET audit.index` targets audit.
  - (b) `AuditLog::first()->update([...])` and `->delete()` throw `AuditLogIsImmutable`.
  - (c) `DB::table('audit_logs')->update([...])` and `->delete()` throw `QueryException` with SQLSTATE `45000`.
  - (d) The row is unchanged afterwards.
- **E-28**: after a reset and after an own change, assert that neither the plain password nor the value of `users.password` (the hash) appears anywhere in `json_encode` of the new audit rows. Also assert that `Log::spy()` received no message containing the plain password.
- **E-30**:
  1. For each route in `Route::getRoutes()` and each method except `HEAD`, build the URI by replacing `{param}` and `{param?}` with `1`.
  2. Skip route names in `PublicRoutes::NAMES`.
  3. Send the request as a guest (CSRF is bypassed in tests) and assert a 302 to `route('login')`.
  4. The test fails if any route is reachable. Separately, it asserts the public list is exactly the one spec 001 declares, and that every public route's name exists.

## Threat Matrix

The change alters **HTTP** routing (protected by default), which is covered by E-30 and FND-026 above. It has no shell, subprocess, VCS/PR automation, executable-file classification or process-integration boundary.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no file classification or execution | — | — |
| Git repository selection | N/A: no VCS automation | — | — |
| Commit state | N/A: no VCS automation | — | — |
| Push state | N/A: no VCS automation | — | — |
| PR commands | N/A: no PR automation | — | — |

## Migration / Rollout

1. **Local infrastructure**: apply the `compose.yaml` `command: --skip-log-bin` (not `MYSQL_EXTRA_OPTIONS`, Decision 11), then `sail down && sail up -d`. Verify `@@log_bin = 0`.
2. **Migrations** (new, additive, each with `down()`), run in order 000001 → 000004:
   - users alteration;
   - roles and permissions;
   - audit logs + triggers;
   - login throttles.

   Locally: `sail artisan migrate:fresh --seed`. Rollback: `sail artisan migrate:rollback --step=N`. The audit migration's `down()` drops the triggers first and refuses in production. The users `down()` recreates `name` (from `first_name` + `last_name`), `email_verified_at` and `remember_token`.
3. **Seeding**:
   - `FoundationSeeder` (catalog + 7 roles) runs in every environment and is idempotent. Deploy step: `php artisan db:seed --class=FoundationSeeder --force`.
   - No seeder creates users (DEC-021).
4. **First administrator** (every environment, once): `php artisan users:create-administrator` (locally `sail artisan users:create-administrator`), run interactively by the person deploying. It prints a temporary password once; the administrator must change it at first login. It refuses to run again while an active administrator exists (DEC-021, Decision 17).
5. **Production database prerequisites**: the trigger privileges from Decision 11. The operating timezone needs no environment setting: it is pinned to `America/Caracas` in `config/app.php` (DEC-019); the PHP image must ship current `tzdata`.
6. **No feature flags.** This is a new application, and no shared environment or real data exists yet.

### PR slice outline (for `sdd-tasks`; delivery strategy `ask-on-risk`, budget 400 authored lines)

The whole change is roughly 4,000 to 5,000 authored lines, so it **clearly exceeds** the budget and needs chained PRs. The slices are vertical, so each one keeps `ensure_pages_exist = true` valid by shipping the pages its tests render.

| # | Slice | Covers | Rough size |
|---|---|---|---|
| 1 | Schema + domain core: 4 migrations, models, enums, seeders, factories, `RecordAuditEvent` + redactor + immutability, test infrastructure, `compose.yaml` / `phpunit.xml` | FND-007, FND-008 (schema), FND-017, FND-024; E-29 (b, c, d), seed test | ~550 |
| 2 | UI foundation: tokens, blade, layouts, base components, `errors/Forbidden` | design-system | ~500 |
| 3 | Auth and session: protected-by-default middleware stack, `PublicRoutes`, login/logout, throttle with lock message, session pinning, `/up` + storage + devtools removal, Home page, own/forced password change | FND-001..006, FND-012..015 (own), FND-026; E-01..E-08, E-14, E-16 (own), E-17, E-28 (own), E-30; DEC-017, DEC-018 | ~850 |
| 4 | Users administration + policies + denial auditing + `AuditOrigin` + `UserRules` | FND-009..011, FND-014 (reset), FND-019; E-09, E-11..E-13, E-15, E-16, E-18..E-20, E-27, E-28 (reset); DEC-020 (self-edit, self-reset) | ~950 |
| 4b | First-administrator console command | DEC-021 (FND-014, FND-020, FND-022) | ~250 |
| 5 | Roles + administrative protections | FND-016, FND-018, FND-020, FND-021; E-10, E-21..E-26 | ~750 |
| 6 | Audit query screen in the operating timezone (`OperatingTime`, `operating_timezone` config) | FND-025; E-29 (a); DEC-019 | ~400 |

Slice 4b depends on slice 4 (`CreateUser`, `AuditOrigin`, `UserRules`) and on slice 1 (protected role). It is independent of slices 5 and 6, and must be merged before any shared or production deployment, because without it no administrator can exist. Slices 1 through 5 each likely exceed 400 lines. `sdd-tasks` should split them further (for example backend and page within a slice) or record a `size:exception` decision under `ask-on-risk`.

## Resolved Decisions

The five business questions raised by the previous version of this design (DEC-PENDIENTE-1..5) were answered by the user and recorded as `Confirmada` in `docs/specs/001-foundation.md` §16 (v1.2). No business question remains open for spec 001.

| Former question | Spec decision | Resolution | Design impact |
|---|---|---|---|
| DEC-PENDIENTE-1 (FND-003 counter) | **DEC-017** | Failed attempts never expire by time; the counter returns to 0 only on a successful login or when the 15-minute lock ends. | Matches the previous default in behavior. `LoginThrottle` has no time window and resets an expired lock lazily on the next read (Decision 7). Tests `DEC-017 …` in `LoginThrottleTest.php`. |
| DEC-PENDIENTE-2 (message while locked) | **DEC-018** | Specific lock message with the remaining minutes; the lock applies to any email, existing or not. | `lockedMinutesRemaining()`, `auth.locked` pluralized copy, `AttemptLogin` `Locked(minutes)` result (Decision 7, login flow). The 5th failure keeps the generic message. Tests `DEC-018 …`. |
| DEC-PENDIENTE-3 (operating timezone) | **DEC-019** | `America/Caracas`; store UTC; the audit query displays and filters by date range in that zone. | `config('app.operating_timezone')` literal, `config/app.php` `timezone` stays `UTC`, `OperatingTime` with half-open UTC day ranges, backend-formatted `occurred_at` (Decision 16). Tests `DEC-019 …`. |
| DEC-PENDIENTE-4 (self-actions) | **DEC-020** | An administrator with the permissions may edit their own data and reset their own password through user management; only the FND-021 prohibitions apply. | No self guard in `UserPolicy::update` / `resetPassword`, `UpdateUser`, `ResetUserPassword`; explicit logout after a self reset (Decision 18). Tests `DEC-020 …`. |
| DEC-PENDIENTE-5 (first administrator) | **DEC-021** | Interactive console command (first name, last name, email); generated temporary password with forced change; runs only when no active user holds the Administrador role; audited as a console event; no credentials in env or code. | `users:create-administrator` → `CreateFirstAdministrator` Action → `CreateUser`, `AuditOrigin::console`, `LocalAdministratorSeeder` dropped, new slice 4b, rollout step 4 (Decision 17). Tests `DEC-021 …`. |

## Technical Follow-ups (not business decisions)

- [ ] **Trigger privileges in the production MySQL.** Confirm which of binlog-off, `log_bin_trust_function_creators=1` or a privileged migration account the hosting allows (Decision 11). If none, the user must choose fallback F2 or F3.
- [ ] Confirm in Laravel 13 that `render` callbacks receive `AccessDeniedHttpException` for policy denials (Decision 10). The E-19 RED test settles it during apply.
- [ ] `$request->ip()` behind a reverse proxy needs `trustProxies` configured once the production topology is known (FND-023 IP accuracy).
- [ ] Confirm during apply that Pest's `expectsQuestion()` drives Laravel Prompts `text()` in the Laravel 13 test harness (Decision 17). If it does not, the command falls back to `$this->ask()`, which keeps the same behavior and tests.
