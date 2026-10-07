# Exploration: 002-customers

> Source: Engram `sdd/002-customers/explore` (observation 33). Product spec: `docs/specs/002-customers.md` (Confirmada).

## Current State (001 baseline; no .codegraph index)

- Stack: Laravel 13 / PHP ^8.4, Inertia v3, Pest 4, Larastan level 7, Pint, Wayfinder (`resources/js/{routes,actions,wayfinder}` generated). No phone, CSV or Excel libraries.
- **Permissions**: `app/Enums/PermissionName.php` (enum + Spanish `description()`); `database/seeders/PermissionCatalogSeeder` (updateOrCreate by name, never assigns); `RoleSeeder` creates the 7 roles only if missing and gives Administrador all permissions only at creation; `FoundationSeeder` runs both; `TestCase` seeds `FoundationSeeder`. The 001 design rejected data migrations; the deploy step is `db:seed --class=FoundationSeeder --force`. FND-017 allows auto-assignment "salvo que la spec lo indique", so 002 §4 is consistent.
  - An existing database needs a mechanism to apply the §4 matrix. Recommended (A2): grant the matrix only for permissions newly created in the seeding run (`wasRecentlyCreated`), including the Administrador top-up, so UI revocations are preserved.
  - Tests hardcoding 9 permissions: `tests/Unit/PermissionNameTest.php`, `tests/Feature/FoundationSeederTest.php`, `Roles/PermissionCatalogTest.php`, `Roles/PermissionCoherenceTest.php:116`, `AdministrativeProtections/LastAdministratorTest.php:80`, `tests/Pest.php` docblock. FND-017 assertions that non-protected roles have 0 permissions conflict with the matrix and must be revised. `tests/Unit/AuditActionTest.php` hardcodes a count of 18.
  - `SyncRolePermissions::ensureCoherent` (DEC-022) covers `roles.*` / `users.*` only; 002 defines nothing for `customers.*`.
- **Pattern**: flat routes in `routes/web.php`; global `RequireAuthentication` + `PublicRoutes`; controllers use `Gate::authorize`; FormRequests authorize via `$this->user()->can()`; Actions wrap `DB::transaction` + `RecordAuditEvent`, with `?User $actor, ?AuditOrigin $origin`; policies auto-discovered. `UserStatusController` gates activate and deactivate with one ability. `UpdateUser` audits only dirty old/new values. `UserRules` is shared by FormRequests and the console command.
  - Users index: `?status=active|inactive|all`, default `active`, counts from backend, `paginate(15)->withQueryString()`. No search today (new in 002).
  - Navigation: `resources/js/navigation.ts` `NAV_GROUPS` has an empty "Comercial" group.
  - `BusinessRuleViolation` renderer is message-only; the duplicate-phone warning (CLI-012, E-14) needs a structured exception/payload plus a confirm flag.
- **Enums**: only `PermissionName` and `AuditAction` (string-backed, Spanish label/description); users use boolean `is_active`. `CustomerStatus`, `CustomerType` and `DocumentType` are new (constitution §21.1).
- **Audit**: `RecordAuditEvent::handle(action, actor, entity, old, new, context, actorEmail, origin)`, no own transaction. `audit_logs.actor_id` FK to users (restrict); `entity_type`/`entity_id` are plain columns with no FK, so audit rows survive a customer delete. Append-only triggers. The audit UI shows `class_basename(entity_type)#id`; the action filter is built from `AuditAction::cases()`. `AuditRedactor` drops only password/remember_token keys.
  - Console audit: `AuditOrigin::console($cmd)` (ip null; context source/command/os_user/host) + explicit actor + context array (import marker goes there). Precedent: `Console/Commands/CreateFirstAdministrator` → `Actions/Users/CreateFirstAdministrator`. The origin must be passed explicitly (default reads the request).
- **Architecture tests**: `NoManualForbiddenTest` (no `abort(403)` in `app/`); `ProtectedByDefaultTest` walks all routes; `PermissionCatalogTest` asserts no `permissions.*` routes.
- **Frontend**: `DataTable` (table from `md:`, cards below), `SegmentedTabs`, `AppPagination`, `StatusBadge` (`isActive` maps to `active`; spec wants `done`, use `category="done"`), `AppCard`, `AppInput` (no `inputmode`/`maxlength` props; attributes fall through to the root div), `AppSelect`, `AppCheckbox`, `AppButton`, `ConfirmDialog` (always danger). Missing: `AppTextarea` (listed in the design system) and a search input with icon. Templates: `users/Index`, `Show`, `Create`.
- **Phone**: no library. Options: B1 in-house `PhoneNumber` value class (no dependency) or B2 `giggsey/libphonenumber-for-php` (needs approval, AGENTS §2).
- **CSV**: native `fgetcsv` / `SplFileObject`, BOM strip, `mb_check_encoding`. One console command exists; no scheduler. Operating timezone `America/Caracas`.

## Recommendation

- Follow the Users pattern: `CustomerPolicy` with `viewAny/view/create/update/deactivate/delete/assign`.
- Permissions: A2 seeding.
- Reuse `RecordAuditEvent`. Delete audits the full previous values (customer + contact + address). Import uses `AuditOrigin::console` + explicit author + an import marker in `context` on the normal created action.
- Phone: B1 (offer B2).
- Import (C1): two phases, validate all rows, then one `DB::transaction` calling the same Create action; shared `CustomerRules`; options `--author=<email>` (required) and `--confirm-duplicate-phones`.
- Duplicate phone: dedicated exception carrying the matches, plus a confirm field.

## Slicing (~2.8–3.5k authored lines, 8 units)

| # | Slice | Est. lines |
|---|---|---|
| 1 | Permissions + matrix seeding + AuditAction cases + Policy + fix 9→16 / 18 tests | 300–400 |
| 2 | Data model + enums + phone/document/date rules | 400–450 |
| 3 | Create/Update actions + controller + requests + duplicates + contact/address + type change | 450–550 (may split) |
| 4 | List + show backend + UI + navigation | 400–450 |
| 5 | Create/Edit forms UI | 350–450 |
| 6 | Deactivate / reactivate / delete | 250–300 |
| 7 | Advisor assignment | 250–300 |
| 8 | Import command | 400–450 |

The forecast exceeds 400 lines under `ask-on-risk`: a chain strategy must be chosen before apply.

## Risks

- Seeding could re-grant permissions an admin revoked.
- Message-only business-rule renderer.
- Phone search in local format vs E.164 storage.
- The real customer CSV must never be versioned or stored in Engram.
- Nested transactions in import (savepoints); restrictive FKs.
- Design-system gaps (`AppTextarea`, search input, `inputmode` passthrough).
- `openspec/config.yaml` says PHP 8.3 while `composer.json` requires ^8.4.

## Open Questions (not resolved)

1. Coherence rules for `customers.*` permissions (DEC-022 analog)?
2. Document number formats and canonical stored form per type (RIF check digit?).
3. Phone scope (Venezuela only vs any country code, landlines, display format); duplicate check against contact-person phones; re-warning on an unchanged phone.
4. Is 29 February valid for birthday/anniversary?
5. Editing or reassigning inactive customers; no-op semantics.
6. Advisor later deactivated or losing `customers.portfolio`.
7. Assignment audit shape (dedicated action vs inside `updated`).
8. Import CSV columns, delimiter (`,` vs `;`), header, author flag.
9. Spec §8 vs components (badge `done` vs `active`, `lg` vs `md`, `AppTextarea`).
10. `created_by` for imported customers = author.
11. Chain delivery strategy.
12. `libphonenumber` approval.

Ready for proposal: yes (no hierarchy contradictions). Settle questions 1–3 before Design.
