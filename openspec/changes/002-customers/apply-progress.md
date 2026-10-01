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

## Phase 5 (PR 5): List and detail — COMPLETE (8/8 tasks, units 5a + 5b)

Branch: `feat/002-customers-5-list` (from `feat/002-customers-4-update` at 5c0aed3). Changes left in the working tree; no commit made.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-------|-------------|----------|
| 5.1/5.2 | `tests/Feature/Customers/CustomerListTest.php` | Feature (HTTP + Inertia) | Customers suite green before | 30 tests, 0 passed (405: no `GET /customers` route, "Not a valid Inertia response") | 30/30 (273 assertions) | E-04 x2 (403, guest), E-11 x(name, accent/case, document x4 forms, phone x4 forms, under 3 digits, `%`/`_` escaping, blank), E-12 x(default + unknown/array status x3, inactive, all, counts, counts with `q`), E-15 x3 (no filter, mine + status + q + counts, no portfolio treats mine as off), CLI-011 x5 (pagination + query string, order, row DTO, nulls, Inactivo label) | none needed |
| 5.5/5.6 | `tests/Feature/Customers/CustomerDetailTest.php` (+ `E-33 (list)` added to `CustomerListTest`) | Feature (HTTP + Inertia) | 30/30 list | 17 failed of 47 (16 detail + E-33 list: 405 / no `available` key) | 47/47 (311 assertions); whole `tests/Feature/Customers` 206/206 | E-04 x2, 404, CLI-013 full DTO, CLI-013 nulls, inactive, `advisorOptions` absent, E-06 display, E-16, E-17, E-20 x3 dates, E-33 deactivation, E-33 revocation, E-33 eligible, E-33 list (3 advisors) | `route('customers.show')` swapped into store/update redirects; tests still assert the same URL and stay green |
| 5.3/5.4/5.7 (frontend) | none (no frontend test runner in the project) | - | `pnpm types:check` clean before | - | `pnpm types:check` clean, `pnpm build` OK | - | `AppInput` additions are optional props with `undefined` defaults, existing callers unchanged (full suite + types green) |

Not RED-captured: the two 5.5 tests that passed by construction once the route existed were still RED before it (all 405). The frontend has no automated tests (design Testing Strategy: E2E not available); it is verified by types, build and the pending human checklist.

### Work Unit Evidence

| Evidence | Unit 5a | Unit 5b |
|---|---|---|
| Focused test | `sail pest tests/Feature/Customers/CustomerListTest.php`: 31 passed | `sail pest tests/Feature/Customers/CustomerDetailTest.php`: 16 passed |
| Runtime harness | `sail pnpm build` OK (`Index` chunk built); browser check of `/customers` at 375/768/1280 px both themes: **PENDING for the human, not claimed** | `sail pnpm build` OK (`Show` chunk built); browser check of a customer page: **PENDING for the human, not claimed** |
| Rollback boundary | `index` action + `GET /customers`, `Customer::scopeSearch`, `CustomerPresenter::listRow`/`availableAdvisorIds`, `Index.vue`, `AppInput` props, navigation entry, list types, `CustomerListTest` | `show` action + `GET /customers/{customer}`, `CustomerPresenter::detail`, `Show.vue`, detail types, `CustomerDetailTest`, the `route('customers.show')` redirects, the `E-33 (list)` test |

### Slice-close gate (observed)
- `sail pest tests/Feature/Customers/CustomerListTest.php`: 31 passed
- `sail pest tests/Feature/Customers/CustomerDetailTest.php`: 16 passed
- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors (one `list<int>` error fixed with `array_values`)
- `sail pnpm types:check`: vue-tsc clean (after `sail artisan wayfinder:generate`)
- `sail pnpm build`: built OK
- `sail artisan test`: 571 passed, 2785 assertions (baseline 524 + 47 new)
- PENDING (human): 375/768/1280 px browser check, both themes, keyboard only, of `/customers` and a customer page (design-system section 11).

### Deviations / decisions
- Backend 5.1/5.2 and 5.5/5.6 were done before the frontend 5.3/5.7 (the task order interleaves them): the list row first carried `advisor {id, name}` and gained `available` with the 5.6 RED (`E-33 (list)`), so the frontend is written once against the final DTO. `Index.vue` and `Show.vue` existed as empty stubs between RED and GREEN only because `ensure_pages_exist = true` requires the file.
- `CustomerPresenter` is a final class with static methods (`listRow`, `detail`, `availableAdvisorIds`); availability is one `User::eligibleAdvisors()->whereIn('users.id', …)->pluck()` query per page (Decision 12).
- The detail DTO is the flat shape of the design's Inertia props summary (`contact` = contact person, `address`, `advisor`), plus `document_type_label`. The CLI-013 section order is therefore a property of `Show.vue` (general data, contact, contact person for companies only, address, notes, advisor), not of the prop key order; it is not assertable from the backend and was verified by reading the page. Anniversary is rendered in "Contacto" only for companies.
- `customers/Index` also sends `mine` (bool, already normalized by the backend: false when the actor lacks `customers.portfolio`) and `q` (trimmed, 100 chars max). The `Mis clientes` checkbox shows only when `canFilterMine` (backend) and `can('customers.portfolio')`.
- Search over document: a letter-prefixed term (`J-1234`) also matches by its digits; a term made only of separators is guarded so it does not become `%%`.
- Show has no action buttons (Phases 6-8) and no "Editar" link, because `customers.edit` does not exist yet.
- Phase 6 note: `GET /customers/create` must be declared BEFORE `GET /customers/{customer}` in `routes/web.php`.
- No [DEC-PENDIENTE] gaps found.
- Authored diff about 1,280 lines (164 added and 21 removed in tracked files, about 1,095 in new files; tests about 500, frontend about 500, production PHP about 220). Far above the 400 budget but cohesive: `size:exception` recommended for PR 5. Honest split: 5a list (about 740: list test 308, `Index.vue` 204, scope, controller, AppInput, navigation, types) and 5b detail (about 540: detail test 195, `Show.vue` 177, presenter detail, show action). The hunks share `CustomerController.php`, `CustomerPresenter.php`, `routes/web.php` and `types/customers.ts`, so a per-unit commit needs `git add -p`; a single phase commit is recommended.

### Proposed commits (Spanish descriptions, no attribution lines)
- `feat(002): listado de clientes con búsqueda, vistas por estado y filtro de cartera [CLI-011, CLI-015, E-04, E-11, E-12, E-15]`
- `feat(002): ficha de cliente con disponibilidad de la asesora [CLI-013, CLI-014, E-04, E-06, E-16, E-17, E-20, E-33]`

## Phase 6 (PR 6): Forms UI — COMPLETE (6/6 tasks, units 6a + 6b)

Branch: `feat/002-customers-6-forms` (from `feat/002-customers-5-list` at ffc551b). Changes left in the working tree; no commit made.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-------|-------------|----------|
| 6.1/6.2 | `tests/Feature/Customers/CreateCustomerTest.php` (4 new tests) | Feature (HTTP + Inertia) | Customers suite green (571 full suite) | 3 of 3 form tests failed with 404 (no `GET /customers/create`; `create` read as an id) | create form tests 3/3 after the create action; the `E-04 (form)` test stays red until the edit route of 6.5, then 4/4; whole file 51/51 (217 assertions) | E-03 forbidden (view + update only), guest redirect, full props (types, grouped document types, 24 states, first state), E-04 create AND edit forbidden to a viewer | none needed |
| 6.4/6.5 | `tests/Feature/Customers/UpdateCustomerTest.php` (8 new tests) | Feature (HTTP + Inertia) | 23/23 before | 5 failed (404 on `GET /customers/{id}/edit`): E-03 edit x2, editable DTO, bare DTO, DEC-CLI-28 inactive | 31/31 (174 assertions) | forbidden, guest, full company DTO with contact and address, bare customer with `null` everywhere, inactive editable, 404 unknown id | none needed |
| 6.4 backfill | same file | Feature (HTTP) | - | PASSED IMMEDIATELY by construction (coverage backfill of Phase 4 behavior, as the task states): `PUT` omitting `address` deletes the stored address; companion test: sending the address keeps it | 2/2 | omitted vs sent | none |
| 6.2/6.5 (frontend) | none (no frontend test runner) | - | `pnpm types:check` clean before | - | `pnpm types:check` clean, `pnpm build` OK | - | 6.3: `CustomerForm.vue` reviewed, see below |

Not RED-captured: the 404 test for an unknown customer id in the edit form passed before the route existed (404 either way); it is a regression guard and became meaningful once the route resolved models. One test bug found and fixed during GREEN (not production): the guest assertion ran in the same test as `actingAs` (moved to its own test), and the "bare customer" DTO test needed `email`/`notes` explicitly null because the factory fills them.

### Work Unit Evidence

| Evidence | Unit 6a | Unit 6b |
|---|---|---|
| Focused test | `sail pest tests/Feature/Customers/CreateCustomerTest.php`: 51 passed | `sail pest tests/Feature/Customers/UpdateCustomerTest.php`: 31 passed |
| Runtime harness | `sail pnpm types:check` clean and `sail pnpm build` OK (`CustomerForm`, `Create` chunks built). Manual create and duplicate-phone flow at 375/1280 px, both themes, keyboard only: **PENDING for the human, not claimed** | same commands (`Edit` chunk built). Manual edit and type-change flow: **PENDING for the human, not claimed** |
| Rollback boundary | `create` action + `GET /customers/create`, `CustomerPresenter::formOptions`, `AppTextarea.vue`, `CustomerForm.vue`, `Create.vue`, form types in `customers.ts`, `DuplicatePhoneMatch` key in `global.d.ts`, the 4 create-form tests | `edit` action + `GET /customers/{customer}/edit`, `CustomerPresenter::editable`, `Edit.vue`, `CustomerEditable` type, the 8 edit-form tests; the type-change warning block lives inside `CustomerForm.vue` (shared file) |

### Slice-close gate (observed)
- `sail pest tests/Feature/Customers/CreateCustomerTest.php`: 51 passed (217 assertions)
- `sail pest tests/Feature/Customers/UpdateCustomerTest.php`: 31 passed (174 assertions)
- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors
- `sail pnpm types:check`: vue-tsc clean (after `sail artisan wayfinder:generate`)
- `sail pnpm build`: built OK
- `sail artisan test`: 583 passed, 2873 assertions (baseline 571 + 12 new)
- PENDING (human): 375/1280 px browser check, both themes, keyboard only, of create, duplicate-phone confirmation, edit and type-change flows (design-system section 11).

### R3-appinput-icon-padding-unverified (task 6.6)
Inspected the generated CSS of `sail pnpm build`: `.px-space-md{padding-inline:var(--spacing-space-md)}` is emitted at byte offset 28225 and `.pl-11{padding-left:calc(var(--spacing) * 11)}` at 29139 of the same stylesheet, in the same cascade layer and with equal specificity. The later rule wins for the left side, so `pl-11` overrides the left half of `padding-inline` in LTR and the leading icon does not overlap the text. No real conflict in the generated output; no change made to `AppInput.vue`. Caveat: the order is Tailwind's deterministic utility sort (`px-*` before `pl-*`), not something this repository controls, and a browser check is still PENDING for the human (the `/customers` search box with the icon).

### Deviations / decisions
- Create/Edit props carry a third list, `states` (id/label of the 24 `VenezuelanState` cases), beyond the design's `documentTypes` and `customerTypes`: task 6.2 requires the state select and the backend is the only source of the labels. Built by `CustomerPresenter::formOptions()` (shared by both pages).
- The edit DTO is `CustomerPresenter::editable()`: raw editable values, phones as display strings, document canonical, `contact` and `address` as objects or `null` (always present as keys).
- `CustomerForm.vue` owns the Inertia form (props: `action` Wayfinder definition, `options`, optional `customer`), so `Create.vue` and `Edit.vue` are thin wrappers and no page mutates a prop. Shaping done in the browser is presentation only: day/month selects, hiding company-only sections for natural, sending an all-blank contact or address group as `null` (the backend's "none" representation), and not sending anniversary/contact for a natural customer (DEC-CLI-15 payload clearing). It computes no phone format, document validity, duplicate detection or availability.
- Type-change warning (DEC-CLI-15): shown in `CustomerForm` only when a stored company is switched to natural and anniversary or contact person hold data; it lists those items and, when the stored document type no longer fits the new type (from the backend's `documentTypes` groups), says the document is NOT removed and must be changed or emptied. The stored document type stays visible in the select (appended option) so the backend can reject it on `document_type` (E-24).
- Duplicate-phone panel (E-14, Decision 10): driven by `form.errors.confirm_duplicate_phone` plus `page.flash.duplicatePhoneMatches`; matches link to `customers.show` in a new tab so the form is not lost; "Guardar de todos modos" sets `confirm_duplicate_phone = true` and resubmits; any change to the phone resets the flag and clears that error.
- The customer type has no default (`''` with a "Seleccione el tipo" placeholder): choosing a default would be an unrequested business choice.
- `AppInput.vue` unchanged (see the padding finding above). `AppTextarea.vue` mirrors the `AppInput` tokens, with `hint`, `error`, `rows`, `maxlength`.
- No "Editar" link on `Show.vue` and no "Nuevo cliente" button on `Index.vue` were added (see gap below); `GET /customers/create` is declared before `GET /customers/{customer}`.
- Process note: the two new tests of `CreateCustomerTest.php` were first appended with a shell heredoc by mistake (contrary to the edit-tools-only preference); content was reviewed and every later edit used Edit/Write.
- Authored diff about 950 lines (372 added in tracked files, about 581 in new files; tests about 185, frontend about 670, production PHP about 95). Over the 400 budget but cohesive: `size:exception` recommended for PR 6. Honest split: 6a create form (about 700: `CustomerForm.vue` 454, `AppTextarea` 70, `Create.vue` 23, types, presenter `formOptions`, create action, route, 4 tests) and 6b edit form (about 250: 8 tests 135, `Edit.vue` 34, presenter `editable`, edit action, route, `CustomerEditable`). The shared hunks (`CustomerController.php`, `CustomerPresenter.php`, `routes/web.php`, `customers.ts`) mean a per-unit commit needs `git add -p`; a single phase commit is recommended.

### Gaps for the orchestrator (not DEC-PENDIENTE)
- No task owns the entry points to the forms: `Index.vue` has no "Nuevo cliente" button (`customers.create`) and `Show.vue` has no "Editar" link (`customers.update`; task 7.5 owns it). Until then the forms are reachable only by URL, which limits the manual flow of 6.6. Suggest adding the "Nuevo cliente" button to `Index.vue` in 7.5 (or a small follow-up).
- No [DEC-PENDIENTE] gaps found.

### Proposed commits (Spanish descriptions, no attribution lines)
- `feat(002): formulario de alta de cliente con aviso de teléfono repetido y confirmación [CLI-001, E-03, E-04, E-14]`
- `feat(002): formulario de edición de cliente con advertencia de cambio de tipo [CLI-002, CLI-008, DEC-CLI-15, DEC-CLI-28, E-23]`
