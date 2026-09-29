# Apply Progress: 001-foundation

Mode: Strict TDD (openspec/config.yaml `strict_tdd: true`). Runner: `./vendor/bin/sail pest` / `./vendor/bin/sail artisan test`.
Delivery: feature-branch-chain (PR #1 targets tracker `feat/001-foundation`). No commits made (project rule).

## Completed tasks (56/108; 1.1-1.26 below, 2.1-2.5 and 2.12 in the PR 2a section, 2.6-2.11 and 2.13 in the PR 2b section, 3.1-3.7 in the PR 3a section, 3.8-3.17 in the PR 3b section)

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

## PR 2a — Design tokens, blade shell, locale (tasks 2.1-2.5 + 2.12, 32/104 done)

> Scope amendment (2026-09-28, user decision UI-06): optional dark mode in the internal ERP (light default, manual `localStorage` toggle, derived dark palette `Propuesta`, portal excluded) amended 2a by adding task 2.12 and rewording 2.2/2.5; task 2.13 (`useAppearance` + toggle) added to PR 2b. See the UI-06 section below.

- [x] 2.1 `resources/css/app.css` replaced with the design-system §2 token block (extracted verbatim from the doc); the starter `Instrument Sans` `@theme inline` removed; the two starter `@source` lines (pagination views, compiled views) kept.
- [x] 2.2 `resources/views/app.blade.php`: `<html lang>` unchanged binding but `dark` class binding removed; `viewport-fit=cover` added; `@fonts` replaced by Google Fonts `<link>` tags (Outfit, Plus Jakarta Sans, Material Symbols Outlined; UI-03 interim).
- [x] 2.3 `config/app.php` default locale `es` (`env('APP_LOCALE', 'es')`); `lang/es/validation.php` (only rules used by this change + `password.*` + `attributes`) and `lang/es/auth.php` stub (`return []`).
- [x] 2.4 `resources/js/app.ts` progress color is `var(--color-secondary)` (a token reference, no hex; Inertia interpolates the value into a CSS `<style>`, confirmed in `@inertiajs/core`).
- [x] 2.5 §11 verification (see below).

### Observed evidence (PR 2a)

- `sail pnpm types:check` -> `vue-tsc --noEmit`, no errors.
- `sail pnpm build` -> `built in ~390ms`, no errors.
- `sail artisan test` -> 27 passed, 135 assertions (unchanged; the `es` default did not break any test).
- `sail pint --test` -> passed (config/app.php and lang/ also formatted with pint -> passed).
- Token utilities generation: Tailwind v4 emits only used classes, so a throwaway `resources/views/zz-probe.blade.php` (deleted afterwards, not in the diff) used `p-space-md px-gutter text-label-md font-label-md shadow-glow bg-dots bg-primary pb-safe`; the built CSS contained every one (e.g. `.p-space-md{padding:var(--spacing-space-md)}`, `.px-gutter{padding-inline:var(--spacing-gutter)}`, `--color-primary:#000845`).

### §11 checklist (docs/ui/design-system.md)

| # | Item | Result |
|---|---|---|
| 1 | `pnpm build` compiles; §2 utilities generated | PASS (build clean; probe above generated `p-space-md`, `px-gutter`, `text-label-md`, `shadow-glow`, `bg-dots`) |
| 2 | No arbitrary hex / default Tailwind palette in the diff | PASS (only hex values are the token declarations in `app.css`; `app.ts` uses `var(--color-secondary)`; no `bg-[#...]` or `gray-*`-style classes added) |
| 3 | No `dark:` classes | PASS in the diff (`rg dark:` finds none in `app.css`, `app.blade.php`, `app.ts`; `dark` class binding removed). `resources/js/pages/Welcome.vue` (starter, 23 `dark:` uses) still exists and is deleted by task 2.10 |
| 4 | Viewed at 375/768/1280 | NOT APPLICABLE to this unit: no route or component renders the shell yet (first real render is Phase 3's login page); re-check in 2.11 / Phase 3 |
| 5 | Touch targets >=44px; inputs >=16px | NOT APPLICABLE: no components yet (2.7 / 2.11) |
| 6 | Visible focus with keyboard | NOT APPLICABLE: no interactive elements yet (2.7 / 2.11) |
| 7 | No `text-secondary-container` on light background | PASS (class not used anywhere in the diff) |

Task 2.5 is scoped to "tokens, viewport, no dark mode": all three are satisfied. Items 4-6 are UI-render checks with nothing to observe yet; they are not failures and are carried into 2.11.

### Work Unit Evidence (PR 2a)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `./vendor/bin/sail pnpm types:check && ./vendor/bin/sail pnpm build` -> both exit 0; `sail artisan test` 27 passed, 135 assertions |
| Runtime harness | N/A — shell has no standalone route until Phase 3; utilities verified with the throwaway probe file |
| Rollback boundary | Revert `resources/css/app.css`, `resources/views/app.blade.php`, `resources/js/app.ts`, `config/app.php` locale default, delete `lang/es/` |

### PR 2a deviations / notes

- TDD: no RED step (documented Phase 2 exception: no JS test framework). No PHP behavior changed.
- The local `.env` (not readable by agents) evidently sets `APP_LOCALE=en`: `tinker` reports `app()->getLocale()` = `en` even though the config default is now `es`. `.env` / `.env.example` are outside the agent's permitted paths; the user must set `APP_LOCALE=es` (and preferably `APP_FALLBACK_LOCALE=es`) in `.env` and `.env.example` for Spanish messages to take effect locally. Tests and the config default are unaffected.
- `vite.config.ts` still registers `bunny('Instrument Sans')` through the Laravel Vite fonts plugin; the build still emits unused Instrument Sans assets (`@fonts` is no longer in the layout so they are never referenced). Left untouched (not in tasks 2.1-2.4); suggest removing it in a later unit.
- Google Fonts loaded from `fonts.googleapis.com` via `<link>` (UI-03 interim; pending approval of `@fontsource-*`/`material-symbols` dependencies).

### UI-06 amendment (tasks 2.12 done; 2.13 pending in PR 2b)

- [x] 2.12 `resources/css/app.css`: `@custom-variant dark (&:where(.dark, .dark *));`, `.dark { … }` overriding all 55 color tokens (names identical to §2), `color-scheme: light` on `:root` and `dark` on `.dark` (in `@layer base`). `resources/views/app.blade.php`: inline no-flash `<script>` in `<head>` before `@vite` (reads `localStorage.appearance` in try/catch, adds `dark` to `<html>` only for `'dark'`). `docs/ui/design-system.md` updated (§1.5, §2, new §3.2 contrast table, §6.5 toggle notes, §10 UI-06, §11). 2.2 and 2.5 reworded and still satisfied (no `dark:` classes; `dark` binding removed; theme class applied only by the inline script).
- [ ] 2.13 `useAppearance.ts` + toggle in `AppLayout.vue` (Spanish `aria-label`): lands with PR 2b layouts. 2.11 now also verifies both themes.

Evidence: `sail pnpm types:check` OK; `sail pnpm build` OK (built CSS contains `.dark{--color-primary:#dee0ff;…}` and `color-scheme:dark`/`light`); `sail artisan test` 27 passed, 135 assertions; `sail pint --test` passed; `rg 'dark:' resources/js resources/views` -> only `resources/js/pages/Welcome.vue` (deleted by 2.10). Contrast ratios computed with a WCAG 2.x luminance script run through `sail php` (values in design-system §3.2).

Notes: (1) dark `secondary-container` is a dark teal (`#006c8a`) so the CTA can keep `text-primary` (now light) at 4.6:1; the brand cyan exists only in light mode. (2) RESOLVED (user, 2026-09-28, option A): `AppButton` `primary` hover changed from `hover:bg-primary-container` (1.2:1 in dark) to `hover:bg-primary/90` in both themes; computed `on-primary` contrast 15.1:1 light / 9.1:1 dark (design-system §3.2, §7.1). (3) PR 2a review workload grows by ~90 CSS + ~90 doc lines; Forecast unchanged (2a remains a small unit; total still ~4,450 order of magnitude).

## PR 2b — Layouts, base components, Forbidden page (tasks 2.6-2.10, 2.13 done; 2.11 pending human; 38/104 done)

- [x] 2.6 `resources/js/types/auth.ts` rewritten: `AuthUser` (id, first_name, last_name, email, must_change_password) and `Auth` (`user: AuthUser | null`, `permissions: string[]`); starter `User` type removed. `types/global.d.ts`: dropped starter `sidebarOpen`. Added `types/navigation.ts` (`NavItem`) and re-exported it from `types/index.ts`.
- [x] 2.7 Components in `resources/js/components/`: `AppIcon`, `AppButton` (6 variants, `shape` lg/full, optional `href` renders an Inertia `Link`), `IconButton` (required `label` -> `aria-label`), `AppInput`, `AppSelect`, `AppCheckbox` (boolean or array v-model), `AppCard`, `StatusBadge` (categories + `isActive` shortcut, always text), `DataTable` (table `md+`, cards `< md`, per-column `cell-<key>` slots), `BottomNav`, `SideNav`, `AppPagination` (prev/next/page-of, Spanish text), `FlashMessage` (prop-driven, dismissible). Shared focus-ring constants in `resources/js/lib/ui.ts`.
- [x] 2.8 `layouts/AuthLayout.vue` (centred `AppCard`) and `layouts/AppLayout.vue` (`SideNav` at `lg`, `BottomNav` below; Inicio/Cuenta always, Usuarios/Roles/Auditoria only with `users.view`/`roles.view`/`audit.view` in `auth.permissions`; header with user name and theme toggle).
- [x] 2.9 `pages/errors/Forbidden.vue` (uses `AppLayout` via `defineOptions`, Spanish text, button back to `home`).
- [x] 2.10 `pages/Welcome.vue` deleted (see gaps: `GET /` now has no page until `Home.vue` in Phase 3).
- [x] 2.13 `composables/useAppearance.ts` (key `appearance`, localStorage in try/catch, toggles `.dark` on `<html>`, mirrors the class set by the inline no-flash script; light default, no prefers-color-scheme) and a toggle `IconButton` in `AppLayout.vue` (`aria-label` "Activar modo oscuro"/"Activar modo claro", 44px).
- [x] 2.11 manual check: marked done by the human in tasks.md after the PR 2b commit (executor did not perform it; browser verification is the user's).

### TDD note (PR 2b)
No RED step: Phase 2 frontend has no JS test framework and none was added (AGENTS.md: new dependencies need approval). Structural verification only.

### Observed evidence (PR 2b)
- `sail pnpm types:check` -> `vue-tsc --noEmit`, exit 0, no errors.
- `sail pnpm exec vp lint` -> exit 0, no output. `sail pnpm check` (fmt+lint+types) fails ONLY on pre-existing formatting of non-JS files (docs, openspec, `resources/css/app.css`); my 3 flagged `.vue` files were fixed with `vp fmt` and no `resources/js` file is flagged now.
- `sail pnpm build` -> `built in 1.01s`; built CSS contains `min-h-11`, `size-11`, `pb-safe`, `pb-28`, `px-space-lg`, `font-label-lg`, `bg-primary-container`, `bg-success-container`, `accent-primary`, `lg:pl-64`, `focus-visible:ring-secondary-container`.
- `sail artisan test` -> 27 passed, 135 assertions. `sail pint --test` -> passed.
- `rg 'dark:|\[#' resources/js resources/views` -> no matches (Welcome.vue removed).

### Work Unit Evidence (PR 2b)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `./vendor/bin/sail pnpm types:check && ./vendor/bin/sail pnpm build` -> both exit 0; `sail artisan test` 27 passed, 135 assertions |
| Runtime harness | N/A for automation (no page renders the layouts yet: Forbidden is wired in task 4.8); manual browser check 2.11 pending the human |
| Rollback boundary | Delete `resources/js/{components,layouts,composables}/`, `lib/ui.ts`, `types/navigation.ts`, `pages/errors/Forbidden.vue`; restore `types/{auth,global.d,index}.ts` and `pages/Welcome.vue` from git |

### PR 2b deviations / gaps
- Wayfinder helpers exist only for `home`; `/users`, `/roles`, `/audit`, `/password` are hardcoded URIs in `AppLayout.vue` (from design.md "Routes and authorization"); switch to Wayfinder helpers once those routes are registered. "Cuenta" (now "Cambiar contraseña", decision 3) points to `/password` (`password.edit`), an assumption: design does not define an account page.
- (Resolved by decision 4 above) Shared `flash` shape was not defined in design (only "flash" in HandleInertiaRequests), so `FlashMessage` is prop-driven and `AppLayout` does not read `page.props.flash`. Wire it when 3c defines the shape.
- No logout control in the layouts (logout route/UI lands in PR 3b).
- Deleting `Welcome.vue` leaves `Route::inertia('/', 'Welcome')` pointing at a missing page (500 in the browser at `/`; no test hits `/`). Task 3.x replaces the route with `HomeController`/`Home.vue`.
- `vite.config.ts` still registers `bunny('Instrument Sans')` (unchanged; the design does not say to remove it).
- `.env.example` shows as modified in git status; not touched by this unit.
- `AppButton` `md:w-auto` / `w-full` follows design-system §7.1 literally.

### PR 2b gap decisions (user, recorded after the PR 2b commit; design Decision 19)

1. `GET /` stays on the interim route until PR 3c; it must remain protected (not public, FND-026) and redirect guests to `login`. No merge to `main` before 3c.
2. `DESTINATIONS` in `AppLayout.vue` moves to Wayfinder helpers when each route exists: new tasks 3.27 (Inicio, Cambiar contraseña), 4.18 (Usuarios), 6.12 (Roles), 7.8 (Auditoría). Total tasks now 108.
3. Label "Cuenta" renamed to "Cambiar contraseña" (icon `key`); `BottomNav` label centred so it can wrap on 375px (verify in 2.11).
4. Flash: native Inertia v3 flash typed as `{ type: 'success' | 'error', message: string }` via `InertiaConfig.flashDataType` (`types/global.d.ts`). `FlashMessage.vue` now takes no props, reads `usePage().flash` and is mounted in `AppLayout` and `AuthLayout`; the `info` type was dropped. Task 3.24 reworded (no custom shared `flash`).
   Evidence: `sail pnpm types:check` exit 0; `vp lint` exit 0; `sail pnpm build` OK.

## PR 3a — Protected by default, public route list, route cleanup (tasks 3.1-3.7; branch feat/001-foundation-3a-protected-by-default, child of PR 2b)

- [x] 3.1 RED `tests/Feature/Authorization/ProtectedByDefaultTest.php` (3 tests: route walk, exact public list, `contains()`).
- [x] 3.2 `app/Support/Http/PublicRoutes.php`. [x] 3.3 `app/Http/Middleware/RequireAuthentication.php` (extends `Authenticate`, skips only public names).
- [x] 3.4 `bootstrap/app.php`: `health: '/up'` removed, middleware appended first in the web group, `redirectGuestsTo(login)`.
- [x] 3.5 `config/filesystems.php` `local.serve=false`; `config/inertia.php` gained `devtools.enabled = env('INERTIA_DEVTOOLS_ENABLED', false)` (key did not exist there before).
- [x] 3.6 `routes/web.php`: placeholder `login` (GET) / `login.store` (POST) returning 501 (real controller in PR 3b). `/` kept on the interim `Welcome` route (page deleted in 2b; replaced in 3c), protected.
- [x] 3.7 pint + route list.

### TDD Cycle Evidence (PR 3a)

| Task | Test File | Layer | RED | GREEN | REFACTOR |
|------|-----------|-------|-----|-------|----------|
| 3.1 | tests/Feature/Authorization/ProtectedByDefaultTest.php | Integration | 3 written, 3/3 errored: `Class "App\Support\Http\PublicRoutes" not found`; after 3.2 only: 1 passed, 2 failed (`GET up did not redirect a guest`: 200; `Public route [login] is not registered`) | 3.2-3.6: 3/3 passed, 10 assertions | pint fixed an unused import |

### Observed evidence (PR 3a)
- `sail artisan route:list` -> exactly 3 routes: `GET / home`, `GET login`, `POST login.store` (no `/up`, no `/storage/{path}`, no devtools).
- `sail artisan test` -> 30 passed, 145 assertions. `sail pint --test` -> passed. `sail pnpm types:check` OK, `sail pnpm build` OK.
- Devtools routes only register under `APP_ENV=local`; the test env cannot prove that part, so it is verified via `route:list` in the Sail (local) environment.

### Work Unit Evidence (PR 3a)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `./vendor/bin/sail pest tests/Feature/Authorization` -> 3 passed, 10 assertions |
| Runtime harness | `sail artisan route:list` -> 3 routes, all in the web group |
| Rollback boundary | Revert `PublicRoutes`, `RequireAuthentication`, `bootstrap/app.php`, `config/{filesystems,inertia}.php`, `routes/web.php`, and the test file |

### PR 3a deviations / notes
- `/` will redirect guests to `/login`, which returns 501 until PR 3b.
- The middleware is appended before `HandleInertiaRequests` (design order).

## PR 3b — Login, logout, throttle, session pinning (tasks 3.8-3.17; branch feat/001-foundation-3b-login-session, child of PR 3a)

- [x] 3.8 `config/session.php`: `driver => 'database'`, `lifetime => 120` literals. `phpunit.xml` now sets `APP_LOCALE=es`/`APP_FALLBACK_LOCALE=es` (the local `.env` has `en`, which would break `lang/es` lookups in tests).
- [x] 3.9 RED `tests/Feature/Auth/LoginTest.php` (E-01..E-06 + lockout audit + locked-attempt audit reason). [x] 3.10 RED `LoginThrottleTest.php` (DEC-017 x4, DEC-018 x2).
- [x] 3.11 `app/Support/Auth/LoginThrottle.php` (row FOR UPDATE via `insertOrIgnore` + `lockForUpdate`; `locked_until` stored as a whole-second timestamp so remaining minutes are exact). [x] 3.12 `lang/es/auth.php` (`failed`, pluralized `locked`).
- [x] 3.13 `app/Actions/Auth/AttemptLogin.php` + `LoginResult.php` (result VO; nothing thrown inside the transaction). [x] 3.14 `LoginRequest`, `LoginController` (create renders `auth/Login`, store), real `guest`-gated routes; `redirectUsersTo(home)` added to `bootstrap/app.php`.
- [x] 3.15 RED `LogoutTest.php` (E-07) and `SessionTest.php` (E-08 x2, E-09 x2). [x] 3.16 `SessionInvalidator`, `EnsureUserIsActive`, `Logout` action, `LogoutController`, `POST /logout`; `EnsureUserIsActive` appended after `RequireAuthentication`.
- [x] 3.17 pint; middleware order confirmed (RequireAuthentication -> EnsureUserIsActive -> HandleInertiaRequests).

### TDD Cycle Evidence (PR 3b)

| Task | Test File | RED | GREEN |
|------|-----------|-----|-------|
| 3.9/3.10 | Auth/LoginTest.php, LoginThrottleTest.php | 13 written; with the 3a placeholder routes: 10 failed + errors (501 from `/login`, missing `errors` in session), 1-3 trivially passing | 16/16 with tests/Feature/Authorization after 3.8, 3.11-3.14 (one test needed `/logout`, green after 3.16) |
| 3.15 | Auth/LogoutTest.php, Auth/SessionTest.php | 6 written: 5 failed (`/logout` 404; E-09 deactivation still returned 200); E-08 expiry and E-09 row-deletion already passed (DB session handler) | 6/6 after 3.16; full suite 49 passed, 248 assertions |

### Observed evidence (PR 3b)
- `sail artisan test` -> 49 passed, 248 assertions. `sail pint --test` -> passed. `sail artisan route:list` -> `GET /`, `GET login`, `POST login`, `POST logout` (all web group).
- No frontend changes in this unit (types/build not re-run).

### Work Unit Evidence (PR 3b)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `./vendor/bin/sail pest tests/Feature/Auth tests/Feature/Authorization` -> 22 passed, 113 assertions |
| Runtime harness | Feature tests drive the real `database` session driver through `loginWithRealSession()` / `requestWithSession()` (real `sessions` rows, real cookie); no browser run (login page is PR 3c) |
| Rollback boundary | Revert `app/Actions/Auth/*`, `app/Support/Auth/*`, `EnsureUserIsActive`, `LoginRequest`, `Auth/{Login,Logout}Controller`, `bootstrap/app.php`, `config/session.php`, `lang/es/auth.php`, `phpunit.xml`, `tests/Pest.php`, `routes/web.php`, `tests/Feature/Auth/*` |

### PR 3b deviations / notes
- `tests/Pest.php` `loginWithRealSession()`/`requestWithSession()` (PR 1c helpers, flagged then as needing adjustment) were fixed: the cookie is now the decrypted session id; `requestWithSession` forgets the guards, session manager/store and `auth.driver` singletons, and passes an encrypted cookie explicitly (`call()` ignores default cookies). Without this the shared test app kept the in-memory login and every "rejected" scenario passed or failed spuriously.
- The must_change_password redirect to `password.edit` is deferred to 3c (route absent); noted in task 3.20.
- `GET /login` renders `auth/Login`, which is created in PR 3c (`ensure_pages_exist` would fail a test hitting it); until then the browser cannot show the login page.
- Tests use a test-only route `/_test/protected` registered per test (not in the app).
- Failed non-locked attempts carry no `reason` in the audit context (design only specifies `reason=locked`).

## Next

PR 3c (3.18+): Home, forced/own password change, Login.vue/ChangePassword.vue, shared props.
