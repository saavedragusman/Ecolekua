# Apply progress: 004-pricing-and-quotations

Mode: Strict TDD. Runner: `./vendor/bin/sail artisan test` (scoped `sail pest --group=cot --filter=...`).

## Phase 1 (PR 1, `feat/004-pricing-and-quotations-1-permissions`): tasks 1.1 to 1.9 done; 1.10 gate green, commit pending (needs user authorization)

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 1.1 | `tests/Pest.php` (group `cot`) | Config | N/A | n/a | `--group=cot` runs 14 tests | single | n/a |
| 1.2/1.3 | `tests/Unit/PermissionNameTest.php`, `tests/Unit/AuditActionTest.php` | Unit | 2 files green before | 5 failed + 2 errors (28/74 cases missing, `QuotationsView` undefined, `pricing.scheme_created` invalid backing value) | 10/10 passed | descriptions (6) and labels (26) asserted one by one | none needed |
| 1.4 | FoundationSeeder, Customers matrix, PermissionCatalog tests | Integration | baseline 1644 tests, 3 failed once catalog grew (21 vs 27, 15 vs 9, catalog set) | RED observed in the full run | green after 1.6 | n/a | n/a |
| 1.5/1.6 | `tests/Feature/Pricing/PricingPermissionsTest.php` (DEC-COT-25) | Integration | N/A (new) | 5 failed (matrix absent) | 5/5 passed | fresh DB, existing 003 DB, revoked not re-granted, renamed role, manage holders | none needed |
| 1.7/1.8 | same file (E-47, DEC-COT-52) | Integration (HTTP) | 12 existing coherence/architecture tests green | 6 failed (302 instead of 422), 3 valid cases passed | 9/9 passed | create/negotiate/cancel, manage, replace set, several rules together, valid sets, no products.view dependency | 1.9 below |
| 1.9 | same + ProductPermissionCoherenceTest + NoManualForbiddenTest | Refactor | 12/12 | n/a | 12/12 after `dependentsOf()` helper | n/a | helper shared by products and quotations blocks |

Note: `ProductPermissionMatrixSeedingTest` needed no edit (it filters with `like 'products.%'`, not by exclusion).

### Gate (2026-10-13)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.38s)
- `sail artisan test`: passed, 1658 tests (1656 passed, 2 skipped, 9523 assertions)
- Harness `db:seed --class=FoundationSeeder` then `Permission::count()`: 28

### Files
Modified: `app/Enums/PermissionName.php`, `app/Enums/AuditAction.php`, `database/seeders/InitialRolePermissions.php`, `app/Actions/Roles/SyncRolePermissions.php`, `tests/Pest.php`, `tests/Unit/PermissionNameTest.php`, `tests/Unit/AuditActionTest.php`, `tests/Feature/FoundationSeederTest.php`, `tests/Feature/Customers/PermissionMatrixSeedingTest.php`, `tests/Feature/Roles/PermissionCatalogTest.php`, `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `tests/Feature/Pricing/PricingPermissionsTest.php`, this file.

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest --group=cot --filter='E-47 \|DEC-COT-25'`: passed |
| Runtime harness | seeder on local DB, `Permission::count()` = 28 |
| Rollback boundary | revert enums, `InitialRolePermissions`, `SyncRolePermissions` block, Pest group and test edits together |

### Remaining
Task 1.10 commit: done (`300a2fd`, authorized by the user). Phases 2 onward: see below.

## Phase 2 (PR 2, `feat/004-pricing-and-quotations-2-money-and-schema`): tasks 2.1 to 2.12 done; 2.13 gate and harness green, commit pending (needs user authorization)

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 2.3/2.4 | `tests/Unit/Pricing/MoneyTest.php`, `AsBigDecimalTest.php` | Unit | N/A (new) | 49 tests: 12 failed + 37 errors (`Money`, `InvalidAmount`, `AsBigDecimal` not found) | 48/49 then 49/49 (one dataset row used scale 2 for a 4-decimal rate: test fixed, not the code) | comma/dot/integer/spaces/rate; 11 rejected inputs; HALF_UP up/down/exact; display positive/negative/zero/rate/scale 0; percentOf exact | `Money` parse/display tidied (named parts, no destructuring hack) |
| 2.5/2.6 | `tests/Unit/Pricing/EnumsTest.php` | Unit | N/A (new) | 20 errors (10 enums not found) | 20/20 | one test per enum (value to label map) + unique-label guard over all 10 + badge maps | none needed |
| 2.7/2.8 | `tests/Feature/Pricing/PricingSchemaTest.php` | Integration (raw SQL on `testing` DB) | N/A (new) | 13 errors (`testing.price_schemes` does not exist); setup rows are inserted outside `toThrow`, so a missing table cannot pass an expectation | first GREEN attempt failed with MySQL 1215 (see Deviations); 13/13 after the fix | positive control next to every violation (accent-different name, same label in another scheme, group vs NULL group, boundary values 0/100/0.01) | none needed |
| 2.8 (models/factories) | `tests/Feature/Pricing/PricingModelsTest.php` | Integration | N/A (new) | 10 failures/errors (models and factories absent) | 10/10 | casts, 2-decimal refusal, enums, `fixed()` and `negotiationLast()` states, every new relation | none needed |
| 2.9/2.10 | `tests/Feature/Pricing/PricingPolicyTest.php` | Integration | N/A (new) | 3 failed + 2 errors (policy not registered, class absent); 2 vacuous denials passed and are backed by the allow-side tests | 7/7 | view only, manage only, independence, class and instance subjects, no roles, Administrador without `pricing.manage`, no `Gate::before` | none needed |
| 2.11 | `tests/Unit/Pricing/MoneyStringExpectationTest.php` (expectation), `tests/Feature/Architecture/MoneyHasNoFloatTest.php` | Unit + architecture | N/A (new) | expectation: 10 failed (`BadMethodCallException`, `toBeMoneyString` undefined). Scanner: test-only code, proven by its own detector tests (10 violating snippets detected, comments ignored) | 29/29 | scale 2 vs 4, strings vs numbers, separators, signs | none needed |
| 2.12 | all of the above | Refactor | green | n/a | `composer types:check` failed with 4 `argument.type` errors on `toScale(int)`; fixed with `@param non-negative-int` | n/a | docblocks aligned |

### Technical follow-up outcome (design, Phase 2)
`brick/math` 1.0.0 API checked against the installed vendor source (`vendor/brick/math/src`; Context7 not used, approved): `BigDecimal::of`, `toScale(int, RoundingMode)`, `withPointMovedLeft`, `multipliedBy`, comparison and `toString()` exist as the design uses them. **The rounding enum case is `RoundingMode::HalfUp`** (the design and tasks wrote `HALF_UP`). `BigNumber::of()` accepts exponents, so `Money::parse` validates the text with its own pattern first. `composer require brick/math:1.0.0` changed `composer.json` (+1 line, exact pin like `enshrined/svg-sanitize`) and `composer.lock` (`content-hash` only; the locked version stays 1.0.0).

### Gate (2026-10-13)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.41s)
- `sail artisan test`: passed, 1786 tests (1784 passed, 2 skipped, 9852 assertions)
- Harness on the local DB (no reset): `sail artisan migrate`, `migrate:rollback --step=1`, `migrate`: all DONE

### Files
Modified: `composer.json`, `composer.lock`, `app/Models/Product.php`, `Combination.php`, `Combo.php`, `ComboComponent.php`, `app/Providers/AppServiceProvider.php`, `tests/Pest.php`, `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `app/Support/Pricing/{Money,InvalidAmount}.php`, `app/Casts/AsBigDecimal.php`, 10 enums in `app/Enums/`, `database/migrations/2026_10_14_000001_create_pricing_tables.php`, models `PriceScheme`, `PriceTier`, `CombinationPrice`, `SizeGroup`, `ComboItemPrice`, `CommercialParameters`, 6 factories, `app/Policies/PricingPolicy.php`, tests `tests/Unit/Pricing/{MoneyTest,AsBigDecimalTest,EnumsTest,MoneyStringExpectationTest}.php`, `tests/Feature/Pricing/{PricingSchemaTest,PricingModelsTest,PricingPolicyTest}.php`, `tests/Feature/Architecture/MoneyHasNoFloatTest.php`.

### Deviations from design
1. `combination_prices.size_group_key` is `VIRTUAL`, not `STORED` (design Schema and Decision 4): MySQL 8.4 rejects `ON DELETE CASCADE` on the base column of a STORED generated column (error 1215). The unique `(combination_id, price_tier_id, size_group_key)` index and the NULL-collision behaviour are identical, and cascade from combination and size group is kept. Later phases that read `size_group_key` are unaffected.
2. `RoundingMode::HalfUp` instead of `HALF_UP` (library naming).
3. `Money::parse` accepts a leading `-` and returns the negative amount (the caller rejects it); it rejects `+`. Decimals are counted literally (`40.500` has 3 decimals and is rejected at `maxScale` 2).
4. `PriceImportStatus::badge()` maps `validated` to `pending` (the design only fixed applied to `done` and discarded to `neutral`); presentational only, change if the UI spec says otherwise.
5. `CommercialParametersFactory` is for `make()` (the migration owns row `id = 1`); `PriceSchemeFactory` has no tiers by default and creates them in `fixed()` and `negotiationLast()` (labels Detal, Intermedio, Mayor, Negociación are fictitious). Extra test files beyond the plan: `PricingModelsTest` (models, factories, relations) and `MoneyStringExpectationTest`.

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest --group=cot --filter='Money\|PricingSchema\|PricingPolicy\|MoneyHasNoFloat'`: passed, 87 tests |
| Runtime harness | migrate / rollback --step=1 / migrate on the local DB: DONE x3, no reset |
| Rollback boundary | revert migration 000001, `composer.json/lock` entry, Support/Casts/Enums/Models/Factories/Policy, the 4 model relation edits, the provider registration, `tests/Pest.php` expectation |

### Size
Authored changed lines (excluding `tasks.md`, `apply-progress.md`, `composer.lock`): 2,241 additions, 0 deletions in 42 files (production about 1,285, tests about 955). The design estimated ~470; the slice carries 10 enums, 6 models, 6 factories, the migration, Money and its tests as one cohesive unit. Recommendation: `size:exception` for this PR.

### Remaining
Task 2.13 commit: done (PR 2a `fb7abb0`, PR 2b `9a221fb`). Phase 3: see below.

## Phase 3 (PR 3a `feat/004-pricing-and-quotations-3a-scheme-create`, commit `74882d5`; PR 3b `feat/004-pricing-and-quotations-3b-scheme-update`): tasks 3.1 to 3.8 done; each part gated green and committed (user decision 2026-10-13, option A)

Gate per part: 3a: pint, types:check, pnpm check, pnpm types:check, pnpm build all passed, `artisan test` 1843 tests (1841 passed, 2 skipped, 10034 assertions). 3b: same five passed, `artisan test` 1863 tests (1861 passed, 2 skipped, 10156 assertions), as in the Gate section below.

### TDD Cycle Evidence

The run was interrupted by an API rate limit after the Actions were written; the first part of the RED log below was observed before the interruption, the seeder RED after the resume.

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 3.1/3.2 | `tests/Unit/Pricing/SchemeRulesTest.php` | Unit | N/A (new) | Only partial: the first run errored with Pest `DatasetMissing` because `SchemeRules::RESERVED_HEADERS` is read at dataset load and the class did not exist. That is a load error, not per-test failures; no per-test RED output was captured. | 31/31 | one case per invalid rule, all errors in one pass, label length 1/30/31/empty/blank, case and spaces, accent-different positive control, 10 reserved headers (plain and upper-case padded), header only as substring accepted | none |
| 3.3/3.4 | `tests/Feature/Pricing/PriceSchemeTest.php` (scheme writes) | Integration (HTTP) | N/A (new) | 40/40 failed (404 on the missing routes; one test failed on missing validation errors) | 32/40 on the first GREEN run, then 40/40 after fixing 4 defects found by the tests (audit key order and `toBe` vs `toEqual` on JSON, `Eloquent\Collection::only()` dropping id keys, parked-order duplicate, `validated()` placing tiers without `id` after those with it) | create tiered and fixed, 7 invalid sets, all errors together, malformed input (7), name as_ci, DEC-COT-51 (reject and accept), labels and limits keep prices, swap of labels/order (unique indexes), rename-only audit and no-op, new tier without prices, DEC-COT-23 ask/confirm/no prices, DEC-COT-57, foreign tier id, invalid tiers on update, fixed scheme, E-03 refuse/allow/no-op, 403 on 4 writes plus unrelated permissions | helper `AffectedPrices`, `SchemeAudit`, `SchemeInput` extracted |
| 3.5/3.6 | same file (seeder) | Integration | N/A (new) | 4 errors (class `PriceSchemeSeeder` absent) + 1 failure (DatabaseSeeder created nothing) | 6/6 seeder tests; file 46/46 | exact 4 schemes and tiers, DEC-COT-26 limits, idempotent second run, existing name in other case skipped, console audit without actor/IP, called from DatabaseSeeder | none |
| 3.7 | architecture suites | Refactor | green | n/a | `ProtectedByDefaultTest` and `NoManualForbiddenTest` pass in the full suite; PHPStan found 8 type errors, fixed by real typing (`normalizeTiers`, list-building loops), no ignores | n/a | `SchemeInput::normalizeTiers` shared by both Actions |

### Gate (2026-10-13)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.43s)
- `sail artisan test`: passed, 1863 tests (1861 passed, 2 skipped, 10156 assertions)
- Harness on the local DB (no reset): `sail artisan route:list --name=pricing.schemes` shows 4 routes; `db:seed --class=PriceSchemeSeeder` twice: counts stay at 4 schemes / 9 tiers / 4 `pricing.scheme_created` audits after both runs.

### Files
Modified: `lang/es/validation.php`, `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `app/Actions/Pricing/{CreatePriceScheme,UpdatePriceScheme,ActivatePriceScheme,DeactivatePriceScheme}.php`, `app/Http/Controllers/Pricing/PriceSchemeController.php`, `app/Http/Requests/Pricing/{StorePriceSchemeRequest,UpdatePriceSchemeRequest}.php`, `app/Support/Pricing/{SchemeRules,SchemeInput,SchemeAudit,AffectedPrices}.php`, `database/seeders/PriceSchemeSeeder.php`, `tests/Unit/Pricing/SchemeRulesTest.php`, `tests/Feature/Pricing/PriceSchemeTest.php`.

### Deviations from design
1. Helpers beyond the plan: `SchemeInput` (request shape rules and `normalizeTiers`), `SchemeAudit` (tier snapshot), `AffectedPrices` (affected prices, the shared "affected prices" answer that task 4.3 planned to extract: Phase 4 can reuse it directly).
2. `AffectedPrices` writes `size_group` as the group's size names joined by ", " (null when ungrouped) because `SizeGroupLabel` belongs to Phase 5b; replace with the label then.
3. Fixed schemes accept no `tiers` (422 `tiers` when sent); the update of a fixed scheme changes only the name.
4. Deactivation message: "El esquema está asignado a productos activos: <names>." via `BusinessRuleViolation` (422 JSON).
5. Update locks the scheme row, then the product rows holding affected prices (in id order); the full scheme-products-combos order of Decision 10 only matters when combos get prices (Phase 6).

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest --group=cot`: passed, 219 tests; `PriceSchemeTest` 46, `SchemeRulesTest` 31 |
| Runtime harness | route list (4 routes) and seeder run twice, second run creates nothing |
| Rollback boundary | revert the 4 Actions, `SchemeRules/SchemeInput/SchemeAudit/AffectedPrices`, seeder and its `DatabaseSeeder` line, 2 requests, controller, 4 routes, the `validation.php` entries and the 2 test files |

### Size and split (applied)
Authored changed lines (excluding `tasks.md`, `apply-progress.md`, `composer.lock`): 1,777 additions, 0 deletions in 17 files (production about 994, tests about 783). Over the 600-line rule, so it was split as below (3a: 886 lines, 3b: 891 lines), both `size:exception`, both independently green:
- **PR 3a** (~890): `SchemeRules`, `SchemeInput`, `SchemeAudit`, `CreatePriceScheme`, `StorePriceSchemeRequest`, controller `store`, the store route, `PriceSchemeSeeder` + `DatabaseSeeder`, lang entries for name/type/tiers, `SchemeRulesTest`, and the E-01/E-02 create, name-uniqueness, 403-create and seeder tests (tasks 3.1, 3.2, 3.5, 3.6 and the create part of 3.3/3.4).
- **PR 3b** (~890): `UpdatePriceScheme`, `ActivatePriceScheme`, `DeactivatePriceScheme`, `AffectedPrices`, `UpdatePriceSchemeRequest`, controller `update/activate/deactivate`, their routes, remaining lang entries, and the DEC-COT-51/23/57, COT-001 update, E-03 and 403 update/status tests.
A three-way split (rules + seeder; create; update/status) would bring each part nearer 600 but 3a/3b are the smallest cut with no dead code in either part. Both parts exceed 600 and would need `size:exception`.

### Remaining
Phase 3 done. Phase 4: see below.

## Phase 4 (PR 6, `feat/004-pricing-and-quotations-4-scheme-assignment`, base PR 3b): tasks 4.1 to 4.3 done; 4.4 gate and harness green, commit pending (needs the orchestrator)

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 4.1/4.2 | `tests/Feature/Pricing/SchemeAssignmentTest.php` | Integration (HTTP) | `--group=cot` 219 tests green before (Phase 3 state) | First run: 24 errors (`Call to undefined function storedTieredScheme()`: the 3b helpers are not loaded when the file runs alone, so the file got its own `assignmentScheme/assignmentPrice` helpers). Second run, the real RED: 25 tests, 1 passed, 22 failed (all with 404 on `PUT /pricing/products/scheme` and the single route), 2 errors (`getByName()` null on the two route names). First GREEN attempt: 21/25; 3 failures from the single route (controller lacked a `Product` parameter, so implicit binding never ran: 422 instead of 404) and 1 test fixture error (same catalog code twice); both fixed, then 25/25 | bulk of 3 with per-product audit, skip unchanged (no write, no audit, `updated_at` untouched), all-unchanged no-op, inactive scheme 422, DEC-COT-23 ask (2 prices, 1 product; nothing changes) and confirm (3 prices across 2 combinations and 2 tiers; other product's price kept), reassign without prices needs no confirmation, skipped product keeps its prices, combo id rejected, 6 malformed inputs, DEC-COT-52 without `products.view`, single equals bulk (old/new values), single asks and rejects inactive, 404 unknown product, rollback on unknown id, 403 audited on both routes, route order, duplicate ids once, tiers untouched | `AffectedPrices` got `forProducts` and `auditByProduct`, and `forTiers` shares one private `rows()` with it |
| 4.3 | `PriceSchemeTest`, `--group=cot` | Refactor | 46 Phase 3 tests green | n/a | `--group=cot` 244/244 after the refactor; PHPStan found 2 type errors (mixed id types), fixed with typed mapping and `$request->integer()` | n/a | `AffectedPrices` reused from 3b: no new helper |

### Gate (2026-10-13)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.46s)
- `sail artisan test`: passed, 1888 tests (1886 passed, 2 skipped, 10276 assertions)
- Harness (no reset): `sail artisan route:list --path=pricing/products` lists `pricing.products.scheme.bulk` (`pricing/products/scheme`) and `pricing.products.scheme.update` (`pricing/products/{product}/scheme`).

### Files
Modified: `app/Support/Pricing/AffectedPrices.php`, `lang/es/validation.php`, `routes/web.php`, `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `app/Actions/Pricing/AssignPriceScheme.php`, `app/Http/Requests/Pricing/AssignPriceSchemeRequest.php`, `app/Http/Controllers/Pricing/ProductSchemeController.php`, `tests/Feature/Pricing/SchemeAssignmentTest.php`.

### Deviations from design
1. Request fields (not fixed by the design): `product_ids` (list), `price_scheme_id`, optional `confirm_price_removal`. The single route merges `product_ids` from the bound product. The unconfirmed-removal answer is a 422 on `confirm_price_removal`.
2. The audit `{scheme}` is the scheme name (`old_values.scheme` is null for a product without scheme).
3. Inactive scheme and unknown product ids are checked in the Action under the locks (`ValidationException` on `price_scheme_id` / `product_ids`); the request only checks shape and existence. Duplicate ids are processed once.
4. Inactive products can be assigned (the spec only requires the scheme to be active); no spec text says otherwise.
5. `AssignPriceScheme` takes no `AuditOrigin`/context from the controller (defaults to request origin); the parameters exist for the initial load (Phase later).
6. The test file defines its own helpers because the 3b helpers live in `PriceSchemeTest.php` and are not loaded when a file runs alone.

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest tests/Feature/Pricing/SchemeAssignmentTest.php`: passed, 25 tests, 116 assertions; `sail pest --group=cot`: 244 passed |
| Runtime harness | `route:list --path=pricing/products`: both routes, bulk first |
| Rollback boundary | revert `AssignPriceScheme`, request, controller, the 2 routes and the `use`, the 4 lang lines, the `AffectedPrices` additions (`forProducts`, `auditByProduct`, `rows()` split) and the test file |

### Size
Authored changed lines (excluding `tasks.md`, `apply-progress.md`): about 633 (53 additions + 2 deletions in 3 modified files; 578 in 4 new files: production about 186, tests 392). Within the ~800 estimate and under the 1,100 split threshold; `size:exception` per the accepted 004 policy.

### Remaining
Task 4.4 commit: done (`27f884d`). Next: Phase 5a, below.

## Phase 5a (PR 7, `feat/004-pricing-and-quotations-5a-combination-prices`, base PR 6): tasks 5a.1, 5a.2, 5a.5, 5a.6 done; 5a.3 and 5a.4 (`MissingPrices`, E-26) ship in PR 8

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 5a.1/5a.2 | `tests/Feature/Pricing/CombinationPriceTest.php` | Integration (HTTP) | `--group=cot` 244 tests green before (Phase 4 state) | 32 tests: 1 passed (the 404 of an unknown product, vacuous before the route), 29 failed (404 instead of 302/422/403: no route), 2 errors (`PermissionName::ProductsManage` undefined: a test typo, fixed to `ProductsUpdate`; `getByName()` null on the route name) | First GREEN run 30/32; the 2 failures were `toBe` on JSON audit payloads (MySQL JSON reorders keys: `toEqual`, as noted in Phase 3). Then 32/32 | E-05 register 3 tiers then change one, comma/dot/integer input; only changed cells written (same value written as `40`, no audit, `updated_at` untouched; third identical save writes no audit at all); E-06 zero, `0,00`, negative, 3 decimals, text, exponent, plus smallest (0.01) and 2-decimal positives accepted, several invalid cells reported together with none saved, negotiation tier; DEC-COT-32 delete with audit and null over an empty cell; inactive combination; foreign combination, unknown ids, foreign tier, product without scheme, duplicate cell; size group required / foreign / unexpected and a grouped price per group; fixed scheme implicit tier; 6 malformed payloads; 404; E-07 403 audited with `pricing.view` only and with products permissions only; route declared | `AffectedPrices::sizeGroupName()` extracted so audit rows and the table share the group name; PHPStan: 4 errors (`__()` returns `array\|string`), fixed with `Lang::string()` |
| 5a.5 | architecture suites | Refactor | green | n/a | `NoManualForbiddenTest`, `ProtectedByDefaultTest` pass in the full suite; no `abort(403)` | n/a | none |

### Gate (2026-10-13)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.52s)
- `sail artisan test`: passed, 1920 tests (1918 passed, 2 skipped, 10419 assertions)
- Harness (no reset): `sail artisan route:list --name=pricing.products.prices` shows `PUT pricing/products/{product}/prices`.

### Files
Modified: `app/Support/Pricing/AffectedPrices.php` (`sizeGroupName()`), `lang/es/validation.php`, `routes/web.php`, `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `app/Actions/Pricing/SyncCombinationPrices.php`, `app/Http/Requests/Pricing/SyncCombinationPricesRequest.php`, `app/Http/Controllers/Pricing/ProductPriceController.php`, `tests/Feature/Pricing/CombinationPriceTest.php`.

### Deviations from design / choices the design did not fix
1. Request shape: `cells` (min 1) each `{combination_id, size_group_id|null, price_tier_id, price}`; `price` must be present and is a string (JSON numbers are rejected so no float travels); `null` deletes (DEC-COT-32). An empty string becomes `null` through the app middleware, so it also deletes.
2. Error fields: foreign combination on `cells.{i}.combination_id`; unknown or foreign tier on `cells.{i}.price_tier_id`; negotiation tier on `cells.{i}.price` (as the task says); group rules on `cells.{i}.size_group_id`; a repeated cell on `cells.{i}.price_tier_id`; a product without a scheme (or whose scheme changed under the lock) on `cells`. The design only fixed `cells.{i}.price` for E-06.
3. Cells are validated entirely before any write (one invalid cell saves nothing; all errors reported together).
4. Lock order: the scheme row (when the product has one), then the product row, re-checking that the scheme did not change.
5. Audit payload: `old_values` and `new_values` both carry the full `{code, size_group, tier, price}` (created: `old_values` empty; deleted: `new_values` empty). `size_group` is the sizes joined by ", " until `SizeGroupLabel` (PR 8); replace then.
6. Tasks 5a.3 and 5a.4 (`MissingPrices`, E-26, COT-025 list flag) are not in this PR by the 2026-10-13 regrouping, so the commit cites COT-003, E-05, E-06, E-07 and DEC-COT-32 only.

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest tests/Feature/Pricing/CombinationPriceTest.php`: passed, 32 tests, 141 assertions |
| Runtime harness | `route:list --name=pricing.products.prices`: the PUT route is listed |
| Rollback boundary | revert `SyncCombinationPrices`, its request, `ProductPriceController`, the route and its `use`, the lang entries, `AffectedPrices::sizeGroupName` and the test file |

### Size
Authored changed lines (excluding `tasks.md`, `apply-progress.md`): about 789 (30 additions + 1 deletion in 3 modified files; 758 in 4 new files: production about 315, tests 443). Under the 1,100 split threshold; `size:exception` per the accepted 004 policy.

### Remaining
PR 8 (Phase 5b1 plus tasks 5a.3 and 5a.4): see the next section. (Earlier note: "Next: PR 8".)

## Phase 5b1 + 5a.3/5a.4 (PR 8, `feat/004-pricing-and-quotations-5b1-size-group-core`, base PR 7): tasks 5b.1, 5b.2, 5a.3, 5a.4 done; gate green; NOT committed (size rule)

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 5b.1/5b.2 (label, rules, plan) | `tests/Unit/Pricing/{SizeGroupLabelTest,SizeGroupRulesTest,SizeGroupPlanTest}.php` | Unit (pure) | N/A (new) | 40 tests, 0 passed: 38 errors (`SizeGroupLabel`, `SizeGroupRules`, `SizeGroupPlan` not found) and 2 failures (an `Error` instead of `InvalidArgumentException`) | First GREEN attempt: the whole suite died silently (PHP fatal, `keptGroups()` declared twice, public accessor and private helper); fixed by renaming the helper to `matchStoredGroups`, then 39/40; the one failure was a test bug (a price without group is rightly listed when the proposal has groups), fixed in the test; then 40/40 | label: single run, runs with gaps, lone size, two neighbours, input order, palette gaps, repeated id, non numeric sizes, unknown size and empty group refused. rules: valid partitions in any order, empty list, repeated size, size left out, order of the names, both errors together, repeat inside one group, empty group, foreign size. plan: equal set, order-insensitive, DEC-COT-58 assign (one and several ungrouped sizes), move between groups, move plus join, merge, split, ungrouped to grouped (with and without prices), grouped to ungrouped, null prices left alone when ungrouping, kept group keeps its prices, one claim per stored group, empty stored group, extra size from another group, listing order, labels, token (sha256, null, order independent, value, updated_at, added/missing price, ignores kept prices) | none needed |
| 5a.3/5a.4 | `tests/Feature/Pricing/CombinationPriceTest.php` (`E-26`) | Integration (DB, query log) | 32 tests of the file green before | 12 tests, 12 errors (`MissingPrices` not found) | 12/12 on the first run | missing tier, all priced tiers without prices and no negotiation tier, inactive ignored, complete and not-requested products left out, per-group cells, ungrouped sizes, size restriction of the combination, ungrouped combination reports no sizes, inactive grouped combination, product without scheme, many products, empty list (0 queries); every case with data asserts 2 queries | PHPStan: 4 errors (`??=` on a reference, `stdClass` rows typed as shaped objects) fixed by indexing the array directly and typing rows `stdClass` |
| refactor | all of the above | Refactor | green | n/a | `pint`, `types:check` 0 errors; focused 52 tests green; `CombinationPriceTest` 44 green | n/a | n/a |

### Gate (2026-10-13)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.25s)
- `sail artisan test`: passed, 1972 tests (1970 passed, 2 skipped, 10556 assertions)
- Harness: N/A (pure classes and one query class, no route); `E-26` runs `MissingPrices` against MySQL and counts the SQL statements.

### Files
Modified: `tests/Feature/Pricing/CombinationPriceTest.php` (+269: E-26 tests and their helpers), `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `app/Support/Pricing/{SizeGroupLabel,SizeGroupRules,SizeGroupPlan,MissingPrices}.php`, `tests/Unit/Pricing/{SizeGroupLabelTest,SizeGroupRulesTest,SizeGroupPlanTest}.php`.

### Design choices and deviations
1. `SizeGroupLabel::of(list<int> $sizeIds, array<int,string> $palette)`: the design only says "from its sizes in size-value order"; contiguity needs the sizes the combination admits, so the palette (id => name, in size order) is a parameter. Throws `InvalidArgumentException` for an empty group or a size outside the palette. The CSV normalization of "-" versus "–" is not included (CSV phase).
2. `SizeGroupPlan::diff(stored, proposed, ungrouped, prices, palette)`: `$prices` is a list of plain rows `{id, size_group_id, tier, tier_order, price, updated_at}` (strings), not an Eloquent `Collection` as in the design signature, so the class is pure and unit-testable without the app container (`Unit` tests are plain). The Action of PR 9 maps `CombinationPrice` + tier to the rows. The extra `palette` parameter labels the dropped groups. Extra accessors: `keptGroups()` (proposed index => stored id), `droppedGroupIds()`, `newGroupIndexes()`, `deletedPriceIds()`, `requiresConfirmation()`.
3. Prices without group are deleted whenever the proposal has groups (they cannot coexist with groups); with an empty proposal they are left alone. The listing order puts the "Sin grupo" prices first, then the stored group order, then tier order. The confirm token only hashes the deleted prices (id, price string, `updated_at`), sorted by id, so it is stable under input order and ignores kept groups.
4. `SizeGroupRules::validate(groups, admitted)`: errors `groups` (repeated or missing sizes, one Spanish message naming the sizes) and `groups.{i}` (empty group, foreign size). Same shape as `SchemeRules`.
5. `MissingPrices::forProducts()` returns `array<productId, list<{combination_id, code, missing: list<{size_group_id, price_tier_id, tier}>, ungrouped_sizes: array<sizeId, name>}>>`, only incomplete combinations. Admitted sizes follow the `ProductPresenter::stockEditor` rule (restriction of the combination on the size attribute, otherwise every allowed size of the product's size order attribute). Products without a scheme are not listed (they are not quotable for another reason, COT-025 first clause). Two raw queries (`DB::table`), no Eloquent hydration.
6. `AffectedPrices::sizeGroupName()` (sizes joined by ", ") is NOT replaced by `SizeGroupLabel` in this PR: tasks.md does not say where, and a faithful label needs the admitted sizes of the combination, which PR 9 loads for the Actions. Replace it there (the audits of `SyncCombinationPrices` and the scheme changes then use the label).
7. Process slip to disclose: the E-26 tests were appended to `CombinationPriceTest.php` with a shell heredoc (one `cat >>` command) instead of the Edit tool. The content is a pure append reviewed afterwards; nothing else was edited that way.
8. `.codegraph/` was created by `gentle-ai codegraph init` (project rule) and is untracked; it is not part of the PR files.

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest --group=cot --filter='SizeGroupPlan\|SizeGroupLabel\|SizeGroupRules\|E-26 \|DEC-COT-53\|DEC-COT-58\|E-08 '`: passed, 52 tests, 137 assertions; `CombinationPriceTest.php`: 44 passed |
| Runtime harness | N/A: pure classes and one query class, no route; E-26 exercises the query on MySQL |
| Rollback boundary | revert the four Support classes, the three unit test files and the E-26 block (plus its imports) of `CombinationPriceTest.php` |

### Size and proposed split (commit withheld)
Authored changed lines (excluding `tasks.md`, `apply-progress.md`): **1,271** (269 additions in the modified test file; new files: production 549 = `SizeGroupPlan` 221, `MissingPrices` 171, `SizeGroupRules` 84, `SizeGroupLabel` 73; tests 453 = `SizeGroupPlanTest` 312, `SizeGroupRulesTest` 77, `SizeGroupLabelTest` 64). The estimate was ~700; the tests are the bulk (722). Over the 1,100 rule, so nothing was committed. Proposed split, both halves already green independently (no shared code between them):
- **PR 8a** (~830): `SizeGroupLabel`, `SizeGroupRules`, `SizeGroupPlan` and their three unit test files (tasks 5b.1, 5b.2). Commit: `feat(004): derivar etiquetas, validar particiones y planificar el cambio de grupos de talla [DEC-COT-53, DEC-COT-58, E-08]`.
- **PR 8b** (~440): `MissingPrices` and the E-26 block of `CombinationPriceTest.php` (tasks 5a.3, 5a.4). Commit: `feat(004): marcar combinaciones activas con precios y tallas sin definir [COT-025, E-26]`.
Everything is in the working tree on `feat/004-pricing-and-quotations-5b1-size-group-core`; 8b would branch from 8a (PR 9 then targets 8b).

### Remaining
Split applied: PR 8a `180ec79`, PR 8b `ad864b8`. PR 9 (5b.3-5b.7): see the next section.

## Phase 5b2 (PR 9a, `feat/004-pricing-and-quotations-5b2-size-group-endpoints`, base PR 8b): tasks 5b.3, 5b.4, 5b.6 done; 5b.7 gate green; split applied (5b.5 ships in PR 9b)

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 5b.3/5b.4 (E-08, E-70, DEC-COT-53/58) | `tests/Feature/Pricing/SizeGroupTest.php` | Integration (HTTP) | `tests/Feature/Products` + `Pricing`: 707 tests (706 passed, 1 skipped) green before | 30 tests (25 after the split; the figures below are from the unsplit run): 2 passed (404 of an unknown combination, vacuous before the route; the "edit removes no size" regression), 23 failed (404 instead of 302/422/403/200), 5 errors (route names null, `getByName()`) | 28/30 on the first GREEN run (one failure: the preview test counted the test user and session inserts as preview writes; one error: a test built two fixtures and created `Talla` twice), 29/30 after fixing the fixture, 30/30 after excluding the framework's own inserts; both fixes were in the tests, not the code | valid groups 28–36/38–44 with order and audit; repeats 36 and leaves 44 out (message asserted); empty group and foreign size; restricted combination admits a subset; product without the size order attribute (axis, then absent); 5 malformed payloads; 404; identical groups = no write, no audit; preview lists 3 prices with display `1.150,00`, no lock, no write, no audit; preview without prices; PUT without token 422; PUT with token (locks, deletes, one audit, `MissingPrices` 6 cells); stale token after an edit and after an added price, then fresh token succeeds; remove groups (order group, tier); merge; DEC-COT-58 assign keeps ids and prices; move drops both groups; 403 x3 audited; routes | `SizeGroupState`, `AdmittedSizes` extracted; PHPStan: 7 errors fixed by real typing |
| 5a (label) | `tests/Feature/Pricing/CombinationPriceTest.php` | Integration | 44 tests green | the grouped-price test expected the derived label `S–M`/`L`: failed with `S, M` | passed after `AffectedPrices::sizeGroupLabel` | two groups; scheme-change audit also asserts `28–36` (SizeGroupTest) | none |
| 5b.5 (DEC-COT-48) | n/a | n/a | n/a | moved to PR 9b | n/a | n/a | n/a |
| 5b.6 | all | Refactor | green | n/a | `composer types:check` 0 errors; `app/Support/Products/Selection` untouched | n/a | n/a |

### Gate (PR 9a, 2026-10-14, after the split)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.28s)
- `sail artisan test`: passed, 1997 tests (1995 passed, 2 skipped, 10726 assertions)
- Harness (no reset, from the unsplit run): `sail artisan route:list --name=pricing.size-groups` lists `POST pricing/combinations/{combination}/size-groups/preview` and `PUT pricing/combinations/{combination}/size-groups`.

### Files (PR 9a)
Modified: `app/Actions/Pricing/SyncCombinationPrices.php`, `app/Support/Pricing/AffectedPrices.php`, `lang/es/validation.php`, `routes/web.php`, `tests/Feature/Pricing/CombinationPriceTest.php`, `openspec/changes/004-pricing-and-quotations/tasks.md`.
Created: `app/Actions/Pricing/{PreviewSizeGroups,SyncSizeGroups}.php`, `app/Http/Controllers/Pricing/SizeGroupController.php`, `app/Http/Requests/Pricing/SizeGroupsRequest.php`, `app/Support/Pricing/{AdmittedSizes,SizeGroupState}.php`, `tests/Feature/Pricing/SizeGroupTest.php`.

### Design choices and deviations
1. `SizeGroupLabel` replaces `AffectedPrices::sizeGroupName()`: `sizeGroupLabel(?SizeGroup, palette)`. The palette (what the combination admits) comes from the new `AdmittedSizes::forCombinations()` (two queries for any number of combinations). The label is strict: a group with no sizes, or sizes outside the palette, throws `InvalidArgumentException`; the existing grouped-price test was updated to declare sizes (its second group had none). `SyncCombinationPrices` labels only the groups of cells it writes, so a rejected cell never reaches the label.
2. `SizeGroupState` (new, `app/Support/Pricing/`) loads palette, stored groups and price rows once for the preview and the save (`loadValidated()` applies `SizeGroupRules`); the Actions stay short and share one answer. Palette order, not stored order, orders the sizes inside a group.
3. Request fields: `groups` (list of lists of size ids; an empty list removes the grouping; required) and optional `confirm_token`. Preview is `POST` with the same body. Errors: `groups` (partition errors, "Confirme la eliminación de los precios afectados.", "Los precios cambiaron; revise de nuevo los grupos.", "Esta combinación no admite grupos de talla."), `groups.{i}`.
4. A combination whose product does not declare Talla as an **order** attribute is refused with 422 `groups` even for an empty list (nothing to group, nothing to remove).
5. Saving groups identical to the stored ones (same sizes, same order) returns without writing or auditing. A token sent when nothing is deleted is ignored.
6. Audit `pricing.size_groups_updated` (subject: the combination): `old_values {partition, deleted_prices}` and `new_values {partition}` with partitions as size names per group (palette order); `deleted_prices` rows are `{code, size_group, tier, price}` like every other 004 audit (design Decision 10 text says `{group, tier, price}`; the audit table does not fix the keys). DEC-COT-48 audits add `removed_sizes` to `old_values` and `context.reason = size_removed` (plus the import marker when the caller has one).
7. The preview answers `group` as the label or `null` (the UI shows "Sin grupo"), `price` as `{value, display}` (`40.00` / `40,00`, `1150.00` / `1.150,00`).
8. (PR 9b, not in PR 9a) `SizeGroupMaintenance` has `removeSizes()` (as designed) and `restrictTo(Combination, restrictions, actor)`, which `UpdateCombination` calls: it finds the sizes the saved restriction no longer admits (`AdmittedSizes::narrowedBy`) and skips every query when the combination has no groups. Both 003 hooks run **before** the change is persisted, so the groups are labelled against the sizes they were defined for.
9. `SyncSizeGroups` locks only the product row (the lock that price writes and 003 combination writes take); it does not read tiers, so the scheme lock is not needed (no deadlock: SyncCombinationPrices takes scheme then product).

### Work unit evidence
| Evidence | Value |
|---|---|
| Focused test | `sail pest tests/Feature/Pricing/SizeGroupTest.php tests/Feature/Pricing/CombinationPriceTest.php`: passed, 74 tests; `sail pest tests/Feature/Products tests/Feature/Pricing tests/Unit`: 1190 passed, 1 skipped |
| Runtime harness | `route:list --name=pricing.size-groups`: both routes listed |
| Rollback boundary | revert the 3 Actions, request, controller, 2 routes and the `use`, `AdmittedSizes`, `SizeGroupState`, the 3 lang lines, the two 003 hook calls (and constructor parameters), `sizeGroupLabel` in `AffectedPrices`/`SyncCombinationPrices`, and the test edits |

### Size and split (applied)
The unsplit work was 1,427 authored lines (below); it was split as proposed. PR 9a (this commit) holds about 1,073 (67 changed in 5 modified files; 1,006 in 6 new files); PR 9b holds the rest. Details of the unsplit measurement: authored changed lines (excluding `tasks.md`, `apply-progress.md`): **1,427** (101 changed in 7 modified files: 72 additions, 29 deletions; 1,326 in 8 new files: production 649 = `SizeGroupMaintenance` 157, `SizeGroupState` 141, `SyncSizeGroups` 118, `AdmittedSizes` 110, `SizeGroupsRequest` 51, `PreviewSizeGroups` 38, `SizeGroupController` 34; tests 677). The estimate was ~550; the endpoint/state classes and the 30-test file are the bulk. Over the 1,100 rule, so nothing was committed. Proposed split (by construction 9a does not need 9b; not executed separately):
- **PR 9a** (~1,080): everything except DEC-COT-48: `PreviewSizeGroups`, `SyncSizeGroups`, `SizeGroupState`, `AdmittedSizes::forCombinations`, request, controller, routes, lang, the `SizeGroupLabel` replacement (`AffectedPrices`, `SyncCombinationPrices`, `CombinationPriceTest` edit) and the SizeGroupTest sections E-08, E-70, DEC-COT-58, authorization and routes (tasks 5b.3, 5b.4 and 5b.6). Commit: `feat(004): definir grupos de talla con confirmación de eliminación de precios [COT-004, E-08, E-70, DEC-COT-53, DEC-COT-58]`.
- **PR 9b** (~350): `SizeGroupMaintenance`, `AdmittedSizes::narrowedBy`, the two 003 hooks and the 5 DEC-COT-48 tests with `sgEditor` and `sgStructure` (task 5b.5). Commit: `feat(004): sacar la talla de su grupo de talla al quitarla del producto o de la combinación [COT-004, DEC-COT-48]`.
PR 9a is committed (`588d48c`) on `feat/004-pricing-and-quotations-5b2-size-group-endpoints`; PR 9b branches from it as `feat/004-pricing-and-quotations-5b2b-size-group-maintenance`.

## Phase 5b2b (PR 9b, `feat/004-pricing-and-quotations-5b2b-size-group-maintenance`, base PR 9a): task 5b.5 done; gate green

Restores the DEC-COT-48 content withheld from PR 9a, identical to the full Phase 5b2 work: `SizeGroupMaintenance` (new), `AdmittedSizes::narrowedBy`, the two 003 hooks (`SyncProductAttributes`, `UpdateCombination`, with their constructor parameter) and the 5 DEC-COT-48 tests with `sgEditor` and `sgStructure` in `SizeGroupTest.php`. TDD evidence and design choice 8 are in the Phase 5b2 section above (RED: 4 of the 5 DEC-COT-48 tests failed before the hooks; the fifth is a regression guard).

### Gate (PR 9b, 2026-10-14)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.39s)
- `sail artisan test`: passed, 2002 tests (2000 passed, 2 skipped, 10758 assertions)

### Size
About 358 authored changed lines (excluding `tasks.md`, `apply-progress.md`): 195 additions and 6 deletions in 4 modified files, 157 in `SizeGroupMaintenance`.

### Remaining
Phase 6 (PR 10).

## Phase 6a (PR 10, `feat/004-pricing-and-quotations-6a-combo-item-prices`, base PR 9b): price half of tasks 6.1/6.2 done; gate green, commit pending

### TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED (observed) | GREEN | Triangulate | Refactor |
|------|-----------|-------|-----------|----------------|-------|-------------|----------|
| 6.1 (prices) | `tests/Feature/Pricing/ComboItemPriceTest.php` | Feature (HTTP) | new file | 22 failed + 3 errors: route absent (404), `getByName` null; 2 fixture collisions fixed (unique combo/product names) | 26/26 passed, 102 assertions | create/update/delete audit snapshots, component number by combo order (not id), unchanged value `22,5 = 22.50` writes nothing, 6 invalid amounts, foreign component, duplicate, inactive combo, malformed payloads, 404, 403 audited, 403 with product permissions only | none needed |
| 6.2 (prices) | — | — | — | — | `SyncComboItemPrices`, `SyncComboItemPricesRequest`, `ComboPriceController`, route `PUT /pricing/combos/{combo}/prices`, 2 lang lines | — | — |

Design choices: the audit `component` is the 1-based number of the component in the combo order (`sort_order`, then id), the same "número de componente" the price import uses (spec §COT-028 columns); `product` is the product name (products have no code). The combo row is the lock (Decision 12).

### Gate (PR 10, 2026-10-14)
- `sail pint --test`: passed
- `sail composer types:check`: passed, 0 errors
- `sail pnpm check`: passed (92 files formatted, no lint errors)
- `sail pnpm types:check`: passed (vue-tsc, no output)
- `sail pnpm build`: passed (built in 1.37s)
- `sail artisan test`: passed, 2028 tests (2026 passed, 2 skipped, 10862 assertions)

### Size
522 authored changed lines (excluding `tasks.md`, `apply-progress.md`): 239 production in 5 files, 283 tests.

### Suggested commit
`feat(004): registrar precios de ítems de combo [COT-005, DEC-COT-06]`

### Remaining
Phase 6b (PR 11): DEC-COT-44 half of 6.1/6.2 plus 6.3 and the 6.4 gate.
