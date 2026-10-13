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
Task 2.13 commit (user authorization). Phase 3 not started.
