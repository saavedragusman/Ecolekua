# Proposal: 002-customers — Customer Registry, Advisor Portfolio and One-Time Import

> Source of truth: `docs/specs/002-customers.md` (status `Confirmada`; CLI-001..CLI-018, E-01..E-41, DEC-CLI-01..DEC-CLI-32 all `Confirmada`; DT-01 technical, confirmed in design).
> Exploration: `openspec/changes/002-customers/exploration.md` (Engram `sdd/002-customers/explore`). Its open questions 2–5 were resolved in the spec as DEC-CLI-25..DEC-CLI-31; the remaining open items are technical and belong to design.
> Scope rule (`openspec/config.yaml` → `rules.proposal`): exactly what spec 002 defines; nothing extra; no tenant/multi-company abstractions.
> Depends on: `001-foundation` (archived: authentication, permissions, audit, ERP layout).

## Intent

Ecolekua customer data lives today in paper, spreadsheets and WhatsApp conversations; every order re-captures it and no single history exists. Business rules §4 require a **valid customer** to confirm an order, so specs 004, 005, 006, 007 and 014 cannot start until a customer registry exists.

This change lets authorized internal users register, view, edit, deactivate, reactivate and (only without history) delete customers from one place, with full audit, backend-enforced permissions, duplicate detection, one advisor portfolio per customer, and a one-time console load of the existing customer list at go-live. The customer detail page is structured so later specs add quotation, order and payment history without redesign.

Success means: every scenario E-01..E-41 that is testable in 002 has a Pest test prefixed with its ID and passes on MySQL; the §4 permission matrix is applied to both fresh and existing databases; and the 002 DoD (spec §12) is satisfied.

## Scope

### In Scope

**Customer record (CLI-001..CLI-008, CLI-013, CLI-017)**
- Customer of type natural person or company (CLI-002, DEC-CLI-01); required data: type, name/business name, phone (CLI-001, DEC-CLI-04); created as active and audited.
- Type change on edit (DEC-CLI-15): company → natural removes anniversary and contact person in the same transaction with audited previous values; UI warns before saving; an incompatible document is not removed — the backend rejects the save until it is changed or cleared (E-23, E-24).
- Optional identification document, type + number, allowed types per customer type, format validation, SENIAT RIF check digit, normalized storage (uppercase, no separators), uniqueness per type + number enforced by validation and by the database (CLI-003, CLI-012, DEC-CLI-02, DEC-CLI-03, DEC-CLI-30, E-34, E-35, E-36).
- Phone: Venezuela only (+58), mobile only for the customer phone, stored normalized in international format (DT-01), displayed readable (CLI-004, DEC-CLI-25, DEC-CLI-26, E-06, E-37). Optional email with format validation.
- One optional contact person, companies only: name + phone required, position and email optional; phone accepts Venezuelan mobile or landline (CLI-005, DEC-CLI-05, DEC-CLI-16, E-38).
- One optional address, any type: address + city + state required, reference optional (CLI-006, DEC-CLI-06, DEC-CLI-17).
- Free-text notes (CLI-007).
- Commemorative dates as day + month only: customer birthday (any type), company anniversary (companies only); 29 February valid (CLI-017, DEC-CLI-14, DEC-CLI-31). No reminders.
- Customer detail page in sections, with reserved space for 004/006 history (CLI-013).

**Duplicate detection (CLI-012)**
- Duplicate document → validation error (E-13).
- Duplicate main phone → warning that identifies the matching customer(s), save only after explicit confirmation; compared only against other customers' main phone, and only when the phone is new or changed (DEC-CLI-27, E-14).

**Lifecycle (CLI-009, CLI-010)**
- Deactivate (with `ConfirmDialog`) and reactivate (no confirmation), neither removes data; both audited (E-07, E-08, E-09). Deactivating an already inactive customer (or reactivating an active one) is a no-op without audit, as in 001 Users (E-40). Inactive customers remain editable and reassignable (DEC-CLI-28).
- Restricted physical delete only for customers without history, `ConfirmDialog` danger "Eliminar"; contact person and address are deleted with the customer in the same transaction, and the audit stores a copy of customer + contact + address previous values (E-10, E-18). In 002 no dependent records exist; later specs add their blocking condition (E-19 is tested in 004/006). Foreign keys from history records toward the customer use restrict-on-delete; contact and address do not block deletion.

**List and search (CLI-011)**
- Views Activos / Inactivos / Todos with backend-computed counts, same default view as the 001 Users list; search by name, document number and phone; **Mis clientes** filter for users with `customers.portfolio`, combinable with views and search; backend pagination (E-11, E-12, E-15).

**Advisor assignment (CLI-014)**
- One optional advisor per customer; eligible advisors are active users with `customers.portfolio`; automatic assignment to the creator when they hold `customers.portfolio`; assign/reassign/unassign with `customers.assign`; assignment and reassignment audited with previous and new advisor (DEC-CLI-07, DEC-CLI-18, E-25..E-28).
- Advisor later deactivated or losing `customers.portfolio`: customers stay assigned and are flagged "asesora no disponible"; no automatic unassignment (DEC-CLI-29, E-33).
- Assignment does not restrict visibility (DEC-CLI-08) and does not determine sales attribution or commissions.

**Permissions and authorization (CLI-015, spec §4)**
- Seven new catalog permissions: `customers.view`, `customers.create`, `customers.update`, `customers.deactivate`, `customers.delete`, `customers.assign`, `customers.portfolio`.
- Initial role matrix of spec §4 (DEC-CLI-11), applied on seeding — this is the explicit exception that FND-017 allows ("unless the spec introducing it states otherwise").
- Permission coherence (DEC-CLI-32, analogous to 001 DEC-022): every `customers.*` permission other than `customers.view` requires `customers.view` when assigning permissions to any role; a non-compliant assignment is rejected and the role is unchanged (E-41). Implemented next to the DEC-022 check in `SyncRolePermissions`.
- Backend authorization via a `CustomerPolicy`; no role-name checks, no `abort(403)` (E-03, E-04, E-09, E-18, E-27).

**Audit (CLI-016)**
- Create, update (changed fields old/new), deactivate, reactivate, delete, and advisor assignment/reassignment, via the existing 001 audit logger inside the same transaction. FND-022 already allows later specs to add audited events.

**One-time import (CLI-018, DEC-CLI-19..DEC-CLI-24)**
- Console command, no ERP screen; UTF-8 CSV; same validations as manual create; all-or-nothing (validate every row, including intra-file duplicates, then one transaction); report with row number, field and reason. A file that is not valid UTF-8 or lacks the expected columns imports nothing and reports the reason (E-39).
- Repeated phones (in file or against the database) → warning, no import unless re-run with an explicit confirmation option.
- Optional advisor-email column (must be an active user with `customers.portfolio`; no auto-assignment); required active author user, recorded as creator and as audit actor with an import marker; invalid/inactive author → nothing imported (E-29..E-32).
- The real source file is never versioned nor stored in Engram.

**Frontend (internal ERP)**
- Customers list, detail, create and edit pages, deactivate/reactivate/delete actions, advisor assignment control, navigation entry under the existing "Comercial" group; following `docs/ui/design-system.md` and spec §8 (mobile-first, ≥44px targets, `type="tel"`/`inputmode="tel"`, `aria-describedby`, no `dark:` classes).

### Out of Scope
- Customer login account, self-registration, private area (005 / 007).
- Quotation history on the detail page (004); order and payment history (006).
- Per-order delivery address and delivery data (006 / 011).
- Sales attribution and commissions (006 / 014).
- Last purchase date and sort by recent activity (006).
- Invoicing and fiscal data for invoices (future integration, constitution §15).
- Import from the ERP UI or recurring imports (DEC-CLI-19); Excel import (DEC-CLI-20).
- Birthday/anniversary reminders (Notifications spec, DEC-CLI-14).
- Customer classification by business line or origin (DEC-CLI-10); visible customer code (DEC-CLI-12).
- Non-Venezuelan phone numbers (DEC-CLI-25).
- Testing E-19 with a real dependent record (no dependent entity exists in 002).

**Commitments recorded for later specs (spec §10; not implemented here):**
- 004: add "has quotations" as a CLI-010 blocking condition with its E-19 test.
- 006: add "has orders or payments" as a CLI-010 blocking condition with its E-19 test; require an identification document to confirm an order.
- 004 / 006: reject new quotations/orders for inactive customers, without affecting in-progress ones.
- 006 / 011: decide whether the customer address is proposed as order delivery address and whether the order keeps its own copy.
- 006: last purchase date in list and detail, and sort by recent activity (no inactivity filter).
- Notifications: birthday reminders (recipients by permission, channel, lead time, anniversary, anti-saturation rule, 29 February handling in non-leap years).

## Capabilities

Existing capabilities in `openspec/specs/`: `authentication`, `user-management`, `password-management`, `roles-permissions`, `authorization`, `administrative-protections`, `audit`.

### New Capabilities
- `customers`: the full customer domain of spec 002 — CLI-001..CLI-018 and E-01..E-41, including the §4 permission table, role matrix and coherence rule, the one-time import command (CLI-018), and decision notes DEC-CLI-01..DEC-CLI-32 and DT-01 where they refine a requirement. A single capability keeps the transcription 1:1 with the source spec and its `CLI` prefix.

### Modified Capabilities
- None at requirement level. Rationale:
  - `roles-permissions`: FND-017 already states that a new permission is not auto-assigned "unless the spec introducing it states otherwise"; spec 002 §4 is that statement, so FND-016..FND-018 and E-24 are unchanged. The matrix is transcribed in `customers`.
  - `roles-permissions` coherence: DEC-CLI-32 adds a new rejection path to role permission assignment for `customers.*`. It is a 002 requirement (transcribed under CLI-015 with E-41), analogous to 001 DEC-022, and does not alter any existing FND requirement or the DEC-022 rule for `roles.*`/`users.*`. Regression protection: existing 001 coherence tests stay green, plus tests for a valid assignment including `customers.*` and an assignment that violates both DEC-022 and DEC-CLI-32 (both messages reported, role unchanged).
  - `audit`: FND-022 already states "Later specs MAY add their own audited events"; CLI-016 is transcribed in `customers`.
  - Implementation-level impact on 001 tests (hard-coded permission/audit-action counts, assertions that non-Administrador roles have zero permissions after seeding) is a code change, not a requirement change; design and tasks handle it.

## Approach

1. **Mirror the 001 Users pattern**: flat routes in `routes/web.php` (protected by default), thin controllers → FormRequest (`$this->user()->can()`) → `CustomerPolicy` (`viewAny/view/create/update/deactivate/delete/assign`) → Actions in `app/Actions/Customers/` wrapping `DB::transaction` + `RecordAuditEvent`. One ability gates both deactivate and reactivate, as `users.deactivate` does in 001.
2. **Data model** via new migrations only: `customers` plus one-to-one contact person and address (tables/columns decided in design); document unique index on (type, normalized number); contact and address removed with the customer, restrict-on-delete only for future history foreign keys; PHP enums `CustomerType`, `CustomerStatus`, `DocumentType` (constitution §21.1).
3. **Shared validation** (`CustomerRules`-style) reused by FormRequests and the import command, as `UserRules` is in 001. Phone and document validation/normalization implemented in-house (small value objects/rules): +58 mobile/landline rules, E.164 storage, cédula/pasaporte formats, SENIAT RIF check digit. **No new Composer or npm dependencies** (exploration option B1).
4. **Permission seeding for existing databases**: extend `PermissionName` and `PermissionCatalogSeeder`; apply the §4 matrix only for permissions newly created in the seeding run, so revocations made later from the roles screen are not re-granted (exploration option A2). Deploy step remains `db:seed --class=FoundationSeeder --force`. Final mechanism confirmed in design.
5. **Duplicate phone warning**: a structured backend response carrying the matching customers plus a confirmation flag on resubmission (the existing message-only business-rule renderer is insufficient). Exact shape in design.
6. **Audit reuse**: new `AuditAction` cases; delete audits the full previous values of customer + contact + address; import uses `AuditOrigin::console` + explicit author + import marker in context.
7. **Import command**: native `SplFileObject`/`fgetcsv`, BOM strip, UTF-8 check; phase 1 validates every row (including intra-file duplicates) and reports; phase 2 creates all rows in one transaction through the same create Action; `--author` (required) and an explicit duplicate-phone confirmation option. Column layout and delimiter decided in design.
8. **Frontend** after backend behavior is green (Strict TDD; authorization tests hit HTTP directly), reusing `DataTable`, `SegmentedTabs`, `AppPagination`, `StatusBadge`, `AppCard`, `AppInput`, `AppSelect`, `ConfirmDialog`; missing design-system pieces (`AppTextarea`, search input with icon, `inputmode` passthrough) added only as needed.

Delivery: forecast well above the 400-line budget; see Review Workload Forecast.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | New | customers, contact person, address tables; unique document index; contact/address removed with the customer |
| `app/Models/Customer*.php` | New | customer, contact person, address models |
| `app/Enums/{CustomerType,CustomerStatus,DocumentType}.php` | New | domain enums |
| `app/Enums/PermissionName.php` | Modified | 7 `customers.*` cases with Spanish descriptions |
| `app/Enums/AuditAction.php` | Modified | customer audit actions |
| `database/seeders/PermissionCatalogSeeder.php` (and/or `RoleSeeder`/`FoundationSeeder`) | Modified | §4 matrix for new permissions on fresh and existing DBs |
| `database/seeders/`, `database/factories/` | New | fictitious customer data only |
| `app/Policies/CustomerPolicy.php` | New | permission-based abilities |
| `app/Actions/Customers/` | New | create, update, deactivate, reactivate, delete, assign, import |
| `app/Http/Controllers/Customers*`, `app/Http/Requests/Customers/` | New | thin controllers, FormRequests |
| `app/Rules/` or `app/Support/` (phone, document, day-month) | New | in-house validation and normalization |
| `app/Console/Commands/` | New | one-time customer import command |
| `bootstrap/app.php` / exception rendering | Modified | structured duplicate-phone warning response (if needed) |
| `routes/web.php` | Modified | customer routes (protected) |
| `resources/js/pages/customers/` | New | Index, Show, Create, Edit |
| `resources/js/components/` | New/Modified | `AppTextarea`, search input, attribute passthrough as needed |
| `resources/js/navigation.ts` | Modified | "Clientes" entry in "Comercial" |
| `app/Actions/Roles/SyncRolePermissions.php` | Modified | `customers.*` coherence check (DEC-CLI-32) |
| `tests/Feature/Customers/`, `tests/Unit/` | New | E-01..E-41 tests (E-19 deferred) |
| `tests/Unit/PermissionNameTest.php`, `tests/Unit/AuditActionTest.php`, `tests/Feature/FoundationSeederTest.php`, `tests/Feature/Roles/*`, `tests/Feature/AdministrativeProtections/LastAdministratorTest.php`, `tests/Pest.php` | Modified | hard-coded counts and zero-permission assertions updated to the new catalog/matrix |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Seeding re-grants `customers.*` permissions an administrator revoked | Med | Grant only for permissions created in that run (A2); covered by a seeder test |
| 001 tests assert non-protected roles have zero permissions after seeding; the §4 matrix breaks them | High (certain) | Revise those tests to the new expected state; FND-017 allows it explicitly |
| Message-only business-rule renderer cannot carry duplicate-phone matches | High (certain) | Dedicated structured response + confirm flag (design) |
| Phone search in local format vs E.164 storage misses matches | Med | Normalize the search term when it looks like a phone (design) |
| In-house RIF check digit / phone rules incorrect | Med | Unit tests with known valid/invalid RIFs and numbers; `libphonenumber` (B2) remains an alternative requiring explicit approval (AGENTS §2) |
| Real customer CSV leaks into git or Engram | Med | Never versioned (path outside repo or gitignored), fictitious fixtures only in tests |
| Nested transactions (savepoints) in the import calling the create Action | Low | Single outer transaction; Actions do not open conflicting transactions; tested by E-30/E-31 |
| Spec §8 vs existing components: `StatusBadge` active → `done` (component maps to `active`), table from `lg:` (component switches at `md:`), missing `AppTextarea` | Low | Design aligns component usage with §8; report any real conflict with the design system instead of resolving it silently |
| Coherence rule (DEC-CLI-32) touches the 001 `SyncRolePermissions` action | Low | Add the `customers.*` check beside the DEC-022 check; E-41 plus existing 001 coherence tests guard both |
| `openspec/config.yaml` says PHP 8.3 while `composer.json` requires ^8.4 | Low | Documentation mismatch only; `composer.json` governs |
| Size well above the 400-line budget | High | Chained PR slices (see forecast); decision needed before apply |

## Rollback Plan

- All schema changes are new migrations with `down()` methods; revert locally with `./vendor/bin/sail artisan migrate:rollback --step=N`. No existing 001 migration is edited.
- Permission rows added by seeding can be removed by a follow-up migration/seeder revert; the matrix only touches `customers.*` rows, so 001 permissions are unaffected.
- Work lives on `feat/002-customers`; each chained PR slice is revertible independently with `git revert` of its merge commit. Slices are ordered so the backend (permissions → model → actions) never depends on unmerged UI.
- The import command runs once at go-live, inside a single transaction: a failed run leaves the database unchanged (all-or-nothing by design).

## Dependencies

- `001-foundation` (merged): permission catalog, `RoleSeeder`, `RecordAuditEvent`/`AuditOrigin`, audit immutability, protected-by-default routing, ERP layout and design-system components.
- No new Composer or npm packages (in-house phone/document validation, native CSV).
- MySQL 8.4 (Sail) with the `testing` database for Pest.
- For CLI-018 at go-live: advisor users and the author user must exist and be active before running the import.

## Success Criteria

Mapped to the spec DoD (§12) and AGENTS.md §12:

- [ ] Every scenario E-01..E-41 has at least one Pest test whose name starts with its ID, green on MySQL (`./vendor/bin/sail artisan test`); E-19 is explicitly deferred to 004/006 because no dependent record exists in 002.
- [ ] Authorization only through `CustomerPolicy`; no role-name checks, no `abort(403)` (architecture tests stay green); every customer route protected by default.
- [ ] Create, update, deactivate, reactivate, delete and assignment run inside Actions in `app/Actions/Customers/`, in a transaction when several entities are affected, and are audited with before/after values.
- [ ] Document uniqueness enforced at database level; foreign keys from history records toward the customer restrict deletion, while contact person and address are removed with the customer in the same transaction.
- [ ] Seeding creates the 7 `customers.*` permissions and applies the §4 matrix on both fresh and existing databases without re-granting revoked permissions.
- [ ] Import is all-or-nothing, reports row/field/reason, requires explicit confirmation for repeated phones, and audits each created customer as an import.
- [ ] Seeders and fixtures contain only fictitious data; no real customer data in the repository or Engram.
- [ ] `sail artisan test`, `sail pint --test`, `sail composer types:check`, `sail pnpm types:check` and `sail pnpm build` pass.
- [ ] Design-system checklist (§11) verified at 375, 768 and 1280 px for the new screens.
- [ ] No tenant/multi-company concepts and no new dependencies in the diff.

## Review Workload Forecast

Estimated authored changed lines: **~2,800–3,500** (additions + deletions, generated Wayfinder files excluded).

| # | Candidate slice | Est. lines |
|---|---|---|
| 1 | Permissions + §4 matrix seeding + `AuditAction` cases + `CustomerPolicy` + fix 001 count/zero-permission tests | 300–400 |
| 2 | Data model + enums + phone/document/day-month rules | 400–450 |
| 3 | Create/Update actions + controller + requests + duplicates + contact/address + type change | 450–550 (may split) |
| 4 | List + detail backend + UI + navigation | 400–450 |
| 5 | Create/Edit forms UI | 350–450 |
| 6 | Deactivate / reactivate / delete | 250–300 |
| 7 | Advisor assignment | 250–300 |
| 8 | Import command | 400–450 |

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

The delivery strategy is `ask-on-risk`: the chain strategy (`stacked-to-main` or `feature-branch-chain`) must be chosen by the user before apply; this proposal does not choose it.
