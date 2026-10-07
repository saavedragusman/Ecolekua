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

## Phase 7 (PR 7): Lifecycle (deactivate, reactivate, delete) - COMPLETE (7/7 tasks)

Branch: `feat/002-customers-7-lifecycle` (from `feat/002-customers-6-forms` at 644eedb). Changes left in the working tree; no commit made. All files written with Edit/Write only.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-------|-------------|----------|
| 7.1/7.2 | `tests/Feature/Customers/CustomerStatusTest.php` (8 tests) | Feature (HTTP) | Customers suite green (583 full suite) | 7 of 8 failed with 404 (no routes); the DEC-CLI-28 "inactive stays editable" test passed immediately (backfill of Phase 4 behavior, as the task states) | 8/8 (40 assertions) | deactivate and activate, both forbidden with `customers.update` only, guest redirect, E-40 both directions, relations and advisor intact | none needed |
| 7.3/7.4 | `tests/Feature/Customers/DeleteCustomerTest.php` (4 tests + E-19 todo) | Feature (HTTP) | same | 4 of 4 failed with 405 (no `DELETE` route); E-19 shown as pending | 4 passed, 1 todo (39 assertions) | company with contact + address and full audit copy, bare customer (`contact`/`address` null in the copy), E-18 forbidden, guest | none needed |
| 7.5 (frontend) | none (no frontend test runner) | - | `pnpm types:check` clean before | - | `pnpm types:check` clean, `pnpm build` OK | - | - |
| 7.6 | `tests/Feature/Architecture/NoManualForbiddenTest.php` | Architecture | - | - | 1/1 passed | - | no `abort(403)` added; no FK from history tables assumed (rule documented in `DeleteCustomer` docblock) |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test | `sail pest tests/Feature/Customers/CustomerStatusTest.php`: 8 passed; `sail pest tests/Feature/Customers/DeleteCustomerTest.php`: 4 passed, 1 todo |
| Runtime harness | `sail pnpm types:check` clean and `sail pnpm build` OK. Manual flow (deactivate with dialog, reactivate, delete with dialog, "Nuevo cliente" button, permission-based visibility, 375/1280 px, both themes, keyboard only): **PENDING for the human, not claimed** |
| Rollback boundary | `DeactivateCustomer`, `ActivateCustomer`, `CustomerStatusController`, the two POST routes, `CustomerStatusTest`; `DeleteCustomer`, `CustomerController::destroy`, the DELETE route, `DeleteCustomerTest`; `Show.vue` buttons and dialogs; `Index.vue` "Nuevo cliente" button |

### Slice-close gate (observed)
- `sail pest tests/Feature/Customers/CustomerStatusTest.php`: 8 passed (40 assertions)
- `sail pest tests/Feature/Customers/DeleteCustomerTest.php`: 4 passed, 1 todo (39 assertions)
- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors
- `sail pnpm types:check`: vue-tsc clean (after `sail artisan wayfinder:generate`)
- `sail pnpm build`: built OK
- `sail artisan test`: 595 passed + 1 todo (E-19), 2958 assertions (baseline 583 + 12 new passing tests + 1 todo)
- PENDING (human): browser checks listed above.

### Deviations / decisions
- Both status Actions re-read the customer with `lockForUpdate()` inside the transaction and compare the locked status (no-op when already in the target state), so concurrent requests cannot double-audit.
- `DeleteCustomer` copy includes `advisor_id` and `advisor_name` (same shape as the `customers.created` audit values) besides the fields, `contact` and `address` the task lists; `new_values` stays empty. `ensureHasNoHistory()` is intentionally empty with the docblock the task requires.
- `Show.vue`: "Desactivar" is shown only when the customer is active and "Reactivar" only when inactive (both need `customers.deactivate`); "Editar" needs `customers.update`; "Eliminar" needs `customers.delete`. A history rejection (not reachable in 002) comes back through the existing `BusinessRuleViolation` hook as an error flash on the page, where "Desactivar" is already offered; no extra UI code was needed.
- Delete confirmation text also states that a customer with history cannot be deleted and must be deactivated.
- Review size: about 530 authored lines (tracked about 114, new files about 416; tests about 207, production PHP about 210, frontend about 100). Over the 400 budget but cohesive: `size:exception` recommended for PR 7. Honest split if wanted: 7a status (Actions, controller, routes, `CustomerStatusTest`, Show.vue activate/deactivate buttons, about 250), 7b delete (`DeleteCustomer`, `destroy`, route, `DeleteCustomerTest`, Show.vue delete button, about 200), 7c UI (`Index.vue` "Nuevo cliente" button and the Show.vue dialogs, about 80). Shared hunks in `routes/web.php`, `CustomerController.php` and `Show.vue` make a single phase commit the practical option.

### Gaps for the orchestrator
- No [DEC-PENDIENTE] gaps found.

### Proposed commit (Spanish description, no attribution lines)
- `feat(002): desactivar, reactivar y eliminar clientes sin historial, y botón "Nuevo cliente" en el listado [CLI-009, CLI-010, CLI-015, CLI-016, DEC-CLI-09, DEC-CLI-28, E-07, E-08, E-09, E-10, E-18, E-40]`

## Phase 8 (PR 8): Advisor assignment - COMPLETE (5/5 tasks)

Branch: `feat/002-customers-8-advisor` (from `feat/002-customers-7-lifecycle` at 049007e). Changes left in the working tree; no commit made. All files written with Edit/Write only.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-----------|-------------|----------|
| 8.1/8.2 | `tests/Feature/Customers/AdvisorAssignmentTest.php` (18 tests incl. 3 dataset cases) | Feature (HTTP) | 596 passed + 1 todo before | 16 of 17 failed (404 no route; 2 Show props missing `advisorOptions`); only the "no advisorOptions without customers.assign" test passed | 18/18 (112 assertions) | reassign, assign from none (null previous), unassign, unassign none (no-op), same advisor (no-op), same unavailable advisor (no-op, no error), E-27 forbidden + guest, E-28 inactive / no portfolio / browser redirect with session error, missing / unknown / non-integer `advisor_id`, inactive customer, E-33 after deactivation with options | `phpstan` fix: `whereKey()->firstOrFail()` and `array_values()` |
| 8.3 (frontend) | none (no frontend test runner) | - | `pnpm types:check` clean | - | `pnpm types:check` clean, `pnpm build` OK | - | - |
| 8.4 | search below | - | - | - | - | - | no automatic unassignment found |

Audit payloads asserted in full (`advisor_id` and `advisor_name`, previous and new), success flash asserted, guest redirect tested.

### 8.4 search result: every write to `advisor_id`
Search over `app`, `routes`, `database/seeders`, `database/factories` (PHP):
- `app/Actions/Customers/CreateCustomer.php:69` - creation-time auto-assignment (creator if eligible advisor).
- `app/Actions/Customers/AssignCustomerAdvisor.php:42` - the only reassign/unassign path (explicit, requires `customers.assign`).
- `database/factories/CustomerFactory.php:79` - test factory state `assignedTo()`.
- Reads only: `Customer` relation/scope, `CustomerPresenter`, `DeleteCustomer` audit copy, audit values. `UpdateCustomer` ignores `advisor_id` (not in rules). User deactivation and permission revocation never touch customers. The import (task 9) does not exist yet: it must be re-checked in Phase 9.

### Slice-close gate (observed)
- `sail pest tests/Feature/Customers/AdvisorAssignmentTest.php`: 18 passed (112 assertions)
- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors (after fixing 2 type findings)
- `sail pnpm types:check`: vue-tsc clean (after `wayfinder:generate`)
- `sail pnpm build`: built OK
- `sail artisan test`: 613 passed + 1 todo (E-19), 3105 assertions (596 + 18 new - 1 obsolete test removed)
- PENDING (human): browser checks (assign control visible only with `customers.assign`, "Sin asesora" option, unavailable advisor shown as "Nombre (no disponible)", success flash, error under the select for an ineligible advisor, 375/1280 px, both themes, keyboard only).

### Deviations / decisions
- The no-op check (same advisor) runs before the eligibility check, so resubmitting the current advisor that is no longer eligible is a no-op instead of a validation error (DEC-CLI-29: the stored assignment is never touched). Design Decision 12 lists the eligibility check first; the visible behavior for any different advisor is unchanged (E-28).
- Removed `CLI-013 advisorOptions is not sent until the assignment control exists` from `CustomerDetailTest.php` (placeholder from Phase 5, now false by design); replaced by the two `advisorOptions` tests in `AdvisorAssignmentTest.php`.
- Options ordered by first name, last name, id. Show.vue appends the current advisor as "Nombre (no disponible)" when not eligible, so the select never shows "Sin asesora" for an assigned customer.
- Flash message "La asesora del cliente fue actualizada." (also on no-op) is a UI text choice, not a business rule.
- Review size: about 460 authored lines (new files about 366: tests 255, production PHP 111; tracked about 96: frontend about 67). Over the 400 budget but cohesive: `size:exception` recommended for PR 8.

### Gaps for the orchestrator
- No [DEC-PENDIENTE] gaps found.

### Proposed commit (Spanish description, no attribution lines)
- `feat(002): asignar, reasignar y quitar la asesora de un cliente desde su ficha [CLI-014, CLI-015, CLI-016, DEC-CLI-18, DEC-CLI-28, DEC-CLI-29, E-26, E-27, E-28, E-33]`

## Phase 9 (PR 9): One-time import - COMPLETE (6/6 tasks)

Branch: `feat/002-customers-9-import` (from `feat/002-customers-8-advisor` at 411c957). Changes left in the working tree; no commit made. All files written with Edit/Write only. Mode: Strict TDD.

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|-----|-----------|-------------|----------|
| 9.1/9.2 (unit 9a) | `tests/Feature/Console/ImportCustomersTest.php` (14 file-level tests) | Feature (console + reader) | 76 passed (CreateCustomerTest, DuplicateDetectionTest, tests/Feature/Console) before | 14 of 14 failed (command and `CustomerCsvReader` did not exist) | 14/14 (47 assertions) | Latin-1 vs the same text in UTF-8; missing column, unknown + missing, repeated column; any order/case/spaces; BOM; `;`; quoted cell with delimiter and line break with physical line numbers; blank lines + CRLF; irregular cell count; empty file; header only; missing path | none needed |
| 9.3/9.4 (unit 9b) | same file (43 more tests incl. 19 dataset cases) | Feature (console, DB, audit) | 14/14 of 9a green | 41 of 57 failed (no import behavior); 15 passed = 14 file-level + 1 regression guard (`CreateCustomer` default advisor, passes immediately because extra args are ignored by old signature) | 57/57 (302 assertions) after one test-side fix: audit `new_values` compared with `toEqual` because the JSON column does not keep key order | E-30: missing phone, registered document, repeated document (rows 2/4, 5/6, typed differently), same number under another document type, state label match (case, accents, spaces) + unknown state, 19 column-mapping cases, no cell values; E-32: 3 advisor cases, 3 author cases (before reading file), author email normalization; E-31: DB + file warnings, confirmation, inactive DB match, 3-way group, confirmation does not override row errors; E-29: full payload, no auto-assignment of the author, BOM + `;` end to end, rollback mid-run with no cell values in output or log; `CreateCustomer` options (4 tests) | `phpstan` fix: `->value ?? $cell` instead of `?->value ?? $cell` |
| 9.5 | search below | - | - | - | - | - | no import-specific branching in `CreateCustomer` beyond its documented parameters |

Audit payload asserted in full for the company row (all scalar fields, `advisor_id`, `advisor_name`, `contact`, `address`) and for the others (`advisor_id`, `advisor_name`, birthday). Per audit row: `actor_id`, `actor_email`, `ip_address` null, `entity_type`, `entity_id`, `old_values` empty, `context.import = true`, `context.source = 'console'`, `context.command = 'customers:import'`, `context.import_row` (physical line: 2, 3, 4; and 2, 4 for a file with a multi-line cell) and the `os_user`/`host` keys. Exit codes asserted on every run; the report tables are parsed from the console output and compared with exact columns (`Fila | Columna | Motivo`, `Fila | Advertencia`) and exact rows; sentinel values in every cell of the leak tests never appear in the output.

### What was built
- `app/Support/Customers/CustomerCsvReader.php` (+ `InvalidCustomerCsv`): path, BOM, UTF-8, delimiter from the header, exact header set, `SplFileObject::fgetcsv` over `php://memory` with escape disabled, physical line numbers (newlines counted per record), blank lines skipped. Reasons carry column names and line numbers only.
- `app/Actions/Customers/ImportCustomers.php` (+ `app/Support/Customers/ImportReport.php`): phase 0 author (before the file), phase 1 validation with `CustomerRules` (+ `after()` hooks), `isEligibleAdvisor()`, intra-file document check, phone warnings against file and DB, phase 2 one `DB::transaction` calling `CreateCustomer::handle(..., confirmDuplicatePhone: true, origin: AuditOrigin::console('customers:import'), autoAssign: false, advisor: row advisor, auditContext: ['import' => true, 'import_row' => line])`.
- `app/Console/Commands/ImportCustomers.php`: thin; prints reasons, the two tables, the hint for `--confirm-duplicate-phones`, "Se importaron N clientes."; any unexpected `Throwable` prints a generic message with the class name only and logs only the class.
- `CreateCustomer::handle()` gained `?AuditOrigin $origin`, `bool $autoAssign = true`, `?User $advisor`, `array $auditContext`; defaults keep every existing caller and test unchanged. With `$autoAssign = false` an ineligible advisor throws `ValidationException` on `advisor_id`.
- `lang/es/validation.php`: new key `customer_advisor_ineligible` (same text as before), now shared by `AssignCustomerAdvisor` and `CreateCustomer` (and the import report).

### 8.4 re-check: every write to `advisor_id` (PHP search over `app`, `routes`, `database/seeders`, `database/factories`)
- `app/Actions/Customers/CreateCustomer.php:88` - the only creation write. With `autoAssign` true it is the creator when eligible (E-25); with false (import) it is the row's own advisor or null (DEC-CLI-23, asserted: an eligible author is never assigned).
- `app/Actions/Customers/AssignCustomerAdvisor.php:42` - the only reassign/unassign path (explicit, requires `customers.assign`).
- `database/factories/CustomerFactory.php:79` - test factory state `assignedTo()`.
- The import has no write of its own: it goes through `CreateCustomer`. Reads only elsewhere (`Customer` relation/scope, presenter, `DeleteCustomer` audit copy, audit values). `UpdateCustomer` still ignores `advisor_id`; no automatic unassignment exists.

### 9.5 checks
- `storage/app/private/.gitignore` is `*` + `!.gitignore`; `git check-ignore -v storage/app/private/imports/clientes.csv` reports the rule, so `storage/app/private/imports/` is ignored and no `.gitignore` change was needed (the design follow-up checkbox in `design.md` is now satisfied).
- Cell values never reach disk: the reader writes only to `php://memory`; the command prints row numbers, column names and reasons (never cell values; an existing customer's id and name appear only in phone warnings, as designed); the single `Log::error` records the exception class only (exception messages can carry SQL bindings). Covered by the rollback test (`Log::spy()`) and the sentinel tests.

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test | `sail pest tests/Feature/Console/ImportCustomersTest.php`: 57 passed (302 assertions) |
| Runtime harness | Run on the `testing` DB only (`sail exec -e DB_DATABASE=testing laravel.test php artisan ...`, config confirmed as `testing`): `migrate:fresh --seed`, two fictitious users (author, advisor with role "Asesora de Ventas"), a fictitious `;` CSV with a multi-line quoted cell in `storage/app/private/imports/` (deleted afterwards, never committed). Missing `--author` -> exit 1 with reason; `/nonexistent.csv` -> exit 1 with reason; real run -> "Se importaron 3 clientes.", exit 0, 3 customers, 3 `customers.created` rows with `actor_id` = author, `ip_address` NULL, `{import: true, source: console, command: customers:import, import_row: 2|4|5}`; second run -> exit 1, report with the two registered documents and three phone warnings, nothing created. Testing DB left at `migrate:fresh` (empty). The dev DB was never touched. |
| Rollback boundary | `ImportCustomers` Action + Command + `CustomerCsvReader` + `InvalidCustomerCsv` + `ImportReport` + `ImportCustomersTest`; the `CreateCustomer` optional parameters (revert with the test cases at the end of the test file); the `customer_advisor_ineligible` lang key and its use in `AssignCustomerAdvisor` |

### Slice-close gate (observed)
- `sail pest tests/Feature/Console/ImportCustomersTest.php`: 57 passed (302 assertions)
- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors (after fixing 1 finding)
- `sail pnpm types:check`: vue-tsc clean
- `sail pnpm build`: built OK
- `sail artisan test`: 670 passed + 1 todo (E-19), 3407 assertions (613 + 57 new)
- `git status --short`: only the intended source, test, lang and openspec files; no CSV, `.env` or dump

### Deviations / decisions
- Row-shape and empty-file checks are file-level failures (design lists only path, UTF-8, delimiter, header): a row whose number of cells differs from the header, an empty file and a header with no rows stop the run with a reason naming line numbers only ("Filas con distinto número de columnas que el encabezado: N."). Also a repeated header column ("Columnas repetidas: x."). These are technical guards, not business rules.
- Blank cells are omitted from the mapped input (not sent as null) so each rule reports once, like a request that omits the field; whole numbers are cast to int as a JSON request would send them.
- Reasons reuse the HTTP validation messages (Spanish attribute names such as "teléfono"), including "solo se admite cuando tipo es company" for company-only fields on a natural row; the CSV `empresa` value maps to the stored `company`, and `pasaporte` to `passport`.
- A bad advisor (unknown, inactive or without `customers.portfolio`) gets one reason for the three cases, the same text as E-28: "La asesora debe ser un usuario activo con permiso para tener cartera." (the report does not reveal which users exist).
- Phone warnings are listed per involved row ("El teléfono se repite en la fila N" / "en las filas N, M" / "El teléfono ya está registrado en el cliente #id (nombre)"); the confirmation option never overrides row errors. With errors, the warnings are still printed after the error table.
- The author check runs before the file is read and returns two reasons: missing `--author` ("Debe indicar el autor con --author=correo.") and unknown/inactive ("El autor indicado no existe o está inactivo.").
- An unexpected failure in phase 2 prints "No se guardó ningún cliente: la importación falló (<class>)." and logs the class only; exit 1.
- Singular wording "Se importó 1 cliente." for one row.
- The reader is an instance (not static) so the Action gets it by injection.
- Design said the Action's phase 2 calls `CreateCustomer` with `origin`, `autoAssign`, `advisor`, `auditContext` named arguments: followed exactly. The eligibility re-check in `CreateCustomer` also protects the race where an advisor is deactivated between validation and creation (tested at Action level).

### Review size and split
- Authored lines: about 1,500 in 9 files (production PHP about 700: Action 358, Command 82, reader 199, report 36, exception 20, plus about 53 changed in `CreateCustomer`, lang and `AssignCustomerAdvisor`; tests about 810). Far over the 400 budget; cohesive (tests sit with the code that turns them green) and the tests are about half of it. `size:exception` recommended for PR 9.
- Honest split if the reviewer wants smaller PRs: 9a reader and file checks (reader, `InvalidCustomerCsv`, command skeleton, the 14 file-level tests, about 560 lines) and 9b row validation and import (Action, `ImportReport`, full command, `CreateCustomer` parameters, lang key, the 43 row-level tests, about 940 lines; 9b alone still exceeds 400 because of the 19-case column-mapping dataset and the E-29/E-31 fixtures). The current working tree is one phase, so splitting means committing 9a first (reader files + the first 14 tests + a skeleton command) and 9b after; the final command file is the 9b version.

### Gaps for the orchestrator
- No [DEC-PENDIENTE] gaps found. Technical choices listed under deviations (file-level shape checks, single advisor reason, per-row warning wording) may be reviewed but do not invent business behavior.

### Proposed commit (Spanish description, no attribution lines)
- Single commit: `feat(002): importar clientes desde CSV con todo o nada, informe por fila y marca de importación en la auditoría [CLI-018, CLI-012, CLI-016, DEC-CLI-20, DEC-CLI-21, DEC-CLI-22, DEC-CLI-23, DEC-CLI-24, DEC-CLI-33, E-29, E-30, E-31, E-32, E-39]`
- If split: `feat(002): lector de CSV y comprobaciones de archivo para customers:import [CLI-018, DEC-CLI-20, E-39]` then `feat(002): importar clientes todo o nada con informe y marca de importación en la auditoría [CLI-018, CLI-012, CLI-016, DEC-CLI-21, DEC-CLI-22, DEC-CLI-23, DEC-CLI-24, DEC-CLI-33, E-29, E-30, E-31, E-32]`

## Review follow-up Phase 9 (R3-stale-advisor-recheck, R3-phase2-error-loses-row)

Scoped fixes after the native review of 13bb8f6.

| Finding | RED | GREEN | Change |
|---|---|---|---|
| R3-stale-advisor-recheck | Test deactivating the advisor through the DB while passing the stale instance: no exception (customer created) | green | `CreateCustomer::eligibleOrFail()` re-reads the advisor inside the transaction (`User::query()->whereKey()->lockForUpdate()->first()`) and checks eligibility on that fresh record; a missing user is ineligible. Same `ValidationException` on `advisor_id`. The fresh record is the one used for `advisor_id` and the audit values. |
| R3-phase2-error-loses-row | Command tests: output showed no `Fila / Columna / Motivo` table (generic failure line with the class name, or exit 0 for the advisor case before finding 1 was fixed) | 61/61 in `ImportCustomersTest.php` | `ImportCustomers::create()` catches a row's `ValidationException`, keeps the whole-import rollback by rethrowing inside the transaction, and returns an `ImportReport` with line, CSV column and reason. The column reuses `column()` and `COLUMN_BY_ATTRIBUTE`, which gained `advisor_id => correo_asesora` (no second mapping). Other Throwables keep the generic handling. |

Decision: the phase 2 report is the same `ImportReport` errors and the same console table as phase 1 (exit 1), so no command change was needed; the header line "el archivo tiene N error(es)" is reused as is. Prepared rows now carry their `cells` so nested-object keys can map to a column. No cell values are printed (reasons come from the lang files).

Tests added: 2 at Action level (stale instance; deleted user) and 2 through the command (document inserted by "another process" via a `creating` hook; advisor deactivated between rows), both asserting exit 1, zero customers and audit rows, the row line, the column and the reason, and no cell value in the output.

## Phase 10: Close-out - 10.1, 10.2, 10.3 COMPLETE; 10.4 PARTIAL ((a)-(e) done, (f) open)

Branch: `feat/002-customers-10-closeout` (from `feat/002-customers-9-import` at 673811f). Changes left in the working tree; no commit made. All files written with Edit/Write only. Mode: Strict TDD.

### 10.1 Coverage audit (observed)

Method: `sail pest <path> --filter='E-<nn>\b'` for each ID, restricted to the spec 002 locations (`tests/Feature/Customers`, `tests/Unit/Customers`, `tests/Feature/Console/ImportCustomersTest.php`) and summed. The restriction is needed because spec 001 reuses the same `E-nn` IDs in its own tests (for example `E-19` also names five authorization tests in `tests/Feature/Authorization/PermissionAuthorizationTest.php`, which are 001 scenarios and are not counted). The word boundary isolates the ID: `E-1\b` finds no tests, so `E-1` never matches `E-10`..`E-19`. Counts include dataset cases and tests whose name mentions the ID after other text (for example `E-35 and E-24 ...` counts under both). Every ID also has at least one test whose name starts with it (checked in the sources).

| ID | tests | passed | failed | todo | ID | tests | passed | failed | todo |
|---|---|---|---|---|---|---|---|---|---|
| E-01 | 2 | 2 | 0 | 0 | E-22 | 6 | 6 | 0 | 0 |
| E-02 | 5 | 5 | 0 | 0 | E-23 | 2 | 2 | 0 | 0 |
| E-03 | 8 | 8 | 0 | 0 | E-24 | 5 | 5 | 0 | 0 |
| E-04 | 5 | 5 | 0 | 0 | E-25 | 3 | 3 | 0 | 0 |
| E-05 | 3 | 3 | 0 | 0 | E-26 | 2 | 2 | 0 | 0 |
| E-06 | 15 | 15 | 0 | 0 | E-27 | 3 | 3 | 0 | 0 |
| E-07 | 1 | 1 | 0 | 0 | E-28 | 3 | 3 | 0 | 0 |
| E-08 | 1 | 1 | 0 | 0 | E-29 | 4 | 4 | 0 | 0 |
| E-09 | 4 | 4 | 0 | 0 | E-30 | 25 | 25 | 0 | 0 |
| E-10 | 2 | 2 | 0 | 0 | E-31 | 4 | 4 | 0 | 0 |
| E-11 | 17 | 17 | 0 | 0 | E-32 | 8 | 8 | 0 | 0 |
| E-12 | 7 | 7 | 0 | 0 | E-33 | 6 | 6 | 0 | 0 |
| E-13 | 11 | 11 | 0 | 0 | E-34 | 37 | 37 | 0 | 0 |
| E-14 | 11 | 11 | 0 | 0 | E-35 | 5 | 5 | 0 | 0 |
| E-15 | 3 | 3 | 0 | 0 | E-36 | 9 | 9 | 0 | 0 |
| E-16 | 5 | 5 | 0 | 0 | E-37 | 5 | 5 | 0 | 0 |
| E-17 | 3 | 3 | 0 | 0 | E-38 | 3 | 3 | 0 | 0 |
| E-18 | 2 | 2 | 0 | 0 | E-39 | 14 | 14 | 0 | 0 |
| **E-19** | 1 | 0 | 0 | **1 (todo)** | E-40 | 2 | 2 | 0 | 0 |
| E-20 | 6 | 6 | 0 | 0 | E-41 | 8 | 8 | 0 | 0 |
| E-21 | 5 | 5 | 0 | 0 | | | | | |

Result: 40 of 40 scenarios (every ID except E-19) have at least one passing test and 0 failures; E-19 is the single `todo` (`DeleteCustomerTest.php`, "se prueba en 004 y 006"). Requirement-named tests: `CLI-007` 4 passed (`CreateCustomerTest` x2, `UpdateCustomerTest`, `CustomerRulesTest`), `CLI-013` 4 passed (`CustomerDetailTest`). No scenario without a test: no gap.

### 10.4 follow-ups

| Item | Change | TDD |
|---|---|---|
| (a) R3-guard-scope | `PermissionMatrixSeedingTest`: the R3-002 guard scans `database/seeders` recursively (`File::allFiles`, `.php` only), matches `RoleSeeder::class` or the class name as a quoted string with a regex instead of a substring, and checks that `FoundationSeeder` passes it to `$this->call([...])`. `RoleSeeder` docblock rewritten to state exactly what the guard proves and that `db:seed --class=RoleSeeder` typed by hand is not covered. | Test hardening; passes immediately (regression guard, 7/7) |
| (b) R3-injection-site | The rollback test now catches the `RuntimeException`, asserts its message and that its trace contains `InitialRolePermissions::apply`, so the test is pinned to the grants phase. | Passes immediately |
| (c) R3-hardcoded-count | Administrador total derived from `PermissionName::cases()` minus `CustomersPortfolio` instead of the literal 15. | Passes immediately |
| (d) R3-q-normalization-untested | `CustomerListTest`: `q` is trimmed and cut to 100 characters (150-character term padded with spaces; exactly 100 kept whole), and a non-string `q` (`q[]=x`, `q[a]=x&q[b]=y`) is an empty term listing everything. | Passes immediately (the controller already behaved so) |
| (e) R3-like-escape-portability | `Customer::contains()` replaced by `whereContains()`: `whereRaw("`col` like ? escape '\\\\'", [...])` over a closed set of literal column SQL (`name`, `document_number`, `phone`); value stays a binding. A test asserts the generated SQL carries `escape '\\'` on every LIKE. | RED: SQL had no `escape` clause (1 failed); GREEN after the change, existing `E-11 escapes % and _` test still green. A behavior-level failing test is not feasible on MySQL (backslash is already the default escape), so the new test guards the SQL text; the change is portability-only. |
| (f) R3-form-payload-shaping-unproved | **OPEN, not checked off.** No frontend test runner exists and adding one (e.g. Vitest) is a new dependency that needs the user's approval (AGENTS section 2). It stays covered only by the manual checks below (section "Manual browser checklist"). | none |

Note on (e): Larastan rejects a dynamic `whereRaw` string (`argument.type`), so the SQL is a `match` over three literal strings instead of wrapping the column with the grammar; backticks make it MySQL-specific, which is the only supported database (AGENTS section 2).

Task 10.4 stays `[ ]` because (f) is open.

### 10.2 Documentation

- `docs/specs/002-customers.md`: untouched (no status change requested).
- `design.md` "Migration / Rollout" runbook: Phase 9 did not change it (command `customers:import {file} {--author=} {--confirm-duplicate-phones}`, location `storage/app/private/imports/`, all-or-nothing, report `Fila | Columna | Motivo` all match), so the runbook text is unchanged. Only the two "Technical follow-ups" checkboxes already satisfied in the apply phase were ticked (`storage/app/private` ignore rule, confirmed in 9.5; `UniqueConstraintViolationException`, confirmed in 2.x). The third follow-up (`openspec/config.yaml` says PHP 8.3, `composer.json` requires ^8.4) is left open: it is a documentation mismatch outside this task, `composer.json` governs.
- `AGENTS.md`: unchanged (no command or convention changed).
- No real file, names or prices added anywhere.

### 10.3 Final gates (observed on the whole branch)

- `sail pint` then `sail pint --test`: passed
- `sail composer types:check`: phpstan 0 errors (after replacing the dynamic `whereRaw` string, see note on (e))
- `sail pnpm types:check`: vue-tsc clean
- `sail pnpm build`: built OK
- `sail artisan test`: 678 passed + 1 todo (E-19), 3463 assertions (baseline 674 + 4 new tests)
- `git status --short`: only `app/Models/Customer.php`, `database/seeders/RoleSeeder.php`, `design.md`, `tasks.md`, `apply-progress.md`, `CustomerListTest.php`, `PermissionMatrixSeedingTest.php`; no `.env`, CSV or dump

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test | `sail pest tests/Feature/Customers/CustomerListTest.php`: 35 passed (326 assertions); `sail pest tests/Feature/Customers/PermissionMatrixSeedingTest.php`: 7 passed (52 assertions) |
| Runtime harness | N/A for the code change: `whereContains()` is exercised against the real MySQL `testing` database by the E-11 search tests (escaping included); no new runtime boundary |
| Rollback boundary | `Customer::whereContains()` / `scopeSearch()`; the added tests in `CustomerListTest.php`; `PermissionMatrixSeedingTest.php` edits and the `RoleSeeder` docblock; independent of each other |

### Manual browser checklist for the human (consolidates every PENDING check of Phases 5-9)

Dev server up (`sail up -d`, `sail pnpm dev`), a user with the needed permissions per case (Administrador, and an "Asesora de Ventas" for portfolio checks). Use fictitious data only. Viewports: 375, 768 and 1280 px unless stated. Each flow in light and dark theme and with keyboard only (Tab, Shift+Tab, Enter, Space, Esc: visible focus, logical order, dialogs trap focus and Esc closes them).

1. `/customers` (list), 375/768/1280, both themes: default "Activos" tab with the counts; "Inactivos" and "Todos" tabs; the counts follow the search term; empty state.
2. `/customers` search box: the magnifier icon never overlaps the typed text (AppInput `px-space-md` + `pl-11`, R3-appinput-icon-padding-unverified); search submits with Enter and with the button; term in the URL.
3. `/customers` "Mis clientes" toggle: visible only with `customers.portfolio`; filters to the actor's customers.
4. `/customers` "Nuevo cliente" button: visible only with `customers.create`, goes to the create form (375/1280).
5. `/customers/{id}` (detail), 375/768/1280: sections in order (general data, contact with birthday, contact person for companies only, address with state, notes, advisor); an inactive customer shows "Inactivo"; unavailable advisor shows the "Asesora no disponible" badge.
6. `/customers/create`, 375/1280: type selector; document type options change with the type; phone, email, birthday and anniversary (day/month selects, anniversary only for companies), notes, company-only contact section, address with the state select; labels always visible; errors announced under each field; text at least 16 px on mobile (no zoom on focus).
7. Duplicate phone on create: save a customer with a phone already registered; the panel lists the matches; "Guardar de todos modos" saves; editing the phone afterwards resets the confirmation (the panel appears again if it is still duplicated).
8. `/customers/{id}/edit`, 375/1280: editable form with phone as display string; an inactive customer is editable; saving keeps the address (it is always sent).
9. Type-change warning (DEC-CLI-15): switching a company with an anniversary and a contact person to "Persona natural" lists what will be removed before saving; saving removes them. Also the document-only case: a company with an unfit document and no anniversary or contact, switched to natural, shows only the note "El documento no se elimina".
10. Detail, "Desactivar": button opens the danger `ConfirmDialog` with the DEC-CLI-09 consequence text, verb "Desactivar"; Esc and Cancel close it without changes; confirming shows the success flash and the "Inactivo" label. "Reactivar" (needs `customers.deactivate`) works without confirmation.
11. Detail, "Eliminar": visible only with `customers.delete`; danger `ConfirmDialog` with the irreversible text, verb "Eliminar"; confirming redirects to the list with the success flash. If a history blocks the delete (not reachable until 004/006) the reason is shown and "Desactivar" is offered.
12. Detail, assign advisor: the control appears only with `customers.assign`; options are the eligible advisors plus "Sin asesora" (selectable and saves as unassigned); an assigned advisor who is no longer eligible appears as "Nombre (no disponible)"; success flash after saving; an error shows under the select for an ineligible advisor.
13. Visibility by permission on the detail: log in as a user with only `customers.view` (no Editar, Desactivar, Eliminar, assign control) and as one with `customers.update` only (Editar yes, the rest no).
14. Navigation: the "Clientes" entry (group Comercial) appears only with `customers.view` and highlights on `/customers` and its sub-pages.

Result of the manual run (2026-10-06, by the human): items 6 to 9 passed, so task 10.4 (f) is closed. Requested adjustment: the detail page `resources/js/pages/customers/Show.vue` gets an `arrow_back` icon link to the list at the top left of the header card, because the "Volver a clientes" button at the bottom went unnoticed; the bottom button stays. Checked with `sail pnpm types:check` and `sail pnpm build`. Items 1 to 5 and 10 to 14 were not reported.

### Deviations / decisions
- 10.1 counts are restricted to the spec 002 test locations because spec 001 reuses `E-nn` IDs in its own tests; the unrestricted filter gives larger numbers (for example E-19: 6 tests, 5 passed + 1 todo) that mix both specs.
- (e) uses a closed `match` of literal SQL instead of grammar-wrapped column names (Larastan constraint); MySQL-only by design.
- No production behavior changed other than the explicit `ESCAPE` clause (results are identical on MySQL).

### Gaps for the orchestrator
- No [DEC-PENDIENTE] found. Open items: 10.4 (f); Definition of Done items that need the human (manual browser checks, `/sdd-verify`, archive); the `openspec/config.yaml` PHP 8.3 vs `composer.json` ^8.4 mismatch.

### Proposed commit (Spanish description, no attribution lines)
- `chore(002): cierre del change con auditoría de cobertura, seguimientos de revisión y cláusula ESCAPE explícita en la búsqueda [CLI-011, CLI-018, DEC-CLI-11, E-11]`
