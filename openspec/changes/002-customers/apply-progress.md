# Apply Progress: 002-customers

Mode: Strict TDD. Runner: `./vendor/bin/sail artisan test` / `./vendor/bin/sail pest --filter=...`.
Delivery: 10 PRs (one per phase), `feature-branch-chain`, tracker `feat/002-customers`. No commits made (AGENTS.md section 11).

## Phase 1 (PR 1): Permissions, audit cases and seeding matrix — COMPLETE (9/9 tasks)

Branch: `feat/002-customers-1-permissions`. Changes left in the working tree.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-------|-------------|----------|
| 1.1/1.2 | `tests/Unit/PermissionNameTest.php`, `tests/Unit/AuditActionTest.php` | Unit | existing 6 tests | 5 failed + 1 error (undefined `CustomersView`) | 7/7 pass | descriptions of all 7 perms asserted; 24 values/labels | none needed |
| 1.3 | `FoundationSeederTest`, `Roles/PermissionCatalogTest`, `Roles/PermissionCoherenceTest`, `LastAdministratorTest`, `Pest.php` docblock | Feature | full suite 238 pre-change green | PermissionCatalogTest failed (Administrador held `customers.portfolio` under old RoleSeeder) | pass after 1.5 | matrix per role asserted | none |
| 1.4/1.5 | `tests/Feature/Customers/PermissionMatrixSeedingTest.php` | Feature | n/a (new) | 4 failed + 1 error (class `InitialRolePermissions` missing) | 5/5 pass | fresh DB, 001 DB, no re-grant, renamed role, missing-role warning | none |
| 1.6/1.7 | `tests/Feature/Customers/CustomerPermissionCoherenceTest.php` | Feature (HTTP) | `PermissionCoherenceTest` green | 9 failed (302 instead of 422), 2 valid-path tests passed as expected | 11/11 pass | 6 dependents, existing-set replace, both-rules-in-one-422, valid set | two rule blocks left as-is (no helper worthwhile) |
| 1.8 | `NoManualForbiddenTest` | Feature | - | - | passes in full suite | - | - |
| 1.9 | all gates | - | - | - | see below | - | - |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test | `sail pest --filter="E-41\|DEC-CLI-32\|DEC-CLI-11"`: 17 passed, 103 assertions |
| Runtime harness | `migrate:fresh --seed` on the `testing` database (not the dev DB): `Permission::count()` = 16, `permission_role` rows = 25 (15 Administrador + 5 Gerente + 4 Asesora + 1 Finanzas) |
| Rollback boundary | `PermissionName`, `AuditAction`, `InitialRolePermissions`, `FoundationSeeder`, `RoleSeeder`, `PermissionCatalogSeeder` docblock, `SyncRolePermissions` rule, the 001 test edits and the new Customers tests together |

### Slice-close gate (observed)
- `sail artisan test`: 238 passed, 1393 assertions
- `sail pint --test`: passed
- `sail composer types:check`: phpstan passed, 0 errors
- `sail pnpm types:check`: vue-tsc clean
- `sail pnpm build`: built OK

### Deviations
- Design Decision 16 missed two literals: `tests/Feature/Roles/RoleManagementTest.php:151,:173` (`has(..., 9)`). Fixed and added to the Decision 16 table in `design.md`.
- `InitialRolePermissions::apply()` is static and takes a third optional `?Closure $warn`; `FoundationSeeder` passes `Laravel\Prompts\warning` (using `$this->command` fails PHPStan: non-nullable in Laravel's docblock).
- Real authored diff about 536 lines (201 tracked changes + 335 new files), mostly tests: over the ~450 threshold, `size:exception` recommended for PR 1.

### Review follow-up (R3-001, R3-002)

- R3-001: `FoundationSeeder::run()` now wraps snapshot + `PermissionCatalogSeeder` + `RoleSeeder` + `InitialRolePermissions::apply()` in one `DB::transaction`. RED: new test (failure injected via `Role::retrieved` inside `apply()`) failed with "16 is identical to 0" (permissions not rolled back). GREEN: nothing persists after the failure and the retry grants the full matrix (Administrador 15).
- R3-002: `RoleSeeder` docblock states it must run only through `FoundationSeeder`; new test scans `database/seeders/` and fails if any seeder other than `FoundationSeeder`/`RoleSeeder` references `RoleSeeder`. No runtime change.
- Gate: `sail artisan test` 240 passed (1407 assertions); pint, pint --test, `composer types:check` clean.

## Phase 2 (PR 2): Domain core — COMPLETE (16/16 tasks, units 2a + 2b)

Branch: `feat/002-customers-2-domain` (from `feat/002-customers-1-permissions` at 5b3c108). Changes left in the working tree; no commit made.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-------|-------------|----------|
| 2.1/2.2 | `tests/Unit/Customers/EnumsTest.php` | Unit | n/a (new) | 9 errors (classes not found) | 9/9 pass (134 assertions) | 24 states, unique values/labels, accent/case folding, round trip, unknown text | none needed |
| 2.3/2.4 | `tests/Feature/Customers/CustomerSchemaTest.php` | Feature | n/a (new) | 7 errors (`testing.customers` missing) | 7/7 pass (26 assertions) | unique pair, many NULL pairs, 1:1 contact/address, cascade, restrict FKs, casts/relations, scopes | `migrate:rollback --step=1` then `migrate` round-trips on `testing` DB |
| 2.5 | `tests/Feature/Customers/CustomerFactoryTest.php` | Feature | n/a (new) | 4 errors (factory missing) | 4/4 pass | default, company/inactive, contact+address, assignedTo | none |
| 2.8/2.9 | `tests/Unit/Customers/PhoneNumberTest.php` | Unit | n/a (new) | 38 errors (class missing) | 37/37 pass (44 assertions) | 9 accepted forms, landline, 3 foreign, 7 format failures, mobile/landline, display, 7 fragments, 7 null fragments | none |
| 2.10/2.11 | `tests/Unit/Customers/DocumentNumberTest.php` | Unit | n/a (new) | 42 errors (class missing) | 42/42 pass (64 assertions) | normalization x7, wrong letter, cédula 5/6/9/10, passport 4/5/20/21, check digit x7 (J G V E P, raw 10 and 11 to 0), RIF wrong digit, display | none |
| 2.12/2.13 | `tests/Unit/Customers/CustomerRulesTest.php` | Unit (boots app + DB) | n/a (new) | 38 errors (`CustomerRules` missing) | 38/38 pass (83 assertions) | phone x4 messages, contact phone, doc format/type/unique/pair, dates (29 Feb, 31 Apr, 30 Feb), half dates, prohibited, 24 states + 3 rejected, notes 5000/5001 | none |
| 2.14 | `tests/Unit/Customers/AdvisorEligibilityTest.php` | Unit (boots app + DB) | n/a (new) | 6 errors (`isEligibleAdvisor` undefined) | 6/6 pass (12 assertions) | active/inactive/no permission/no role, same set, two roles listed once, deactivation | none |
| 2.14 (factory) | `tests/Feature/Customers/CustomerFactoryTest.php` | Feature | 4/4 | 10 errors (`withDocument` undefined) | 14/14 pass (46 assertions) | cédula/RIF defaults, all 8 types canonical + valid, 12 unique documents | none |
| 2.6/2.15 | - | - | - | - | `composer types:check` 0 errors | - | digit stripping not shared (different separator sets): no helper extracted |

### Work Unit Evidence

| Evidence | Unit 2a | Unit 2b |
|---|---|---|
| Focused test | `sail pest tests/Unit/Customers/EnumsTest.php`: 9 passed; `CustomerSchemaTest`: 7 passed; `CustomerFactoryTest`: 14 passed | `sail pest tests/Unit/Customers`: all green (Phone 37, Document 42, Rules 38, Eligibility 6) |
| Runtime harness | `migrate:rollback --step=1` then `migrate` on the `testing` DB (`DB_DATABASE=testing`): both DONE; status shows the migration Ran | N/A: pure domain classes covered by unit tests |
| Rollback boundary | migration, 4 enums, 3 models, 3 factories, `CustomerSchemaTest`, `EnumsTest`, `CustomerFactoryTest` (the `withDocument` hunk needs `DocumentNumber` from 2b) | `app/Support/Customers/*`, `app/Rules/*`, `User` additions, `lang/es/validation.php` additions, 4 unit test files |

### Slice-close gate (observed)
- `sail artisan test`: 393 passed, 1816 assertions
- `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors
- `sail pnpm types:check`: vue-tsc clean
- `sail pnpm build`: built OK

### Deviations / decisions
- `Customer::scopeSearch` is NOT created in 2.4: nothing in Phase 2 tests it and it needs `PhoneNumber::searchFragment` plus E-11 tests; it belongs to task 5.2 (already listed there). `withStatus` and `assignedTo` exist and are tested.
- Document-type vs customer-type compatibility (E-24, E-35) is reported on `document_type` by a closure in `CustomerRules::after()`, not by `DocumentNumberFormat` (a rule on `document_number` cannot fail another attribute). `DocumentNumberFormat` still reads `document_type` and validates format only. Error key and behavior are as the spec requires. Phases 3-4 must register `CustomerRules::after()` on the FormRequests, as already planned.
- `CustomerRules::uniqueDocument()` is an anonymous `DataAwareRule` (needs `document_type` to normalize the number).
- Added `DocumentType::isRif()` (used by `DocumentNumber` and the factory).
- `PhoneNumber::searchFragment` requires at least 3 digits AFTER stripping the leading `+58`/`0058`/`0` (design left the order ambiguous; this avoids a near-match-everything fragment such as `04`).
- Added a `withDocument(?DocumentType)` factory state (opt-in; default documents stay null so tests control uniqueness). Call it after `company()` so the type resolves to RIF J.
- `VenezuelanState` values are snake_case ASCII slugs (e.g. `distrito_capital`, `la_guaira`); "La Guaira" is the current official name of the former Vargas state.
- Confirmed the technical follow-up: Laravel v13.33.0 on MySQL 8.4 throws `Illuminate\Database\UniqueConstraintViolationException` for the unique-index violation (asserted in `CustomerSchemaTest`).
- Real authored diff about 2,050 lines (68 tracked + 1,981 new files; tests about 855, production about 1,126), mostly tests and dataset-heavy: far above the ~450 threshold. `size:exception` recommended for PR 2; units 2a (about 950) and 2b (about 1,100) are the honest split.

## Review follow-up Phase 2 (R3-empty-nested-array, R3-plus58-trunk-zero, R3-revocation-untested)

Scoped fixes after the native review of 6b3e460 + fd69109.

| Finding | RED | GREEN | Change |
|---|---|---|---|
| R3-empty-nested-array | 4 failures (`contact: []` / `address: []` passed validation for company and natural) | 92/92 in the 3 touched files | Decision: an empty array is REJECTED (422 on `contact` / `address`), not normalized to null. `null`/absent still means "none" and an object upserts, so deleting stays explicit and an accidental `[]` cannot silently remove data. Implemented as `CustomerRules::notEmptyArray()` plus `validation.not_empty_array` message. (`required_array_keys` and `filled` were tried and discarded: duplicate parent+child errors, and `filled` rejects `null`.) |
| R3-plus58-trunk-zero | 3 errors (`+58 0414…`, `+58 0212…`, `0058 0414…` rejected as format) + 1 fragment failure (`0414123`) | green | `PhoneNumber::withoutTrunkZero()` drops a single `0` after `+58`/`0058` in `parse` and `searchFragment`. Foreign numbers still `foreign`; `+58 00414…` still `format`; customer landline rule untouched. |
| R3-revocation-untested | n/a | passed immediately (no production change) | New test detaches `customers.portfolio` from the role; `isEligibleAdvisor()` false and the user leaves `eligibleAdvisors()`. |

Verification: touched tests 92/92; `sail artisan test` 404 passed (1838 assertions); `sail pint --test` passed; `composer types:check` phpstan 0 errors. Authored diff about 88 lines (83 added, 5 removed).

## Phase 3 (PR 3): Create backend — COMPLETE (6/6 tasks, units 3a + 3b)

Branch: `feat/002-customers-3-create` (from `feat/002-customers-2-domain` at b895a00). Changes left in the working tree; no commit made. The run was interrupted twice (rate limit, timeout) and resumed; state was re-verified by running the scoped tests.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-------|-------------|----------|
| 3.1/3.2 | `tests/Feature/Customers/CreateCustomerTest.php` | Feature (HTTP) | `NoManualForbiddenTest`, Authorization tests 14/14 after | 46 tests, 0 passed (43 failed with 404, 3 errors: `CreateCustomer` missing) | 46/46 (194 assertions) | E-01 min and full company, E-02 x5, E-03 x2, E-06 x4 forms, E-13 x4 (active/inactive/other type/race), E-25 x3, E-34 x10, E-35 x2, E-36, E-37 x3, E-38, CLI-007 x2, empty `{}`/`[]` contact and address with resolved Spanish message, natural + contact, ignored client fields | none needed |
| 3.2 (policy) | `tests/Feature/Customers/CustomerPolicyTest.php` | Feature | n/a (new) | 22 tests: 8 failed (no policy: every allow failed) | 22/22 | 7 abilities allow, deny with every other permission, deny without roles, no role-name grant | none |
| 3.4/3.5 | `tests/Feature/Customers/DuplicateDetectionTest.php` | Feature (HTTP) | 46/46 + 22/22 | 10 tests: 6 failed (422 expected, 302 received; Inertia back vs redirect), 4 passed by design (no-warning and confirm clauses) | 10/10 (51 assertions) | document display/null, inactive matches, name order, explicit false, no contact/address written, validation wins, Inertia flash, DEC-CLI-27 both directions | PHPStan list type fixed with `array_values` |
| 3.3 | `NoManualForbiddenTest`, `ProtectedByDefaultTest` | Architecture | - | - | pass in the full suite | - | controller kept thin |

RED evidence was captured for all three test files in this run. Not captured: a RED run of the tests that passed immediately (DEC-CLI-27 no-warning, validation-wins and confirm=true clauses and the "deny" half of the policy test): they are regression guards that pass before the production code by construction, noted as such.

### Work Unit Evidence

| Evidence | Unit 3a | Unit 3b |
|---|---|---|
| Focused test | `CreateCustomerTest` 46 passed; `CustomerPolicyTest` 22 passed | `DuplicateDetectionTest` 10 passed |
| Runtime harness | `sail artisan route:list --name=customers.store`: `POST customers customers.store › Customers\CustomerController@store` | N/A: HTTP feature tests |
| Rollback boundary | policy, request, `CreateCustomer` (minus phone check), controller `store`, route, `User::fullName()`, `tests/Pest.php` helper, 2 test files | `DuplicatePhoneWarning`, `RenderDuplicatePhoneWarning`, `bootstrap/app.php` hook, phone check and `confirmDuplicatePhone` argument in `CreateCustomer`/controller, `DuplicateDetectionTest` |

### Slice-close gate (observed)
- `sail artisan test`: 482 passed, 2108 assertions
- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors
- `sail pnpm types:check`: vue-tsc clean
- `sail pnpm build`: built OK

### Deviations / decisions
- `CreateCustomer::handle()` has only `(array $data, User $actor, bool $confirmDuplicatePhone = false)`. The Phase 9 parameters (`origin`, `autoAssign`, `advisor`, `auditContext`) are NOT added: no test needs them yet (Strict TDD); task 9.4 adds them with their tests.
- Success redirect uses the URL `/customers/{id}` instead of `route('customers.show')`: the named route arrives with 5.6. Tests assert the URL; swap to `route()` in 5.6.
- Added `User::fullName()` (used for `advisor_name` in the audit; Phase 8 reuses it) and moved the `createCustomerPayload()` test helper to `tests/Pest.php` so the test files run alone.
- Added `CustomerPolicyTest` (not listed in tasks): all 7 abilities map to one permission each.
- Duplicate-phone match `document` is the display string (`J-12345678-4`) or null.
- The unique-index race is caught outside the transaction and only converted when the message names `customers_document_unique`.
- Duplicate-phone finder lives as a private method of `CreateCustomer`; extraction for `UpdateCustomer` is a 4.3 decision.
- Authored diff about 1,075 lines (3a about 790, 3b about 285), mostly tests: `size:exception` recommended for PR 3.

## Phase 4 (PR 4): Update backend — COMPLETE (6/6 tasks, units 4a + 4b)

Branch: `feat/002-customers-4-update` (from `feat/002-customers-3-create` at c26a695). Changes left in the working tree; no commit made.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-----|-----|-----|
| 4.1 (a) create-side rethrow | `CreateCustomerTest` | Feature | `tests/Feature/Customers` 117/117 | PASSED IMMEDIATELY by construction (backfill of Phase 3 behavior, as the task states) | 1/1 | mocked audit throws a unique violation on another index | none |
| 4.1/4.2 | `tests/Feature/Customers/UpdateCustomerTest.php` | Feature (HTTP + Action) | 117/117 | 23 tests, 0 passed (21 x 404, 2 errors: `UpdateCustomer` missing; the update-side rethrow test failed with `BindingResolutionException`) | 23/23 (111 assertions) | E-05 x3 (phone diff, no-change, multi-field diff), E-13 x4 (other doc, own doc in other form, race, rethrow), E-16 x3, E-17 x2, E-23 x2, E-24 x2, E-38, CLI-007, E-03, CLI-008 x2 (+3 mandatory fields) | 4.3 below |
| 4.3 | - | - | 141/141 Customers suite | - | green after extraction | - | duplicate-phone finder, document normalization and unique-index race conversion moved to `Concerns\WritesCustomers` (trait), used by both Actions; Actions stay separate |
| 4.4 (dates) | `tests/Feature/Customers/CommemorativeDatesTest.php` | Feature (HTTP) | - | PASSED IMMEDIATELY by construction (13/13): the rules from 2b and the wiring of 4a already cover E-20/E-21/E-22; no gap found in `CustomerRules` | 13/13 (56 assertions) | natural+company, null clears, 4 impossible dates, 29 Feb both dates, anniversary company vs natural, 4 half dates | none |
| 4.4/4.5 (phone) | `DuplicateDetectionTest.php` (extended) | Feature (HTTP) | 10/10 | 15 tests: 2 failed (E-14 edit warning: 302 instead of 422; Inertia flash redirect), 3 passed by construction (confirm=true, DEC-CLI-27 no-warning on unchanged phone, own/contact phones) | 15/15 (75 assertions) | change warns, confirm saves, Inertia flash, unchanged phone no warning, contact phones and self never match | none |

### Work Unit Evidence

| Evidence | Unit 4a | Unit 4b |
|---|---|---|
| Focused test | `sail pest tests/Feature/Customers/UpdateCustomerTest.php`: 23 passed | `CommemorativeDatesTest`: 13 passed; `DuplicateDetectionTest`: 15 passed |
| Runtime harness | `sail artisan route:list --name=customers.update`: `PUT customers/{customer} customers.update › Customers\CustomerController@update` | N/A: HTTP feature tests |
| Rollback boundary | `UpdateCustomer`, `UpdateCustomerRequest`, controller `update`, route, `Concerns\WritesCustomers` + the `CreateCustomer` edits that use it, `updateCustomerPayload()` in `tests/Pest.php`, `UpdateCustomerTest`, the create-side rethrow test | phone check and `confirmDuplicatePhone` argument in `UpdateCustomer`/controller, `CommemorativeDatesTest`, the `(edit)` tests in `DuplicateDetectionTest` |

### Slice-close gate (observed)
- `sail pest --filter="E-05|E-13|E-16|E-17|E-23|E-24|E-38|CLI-007|E-20|E-21|E-22|E-14"`: 83 passed (341 assertions)
- `sail artisan test`: 524 passed, 2303 assertions
- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors
- `sail pnpm types:check`: vue-tsc clean
- `sail pnpm build`: built OK

### Deviations / decisions
- `UpdateCustomer::handle(Customer, array, User, bool $confirmDuplicatePhone = false)` returns the row locked and reloaded (a fresh instance), not the instance passed in.
- 4a was built without the phone check and 4b added it, so the units are separable at the behavior level, but the hunks share `UpdateCustomer.php`, `CustomerController.php` (the `confirm_duplicate_phone` argument) and the new files: a clean per-unit commit needs `git add -p`. A single commit for the phase is recommended.
- Moved the shared helpers of `CreateCustomer` into a trait (`Concerns/WritesCustomers`) in the same step as writing `UpdateCustomer`, so there was no separate GREEN-with-duplication state; the Create tests stayed green (141/141).
- `updateCustomerPayload()` lives in `tests/Pest.php` so `DuplicateDetectionTest` runs alone.
- Missing `contact`/`address` keys in an update payload mean "none" (full-replace), per Decision 9; a natural customer's anniversary and contact are cleaned by the Action even if a caller sends them.
- `customers.show` is still a fixed URL in the redirect (task 5.6 swaps it).
- The Phase 3 rethrow tests mock `RecordAuditEvent` to throw a unique violation on a different index (no real second unique index is reachable from the Action).
- Authored diff about 1,050 lines (new files 841 plus about 158 added and 55 removed in tracked files), roughly 65% tests: `size:exception` recommended for PR 4 (4a about 700, 4b about 350).
