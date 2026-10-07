# Design: 002-customers — Customer Registry, Advisor Portfolio and One-Time Import

> Source of truth: `docs/specs/002-customers.md` (status `Confirmada`; CLI-001..CLI-018, E-01..E-41, DEC-CLI-01..DEC-CLI-33 all `Confirmada`, so the design gate in AGENTS.md §4.4 is open). DT-01 is confirmed here (Decision 4).
> Inputs: `proposal.md`, `specs/customers/spec.md`, `exploration.md`, the archived `001-foundation` design, `docs/ui/design-system.md` and the current code (read directly; no `.codegraph/` index exists in this repository).
> This file is the project's only plan artifact (`openspec/config.yaml` → `rules.design`). There is no separate `plan.md`.

## Technical Approach

002 is a conventional module that copies the 001 Users pattern end to end: **thin controller → FormRequest (validation + `authorize()` through `CustomerPolicy`) → Action in `app/Actions/Customers/` (business rules + `DB::transaction` + `RecordAuditEvent` in the same transaction)**. It adds **no Composer or npm dependency**: phone and document handling are two small in-house value classes, the CSV import uses native PHP.

Five design moves carry the change:

1. **Data model**: one `customers` table plus two one-to-one child tables (`customer_contacts`, `customer_addresses`) that are part of the customer aggregate (cascade with the customer, never history). Three string-backed enums (`CustomerType`, `CustomerStatus`, `DocumentType`). The document is unique on `(document_type, document_number)` at database level; the phone is stored in E.164.
2. **Permissions**: 7 new `PermissionName` cases; the §4 matrix is declared once as seed data and applied **only to permissions created in the current seeding run** (plus the protected role when it is created), so fresh and existing databases converge and later revocations are never undone.
3. **Validation shared by HTTP and console**: `CustomerRules` (like 001 `UserRules`) is used by the FormRequests and by the import command; normalization happens in the value classes, and the Actions are the authority for everything that must hold for every caller (duplicate-phone warning, advisor eligibility, type-change cleanup).
4. **Duplicate phone warning**: a dedicated `DuplicatePhoneWarning` exception thrown by the create/update Actions, rendered as a validation-style response (422 JSON / redirect with error) plus the structured list of matching customers; the client resubmits with `confirm_duplicate_phone = true`.
5. **One-time import**: `customers:import` console command → `ImportCustomers` Action: read and check the whole file, validate every row (including intra-file duplicates), report, and only then create all rows in one transaction through the same `CreateCustomer` Action, audited with a console origin, the explicit author as actor and an import marker.

Requirement to mechanism map:

| Requirement | Mechanism |
|---|---|
| CLI-001, CLI-002, CLI-004..CLI-007, CLI-017 | `customers` + child tables, `CustomerRules`, `CreateCustomer`, `PhoneNumber`, `CustomerController@store` |
| CLI-002 (type change, DEC-CLI-15) | `UpdateCustomer` cleanup of anniversary + contact in the same transaction; validation against the submitted final type |
| CLI-003, CLI-012 (document) | `DocumentType`, `DocumentNumber` (normalize, format, SENIAT check digit), `CustomerRules::uniqueDocument()`, unique index |
| CLI-012 (phone, DEC-CLI-27) | `DuplicatePhoneWarning` thrown by `CreateCustomer` / `UpdateCustomer` only when the phone is new or changed; `confirm_duplicate_phone` flag |
| CLI-008 | `UpdateCustomer` with changed-fields-only audit |
| CLI-009 (DEC-CLI-28) | `DeactivateCustomer` / `ActivateCustomer` (no-op semantics), one ability `deactivate` |
| CLI-010 | `DeleteCustomer` (history guard extension point, full audit copy), cascade of child rows |
| CLI-011 | `CustomerController@index`: status views, counts, search, "Mis clientes", pagination |
| CLI-013 | `customers/Show` page in `AppCard` sections with reserved slots |
| CLI-014 (DEC-CLI-18, DEC-CLI-29) | Auto-assignment in `CreateCustomer`, `AssignCustomerAdvisor`, `User::isEligibleAdvisor()` / `eligibleAdvisors()` scope, "asesora no disponible" flag |
| CLI-015, §4 | `CustomerPolicy`, `PermissionName`, `InitialRolePermissions` seeding, DEC-CLI-32 check in `SyncRolePermissions` |
| CLI-016 | 6 new `AuditAction` cases, `RecordAuditEvent` inside every Action transaction |
| CLI-018 (DEC-CLI-19..24) | `customers:import` → `ImportCustomers` → `CustomerCsvReader` + `CreateCustomer` |

## Architecture Decisions

### Decision 1: Module layout mirrors 001 Users

**Choice**: Controllers in `app/Http/Controllers/Customers/` (`CustomerController`, `CustomerStatusController`, `CustomerAdvisorController`), FormRequests in `app/Http/Requests/Customers/`, Actions in `app/Actions/Customers/`, `CustomerPolicy` auto-discovered, value/support classes in `app/Support/Customers/`, validation rule objects in `app/Rules/`. Reads call `Gate::authorize()` in the controller; writes authorize in `FormRequest::authorize()` (store/update/advisor) or in the controller before calling the Action (status and delete endpoints have no body), exactly as `UserStatusController` does.
**Alternatives considered**: A service/repository layer (rejected by constitution simplicity, AGENTS.md §7.10). Validation inside the Actions only (rejected: loses FormRequest error binding for Inertia forms).
**Rationale**: `openspec/config.yaml` `rules.design` mandates this pattern; authorization runs before any Action opens a transaction, so a denied request has no side effects and the existing `RenderAccessDenied` hook audits it (`authorization.denied`).

### Decision 2: Data model — `customers` + one-to-one `customer_contacts` and `customer_addresses`

**Choice**: Three tables in a single new migration (schema in Interfaces). Contact and address live in their own tables with a **unique** `customer_id` (enforces "one per customer", E-17) and `cascadeOnDelete()` toward `customers` (CLI-010: they are part of the customer and never block deletion). `customers.advisor_id` and `customers.created_by` reference `users` with `restrictOnDelete()` (users are never deleted anyway, FND-010). Commemorative dates are two nullable `tinyint unsigned` columns each (`birthday_day`, `birthday_month`, `anniversary_day`, `anniversary_month`). Status is a string column backed by `CustomerStatus` (spec §5 requires an Enum, constitution §21.1), not the boolean that 001 users use.

**Rule for later specs (recorded, not implemented here)**: every history table that references a customer (`quotations`, `orders`, `payments`, …) MUST declare its foreign key with `restrictOnDelete()` and add its blocking check to `DeleteCustomer` (Decision 11).

**Alternatives considered**: (a) Contact and address as nullable columns on `customers`: fewer joins, but 11 extra nullable columns whose "all-or-none" rule must be enforced by hand, and the 1:1 aggregate intent is lost. (b) A `MM-DD` string or a dummy-year `date` for commemorative dates: a dummy year leaks a meaningless value into queries, and a string needs parsing; two integers make the future Notifications query (`WHERE birthday_month = ? AND birthday_day = ?`) trivial. (c) `restrictOnDelete()` on child tables with explicit deletes in the Action: equivalent result, but a later caller that forgets the explicit delete would fail; cascade matches "they are part of the customer".
**Rationale**: CLI-005, CLI-006, CLI-010, CLI-017, DEC-CLI-05, DEC-CLI-06, DEC-CLI-14; AGENTS.md §7.4 (the Action still deletes inside a transaction and audits the copy first).

### Decision 3: Enums

**Choice** (all string-backed, English values, Spanish `label()`):

| Enum | Cases (value → label) |
|---|---|
| `CustomerType` | `natural` → "Persona natural", `company` → "Empresa" |
| `CustomerStatus` | `active` → "Activo", `inactive` → "Inactivo" |
| `DocumentType` | `cedula_v` → "Cédula V", `cedula_e` → "Cédula E", `passport` → "Pasaporte", `rif_j` → "RIF J", `rif_g` → "RIF G", `rif_v` → "RIF V", `rif_e` → "RIF E", `rif_p` → "RIF P" |

`DocumentType::allowedFor(CustomerType): list<self>` returns `cedula_v, cedula_e, passport` for natural and the five RIF types for company (CLI-003). `DocumentType::letter(): ?string` returns the prefix letter (`V`, `E`, `J`, `G`, `P`) for cédula/RIF and `null` for passport.
**Alternatives considered**: Single-letter values (`V`, `E`, `J`…): ambiguous, because cédula V and RIF V (and cédula E and RIF E) are different document types with different formats and uniqueness scopes.
**Rationale**: constitution §21.1, DEC-CLI-01, DEC-CLI-02, CLI-003.

### Decision 4: DT-01 confirmed — phone stored in E.164, parsed in-house

**Choice**: DT-01 is **confirmed**: every phone (customer and contact person) is stored as E.164 (`+58` + 10-digit national significant number, e.g. `+584141234567`), column `varchar(16)`. `App\Support\Customers\PhoneNumber` is a small immutable value class:

`PhoneNumber::parse(string $raw): PhoneNumber` (throws `InvalidPhoneNumber` with a reason code):
1. Remove spaces, dots, hyphens, parentheses and slashes.
2. If it starts with `+` or `00`: the country code must be `58`, otherwise reason `foreign` (DEC-CLI-25); the rest is the national significant number (NSN).
3. Otherwise it must be digits only: 11 digits starting with the trunk `0` (`04141234567`) → drop the `0`; 10 digits starting with `2` or `4` (`4141234567`) → NSN as-is; 12 digits starting with `58` → drop `58`. Anything else → reason `format`.
4. The NSN must be exactly 10 digits and start with `4` (mobile, the spec's "04xx") or `2` (landline, "02xx"); anything else (e.g. `0800…`) → reason `format`.

Methods: `e164(): string`, `isMobile(): bool` (NSN starts with `4`), `isLandline(): bool` (starts with `2`), `display(): string` → local readable form `0414-123-4567` / `0212-555-1234`, and `static searchFragment(string $term): ?string` (Decision 13).

`App\Rules\VenezuelanPhone(bool $allowLandline)` wraps `parse()`: reason `foreign` → "Solo se admiten números de Venezuela (+58)."; reason `format` → "El teléfono no es un número venezolano válido."; landline with `allowLandline = false` → "El teléfono del cliente debe ser un celular (04xx)." The customer phone uses `allowLandline: false` (DEC-CLI-26, E-37); the contact phone uses `true` (E-38).

The display string is produced by the backend and sent in props (backend authority, as `OperatingTime` does for dates); the frontend never reformats phones.

**Mobile/landline classification is the spec's literal prefix rule (`04xx` / `02xx`)**; the design does not add a carrier or area-code list, which would be an unrequested business rule.
**Alternatives considered**: `giggsey/libphonenumber-for-php` (exploration B2): accurate carrier data, but a new dependency that AGENTS.md §2 requires to justify and approve, for a Venezuela-only scope (DEC-CLI-25) that ten lines cover. It remains the upgrade path if international numbers are ever allowed. Storing the local form (`04141234567`): breaks WhatsApp links and the explicit DT-01 proposal.
**Rationale**: DT-01, CLI-004, CLI-005, DEC-CLI-25, DEC-CLI-26, E-06, E-37, E-38.

### Decision 5: Identification document — canonical form, format and SENIAT check digit

**Choice**: `App\Support\Customers\DocumentNumber` (static helpers) and `App\Rules\DocumentNumberFormat` (a `DataAwareRule` that reads `document_type`).

**Normalization** (`DocumentNumber::normalize(DocumentType $type, string $raw): string`), applied before format validation, uniqueness and storage:
1. Remove spaces, dots and hyphens (CLI-003) and uppercase.
2. Cédula and RIF: if the value starts with a letter it must be the type's letter (`J` for `rif_j`…), otherwise the format check fails; if it starts with a digit, the type's letter is prepended. The **stored canonical form includes the letter**: `V12345678`, `J123456784`. This makes E-36 work when the user types the RIF with its letter in lowercase (`j-12.345.678 4`) or without it.
3. Passport: the uppercase alphanumeric string as typed, without separators.

**Format** (after normalization, DEC-CLI-30, E-34):

| Type | Canonical pattern | Extra check |
|---|---|---|
| `cedula_v`, `cedula_e` | letter + 6 to 9 digits (`^[VE]\d{6,9}$`, letter = type) | — |
| `rif_j/g/v/e/p` | letter + 8 digits + 1 check digit (`^[JGVEP]\d{9}$`, letter = type) | SENIAT check digit |
| `passport` | `^[A-Z0-9]{5,20}$` | — |

**SENIAT check digit** (`DocumentNumber::rifCheckDigit(string $letter, string $eightDigits): int`):
1. Letter value: `V = 1`, `E = 2`, `J = 3`, `P = 4`, `G = 5`.
2. Weighted sum: `letterValue × 4` plus the eight digits multiplied by `3, 2, 7, 6, 5, 4, 3, 2` in order.
3. `check = 11 − (sum mod 11)`; if `check ≥ 10`, the check digit is `0`.
4. Worked example (synthetic): `J-12345678-?` → `3×4 = 12`; `1×3 + 2×2 + 3×7 + 4×6 + 5×5 + 6×4 + 7×3 + 8×2 = 138`; sum `150`; `150 mod 11 = 7`; `11 − 7 = 4` → `J-12345678-4` is valid, any other last digit is rejected.

**Display** (`DocumentNumber::display()`): cédula `V-12345678`, RIF `J-12345678-4`, passport unchanged.

**Type compatibility** (E-35, E-24): `document_type` must be in `DocumentType::allowedFor(submitted type)`; the error is reported on `document_type`. `document_type` and `document_number` are `required_with` each other (the document is optional as a pair, DEC-CLI-02).

**Uniqueness** (CLI-012, E-13, E-36): `CustomerRules::uniqueDocument(?Customer $ignoring)` compares the normalized pair against active and inactive customers; the unique index `customers_document_unique (document_type, document_number)` is the database guarantee (MySQL allows many `NULL` pairs). A race that passes validation and hits the index (`UniqueConstraintViolationException`) is converted by the Actions into the same validation error on `document_number`.
**Alternatives considered**: Storing only the digits and the letter in `document_type`: equivalent uniqueness, but E-36 explicitly expects the stored document "en mayúsculas y sin separadores", which reads naturally as the full canonical string; storing the letter also makes document search by `J1234…` a plain `LIKE`. Zero-padding short RIF numbers automatically: not in the spec (8 digits are required), so not done.
**Rationale**: CLI-003, CLI-012, DEC-CLI-02, DEC-CLI-03, DEC-CLI-30, E-13, E-24, E-34, E-35, E-36.

### Decision 6: Permissions — catalog, initial matrix applied only to newly created permissions (A2 confirmed)

**Choice**:
- `PermissionName` gains 7 cases (`customers.view`, `customers.create`, `customers.update`, `customers.deactivate`, `customers.delete`, `customers.assign`, `customers.portfolio`) with the Spanish descriptions of spec §4. `PermissionCatalogSeeder` is unchanged (upsert by name, never deletes, never assigns).
- The initial grants are declared once as seed data in `Database\Seeders\InitialRolePermissions` (a `final class` with a `MATRIX` constant: permission name → list of role names). For the 9 permissions of 001 the list is `['Administrador']` (001 behavior); for `customers.*` it is the §4 table. **Administrador does not receive `customers.portfolio`** (§4), so from 002 on the protected role no longer holds "every permission"; nothing in the code relies on that.
- `RoleSeeder` stops attaching permissions itself; it only creates missing roles (unchanged otherwise).
- `FoundationSeeder` orchestrates, in one run:
  1. snapshot the permission names that already exist and whether the protected role exists;
  2. call `PermissionCatalogSeeder` and `RoleSeeder`;
  3. call `InitialRolePermissions::apply($createdPermissionNames, $protectedRoleCreated)`, which attaches a matrix pair `(permission, role)` **only if** the permission was created in this run, **or** the role is the protected role and it was created in this run. Pairs already present are skipped (`syncWithoutDetaching`). A role name from the matrix that does not exist (a non-protected role renamed from the UI) is skipped with a console warning; seeding never fails for it.
- Consequences: a fresh database gets exactly 001's grants plus the §4 matrix; an existing 001 database gets the §4 matrix for the 7 new permissions on the first `db:seed --class=FoundationSeeder --force` after deploy; any later revocation from the roles screen is never re-granted, because on later runs the permissions are no longer new. The deploy step stays the one documented in 001.
- **Supported path**: `FoundationSeeder` (called by `DatabaseSeeder`, by `TestCase` and by the deploy step). Running `PermissionCatalogSeeder` alone would create permissions without their initial grants; it is not a deploy path and this is documented in its docblock.

**Why this does not contradict FND-017**: FND-017 says a new permission "MUST NOT be automatically assigned to any role, unless the spec introducing it states otherwise". Spec 002 §4 (DEC-CLI-11) is that statement for `customers.*`, and it adds "después se gestiona desde la pantalla de roles", which the created-in-this-run rule honors. The E-24 behavior (a permission whose spec states no assignment is not granted) is unchanged, and the 001 permissions keep their 001 grants.

**Alternatives considered**: (A1) Grant the matrix on every seeding run: re-grants revocations made from the roles screen, contradicting "se gestiona desde la pantalla de roles". (B) A data migration that grants the matrix once: runs automatically on deploy, but 001 Decision 2 rejected data in migrations for UI-editable data, and tests seed through `FoundationSeeder`, so two mechanisms would drift. (C) A "seeded grants" bookkeeping table: exact, but a new table for a problem the created-in-this-run rule already solves.
**Rationale**: spec §4, DEC-CLI-11, FND-017, AGENTS.md §7.3 (role names appear only in seed data, never in runtime checks).

### Decision 7: DEC-CLI-32 coherence next to DEC-022 in `SyncRolePermissions`

**Choice**: `SyncRolePermissions::ensureCoherent()` gains one rule: if any of `customers.create`, `customers.update`, `customers.deactivate`, `customers.delete`, `customers.assign`, `customers.portfolio` is in the submitted set and `customers.view` is not, add the message "Los permisos de clientes requieren también «Ver el listado y la ficha de todos los clientes»." to the same `ValidationException` on `permissions`. It runs where DEC-022 runs (inside the transaction, before any write and before the "nothing changed" early return), so the role never changes and no audit row is written (E-41). `CreateRole` accepts no permissions (DEC-013), so it needs no check. The initial matrix already complies.
**Alternatives considered**: A FormRequest rule: guards only the HTTP path (same reasoning as 001 Decision 23).
**Rationale**: DEC-CLI-32, E-41, DEC-022.

### Decision 8: Authorization — `CustomerPolicy`

**Choice**: `CustomerPolicy` with one permission per ability; no role names, no `Gate::before`, no `abort(403)` (`NoManualForbiddenTest` keeps guarding `app/`):

| Ability | Permission | Used by |
|---|---|---|
| `viewAny` | `customers.view` | index |
| `view` | `customers.view` | show |
| `create` | `customers.create` | create, store |
| `update` | `customers.update` | edit, update |
| `deactivate` | `customers.deactivate` | activate **and** deactivate (as `users.deactivate` in 001; spec §4 note) |
| `delete` | `customers.delete` | destroy |
| `assign` | `customers.assign` | advisor update |

`customers.portfolio` is not an ability: it is an **eligibility** attribute. `User::isEligibleAdvisor(): bool` (`is_active && hasPermission(CustomersPortfolio)`) and a `User::scopeEligibleAdvisors()` query scope (active users with an `EXISTS` over `role_user → permission_role → permissions`) are the only places that read it. The "Mis clientes" filter is available when `$actor->hasPermission(CustomersPortfolio)`; a request with `mine=1` from a user without it is treated as "filter off" (same tolerance as DEC-023 unknown values), not as a denial.
**Rationale**: CLI-014, CLI-015, AGENTS.md §7.2–§7.3, E-03, E-04, E-09, E-18, E-27.

### Decision 9: `CreateCustomer` and `UpdateCustomer` Actions

**Choice**:

`CreateCustomer::handle(array $data, User $actor, bool $confirmDuplicatePhone = false, ?AuditOrigin $origin = null, bool $autoAssign = true, ?User $advisor = null, array $auditContext = []): Customer`, inside `DB::transaction`:
1. Normalize: `PhoneNumber::parse()->e164()` for the customer and contact phones, `DocumentNumber::normalize()` for the document.
2. Duplicate phone (Decision 10): unless `$confirmDuplicatePhone`, find other customers with the same main phone; if any, throw `DuplicatePhoneWarning` (nothing written yet).
3. Advisor: when `$autoAssign`, `advisor_id = $actor->isEligibleAdvisor() ? $actor->id : null` (E-25). When `$autoAssign` is false (import), use `$advisor`, which must satisfy `isEligibleAdvisor()` or a `ValidationException` on `advisor_id` is thrown.
4. Create `customers` (`status = active`, `created_by = $actor->id`), then the contact (only for companies; the rules already reject it otherwise) and the address.
5. Audit `customers.created` with the full created values (Audit payloads), merging `$auditContext` into `context`.

`UpdateCustomer::handle(Customer $customer, array $data, User $actor, bool $confirmDuplicatePhone = false): Customer`, inside `DB::transaction`:
1. Lock the customer row (`lockForUpdate()`), reload contact and address.
2. Normalize as above.
3. Duplicate phone **only if the normalized phone differs from the stored one** (DEC-CLI-27, E-14 last clause).
4. Fill the scalar fields. If the final type is `natural`, set both anniversary columns to `null` and delete the contact (DEC-CLI-15, E-23). The request itself may not carry a contact or anniversary for a natural customer (validation rejects them, E-16/E-22); the UI clears those fields after its warning.
5. Contact and address use **full-replace (PUT) semantics**: `contact = null` deletes the existing row, an object upserts it; same for `address`. The edit form always sends the complete state.
6. Compute the diff (Audit payloads). If nothing changed, return without writing an audit row (as `UpdateUser`).

The document compatibility check runs in validation against the **submitted final type**, so E-24 (company → natural keeping RIF J) is rejected on `document_type` before the Action runs and the customer is unchanged.

The advisor is **not** editable through `UpdateCustomer` (an `advisor_id` key in the update payload is not in the validated rules and is ignored); it changes only through `AssignCustomerAdvisor` (Decision 12).
**Alternatives considered**: Reusing one "SaveCustomer" Action for both: create and update differ in auto-assignment, duplicate-phone condition, type-change cleanup and audit shape; two small Actions are clearer.
**Rationale**: CLI-001, CLI-002, CLI-005..CLI-008, CLI-012, CLI-014, CLI-017, DEC-CLI-15, DEC-CLI-18, DEC-CLI-27, AGENTS.md §7.4.

### Decision 10: Duplicate-phone warning — structured exception plus confirmation flag

**Choice**:
- `App\Exceptions\DuplicatePhoneWarning` (extends `RuntimeException`) carries `matches: list<array{id: int, name: string, document: ?string, status: string, status_label: string}>` (every other customer, active or inactive, whose **main** phone equals the submitted one; contact phones are never compared, DEC-CLI-27), ordered by name. It is thrown by `CreateCustomer` / `UpdateCustomer` **after** FormRequest validation passes and **before** any write.
- `App\Exceptions\RenderDuplicatePhoneWarning` (invokable, registered in `bootstrap/app.php` beside the other two hooks):
  - JSON: `422` with `{"message": "...", "errors": {"confirm_duplicate_phone": ["..."]}, "duplicate_phone_matches": [...]}`.
  - Inertia/HTML: `Inertia::flash('duplicatePhoneMatches', $matches)` and `back()->withErrors(['confirm_duplicate_phone' => $message])`.
  - Message: "Este teléfono ya está registrado en otro cliente. Revise las coincidencias y confirme si desea guardar de todos modos."
- The FormRequests accept `confirm_duplicate_phone` (`sometimes|boolean`) and pass it to the Action. The warning is an error on `confirm_duplicate_phone` because that is exactly the field the user must supply to continue.
- Frontend (`CustomerForm`): when `form.errors.confirm_duplicate_phone` is present, it shows a warning panel listing `page.flash.duplicatePhoneMatches` (name, document, status badge, link to `customers.show`) and a "Guardar de todos modos" button that sets `form.confirm_duplicate_phone = true` and resubmits. Any edit of the phone field resets the flag to `false`, so a confirmation never covers a different number. `InertiaConfig.flashDataType` gains the optional `duplicatePhoneMatches` key.
**Alternatives considered**: (a) Reusing `BusinessRuleViolation`: its renderer is message-only and returns a generic error flash, which cannot identify the matches in a structured way. (b) A plain validation rule in the FormRequest: guards only the HTTP path; the import and any future caller would bypass it (same argument as DEC-022). (c) Putting the list inside the validation message: Inertia shares only the first message per key, and links to the matching customers would be lost.
**Rationale**: CLI-012, DEC-CLI-03, DEC-CLI-27, E-14.

### Decision 11: Lifecycle — deactivate, reactivate, restricted delete

**Choice**:
- `DeactivateCustomer::handle(Customer, User $actor)` / `ActivateCustomer::handle(Customer, User $actor)`: in a transaction, lock the row; if the customer is already in the target status, return without writing or auditing (E-40, same no-op rule as 001 Decision 21); otherwise update `status` and audit `customers.deactivated` / `customers.activated` with `{status}` before/after. No other data or relation is touched (E-07). Inactive customers remain editable and reassignable (DEC-CLI-28): no status check exists in `UpdateCustomer` or `AssignCustomerAdvisor`.
- `DeleteCustomer::handle(Customer, User $actor)`: in a transaction, lock the row, run `ensureHasNoHistory()` (a private method that in 002 has no condition; its docblock states that 004 adds "has quotations" and 006 adds "has orders or payments", each throwing `BusinessRuleViolation` "El cliente tiene historial y no se puede eliminar. Puede desactivarlo." with its E-19 test), capture the full copy (customer + contact + address), delete the customer (child rows go by cascade in the same statement and transaction), and audit `customers.deleted` with that copy in `old_values`. The audit row survives because `audit_logs.entity_type/entity_id` have no foreign key (001).
- `CustomerStatusController::activate|deactivate` and `CustomerController::destroy` call `Gate::authorize()` first, then the Action; delete redirects to `customers.index` with a success flash; a `BusinessRuleViolation` is rendered by the existing hook (flash error + back), and the detail page already offers "Desactivar" (spec §8).
- E-19 is not testable in 002 (no dependent record exists). It is recorded as a Pest `->todo('E-19 … se prueba en 004 y 006')` in `DeleteCustomerTest.php`, so every run shows it as pending, like the 001 temporary gaps.
**Rationale**: CLI-009, CLI-010, DEC-CLI-09, DEC-CLI-13, DEC-CLI-28, E-07..E-10, E-18, E-19, E-40, AGENTS.md §7.9.

### Decision 12: Advisor assignment

**Choice**: `PUT /customers/{customer}/advisor` (`customers.advisor.update`), `UpdateCustomerAdvisorRequest` (`authorize`: `can('assign', $customer)`; rules: `advisor_id` `present|nullable|integer|exists:users,id`). `AssignCustomerAdvisor::handle(Customer, ?User $advisor, User $actor)`: in a transaction, lock the row; if `$advisor` is not null and not `isEligibleAdvisor()`, throw `ValidationException` on `advisor_id` ("La asesora debe ser un usuario activo con permiso para tener cartera.", E-28); if the advisor does not change, no-op; otherwise update and audit `customers.advisor_assigned` with previous and new advisor (id and full name, or `null`). `null` unassigns (DEC-CLI-18: assignment is optional).

"Asesora no disponible" (DEC-CLI-29, E-33): computed by the backend, never stored. For a page of customers, the controller loads the advisor ids of the page and runs one `User::eligibleAdvisors()->whereIn('id', …)->pluck('id')` query; `advisor.available = in that set`. No automatic unassignment exists anywhere.

Eligible-advisor options (`id`, full name) are sent to `customers/Show` only when `Gate::allows('assign', $customer)`, like the 001 roles options.
**Alternatives considered**: Recording the automatic creation-time assignment as a separate `customers.advisor_assigned` row: the `customers.created` row already carries the advisor as a created value in the same transaction, so a second row would duplicate it.
**Rationale**: CLI-014, CLI-016, DEC-CLI-07, DEC-CLI-08, DEC-CLI-18, DEC-CLI-29, E-25..E-28, E-33.

### Decision 13: List, search and "Mis clientes"

**Choice**: `GET /customers?status=active|inactive|all&q=…&mine=1`:
- `status`: default `active` (same as the 001 Users list, DEC-023); an unknown value is `active`.
- `q` (trimmed, max 100 chars; longer input is truncated): a customer matches when **any** of these holds (`LIKE` with `%`/`_` escaped):
  - `name LIKE %q%` (the MySQL `utf8mb4` collation is case- and accent-insensitive);
  - `document_number LIKE %D%`, where `D` is `q` uppercased without spaces, dots and hyphens; if `D` starts with a letter followed by digits, `%digits%` is tried as well, so `J-1234`, `j1234` and `1234` all match `J123456784`;
  - `phone LIKE %P%`, where `P = PhoneNumber::searchFragment(q)`: remove separators; only when the rest is digits (optionally with a leading `+`) and has at least 3 digits; strip a leading `+58` / `0058` when present, otherwise strip one leading trunk `0`. So `0414 123`, `414-123`, `+58 414 123` and `1234567` all match `+584141234567`.
- `mine=1`: only when the actor holds `customers.portfolio` (Decision 8): `advisor_id = actor id`; combinable with `status` and `q`.
- `counts` (`active`, `inactive`, `all`) are computed by the backend with the **same `q` and `mine` filters** and independent of `status`, so each tab shows how many results it would list.
- Order `name`, then `id`; `paginate(15)->withQueryString()`.
- Row DTO: `id`, `name`, `type`, `type_label`, `document` (display string or `null`), `phone` (display), `status`, `status_label`, `advisor` (`{id, name, available}` or `null`).
- Indexes: `(status, name)`, `phone`, `advisor_id`, and the unique document index. `LIKE '%…%'` cannot use an index; the expected volume (hundreds to low thousands of customers) makes a scan acceptable, and no full-text index is added.
**Alternatives considered**: Counts independent of the search: the tabs would contradict the list while searching. A separate search field per attribute: more UI for no stated need. Debounced search-as-you-type: needs a debounce helper (no `@vueuse` installed); the search submits on Enter or the search button instead.
**Rationale**: CLI-011, DEC-CLI-08, E-11, E-12, E-15.

### Decision 14: One-time import command

**Choice**:
- Signature: `customers:import {file : Ruta del archivo CSV en UTF-8} {--author= : Correo del usuario interno activo que figura como creador} {--confirm-duplicate-phones : Importa aunque haya teléfonos repetidos, después de revisarlos}`. Non-interactive (it can run under `--no-interaction`). The command is thin: it calls `App\Actions\Customers\ImportCustomers` and prints its report.
- **File checks** (`App\Support\Customers\CustomerCsvReader`), each failing the whole run with a reason and exit code 1:
  1. the path exists and is readable;
  2. content is read whole (a one-time file of a few thousand rows), a UTF-8 BOM (`EF BB BF`) is removed, and `mb_check_encoding($content, 'UTF-8')` must be true → otherwise "El archivo no está codificado en UTF-8." (E-39);
  3. the delimiter is detected from the header line: `,` or `;` (spreadsheets in Spanish locales export `;`), whichever splits the header into the expected columns; parsing uses `SplFileObject::fgetcsv` over an in-memory stream with enclosure `"` and escape `""` disabled (`''`), so quoted cells may contain delimiters and line breaks;
  4. the header (trimmed, case-insensitive, any order) must contain **exactly** the expected columns; missing or unknown columns are listed → "Faltan las columnas: … / Columnas no reconocidas: …" (E-39).
- **Columns** (Spanish, because the file is prepared by Ecolekua staff):

| Column | Maps to | Notes |
|---|---|---|
| `tipo` | `type` | `natural` or `empresa` (case-insensitive) |
| `nombre` | `name` | required |
| `tipo_documento` | `document_type` | `cedula_v`, `cedula_e`, `pasaporte`, `rif_j`, `rif_g`, `rif_v`, `rif_e`, `rif_p`; blank = no document |
| `numero_documento` | `document_number` | as a person would type it |
| `telefono` | `phone` | required, mobile |
| `correo` | `email` | optional |
| `cumpleanos_dia`, `cumpleanos_mes` | `birthday_day`, `birthday_month` | optional pair |
| `aniversario_dia`, `aniversario_mes` | `anniversary_day`, `anniversary_month` | optional pair, companies only |
| `observaciones` | `notes` | optional |
| `contacto_nombre`, `contacto_cargo`, `contacto_telefono`, `contacto_correo` | `contact.*` | the contact exists when any of the four is non-blank |
| `direccion`, `direccion_ciudad`, `direccion_estado`, `direccion_referencia` | `address.line/city/state/reference` | the address exists when any of the four is non-blank |
| `correo_asesora` | advisor | optional; must be an active user with `customers.portfolio` |

- **Phase 0 — author** (DEC-CLI-24, E-32): `--author` is required; the email is normalized as in `UserRules`; a missing, unknown or inactive user fails the run before reading rows ("El autor indicado no existe o está inactivo.").
- **Phase 1 — validate every row** (DEC-CLI-21, E-30): each non-blank line (row number = physical line number, header = 1) is mapped to the same input shape as the HTTP form and validated with `CustomerRules` (identical rules and messages); `correo_asesora` is resolved and checked with `isEligibleAdvisor()`; normalized documents are compared **between rows** (the later row gets "Documento repetido en la fila N"). Error keys are mapped back to CSV column names. Phones: normalized main phones repeated between rows or already present in `customers` are collected as **warnings** (DEC-CLI-22, E-31) with the other row number or the existing customer id and name.
- **Report**: a console table `Fila | Columna | Motivo`, then the phone warnings. It never prints cell values other than the row number, and never writes the report to a file.
- **Decision**: any error → exit 1, nothing created. No errors but phone warnings and no `--confirm-duplicate-phones` → exit 1, nothing created, with the hint to re-run with the option after reviewing. Otherwise **Phase 2**: one `DB::transaction` that calls `CreateCustomer::handle($row, actor: $author, confirmDuplicatePhone: true, origin: AuditOrigin::console('customers:import'), autoAssign: false, advisor: $rowAdvisor, auditContext: ['import' => true, 'import_row' => $line])` for each row (Laravel runs the Action's nested transaction as a savepoint; any exception rolls back everything). Exit 0 with "Se importaron N clientes."
- **Audit** (DEC-CLI-24): each row is a normal `customers.created` event with `actor_id = author`, `ip_address = null`, `context = {source: console, command: customers:import, os_user, host, import: true, import_row}`; `created_by = author`. CLI-014's automatic assignment does not apply (DEC-CLI-23).
- **Real file handling**: the real file is never versioned or stored in Engram (AGENTS.md §4.5). The documented location is `storage/app/private/imports/` (already ignored by the `storage/app/private/.gitignore` that Laravel ships; the apply phase verifies it) or any path outside the repository. Tests build fictitious CSV files at runtime in a temporary directory.
**Alternatives considered**: Importing valid rows and reporting the rest (rejected by DEC-CLI-21). A fixed delimiter: an Excel export in a Spanish locale would fail on the header for a purely technical reason. An interactive confirmation prompt: DEC-CLI-22 asks for an explicit option after reviewing the report, which also works in non-interactive shells.
**Rationale**: CLI-018, DEC-CLI-19..DEC-CLI-24, E-29..E-32, E-39.

### Decision 15: Frontend

**Choice**:
- Pages in `resources/js/pages/customers/`: `Index.vue`, `Show.vue`, `Create.vue`, `Edit.vue`. Shared form fields in `resources/js/components/customers/CustomerForm.vue` (type selector, document, phone, email, dates, notes, contact section only for companies, address section, duplicate-phone panel, type-change warning). Types in `resources/js/types/customers.ts`. Wayfinder helpers for every URL.
- **Index**: `SegmentedTabs` (Activos / Inactivos / Todos with backend counts), a search form (`AppInput` with the `search` icon, submit on Enter or the button), a "Mis clientes" `AppCheckbox`/toggle shown only when `can('customers.portfolio')`, `DataTable` + `AppPagination`, `StatusBadge` with `category="done"` for active and `category="neutral"` for inactive and the Spanish label (spec §8), and an "Asesora no disponible" `StatusBadge` (`pending`) next to the advisor name when `available` is false.
- **Show**: `AppCard` sections in this order: Datos generales, Contacto (phone, email, birthday), Persona de contacto (companies only), Dirección, Observaciones, Asesora (with the assign form when allowed); then an empty, commented slot for the 004/006 history sections (CLI-013). Actions: "Editar" (`customers.update`), "Desactivar" (`ConfirmDialog`, verb "Desactivar", consequence text of DEC-CLI-09) / "Reactivar" (no dialog) (`customers.deactivate`), "Eliminar" (`ConfirmDialog`, verb "Eliminar", irreversible text) only with `customers.delete`.
- **Create / Edit**: labels always visible, errors via `aria-describedby`, phones `type="tel"` + `inputmode="tel"`, emails `type="email"`, text ≥16px. Dates as two `AppSelect` (day 1–31, month names); the backend validates the combination. Switching an existing company to natural shows an inline warning listing what will be removed (anniversary, contact person) before saving and clears those fields from the payload (DEC-CLI-15).
- Component changes (additive, no breaking change): `AppInput` gains optional `inputmode`, `maxlength` and `icon` props (attributes currently fall through to the wrapper `div`, so explicit props are needed; `icon` renders the leading icon with `pl-11` as design-system §7.4 describes for search); new `AppTextarea.vue` (design-system §7.4 lists it; same tokens as `AppInput`) for notes.
- Navigation: a `customers` entry ("Clientes", icon `groups`, `customers.view`, group `Comercial`, priority 10) in `resources/js/navigation.ts` with the Wayfinder `index` helper.
- **Spec §8 vs components — no conflict found**: design-system §7.7 says the table appears at "`lg:` (y `md:` cuando quepa)" and cards below `md`; `DataTable` switches at `md`, which satisfies both the design system and spec §8 (table at `lg`, cards below `md`), so the component is reused unchanged. `StatusBadge` already supports `category="done"`; the `isActive` shortcut (which maps to `active`) is simply not used for customers.
**Rationale**: spec §8, design-system §7, constitution §14 (mobile-first), AGENTS.md §8.

### Decision 16: Tests updated in 001, and why

The 001 requirements do not change (proposal "Modified Capabilities: none"); only tests whose literals encode the 001-only catalog change. Where possible the new assertions derive from `PermissionName::cases()` / `AuditAction::cases()` or compare "before vs after" counts instead of literals.

| Test | Current assertion | Change | Reason |
|---|---|---|---|
| `tests/Unit/PermissionNameTest.php` | exactly 9 cases | exactly 16, list extended with `customers.*` | catalog grows (FND-017: each spec declares its permissions) |
| `tests/Unit/AuditActionTest.php` | exactly 18 cases / 18 unique labels | exactly 24, list extended | CLI-016 adds 6 events (FND-022 allows it) |
| `tests/Feature/FoundationSeederTest.php` test 1 | name mentions "9 permissions" | rename; assertion already derives from `cases()` | wording only |
| `tests/Feature/FoundationSeederTest.php` test 2 | Administrador has 9; every other role 0 | 001 permissions only on Administrador; `customers.*` grants equal the §4 matrix (moved to DEC-CLI-11 tests) | §4 matrix is the FND-017 "unless the spec states otherwise" case |
| `tests/Feature/FoundationSeederTest.php` test 3 | `Permission::count() = 9`, admin 8, Gerente 1 | `count(cases())`, admin = its matrix − 1, Gerente = 1 + its 5 `customers.*` | same scenario, larger catalog |
| `tests/Feature/Roles/PermissionCatalogTest.php` (FND-017 seed test) | 9 permissions, all on Administrador, others 0 | `count(cases())`; Administrador holds every 001 permission and its §4 set; non-protected roles hold exactly their §4 set | same as above |
| `tests/Feature/Roles/PermissionCoherenceTest.php:116` | protected role still has 9 | "unchanged" (count captured before the request) | Administrador now holds 15 |
| `tests/Feature/AdministrativeProtections/LastAdministratorTest.php:80, :93` | 9 unchanged / 8 after revoke | "unchanged" / `count(submitted set)` | literals depended on the catalog size |
| `tests/Pest.php` (`administrator()` docblock) | "all 9 permissions" | "the Administrador grants of the initial matrix (all except `customers.portfolio`)" | documentation accuracy |
| `tests/Feature/Roles/RoleManagementTest.php:151, :173` (found during apply of Phase 1) | `has('role.permissions', 9)` / `has('permissions', 9)` | role's real permission count / `count(PermissionName::cases())` | same literal dependency on the catalog size |

`AuditQueryTest` already derives from `AuditAction::cases()`; the E-24 test in `PermissionCatalogTest` submits every permission, which stays coherent. No other 001 test asserts the catalog size (checked with a search over `tests/`).

## Data Flow

Create / update (web):

```
POST /customers  (or PUT /customers/{customer})
  StoreCustomerRequest / UpdateCustomerRequest
    authorize: can(create|update)        ── denied → AccessDeniedHttpException → audited 403
    rules: CustomerRules (VenezuelanPhone, DocumentNumberFormat, uniqueDocument, dates, contact/address)
                                          ── invalid → 302 back with errors / 422 JSON
  CreateCustomer / UpdateCustomer
    DB::transaction {
      [update] lock customer row
      normalize phone (E.164) + document (canonical)
      phone new/changed && !confirm_duplicate_phone && matches? ─→ throw DuplicatePhoneWarning
      [create] advisor = actor if eligible          [update] type natural → drop anniversary + contact
      write customers / customer_contacts / customer_addresses
      RecordAuditEvent(customers.created | customers.updated)
    }
  DuplicatePhoneWarning → RenderDuplicatePhoneWarning
      JSON: 422 {errors.confirm_duplicate_phone, duplicate_phone_matches}
      Inertia: flash(duplicatePhoneMatches) + back()->withErrors(confirm_duplicate_phone)
      → form shows matches → "Guardar de todos modos" → resubmit with confirm_duplicate_phone = true
  → 302 customers.show (flash success)
```

Seeding (fresh or existing database):

```
FoundationSeeder
  before = existing permission names; protectedExisted = protected role exists?
  PermissionCatalogSeeder  (upsert 16 names)
  RoleSeeder               (create missing roles; no grants)
  InitialRolePermissions::apply(created = catalog − before, protectedCreated = !protectedExisted)
      for (permission, role) in MATRIX:
        if permission ∈ created  or  (role is protected and protectedCreated): attach if missing
```

Import:

```
customers:import file --author=email [--confirm-duplicate-phones]
  author active? ─no→ report, exit 1
  CustomerCsvReader: readable → strip BOM → UTF-8? → delimiter , | ; → exact header ─fail→ reason, exit 1
  ImportCustomers phase 1: per row → CustomerRules + advisor eligibility + intra-file documents
                           phones vs file and DB → warnings
  errors? ─yes→ report, exit 1          warnings && !confirm? ─yes→ report + hint, exit 1
  phase 2: DB::transaction { foreach row: CreateCustomer(actor=author, origin=console, autoAssign=false,
                                                        advisor=row advisor, context{import, import_row}) }
  → "Se importaron N clientes.", exit 0
```

## File Changes

Generated Wayfinder files (`resources/js/actions/**`, `resources/js/routes/**`) are regenerated by the build and excluded from the authored-line count.

| File | Action | Description |
|---|---|---|
| `database/migrations/2026_09_30_000001_create_customers_tables.php` | Create | `customers`, `customer_contacts`, `customer_addresses` (Interfaces → Schema) |
| `app/Enums/CustomerType.php`, `CustomerStatus.php`, `DocumentType.php` | Create | Decision 3 |
| `app/Enums/PermissionName.php` | Modify | 7 `customers.*` cases + descriptions (Decision 6) |
| `app/Enums/AuditAction.php` | Modify | 6 `customers.*` cases + labels |
| `app/Models/Customer.php`, `CustomerContact.php`, `CustomerAddress.php` | Create | Casts, relations (`contact`, `address`, `advisor`, `creator`), scopes `search`, `withStatus`, `assignedTo` |
| `app/Models/User.php` | Modify | `isEligibleAdvisor()`, `scopeEligibleAdvisors()` |
| `app/Support/Customers/PhoneNumber.php`, `InvalidPhoneNumber.php` | Create | Decision 4 |
| `app/Support/Customers/DocumentNumber.php` | Create | Decision 5 |
| `app/Support/Customers/CustomerRules.php` | Create | Shared rules for FormRequests and import |
| `app/Support/Customers/CustomerPresenter.php` | Create | Row / detail / form DTOs, display strings, advisor availability |
| `app/Support/Customers/CustomerCsvReader.php` | Create | Encoding, BOM, delimiter, header, rows (Decision 14) |
| `app/Rules/VenezuelanPhone.php`, `app/Rules/DocumentNumberFormat.php` | Create | Validation rule objects |
| `app/Policies/CustomerPolicy.php` | Create | Decision 8 |
| `app/Actions/Customers/CreateCustomer.php`, `UpdateCustomer.php`, `ActivateCustomer.php`, `DeactivateCustomer.php`, `DeleteCustomer.php`, `AssignCustomerAdvisor.php`, `ImportCustomers.php` | Create | Decisions 9–14 |
| `app/Actions/Roles/SyncRolePermissions.php` | Modify | DEC-CLI-32 rule; docblock on initial grants |
| `app/Exceptions/DuplicatePhoneWarning.php`, `RenderDuplicatePhoneWarning.php` | Create | Decision 10 |
| `bootstrap/app.php` | Modify | Register the duplicate-phone render hook |
| `app/Http/Controllers/Customers/CustomerController.php`, `CustomerStatusController.php`, `CustomerAdvisorController.php` | Create | Thin controllers |
| `app/Http/Requests/Customers/StoreCustomerRequest.php`, `UpdateCustomerRequest.php`, `UpdateCustomerAdvisorRequest.php` | Create | Validation + authorize |
| `app/Console/Commands/ImportCustomers.php` | Create | `customers:import` (thin) |
| `routes/web.php` | Modify | Customer routes (Interfaces), protected by default |
| `database/seeders/InitialRolePermissions.php` | Create | Matrix constant + `apply()` |
| `database/seeders/FoundationSeeder.php`, `RoleSeeder.php` | Modify | Snapshot + apply matrix; `RoleSeeder` no longer attaches |
| `database/factories/CustomerFactory.php`, `CustomerContactFactory.php`, `CustomerAddressFactory.php` | Create | Fictitious data; states `company()`, `inactive()`, `withContact()`, `withAddress()`, `assignedTo()` |
| `lang/es/validation.php` | Modify | Messages/attributes for the new fields |
| `resources/js/pages/customers/Index.vue`, `Show.vue`, `Create.vue`, `Edit.vue` | Create | Decision 15 |
| `resources/js/components/customers/CustomerForm.vue` | Create | Shared form fields, duplicate-phone panel, type-change warning |
| `resources/js/components/AppTextarea.vue` | Create | Design-system §7.4 |
| `resources/js/components/AppInput.vue` | Modify | `inputmode`, `maxlength`, `icon` props |
| `resources/js/types/customers.ts`, `resources/js/types/global.d.ts` | Create / Modify | DTO types; `duplicatePhoneMatches` flash key |
| `resources/js/navigation.ts` | Modify | "Clientes" entry in Comercial |
| `tests/Unit/Customers/*`, `tests/Feature/Customers/*`, `tests/Feature/Console/ImportCustomersTest.php` | Create | Testing Strategy |
| 001 tests listed in Decision 16 | Modify | Catalog-size literals and zero-permission assertions |

## Interfaces / Contracts

### Schema

| Table | Columns | Keys / constraints |
|---|---|---|
| `customers` | `id`; `type` varchar(20); `name` varchar(200); `document_type` varchar(20) null; `document_number` varchar(30) null; `phone` varchar(16) (E.164); `email` varchar(255) null; `birthday_day` tinyint unsigned null; `birthday_month` tinyint unsigned null; `anniversary_day` tinyint unsigned null; `anniversary_month` tinyint unsigned null; `notes` text null; `advisor_id` bigint unsigned null; `status` varchar(20) default `active`; `created_by` bigint unsigned; timestamps | unique `customers_document_unique (document_type, document_number)`; index `(status, name)`; index `phone`; FK `advisor_id` → users **restrict** (indexed); FK `created_by` → users **restrict** |
| `customer_contacts` | `id`; `customer_id`; `name` varchar(150); `position` varchar(100) null; `phone` varchar(16) (E.164); `email` varchar(255) null; timestamps | unique `customer_id`; FK → customers **cascade** |
| `customer_addresses` | `id`; `customer_id`; `line` varchar(255); `city` varchar(100); `state` varchar(40) (`VenezuelanState` value, DEC-CLI-33); `reference` varchar(255) null; timestamps | unique `customer_id`; FK → customers **cascade** |

Timestamps in UTC; `created_at` is displayed with `OperatingTime::format()`. Future history tables reference `customers.id` with **restrict** (Decision 2).

### Enums (additions)

```php
// PermissionName (+7)
case CustomersView = 'customers.view';           // 'Ver el listado y la ficha de todos los clientes'
case CustomersCreate = 'customers.create';       // 'Registrar clientes'
case CustomersUpdate = 'customers.update';       // 'Editar datos del cliente, su persona de contacto y su dirección'
case CustomersDeactivate = 'customers.deactivate'; // 'Desactivar y reactivar clientes'
case CustomersDelete = 'customers.delete';       // 'Eliminar clientes sin historial'
case CustomersAssign = 'customers.assign';       // 'Asignar y reasignar la asesora de un cliente'
case CustomersPortfolio = 'customers.portfolio'; // 'Poder tener cartera: ser asesora asignada de clientes'

// AuditAction (+6; 18 → 24)
case CustomerCreated = 'customers.created';               // 'Cliente creado'
case CustomerUpdated = 'customers.updated';               // 'Cliente modificado'
case CustomerDeactivated = 'customers.deactivated';       // 'Cliente desactivado'
case CustomerActivated = 'customers.activated';           // 'Cliente reactivado'
case CustomerDeleted = 'customers.deleted';               // 'Cliente eliminado'
case CustomerAdvisorAssigned = 'customers.advisor_assigned'; // 'Asesora de cliente asignada'
```

### Initial matrix (seed data, `InitialRolePermissions::MATRIX`)

| Permission | Roles |
|---|---|
| 9 permissions of 001 | Administrador |
| `customers.view` | Administrador, Gerente, Asesora de Ventas, Finanzas |
| `customers.create`, `customers.update` | Administrador, Gerente, Asesora de Ventas |
| `customers.deactivate`, `customers.assign` | Administrador, Gerente |
| `customers.delete` | Administrador |
| `customers.portfolio` | Asesora de Ventas |

### Key PHP contracts

```php
// app/Support/Customers/PhoneNumber.php
final readonly class PhoneNumber {
    public static function parse(string $raw): self;              // throws InvalidPhoneNumber (reason: 'foreign'|'format')
    public function e164(): string;                                // '+584141234567'
    public function display(): string;                             // '0414-123-4567'
    public function isMobile(): bool;                              // NSN starts with 4
    public function isLandline(): bool;                            // NSN starts with 2
    public static function searchFragment(string $term): ?string;  // digits to LIKE, or null
}

// app/Support/Customers/DocumentNumber.php
final class DocumentNumber {
    public static function normalize(DocumentType $type, string $raw): string;
    public static function isValid(DocumentType $type, string $normalized): bool; // format + RIF check digit
    public static function rifCheckDigit(string $letter, string $eightDigits): int;
    public static function display(DocumentType $type, string $normalized): string;
}

// app/Support/Customers/CustomerRules.php
final class CustomerRules {
    /** @return array<string, list<mixed>> */
    public static function customer(?Customer $ignoring = null): array; // all fields incl. contact.*, address.*
    /** @return list<Closure(Validator): void> */
    public static function after(): array;                              // day+month combinations (29 Feb valid)
}

// app/Actions/Customers
CreateCustomer::handle(array $data, User $actor, bool $confirmDuplicatePhone = false, ?AuditOrigin $origin = null,
                       bool $autoAssign = true, ?User $advisor = null, array $auditContext = []): Customer;
UpdateCustomer::handle(Customer $customer, array $data, User $actor, bool $confirmDuplicatePhone = false): Customer;
DeactivateCustomer::handle(Customer $customer, User $actor): Customer;
ActivateCustomer::handle(Customer $customer, User $actor): Customer;
DeleteCustomer::handle(Customer $customer, User $actor): void;
AssignCustomerAdvisor::handle(Customer $customer, ?User $advisor, User $actor): Customer;
ImportCustomers::handle(string $path, string $authorEmail, bool $confirmDuplicatePhones): ImportReport; // readonly result: created, errors, warnings, reason

// app/Models/User.php (additions)
public function isEligibleAdvisor(): bool;              // is_active && hasPermission(CustomersPortfolio)
public function scopeEligibleAdvisors(Builder $query): void;
```

### Validation contract (field → rules → error key)

| Field | Rules | Error key used by tests |
|---|---|---|
| `type` | required, enum `CustomerType` | `type` |
| `name` | required, string, max 200 | `name` (E-02) |
| `document_type` | nullable, `required_with:document_number`, enum, allowed for submitted `type` | `document_type` (E-24, E-35) |
| `document_number` | nullable, `required_with:document_type`, string, max 30, `DocumentNumberFormat`, `uniqueDocument` | `document_number` (E-13, E-34, E-36) |
| `phone` | required, string, max 30, `VenezuelanPhone(allowLandline: false)` | `phone` (E-02, E-37) |
| `email` | nullable, email, max 255 | `email` |
| `birthday_day` / `birthday_month` | nullable, integer 1–31 / 1–12, `required_with` each other; combination checked in `after()` with `checkdate($m, $d, 2000)` (leap year → 29 Feb valid) | `birthday_day` (E-21) |
| `anniversary_day` / `anniversary_month` | same + `prohibited_unless:type,company` | `anniversary_day` (E-21, E-22) |
| `notes` | nullable, string, max 5000 | `notes` |
| `contact` | nullable, array, `prohibited_unless:type,company` | `contact` (E-16) |
| `contact.name` / `contact.phone` | `required_with:contact`; max 150 / `VenezuelanPhone(allowLandline: true)` | `contact.phone` (E-38) |
| `contact.position` / `contact.email` | nullable; max 100 / email max 255 | — |
| `address` | nullable, array | — |
| `address.line` / `.city` / `.state` | `required_with:address`; max 255 / 100; `.state` `Rule::enum(VenezuelanState)` (DEC-CLI-33) | `address.*` |
| `address.reference` | nullable, max 255 | — |
| `confirm_duplicate_phone` | sometimes, boolean | `confirm_duplicate_phone` (E-14, warning) |

### Routes and authorization

| Method | URI | Name | Authorization |
|---|---|---|---|
| GET | `/customers` | `customers.index` | `customers.view` |
| GET | `/customers/create` | `customers.create` | `customers.create` |
| POST | `/customers` | `customers.store` | `customers.create` |
| GET | `/customers/{customer}` | `customers.show` | `customers.view` |
| GET | `/customers/{customer}/edit` | `customers.edit` | `customers.update` |
| PUT | `/customers/{customer}` | `customers.update` | `customers.update` |
| DELETE | `/customers/{customer}` | `customers.destroy` | `customers.delete` |
| POST | `/customers/{customer}/activate` | `customers.activate` | `customers.deactivate` |
| POST | `/customers/{customer}/deactivate` | `customers.deactivate` | `customers.deactivate` |
| PUT | `/customers/{customer}/advisor` | `customers.advisor.update` | `customers.assign` |

All routes are protected by default (`RequireAuthentication`); none is added to `PublicRoutes`. Console (outside HTTP): `customers:import`, restricted by shell access and by its own author check.

### Audit payloads

| Action | `old_values` | `new_values` | `context` |
|---|---|---|---|
| `customers.created` | — | all scalar fields (`type`, `name`, `document_type`, `document_number`, `phone`, `email`, dates, `notes`, `status`, `advisor_id`, `advisor_name`), `contact` object or `null`, `address` object or `null` | import only: `import: true`, `import_row`, plus console origin |
| `customers.updated` | changed scalar fields only; `contact` / `address` full previous object (or `null`) only when any of their fields changed, was created or was removed | same keys, new values | — |
| `customers.deactivated` / `customers.activated` | `{status}` | `{status}` | — |
| `customers.deleted` | full copy: all scalar fields + `created_by` + `contact` + `address` | — | — |
| `customers.advisor_assigned` | `{advisor_id, advisor_name}` (nulls when none) | `{advisor_id, advisor_name}` | — |

Phones in audit values are E.164, documents canonical. The entity is always the `Customer` (`entity_type`/`entity_id`), shown as `Customer #id` in the 001 audit screen.

### Inertia props (summary)

- `customers/Index`: `status`, `q`, `mine`, `canFilterMine`, `counts{active, inactive, all}`, `customers` (paginated rows, Decision 13).
- `customers/Show`: `customer` (detail DTO: display strings for document, phones, dates `"29 de febrero"`, `created_at`; `contact`, `address`, `advisor{id, name, available}`), `advisorOptions` (only when `assign` is allowed).
- `customers/Create`: `documentTypes` (grouped by customer type, from `DocumentType::allowedFor()`), `customerTypes`.
- `customers/Edit`: the same plus `customer` (form DTO with raw editable values: phone as display string, document number canonical).

## Testing Strategy

Test infrastructure: Pest on the MySQL `testing` database with `RefreshDatabase`; `TestCase` seeds `FoundationSeeder`; authorization tests call HTTP endpoints; every scenario test name starts with its ID (`it('E-14 …')`); decision-only tests start with the decision ID (`DEC-CLI-11 …`, `DEC-CLI-27 …`). Strict TDD: each test is written RED before its implementation.

| Layer | What to test | Approach |
|---|---|---|
| Unit | `PhoneNumber` (local, spaced, `+58`, `0058`, `58…`, no-trunk forms → same E.164; foreign; landline/mobile; `display`; `searchFragment`), `DocumentNumber` (normalization incl. lowercase letter and separators, cédula 5/6/9/10 digits, passport 4/5/20/21 chars, synthetic valid/invalid RIFs for each letter), enums (`allowedFor`, labels), catalog/audit enum lists | Plain Pest in `tests/Unit/Customers/` and the updated 001 unit tests |
| Integration (HTTP) | Every E-01..E-41 except E-19 and the console scenarios; seeding matrix; coherence | Pest feature tests, `assertInertia`, `assertJsonValidationErrors`, DB and audit assertions |
| Integration (Console) | E-29..E-32, E-39 | `$this->artisan('customers:import', …)` with runtime-generated fictitious CSV files (UTF-8, UTF-8 with BOM, `;` delimiter, Latin-1 via `mb_convert_encoding` for E-39) |
| Architecture | No `abort(403)`; every route protected | Existing `NoManualForbiddenTest`, `ProtectedByDefaultTest` (they cover the new routes automatically) |
| E2E | — | Not available; frontend verified by `pnpm types:check`, `pnpm check`, `pnpm build` and the design-system §11 checklist at 375/768/1280 px |

Scenario → test file map:

| Scenario / decision | Test file |
|---|---|
| DEC-CLI-11 (fresh DB = matrix; existing 001 DB gets the matrix for the 7 new permissions without touching 001 grants; a revoked `customers.*` grant is not re-granted on re-run; a renamed role is skipped) | `tests/Feature/Customers/PermissionMatrixSeedingTest.php` |
| E-41 (each dependent permission without `customers.view` → 422 on `permissions`, role unchanged, no audit; with `customers.view` → accepted) | `tests/Feature/Customers/CustomerPermissionCoherenceTest.php` |
| E-01, E-02, E-03, E-06, E-25, E-34, E-35, E-36, E-37 | `tests/Feature/Customers/CreateCustomerTest.php` |
| E-13 (create and edit), E-14 (all four clauses), DEC-CLI-27 (contact phones never compared) | `tests/Feature/Customers/DuplicateDetectionTest.php` |
| E-05, E-16, E-17, E-23, E-24, E-38 | `tests/Feature/Customers/UpdateCustomerTest.php` |
| E-20, E-21, E-22 (incl. 29 February valid, 31 April invalid) | `tests/Feature/Customers/CommemorativeDatesTest.php` |
| E-04 (list), E-11, E-12, E-15 | `tests/Feature/Customers/CustomerListTest.php` |
| E-04 (detail), E-33 (detail and list flag), CLI-013 sections | `tests/Feature/Customers/CustomerDetailTest.php` |
| E-07, E-08, E-09, E-40 | `tests/Feature/Customers/CustomerStatusTest.php` |
| E-10, E-18, E-19 (`->todo`, deferred to 004/006) | `tests/Feature/Customers/DeleteCustomerTest.php` |
| E-26, E-27, E-28, unassign | `tests/Feature/Customers/AdvisorAssignmentTest.php` |
| E-29, E-30, E-31, E-32, E-39 | `tests/Feature/Console/ImportCustomersTest.php` |

Non-obvious test designs:
- **E-06**: post `0414-123.45.67`; assert DB `phone = '+584141234567'`; `GET /customers/{id}` props show `0414-123-4567`.
- **E-14**: (1) create with an existing phone → JSON 422, `errors.confirm_duplicate_phone`, `duplicate_phone_matches.0.id` = existing id, customer count unchanged; (2) same payload with `confirm_duplicate_phone = true` → created; (3) edit another customer to that phone → same warning, saved only with confirmation; (4) edit notes of a customer whose phone already matches another → saved, no warning. A contact person's phone equal to the submitted phone produces no warning (DEC-CLI-27).
- **E-23**: company with anniversary and contact → `PUT` type `natural` without contact/anniversary → anniversary columns null, contact row gone, one `customers.updated` row whose `old_values` hold `type`, the anniversary and the contact object.
- **E-24**: company with a valid RIF J → `PUT` type `natural`, same document → 422 on `document_type`; the row, contact and anniversary are unchanged; no audit row.
- **E-33**: assign to an eligible advisor, then deactivate that user (or revoke `customers.portfolio`) → `advisor_id` unchanged; Show and Index props `advisor.available = false`.
- **E-36**: create with `J-12345678-4`; then create with `j.12345678 4` → 422 on `document_number`; DB value `J123456784`.
- **E-40**: an inactive customer → `POST …/deactivate` → still inactive, zero `customers.deactivated` rows. Mirror case for activate.
- **E-10**: company with contact and address → `DELETE` → three rows gone; `customers.deleted` `old_values` contain the customer fields, `contact` and `address`.
- **E-31**: DB has a customer with phone X; CSV has X on one row and Y twice → exit 1, report lists the three warnings, zero customers created; re-run with `--confirm-duplicate-phones` → all rows created.
- **E-29**: each created customer has `created_by = author`, one `customers.created` row with `actor_id = author`, `ip_address = null`, `context.import = true`, `context.source = 'console'`; the row with `correo_asesora` has that advisor; rows without it have none, even if the author holds `customers.portfolio`.
- **E-39**: (a) a Latin-1 file with `ñ` → reason "no está codificado en UTF-8"; (b) a header without `telefono` → reason lists the missing column; zero customers in both.

## Threat Matrix

The change adds HTTP routes (protected by default, already enforced by the 001 E-30 route-enumeration test) and a console command that reads one local file chosen by the operator. It has no shell, subprocess, VCS/PR automation, executable-file classification or process-integration boundary.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no file classification or execution; the import only reads a CSV as data | — | — |
| Git repository selection | N/A: no VCS automation | — | — |
| Commit state | N/A: no VCS automation | — | — |
| Push state | N/A: no VCS automation | — | — |
| PR commands | N/A: no PR automation | — | — |

## Migration / Rollout

1. **Migration** (new, additive, with `down()` dropping the three tables in reverse order): `2026_09_30_000001_create_customers_tables`. Locally `./vendor/bin/sail artisan migrate`; rollback `./vendor/bin/sail artisan migrate:rollback --step=1`. No 001 migration is edited.
2. **Seeding** (every environment, same deploy step as 001): `php artisan db:seed --class=FoundationSeeder --force`. On the first run after deploy it creates the 7 `customers.*` permissions and grants the §4 matrix; later runs grant nothing new. Rolling the permissions back requires detaching their `permission_role` rows first (that FK is restrict), then deleting the permission rows.
3. **Go-live import (once)**: create and activate the advisor users with `customers.portfolio` and the author user first; place the real file in `storage/app/private/imports/` or outside the repository; run `php artisan customers:import storage/app/private/imports/<file>.csv --author=<email>`; review the report; fix and re-run until clean; add `--confirm-duplicate-phones` only after reviewing the warnings. A failed run leaves the database unchanged. Delete the file afterwards.
4. **No feature flags.**

### PR slice outline (for `sdd-tasks`; delivery strategy `ask-on-risk`, advisory budget ~400 authored lines)

Estimated total ~3,300–3,700 authored lines. Slices are ordered so each one is green on its own (`ensure_pages_exist = true`: a slice ships every page its tests render). The chain strategy (`stacked-to-main` or `feature-branch-chain`) is the user's decision before apply.

| # | Slice | Scenarios / decisions | Depends on | Est. lines |
|---|---|---|---|---|
| 1 | Permissions: 7 `PermissionName` cases, `InitialRolePermissions` + `FoundationSeeder`/`RoleSeeder` changes, DEC-CLI-32 in `SyncRolePermissions`, 6 `AuditAction` cases, 001 test updates (Decision 16) | DEC-CLI-11, E-41 | — | 300–380 |
| 2 | Domain core: migration, 3 enums, 3 models + factories, `PhoneNumber`, `DocumentNumber`, `VenezuelanPhone`, `DocumentNumberFormat`, `CustomerRules`, `User` eligibility helpers, unit tests | DT-01, E-06/E-34/E-36/E-37 at unit level | 1 | 400–450 |
| 3 | Create backend: `CustomerPolicy`, store route, `StoreCustomerRequest`, `CreateCustomer`, `DuplicatePhoneWarning` + renderer | E-01, E-02, E-03, E-06, E-13 (create), E-14 (create clauses), E-25, E-34, E-35, E-36, E-37 | 2 | 400–450 |
| 4 | Update backend: update route, `UpdateCustomerRequest`, `UpdateCustomer` (type change, contact/address sync, dates) | E-05, E-13 (edit), E-14 (edit clauses), E-16, E-17, E-20, E-21, E-22, E-23, E-24, E-38 | 3 | 400–450 |
| 5 | List and detail: index/show, `CustomerPresenter`, `Index.vue`, `Show.vue` (read-only sections), `AppInput` props, navigation entry | E-04, E-11, E-12, E-15, E-33 (flag) | 4 | 400–450 |
| 6 | Forms UI: `Create.vue`, `Edit.vue`, `CustomerForm.vue`, `AppTextarea.vue`, duplicate-phone panel, type-change warning, flash type; GET create/edit authorization tests | E-03/E-04 on the form routes; UI for E-14, E-23 | 5 | 400–450 |
| 7 | Lifecycle: activate/deactivate/delete Actions, controllers, routes, Show actions with `ConfirmDialog` | E-07, E-08, E-09, E-10, E-18, E-19 (todo), E-40 | 5 | 280–330 |
| 8 | Advisor assignment: `AssignCustomerAdvisor`, request, route, Show assign control and options | E-26, E-27, E-28, E-33 (after deactivation) | 5 | 250–300 |
| 9 | Import: `CustomerCsvReader`, `ImportCustomers`, `customers:import` | E-29, E-30, E-31, E-32, E-39 | 3 | 400–450 |

Slices 7, 8 and 9 are independent of each other; 9 needs only slice 3 (the create Action). Slices 3 and 4 are the most likely to exceed the advisory budget; `sdd-tasks` may split each into "Action + tests" and "request/route wiring" if needed.

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

## Open Questions

No blocking question: every business behavior used above is `Confirmada` in spec 002, and the remaining choices are technical.

Non-blocking, for the user (the design implements the literal spec meanwhile):
- [x] **Address "estado" (CLI-006, DEC-CLI-17)**: resolved by DEC-CLI-33 (2026-09-30): closed list of the 24 Venezuelan federal entities. Implemented as a string-backed `VenezuelanState` enum (Spanish labels) validated with `Rule::enum`; `customer_addresses.state` stores the enum value; the import command maps the `direccion_estado` column to the enum by case- and accent-insensitive label match and reports a row error otherwise. City stays free text.

Technical follow-ups (apply phase):
- [ ] Confirm that `storage/app/private/.gitignore` ignores everything (`*`) in this repository before documenting it as the import location.
- [ ] Confirm the Laravel 13 `UniqueConstraintViolationException` class is thrown for the document index race on MySQL 8.4 (used by Decision 5).
- [ ] `openspec/config.yaml` says PHP 8.3 while `composer.json` requires ^8.4 (documentation mismatch noted by the proposal; `composer.json` governs).
