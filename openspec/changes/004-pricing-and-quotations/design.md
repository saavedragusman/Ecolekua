# Design: 004-pricing-and-quotations — Price Schemes, Prices, Exchange Rate, Quotation Calculation, ERP Quotations, Printable View, Email and Bulk CSV Price Updates

> Source of truth: `docs/specs/004-pricing-and-quotations.md` (status "Lista para Design"; COT-001..COT-031, E-01..E-73, DEC-COT-01..DEC-COT-59 all `Confirmada`; DEC-COT-57..59 are the user-confirmed notes N-1, N-9 and N-10, so the AGENTS.md §4.4 gate is open). DT-01..DT-06 are **confirmed here** (Decisions 3, 14, 18, 24, 11, 21).
> Inputs: `proposal.md`, `specs/pricing-and-quotations/spec.md`, `exploration.md` (§1 current state, D1–D9, C1–C9, §7 risks), the archived `003-products` design (conventions), `docs/constitution.md`, `openspec/config.yaml` `rules.design`, and the current code read directly (the repository has no `.codegraph/` index).
> This file is the project's only plan artifact (`rules.design`). There is no separate `plan.md`.
> The four business gaps found in the first design pass were confirmed by the user on 2026-10-13 as **DEC-COT-53..DEC-COT-56** (E-70..E-73) and are designed in Decisions 10, 15, 17 and 19. No business decision is pending; the interpretations that remain are listed as notes N-1..N-10 in Open Questions for the user's awareness.

## Technical Approach

004 extends the 001–003 pattern without new architecture: **thin controller → FormRequest (validation + `authorize()` through a Policy) → Action in `app/Actions/Pricing/` or `app/Actions/Quotations/` (business rules + `DB::transaction` + `RecordAuditEvent` in the same transaction)**. Pure, Eloquent-free logic shared by several callers (HTTP Actions, the console load, the calculation, unit tests) lives in `app/Support/Pricing/` and `app/Support/Quotations/`.

Eight design moves carry the change:

1. **Exact money everywhere** (DT-01). `DECIMAL` columns, `Brick\Math\BigDecimal` in PHP through one `Money` helper and one Eloquent cast, `HALF_UP` only at the points the spec names. The backend sends amounts as strings (canonical and display); the frontend never does arithmetic.
2. **Configuration as rows** (constitution §11). Schemes and tiers are rows; a fixed-price scheme owns one implicit tier, so every price is keyed `(combination, tier, size group?)` and no `NULL` tier exists (D9).
3. **One calculation engine** (DT-02). `CalculateQuotation` resolves every size row through 003's `ResolveSelection` with a **per-calculation memoizing loader** (D1), reads product facts and prices in a constant number of queries (D2), and delegates counting, tier choice, advance, Bs conversion and pack notices to pure classes. It never writes and never calls the rate API.
4. **Drafts store input, issued quotations store a frozen copy.** A draft line keeps what the user chose (selection, size rows, customizations, components, note, negotiated price) and is recalculated on open and on save. Issuing writes the frozen copy (prices, tier, origin, subtotals, totals, advance, rate, Bs, recipient and advisor display data) into dedicated columns under a row lock (DT-03).
5. **Effective status on read + scheduled persistence** (COT-017, D5). An issued quotation past its expiry day is shown as expired by a SQL expression even before the daily command persists and audits the transition; write paths persist a due expiry before checking state.
6. **Append-only exchange rates with one effective row per day** (COT-030, D4). A correction supersedes the previous row; a generated unique key guarantees one effective rate per date in the database.
7. **Integrations after commit** (constitution §15, DT-05, DT-06). The rate API is called only by a scheduled command; email is a queued job per recipient dispatched after commit; domain events for `015` are dispatched after commit with no listener in 004.
8. **One price CSV format** (DT-04). Export, ERP import and files 2 and 3 of the initial load share one reader (an extension of `CsvReader`), one writer and one `ValidatePriceImport` Action; apply re-checks the stored previous values under lock.

Requirement to mechanism map:

| Requirement | Mechanism |
|---|---|
| §4, DEC-COT-25, DEC-COT-52 | 6 `PermissionName` cases, `InitialRolePermissions::MATRIX`, two coherence blocks in `SyncRolePermissions::ensureCoherent()` (Decisions 6, 7) |
| COT-001 | `price_schemes`, `price_tiers`, `SchemeRules` (pure), `CreatePriceScheme`, `UpdatePriceScheme`, `ActivatePriceScheme`, `DeactivatePriceScheme`, `PriceSchemeSeeder` (Decision 8) |
| COT-002 | `products.price_scheme_id`, `AssignPriceScheme` (one or many products) (Decision 9) |
| COT-003, COT-025 (list flag) | `combination_prices`, `SyncCombinationPrices`, `MissingPrices` query (Decision 10) |
| COT-004, DEC-COT-53 | `size_groups`, `size_group_values`, `SizeGroupPlan` (pure), `PreviewSizeGroups`, `SyncSizeGroups` (two-step confirm), `SizeGroupMaintenance` called from 003 Actions (Decision 10) |
| COT-005 | `combo_item_prices`, `SyncComboItemPrices`, `ComboComponents::replace` rewrite with id and product fallback (Decision 12) |
| COT-006 | `commercial_parameters` single row, `UpdateCommercialParameters` (Decision 13) |
| COT-030, §8 rate history | `exchange_rates`, `FetchExchangeRate` command + Action, `RecordManualExchangeRate` (ordered supersede), `CurrentExchangeRate`, `ExchangeRate::history()` (Decision 11) |
| COT-008..COT-012, COT-025, DEC-COT-54, DEC-COT-55 | `CalculateQuotation`, `Support/Quotations/Calculation/*` incl. `PackCatalog`, `PackAdmission`, negotiation basis (Decisions 14–17) |
| COT-013..COT-015 | `quotations` and child tables, `QuotationNumber` sequence, `CreateQuotation`, `UpdateQuotationDraft` (Decisions 18, 19) |
| COT-012 (persisted), COT-010 (agreed) | `SetNegotiatedPrice`, `SetAgreedAdvance` (Decision 17) |
| COT-016 | `IssueQuotation` (Decision 18) |
| COT-017 | `QuotationStatusResolver` (read), `ExpireQuotation`, `quotations:expire` (Decision 20) |
| COT-018, COT-019 | `DuplicateQuotation`, `CancelQuotation`, `DiscardQuotation` (Decision 20) |
| COT-020 | `QuotationController@index/show`, `QuotationPresenter`, customer history prop (Decision 27) |
| COT-021, COT-031, DEC-COT-46, DEC-COT-50 | `config/company.php`, shared Blade partial, `QuotationPrintController`, `SendQuotationEmail` job, `quotation_email_deliveries` (Decision 21) |
| COT-022 | `PricingPolicy`, `QuotationPolicy` (Decision 6) |
| COT-023 | 26 new `AuditAction` cases (Interfaces) |
| COT-024 | history guards in `DeleteCustomer`, `DeleteProduct`, `DeleteCombination`, `DeleteCombo`, `UpdateCombination` (Decision 22) |
| COT-026..COT-029 | `CsvReader::readDocument`, `CsvWriter`, `PriceTemplate`, `ExportPrices`, `ValidatePriceImport`, `ApplyPriceImport`, `DiscardPriceImport`, `pricing:expire-imports` (Decisions 23, 24) |
| COT-007 | `pricing:load` command (Decision 25) |
| §10 (`015`) | six after-commit events, no listeners (Decision 26) |
| §8 | Inertia pages and components (Decision 28) |

## Architecture Decisions

### Decision 1: Module layout mirrors 001–003

**Choice**: Controllers in `app/Http/Controllers/Pricing/` and `app/Http/Controllers/Quotations/`; FormRequests in `app/Http/Requests/Pricing/` and `app/Http/Requests/Quotations/`; Actions in `app/Actions/Pricing/` (schemes, assignment, prices, size groups, combo prices, parameters, rates, export, import, load) and `app/Actions/Quotations/` (calculation, lifecycle, negotiation, email), as spec §12 requires. Pure logic in `app/Support/Pricing/` (`Money`, `SchemeRules`, `Import/*`, `RateParser`) and `app/Support/Quotations/` (`Calculation/*`, `QuotationStatusResolver`, `QuotationPresenter`). Reads call `Gate::authorize()` in the controller; writes authorize in `FormRequest::authorize()`, or in the controller before the Action for body-less endpoints (issue, duplicate, discard, resend, activate), exactly as 003.
**Alternatives considered**: one `Commercial` namespace for both areas (rejected: spec §12 names both namespaces). A service/repository layer (rejected: AGENTS.md §7.10).
**Rationale**: `rules.design`; authorization runs before any transaction, so a denied request has no side effects and `RenderAccessDenied` audits it.

### Decision 2: Enums

**Choice** (string-backed, English values, Spanish `label()`, constitution §21.1, spec §5):

| Enum | Cases (value → label) | Used by |
|---|---|---|
| `PriceSchemeType` | `tiered` → "Por tramos", `fixed` → "Precio fijo" | schemes (DEC-COT-51: set on create) |
| `PriceTierKind` | `priced` → "Con precio", `negotiation` → "Negociación" | tiers |
| `QuotationStatus` | `draft` → "Borrador", `issued` → "Emitida", `expired` → "Vencida", `cancelled` → "Anulada", `approved` → "Aprobada" | quotations; `badge()` → `neutral`/`active`/`pending`/`neutral`/`done` (COT-015) |
| `QuotationOrigin` | `advisor` → "Asesora", `web` → "Web" | quotations (`web` is written by `005`) |
| `PriceOrigin` | `tier` → "Tramo", `fixed` → "Fijo", `combo` → "Combo", `negotiated` → "Negociado" | lines |
| `ExchangeRateOrigin` | `api` → "API", `manual` → "Manual" | rates |
| `EmailDeliveryStatus` | `pending` → "En cola", `sent` → "Enviado", `failed` → "Fallido" | deliveries (Decision 21) |
| `PriceImportScope` | `scheme_prices` → "Precios de combinaciones de un esquema", `combo_item_prices` → "Precios de ítems de combos" | imports |
| `PriceImportStatus` | `validated` → "Validada", `applied` → "Aplicada", `discarded` → "Descartada" | imports; `badge()` applied → `done`, discarded → `neutral` |
| `LineKind` | `product` → "Producto", `combo` → "Combo" | lines |

`CatalogStatus` (003) is reused for scheme status. `approved` exists so the column and badges are complete; no 004 code path sets it (spec §10, `006`).
**Rationale**: spec §5, COT-015, §8.

### Decision 3: DT-01 confirmed — exact money

**Choice**: DT-01 is **confirmed**.
- `brick/math` is declared as a **direct** `require` in `composer.json` with the version Laravel already installs (`1.0.0` in `composer.lock` on 2026-10-13; owner-approved 2026-10-13). No other money library.
- `App\Support\Pricing\Money` (static, pure): `parse(string $input, int $maxScale): BigDecimal` (accepts `.` or `,` as decimal separator, no thousands separator, no exponent; throws `InvalidAmount`), `round(BigDecimal, int $scale = 2): BigDecimal` (`RoundingMode::HALF_UP`), `toStorage(BigDecimal, int $scale): string`, `display(BigDecimal|string, int $scale = 2): string` (es-VE display: `1.150,00`), `percentOf(BigDecimal $percent, BigDecimal $amount): BigDecimal` (exact, no rounding).
- `App\Casts\AsBigDecimal:{scale}` casts a `DECIMAL` column to `BigDecimal` and back (`toScale($scale)` on write; a value with more decimals than the scale is a programming error and throws, never silently rounds). Models never use the `decimal:2` string cast for amounts that take part in arithmetic.
- Rounding points (DEC-COT-02, nothing else rounds): a price entered with more than 2 decimals is **rejected** on the ERP table (E-06) and **rounded with a warning** on CSV import (DEC-COT-33) or **rounded** on the initial load (COT-007); the required advance is rounded **once** on the sum of its three parts (COT-009 step 9, COT-010); the proposed agreed advance is rounded; each Bs equivalent is rounded; the API `promedio` is rounded to 2 decimals (DEC-COT-60). Subtotals and totals are exact products and sums of 2-decimal values, so they are exact at scale 2.
- **API `promedio` without `float`**: the response body is first decoded to check structure, then the `promedio` number token is extracted from the **raw body** with a JSON-number regex and parsed with `BigDecimal::of()`; it is never read from the decoded array. Rounded `HALF_UP` to 2 decimals (DEC-COT-60).
- Precision: prices `DECIMAL(10,2)`; line and quotation amounts `DECIMAL(14,2)`; Bs amounts `DECIMAL(18,2)`; rate `DECIMAL(14,2)` (DEC-COT-60); percentage `DECIMAL(5,2)`.
- Frontend: amounts arrive as `{value: "1150.00", display: "1.150,00"}`; inputs are text fields with `inputmode="decimal"` sent as strings. TypeScript types for amounts are `string`.
- **Money inside JSON is always a string.** Every amount written to a JSON column (`quotations.advance_breakdown`, the frozen copies `recipient_snapshot`/`advisor_snapshot` if they ever carry an amount, `price_import_changes.warnings` variation values, `price_imports.summary`) and to every audit payload (`old_values`, `new_values`, `deleted_prices`, `deleted_item_prices`, `prices`/`item_prices` copies, `quotations.discarded` full copy) is serialized with `Money::toStorage()` as a canonical string (`"40.00"`, rate `"875.6505"`, percentage `"50.00"`, variation `"12.50"`), never a JSON number. Piece counts (`pieces_by_line`) and row counts are integers, not money. `Money::toStorage()` is the only serializer; no 004 code passes a `BigDecimal` or a numeric value straight to `json_encode`. Enforced by the architecture test `MoneyJsonHasNoFloatTest` and the `toBeMoneyString()` expectation (Testing Strategy).
**Alternatives considered**: `bcmath` functions (no rounding mode helper, string-in/string-out everywhere, easy to misuse). Integer cents (Bs products need 4 decimals before rounding; mixed scales make cents error-prone).
**Rationale**: DT-01, DEC-COT-01, DEC-COT-02, AGENTS.md §8, spec §12 ("ningún importe usa `float`").

### Decision 4: Data model and database guarantees

**Choice**: five additive migrations (one per slice that first needs them; full schema in Interfaces). Models: `PriceScheme`, `PriceTier`, `CombinationPrice`, `SizeGroup`, `ComboItemPrice`, `CommercialParameters`, `ExchangeRate`, `PriceImport`, `PriceImportChange`, `Quotation`, `QuotationLine`, `QuotationLineRow`, `QuotationLineComponent`, `QuotationEmailDelivery`. Pivot-like tables without models: `size_group_values`, `quotation_line_customizations`, `quotation_line_negotiated_prices`, `quotation_sequences`.

| Rule | Database | Action (friendly error) |
|---|---|---|
| Scheme name unique, case-insensitive (COT-001) | `price_schemes.name` unique, `utf8mb4_0900_as_ci` (003 Decision 3 collation) | `Rule::unique` |
| Tier label unique within a scheme and distinct from fixed CSV headers (Decision 8) | unique `(price_scheme_id, label)` as_ci | `SchemeRules` |
| One price per combination, tier and size group (COT-003, COT-004) | unique `(combination_id, price_tier_id, size_group_key)`, `size_group_key STORED IFNULL(size_group_id, 0)` (003 `size_key` precedent) | Action |
| Price > 0 (DEC-COT-02) | CHECK `unit_price > 0` on every price table | `Money::parse` + rule |
| A size in at most one group of a combination (COT-004) | `size_group_values` PK `(size_group_id, size_value_id)` + unique `(combination_id, size_value_id)` (denormalized `combination_id`) | `SizeGroupRules` |
| One price per combo component (DEC-COT-06) | `combo_item_prices.combo_component_id` unique | Action |
| Quotation number unique (spec §12) | `quotations.number` unique; `quotation_sequences` single row | `QuotationNumber::next()` under lock (Decision 19) |
| One effective rate per day (spec §12, COT-030) | `effective_date STORED IF(superseded_at IS NULL, rate_date, NULL)` unique | Decision 11 |
| Recipient is a customer **or** a prospect (COT-013) | CHECK `(customer_id IS NULL) <> (prospect_name IS NULL)` | `QuotationRules` |
| Quotation data restricts deletes toward customers, products, combinations, combos (COT-024) | `restrictOnDelete()` on `quotations.customer_id`, `quotation_lines.product_id/combination_id/combo_id`, `quotation_line_components.combination_id`, `quotation_line_customizations.service_product_id` | history guards (Decision 22) |
| Price rows of a deletable combination go with it (C4) | `combination_prices.combination_id`, `size_groups.combination_id`, `combo_item_prices.combo_component_id` **cascade** | `DeleteCombination` / `DeleteCombo` copy prices into the audit first |
| Size rows unique per line (COT-014) | unique `(quotation_line_id, size_key)`, `size_key STORED IFNULL(size_value_id, 0)` | `QuotationRules` (E-35) |

Exploration **C4** is resolved by this split: FKs from quotation data restrict; FKs from configuration (prices, groups) cascade, and the delete Actions add the prices to the existing full-copy audit payload. The DoD line "FKs restrict toward customers, combinations and combos" is honored for every table that represents history.
CHECK constraints are added with `DB::statement('ALTER TABLE … ADD CONSTRAINT … CHECK (…)')` (MySQL-only, as 003).
**Rationale**: spec §5, §12, COT-024, DEC-COT-24.

### Decision 5: Permissions and audit catalog

**Choice**:
- `PermissionName` gains `QuotationsView`, `QuotationsCreate`, `QuotationsNegotiate`, `QuotationsCancel`, `PricingView`, `PricingManage` (22 → 28) with the spec §4 Spanish descriptions.
- `InitialRolePermissions::MATRIX` gains the §4 table (Interfaces). The existing created-in-this-run rule applies it to fresh **and existing** databases on the next `FoundationSeeder` run and never re-grants a revoked pair (002 Decision 6, unchanged; spec §4 "design.md define cómo se aplica").
- `AuditAction` gains 26 cases (48 → 74; Interfaces), with unique Spanish labels.
- 001–003 tests that hard-code counts are updated (Decision 30).
**Rationale**: spec §4, COT-023, FND-017, FND-022.

### Decision 6: Authorization

**Choice**: permission-only policies; no role names, no `Gate::before`, no `abort(403)` (`NoManualForbiddenTest` guards `app/`).

| Policy | Ability | Permission |
|---|---|---|
| `PricingPolicy` (registered with `Gate::policy()` in `AppServiceProvider` for `PriceScheme`, `CombinationPrice`, `ComboItemPrice`, `CommercialParameters`, `ExchangeRate`, `PriceImport`, as `registerCatalogPolicy` in 003) | `view` (screens, export, import history) | `pricing.view` |
| | `manage` (schemes, assignment, prices, groups, combo prices, parameters, manual rate, upload, apply, discard) | `pricing.manage` |
| `QuotationPolicy` (auto-discovered) | `viewAny`, `view`, `print` | `quotations.view` |
| | `create`, `update`, `issue`, `resend`, `duplicate`, `discard`, `calculate` | `quotations.create` |
| | `negotiate` | `quotations.negotiate` |
| | `cancel` | `quotations.cancel` |

- Abilities answer **only** the permission. State rules (draft only, issued only, same user for import apply) are business rules answered with `ValidationException` or `BusinessRuleViolation` (422 / flash), so E-38 is a rejection, not a 403. Exception: COT-028 "el mismo usuario que validó" is checked in `ApplyPriceImport`/`DiscardPriceImport` as `BusinessRuleViolation` (it is a rule about the import, not a permission).
- The calculation endpoint (COT-022: from the ERP it requires `quotations.create`): when the payload carries a negotiated price or an agreed advance, the controller also authorizes `negotiate` (E-20 "envía un precio negociado o un anticipo acordado → 403").
- Pricing screens do not require `products.view` (DEC-COT-52): they read product and combination reference data (code, name, status) through their own queries, never through 003 controllers.
- Customer show (COT-020, CLI-013): the quotation history prop is included only when `can('viewAny', Quotation::class)`.
**Rationale**: COT-022, spec §4, DEC-COT-52, AGENTS.md §7.2–§7.3.

### Decision 7: Permission coherence

**Choice**: `SyncRolePermissions::ensureCoherent()` gains two blocks after the `products.*` one, in the same style (prefix filter over `PermissionName::cases()`): any `quotations.*` other than `quotations.view` without `quotations.view` → "Los permisos de cotizaciones requieren también «Ver cotizaciones, su detalle, su vista imprimible y el historial en la ficha del cliente».", and `pricing.manage` without `pricing.view` → "El permiso «Gestionar precios…» requiere también «Ver esquemas, precios…»." (exact descriptions from §4). It runs before any write and before the "nothing changed" return, so the role never changes and nothing is audited (E-47). The docblock cites spec 004 §4. No dependency on `products.view` (DEC-COT-52).
**Rationale**: spec §4, E-47.

### Decision 8: Price schemes and tiers (COT-001)

**Choice**:
- `CreatePriceScheme::handle(array $data, User, ?AuditOrigin, array $auditContext)`: `name` (1–60, as_ci unique), `type` (`tiered`|`fixed`, required, immutable afterwards — DEC-COT-51: `UpdatePriceScheme` rejects a `type` different from the stored one with an error on `type`), `tiers` for tiered schemes. A **fixed** scheme gets one system tier (`label` "Precio", `min 1`, `max null`, `priced`) that is not shown or editable; this keeps every price keyed by a tier (D9).
- `SchemeRules::validateTiers(list<{id?, label, min, max, kind}>)` (pure): at least one tier; the first `min = 1`; each next `min = previous max + 1` (no gaps, no overlaps); only the last tier has `max = null`, every other has `max >= min`; `negotiation` only on the last tier; labels 1–30 chars, unique case-insensitively within the scheme, and not equal (after `mb_strtolower(trim())`) to any fixed CSV header (`codigo`, `grupo_talla`, `producto`, `nombre`, `estado`, `precio`, `codigo_combo`, `componente`, `cantidad`, `precio_item`) — this is the technical rule the user delegated (proposal P-8), needed because COT-027 maps tier columns by label. Errors on `tiers` / `tiers.{i}.{field}` (E-02).
- `UpdatePriceScheme`: locks the scheme row; tiers are sent as the full ordered list, existing ones with `id`. Changing labels or limits never touches prices (COT-001). A new tier leaves combinations without a price in it (COT-025). A removed tier that has prices — and a tier changed from `priced` to `negotiation` that has prices (note N-1) — requires `confirm_price_removal = true`; without it the Action answers a `ValidationException` on `tiers` with the number of prices and products affected, so the UI opens the `ConfirmDialog` (DEC-COT-23). With it, those prices are deleted in the same transaction, after locking the affected product rows in id order (Decision 10 lock order), and listed in the audit `old_values.deleted_prices` as `{code, size_group, tier, price}`.
- `DeactivatePriceScheme`: rejected with `BusinessRuleViolation` naming the active products that use it (E-03, "El esquema está asignado a productos activos: …"); `ActivatePriceScheme` has no condition. No-op without audit when already in state (003 rule).
- `PriceSchemeSeeder` (called from `DatabaseSeeder` and from the deploy step, idempotent: `firstOrCreate` by name): Uniformes (Detal 1–6 · 7–12 · 13–49 · Negociación 50+), Pañales de adulto (Detal 1–19 · Especial 20+), Protector y absorbentes (Detal 1–6 · Especial 7+), Precio fijo. Tier labels are written exactly as in COT-001 ("Detal", "7–12", "13–49", "Negociación", "Especial"). Created schemes are audited as `pricing.scheme_created` with console origin.
**Alternatives considered**: a nullable `price_tier_id` for fixed prices (a NULL in a unique key needs another generated column and every query branches on scheme type).
**Rationale**: COT-001, DEC-COT-05, DEC-COT-23, DEC-COT-26, DEC-COT-51, E-01..E-03.

### Decision 9: Scheme per product (COT-002)

**Choice**: `products.price_scheme_id` nullable FK **restrict** (a scheme is never deleted; no delete route). `AssignPriceScheme::handle(list<int> $productIds, PriceScheme $scheme, bool $confirmPriceRemoval, User, ?AuditOrigin, array $auditContext)`: the scheme must be active; locks the products in id order; products whose scheme does not change are skipped (no audit); products that change scheme and have prices require `confirm_price_removal` (same answer as Decision 8) and their prices (all combinations, all groups) are deleted; one `pricing.product_scheme_assigned` audit row **per product** with `{scheme}` before/after and `deleted_prices` (E-04). Combos have no scheme (the Action only takes products). The same Action serves the product price page header (one id) and the bulk action of the pricing product list (many ids). Product rows are listed only from pricing queries (DEC-COT-52), so a user with `pricing.manage` and without `products.view` can assign.
**Rationale**: COT-002, DEC-COT-22, DEC-COT-23, E-04.

### Decision 10: Combination prices and size groups (COT-003, COT-004, COT-025)

**Choice**:
- `SyncCombinationPrices::handle(Product $product, list<{combination_id, size_group_id|null, price_tier_id, price|null}> $cells, User)`: one save of the product price table. Locks the product row (the same lock 003 combination writes take, so prices and combination edits serialize). Rules: the combination belongs to the product (active or inactive, COT-003); the tier is a `priced` tier of the product's current scheme; when the combination has groups, `size_group_id` is required and belongs to it, otherwise it must be null; price `null` deletes the price (the table is the only place where prices are deleted, DEC-COT-32); otherwise `Money::parse(max 2 decimals)` and `> 0` (E-06 → `cells.{i}.price`). Only changed cells are written; each create/update/delete is one audit row `pricing.price_created|updated|deleted` on the `CombinationPrice` with `{code, size_group, tier, price}` (E-05).
- **Lock order** for every price write (table, import apply, scheme change, tier removal, size groups, initial load): scheme row (when tiers are read) → product rows in id order → combo rows in id order. Deadlock-free and compatible with 003, which locks products before combinations.
- **Size groups**: `size_groups(id, combination_id cascade, sort_order)` and `size_group_values(size_group_id cascade, combination_id, size_value_id restrict)`. A group has no stored name; its label is derived by `SizeGroupLabel::of()` from its sizes in size-value order: contiguous runs joined with "–", runs separated by ", " (e.g. "28–36"). The derived label is the CSV `grupo_talla` value (normalized: "-" and "–" compare equal). `SyncSizeGroups::handle(Combination, list<list<int> $sizeValueIds>, ?string $confirmToken, User)` (`pricing.manage`): only for a combination whose product declares the attribute with special use `size` as an **order** attribute (COT-004, 003 Decision 13 recognition); the groups must **partition** the sizes admitted by the combination (its restriction on the size attribute, or the product's allowed sizes): no overlap, no admitted size left out (E-08 → `groups`). An empty list means "no groups" (removing the grouping). Audit `pricing.size_groups_updated` with the before/after partition by size names and `deleted_prices`.
- **Group transitions with prices (DEC-COT-53, E-70)**. `SizeGroupPlan::diff(stored, proposed, ungroupedSizeIds): SizeGroupPlan` (pure, `app/Support/Pricing/`) classifies the change:
  - a proposed group whose size set **equals** a stored group's set, or equals it plus sizes that were in **no** group (the COT-004 flow "sin precio hasta asignarla a un grupo", note N-9), **keeps** that stored group's id and its prices;
  - every other stored group is **dropped** (merged, split, sizes moved between groups) and its prices are listed for deletion; every other proposed group is **new** (no prices, COT-025);
  - ungrouped → grouped: the combination's prices with `size_group_id = null` are listed for deletion; grouped → ungrouped (empty list): every group is dropped with its prices.
  The plan returns `deleted_prices: list<{group_label|null, tier_label, price}>` (sorted by group order, then tier order) and `confirm_token = sha256(json(deleted_price ids + their unit_price + updated_at))`.
- **Two-step confirmation flow** (same criterion as DEC-COT-23):
  1. `POST /pricing/combinations/{combination}/size-groups/preview` → `PreviewSizeGroups` (read-only, `pricing.manage`, no lock, no audit): validates the partition (E-08 errors as JSON 422) and returns `{requires_confirmation: bool, deleted_prices: [{group: "28–36"|null, tier: "Detal", price: {value, display}}], confirm_token: string|null}` computed by the backend. The `SizeGroupEditor` calls it on "Guardar"; with `requires_confirmation = false` it submits directly; otherwise it opens `ConfirmDialog` (variant `danger`) listing each row "grupo · tramo · valor" ("Sin grupo" when null).
  2. `PUT /pricing/combinations/{combination}/size-groups` with `groups` and, after confirmation, `confirm_token`. `SyncSizeGroups` locks the product row (Decision 10 lock order), recomputes the plan from the locked state and: deletes nothing and answers `ValidationException` on `groups` "Confirme la eliminación de los precios afectados." when the plan deletes prices and no token was sent ("sin confirmación nada cambia", E-70); answers `ValidationException` "Los precios cambiaron; revise de nuevo los grupos." when the recomputed token differs from the sent one (a price written in between), so the user always confirms exactly what is deleted; otherwise, in one transaction, deletes the listed prices, drops and creates groups as planned and writes one `pricing.size_groups_updated` audit row with the partitions and `deleted_prices` `{group, tier, price}` (strings, Decision 3). The combination then shows "sin precio" (COT-025, `MissingPrices`) until new prices are loaded.
  - Negotiated prices of draft lines that pointed to a dropped group go with it (`quotation_line_negotiated_prices.size_group_id` cascade) and the next recalculation discards the line's negotiation by its basis (Decision 17, DEC-COT-55).
- **New size** (COT-004): nothing is written; a size row whose size is in no group resolves to "sin precio" (Decision 15).
- **Removed size** (DEC-COT-48): `SizeGroupMaintenance::removeSizes(list<int> $combinationIds, list<int> $sizeValueIds, User)` deletes the `size_group_values` rows; a group left empty is deleted with its prices; audit `pricing.size_groups_updated` with the removed sizes and `deleted_prices`. It is called inside the existing transactions of `SyncProductAttributes` (size removed from the product's allowed values, next to the 003 stock-minimum cleanup, Decision 11 step 7 of 003) and `UpdateCombination` (size restriction narrowed). These are the only 003 Actions touched for COT-004.
- **"Sin precio" flag** (COT-025 last bullet, E-26): `MissingPrices::forProducts(list<int>)` returns, for each **active** combination, the priced tiers (and groups) without a price, and sizes without a group, in two queries. The pricing product list shows an "Incompleta" marker; the product price table marks empty cells.
**Alternatives considered**: a stored group name (no field in the spec model; derived labels avoid a free-text field that could drift from the sizes). Groups keyed by ranges (sizes are not numeric everywhere). For E-70, a single `PUT` answering a `ValidationException` with a count (the Decision 8 pattern) was rejected because the dialog must list group, tier and value, and Inertia error bags carry strings only; a confirm token instead of a plain boolean was chosen so a price written between preview and confirm is never deleted unseen.
**Rationale**: COT-003, COT-004, COT-025, DEC-PRD-06, DEC-COT-23, DEC-COT-32, DEC-COT-48, DEC-COT-53, E-05..E-08, E-26, E-70.

### Decision 11: DT-05 confirmed — exchange rate (COT-030)

**Choice**: DT-05 is **confirmed**.
- Table `exchange_rates` (append-only; no update or delete path; Interfaces). `ExchangeRate` model has no `update`/`delete` usage outside `RecordManualExchangeRate` setting `superseded_at`/`superseded_by_id` (the only allowed write to an existing row, D4).
- `exchange-rate:fetch` command → `FetchExchangeRate` Action: today = `OperatingTime::today()` (America/Caracas). If an effective rate for today exists (any origin), it ends without calling the API (E-63 second run). Otherwise `Http::timeout(10)->connectTimeout(5)->retry(3, [2000, 10000], throw: false)->acceptJson()->get(config('services.exchange_rate.url'))`. Failure = connection error, non-2xx, invalid JSON, missing `promedio`, or a value ≤ 0 after `RateParser` (Decision 3). On failure it saves nothing, writes `Log::warning('exchange_rate.fetch_failed', {reason, status})` and dispatches `ExchangeRateFetchFailed` (Decision 26). On success it inserts `{rate_date: today, value, origin: api, source_updated_at: fechaActualizacion parsed as datetime and stored in UTC}`; a concurrent manual insert surfaces as `UniqueConstraintViolationException` on `effective_date` and is ignored (the manual rate wins, COT-030). API rows are not audited (COT-023 audits manual rates only).
- **Schedule** (`routes/console.php`, first `Schedule` entries of the project): `Schedule::command('exchange-rate:fetch')->dailyAt('07:00')->timezone('America/Caracas')`, plus retry runs at `07:30`, `08:00` and `09:00` in the same timezone, which end immediately when today's rate exists. Retry policy (the spec delegates it to design): 3 HTTP attempts per run with 2 s and 10 s backoff, and the three later runs; at most 12 calls per day, only while the day has no rate. All entries `withoutOverlapping()`.
- `RecordManualExchangeRate::handle(string $value, User)` (`pricing.manage`): value `> 0`, up to 2 decimals (DEC-COT-60, E-64 → `value`); only for today. Because `effective_date` is unique and derived from `superseded_at`, the writes inside `DB::transaction(..., attempts: 3)` are in this exact order:
  1. `SELECT … FROM exchange_rates WHERE effective_date = :today FOR UPDATE` (the previous effective row, or none);
  2. if it exists: `UPDATE … SET superseded_at = now() WHERE id = :previous` — its `effective_date` becomes `NULL`, freeing the unique key;
  3. `INSERT` the manual row `{rate_date: today, value, origin: manual, recorded_by}` — it now owns `effective_date = today`;
  4. if a previous row exists: `UPDATE … SET superseded_by_id = :new WHERE id = :previous`;
  5. audit `pricing.exchange_rate_recorded` with the previous effective `{date, value, origin}` (or none) and the new one, values as strings.
  If no row existed at step 1 and the API command inserts today's row before step 3, the insert raises `UniqueConstraintViolationException`; the Action catches it once, rolls back and reruns steps 1–5 (now superseding the API row), so the manual rate always wins (COT-030). Deadlocks from the gap lock are retried by `attempts: 3`. A later API run does not replace it (first bullet).
- **Rate history** (spec §8 "historial de tasas", `pricing.view`): `ExchangeRate::scopeHistory()` orders by `rate_date desc, id desc` and eager-loads `recordedBy:id,name`; `PricingSettingsController@show` passes it as the `rates` prop, `paginate(15, pageName: 'rates_page')`, every row (effective and superseded) mapped to `{date, value: {value, display (2 decimals)}, origin, origin_label, recorded_by: ?string, effective: bool, superseded_at: ?string, created_at}`. No new route: it is a section of `GET /pricing/settings` ("Historial de tasas", `DataTable` with a "Reemplazada" badge on superseded rows). The history is read-only (append-only table).
- `CurrentExchangeRate::get(): ?RateSnapshot` (`value`, `date`, `origin`): today's effective row, otherwise the most recent effective row by `rate_date` (one indexed query). Calculation and issue read it; nothing else calls the API (COT-030 "desacoplada", E-31).
- `config/services.php` `exchange_rate.url` = `env('EXCHANGE_RATE_URL', 'https://ve.dolarapi.com/v1/dolares/oficial')`. Tests use `Http::fake()` and `Http::preventStrayRequests()` (in `TestCase` for the whole suite).
**Alternatives considered**: overwrite the day's row (forbidden by COT-030 "no se editan"); a separate "corrections" table (two places to read the effective rate). Exploration **C1** is resolved by the superseded marker + generated unique key.
**Rationale**: COT-030, DEC-COT-38, DEC-COT-43, DT-05, E-63, E-64, E-65.

### Decision 12: Combo item prices and DEC-COT-44

**Choice**:
- `combo_item_prices(combo_component_id unique cascade, unit_price)`. `SyncComboItemPrices::handle(Combo, list<{combo_component_id, price|null}>, User)` (`pricing.manage`): locks the combo row; price rules as Decision 10; audit `pricing.combo_item_price_created|updated|deleted` with `{combo_code, component, product, quantity, price}`. The combo price is never stored (COT-005): `Σ price × quantity` is computed by the calculation and by the price page presenter.
- **`ComboComponents::replace` keeps component identity** (DEC-COT-44, exploration D3). The `UpdateCombo` payload gains an optional `components.*.id` (the existing component the entry edits; the 003 combo form sends it from now on). New algorithm, inside the existing transaction:
  1. entries whose `id` is a component of this combo **and** whose `product_id` equals the stored one are **updated in place** (quantity, `sort_order`, restriction values replaced) — their price survives quantity and order changes;
  2. **fallback for entries without `id`** (callers that do not send ids: `CreateCombo`, the 003 importer, an older client): each such entry is matched to a stored component **not yet kept** with the **same `product_id`**, pairing the k-th id-less entry of a product with the k-th remaining stored component of that product in stored `sort_order`; a match is updated in place exactly as step 1, so its price survives (COT-005 "cuyo producto no cambia");
  3. entries left unmatched (no `id` and no remaining stored component of their product, or an `id` that points to a component with another product) create a **new** component (no price, COT-025);
  4. stored components not kept by steps 1–2 are deleted (their price cascades);
  5. deleted prices are added to the `products.combo_updated` audit row as `old_values.deleted_item_prices` (COT-005 "cada cambio queda auditado"; one row, not a pricing row, because the change is the combo edit).
  `matches()` stays as the early "nothing changed" exit. `CreateCombo` keeps calling `replace()` without ids: on a new combo nothing is stored, so step 2 matches nothing and every entry is new (behavior unchanged). `ComboRules` gains `components.*.id` (`nullable|integer`); an `id` of another combo is rejected on `components.{i}.id`. Ids, when present, always win over the fallback, so two components of the same product are disambiguated by the 003 combo form, which sends ids from now on.
**Alternatives considered**: price keyed by `(combo_id, position)` (breaks on reorder, which DEC-COT-44 says keeps the price); product-only matching as the **primary** rule (ambiguous when the same product appears twice, 003 note N-3) — kept only as the ordered fallback for id-less callers; leaving id-less callers with delete-and-recreate (would drop prices on an import or older client that keeps the product, contradicting COT-005).
**Rationale**: COT-005, DEC-COT-06, DEC-COT-44, E-10, E-60.

### Decision 13: Commercial parameters (COT-006)

**Choice**: single-row table `commercial_parameters(id = 1, advance_percentage DECIMAL(5,2), validity_days smallint unsigned, updated_by null, timestamps)`, created by its migration with 50.00 / 7 (business rules §3, DEC-COT-15). `CommercialParameters::current()` reads it. `UpdateCommercialParameters`: percentage `0 ≤ p ≤ 100` with up to 2 decimals (technical scale for "entero o decimal"), validity integer `1–365` (upper bound technical); E-11 → `advance_percentage`, `validity_days`; audit `pricing.parameters_updated` changed fields only; no-op without audit. New values apply to quotations issued afterwards (the issue copies them, Decision 18).
**Rationale**: COT-006, E-11.

### Decision 14: DT-02 confirmed — `CalculateQuotation`

**Choice**: DT-02 is **confirmed**: `App\Actions\Quotations\CalculateQuotation::handle(CalculationInput $input): CalculationResult`, no writes, no HTTP, no stock tables. It is the one entry point for the editor (COT-013), the draft save, the issue and later `005`.
- **Input** (`CalculationInput::fromArray()` validates the shape and throws `ValidationException` only for malformed structure; business problems are returned as errors so drafts can be saved with them): `lines[]` with `kind`, `product_id` or `combo_id`, `selection` (003 input shape without size), `rows[] {size_value_id|null, quantity}`, `customizations[] {service_product_id, quantity_per_garment}`, `combo_quantity`, `components {componentId: selection}`, optional `negotiated {prices: [{size_group_id|null, price}], confirmed: bool}`; and optional `agreed_advance`.
- **Process** (COT-009 steps, in this order):
  1. Batch reads (Decision 16): product facts, schemes with tiers, packs, rate, parameters.
  2. **Resolve** each product row with `ResolveSelection` — selection + `order[sizeAttributeId] = size_value_id` when the product declares the size attribute as an order attribute; one call per row (step 1, E-28); `customizations` passed as ids (COT-011); combos resolved once per line. A `ValidationException` becomes row/line errors with keys prefixed `lines.{i}.rows.{j}.` / `lines.{i}.`.
  3. **Count** (`PieceCounter`, pure, Decision 15).
  4. **Tier and unit prices** (`TierSelector`, `PriceBook` lookups).
  5. **Requires advisor** and negotiated prices (Decision 17).
  6. **Subtotals, total, advance** (`AdvanceCalculator`), **Bs** (`BsConverter`), **pack notices** (`PackAdvisor`).
- **Output** (`CalculationResult::toArray()`, amounts as `{value, display}` strings): per line `code`, `descriptive_name`, `tier_label`, `price_origin`, rows with `unit_price`, `size_group_label`, `subtotal`, `customizations` (with "sin cargo"), `included_customizations`, `requires_advisor` + `reason`, `negotiated_discarded` (bool) + `negotiated_discarded_reason`, `negotiation_basis` (current `{combination_id, size_group_ids}`), `pack_notice` (null for a line with more than one unit price), `errors` (field → message); per quotation `pieces_by_line` (`uniforms`, `diapers`), `total`, `advance_percentage`, `required_advance` + `breakdown {automatic, stock, agreed}`, `proposed_agreed_advance`, `agreed_advance_discarded`, `rate {value, date, origin}` or `null` ("sin tasa"), `total_bs`, `advance_bs`, `requires_advisor` + `reasons[]`, `complete` (no errors, no line without price, no line requiring advisor without negotiated/confirmed price, no missing agreed advance).
- Only priced lines add to the total (COT-009 step 8). A line with an error or without price contributes nothing and makes `complete = false`.
**Rationale**: COT-009, DT-02, E-31.

### Decision 15: Counting, tiers and unit prices (COT-008, COT-009 steps 2–6, COT-025)

**Choice** (pure classes in `app/Support/Quotations/Calculation/`, unit-tested without the database):
- `PieceCounter::count(list<LineFacts>)`: sums the quantities of **valid rows** of product lines whose product is not a service and whose scheme is **tiered**, by business line (`uniforms`, `diapers`); fixed-price lines, combo lines and customizations do not count (DEC-COT-07, DEC-COT-13); service lines count apart, keyed by **product id** (DEC-COT-14; a service is a product, proposal P-7). Rows with a selection error do not count (note N-2: drafts only; an issuable quotation has no error rows, so the issued result is unaffected).
- `TierSelector::for(tiers, quantity)`: the tier whose `[min, max]` contains the quantity; for a fixed scheme its single tier. The quantity is the business-line total for goods and the service total for services (COT-008 last bullet, E-15, E-25).
- `PriceBook` (loaded once per calculation): `combination_prices` for all resolved combination ids (one query), size groups and their sizes (one query), combo item prices for the combos of the lines (one query).
- Unit price of a row: the price of `(combination, tier, size group of the row's size)`; when the combination has groups and the size is in none, or no price exists, or the product has no scheme or an inactive one, the line gets the error **"sin precio"** on `lines.{i}` (COT-025, E-26, E-27); no value is invented.
- Combo line (COT-005, step 6): every component must have an item price, otherwise "sin precio"; subtotal = `Σ(item price × component quantity) × combo_quantity` (E-10, E-60); price origin `combo`.
- Customizations: never priced (COT-011, E-24); returned with `quantity_per_garment` and "sin cargo". Included customizations of the combination are returned without price.
- **Pack notice** (COT-009 step 11, DEC-COT-08, DEC-COT-47, DEC-COT-54), for each **product** line without errors and with a price:
  1. **Single unit price only (DEC-COT-54, E-71)**: when the line's rows carry more than one distinct unit price (rows in size groups with different prices) the line gets no notice and `PackAdvisor` is not called; the line is calculated exactly as without packs. Two groups with the same unit price count as one unit price (note N-10).
  2. **Admission (DEC-PRD-42 + the line's selection)** — `PackAdmission::admits(ComponentSnapshot $component, list<array> $rowChoices, list<int> $rowCombinationIds, array $palette): bool` in `app/Support/Quotations/Calculation/` (outside `app/Support/Products/Selection/`, so no price/stock literal enters the 003 engine). For a candidate pack (Decision 16) it answers true only when, for **every** row of the line:
     - **axes**: `ComboMembership::includes($component->restriction, axes of the row's resolved combination, axis attribute ids of the product)` is true (the exact 003 DEC-PRD-42 rule, reused unchanged);
     - **full selection (axes + order-attribute restrictions)**: `SelectionRules::validate($component->product, $rowChoice, $palette, $component->restriction)` — the same call `ComboSelectionRules::validate()` makes for a combo component — returns a `ResolvedSelection` (not an error array) whose combination id equals the row's resolved combination id. `$rowChoice` is the exact input the row was resolved with in step 2 (selection, size of the row when size is an order attribute, customizations), so a restriction on an order attribute such as color rejects the pack exactly as it would reject that choice inside the combo.
     Both calls are pure over the memoized snapshots: no query per row. A pack without an item price (`PriceBook`) cannot be compared and gives no notice.
  3. **Comparison**: line quantity (Σ rows) ≥ pack quantity (component `quantity`) and pack item price < the line's single unit price; `PackAdvisor::best()` picks one notice (greatest estimated saving, tie → more units, DEC-COT-47) with `{combo_code, combo_name, packs_fit = intdiv(qty, pack qty), estimated_saving = packs_fit × pack qty × (unit price − item price)}` (strings).
**Rationale**: COT-008, COT-009, COT-011, COT-025, DEC-PRD-42, DEC-COT-08, DEC-COT-47, DEC-COT-54, E-09, E-10, E-14..E-17, E-24..E-27, E-49, E-69, E-71.

### Decision 16: D1/D2 — query budget of the calculation

**Choice**:
- **D1 (memoizing loader)**: `CatalogSnapshotLoader` gains `memoized(): self`, which returns a fresh instance that caches `forProducts()` per product id (fetching only ids not yet cached, in the same constant number of queries), `forCombo()` per combo id and `palette()`. `CalculateQuotation` builds `new ResolveSelection($loader->memoized())` per call and primes it with `forProducts(all product ids of the lines and of the combo components)`. The default instance is unchanged, so 003 behavior and its tests stay identical. The change adds no string literal matching `pric|stock|inventor|cost` and no import from pricing namespaces, so `SelectionHasNoPriceOrStockDependencyTest` stays green (verified as part of the slice gate). `ResolveSelection` and `ListSelectionOptions` are **not modified**.
- **D2 (product facts)**: one query on `products` for the line products (`business_line`, `supply_mode`, `price_scheme_id`); one on `price_schemes` + `price_tiers` for their schemes. No 003 DTO is extended (the architecture test forbids price strings there).
- **Candidate packs** (`PackCatalog::load(list<int> $lineProductIds, CatalogSnapshotLoader $memoized): list<ComboSnapshot>`, `app/Support/Quotations/Calculation/`, a query class, not pure): one query
  ```sql
  SELECT cc.combo_id FROM combo_components cc
  JOIN combos c ON c.id = cc.combo_id AND c.status = 'active'
  WHERE cc.product_id IN (:lineProductIds)
    AND cc.combo_id IN (SELECT combo_id FROM combo_components GROUP BY combo_id HAVING COUNT(*) = 1)
  ```
  then `$memoized->forCombo($id)` per candidate (one query each; the component product snapshots are already primed, so `forProducts()` adds none), keeping only `ComboSelectionRules::isOffered()` combos (the existing 003 "offered" rule). The candidate combo ids join the `PriceBook` combo-item-price query (still one query). Only the product lines' products are passed, so packs are never looked up for combo lines.
- Other reads: one for the current rate, one for parameters.
- **Budget**: the number of queries depends on the number of **distinct products and combos** (lines' combos plus candidate packs, a catalog-dependent constant), not on lines × size rows. Test: a quotation with 1 line × 1 row of product A and one with 5 lines × 6 rows of the same product A issue the same number of queries (`DB::enableQueryLog()`), as 003's DT-02 bound.
**Alternatives considered**: (A) plain `ResolveSelection` per row (~11 queries per row; the editor recalculates on each change); (B) calling `SelectionRules::validate` directly (bypasses the Action DT-02 names).
**Rationale**: exploration D1, D2, proposal risk "calculation performance".

### Decision 17: Advance, negotiation and requires-advisor (COT-010, COT-012)

**Choice**:
- `AdvanceCalculator` (pure): `automatic = percent × Σ subtotals of on-demand and service lines with automatic price` (exact); `stock = Σ subtotals of stock-with-minimum, stock-depletable and combo lines with automatic price`; `agreed = agreed advance` (when negotiated lines exist); `required = round(automatic + stock + agreed, 2, HALF_UP)` (E-29, E-30). The breakdown shows `automatic` rounded for display; because `stock` and `agreed` are already at scale 2 the parts add up to `required` (exploration P-5).
- **Requires advisor** (step 7): the line's tier is `negotiation` (reason `"{min} piezas de {línea en minúsculas} o más"` for goods, e.g. "50 piezas de uniformes o más", and `"{min} unidades de {servicio} o más"` for services), or `ResolvedSelection::requiresAdvisor` is true (reason "color personalizado"). Combos never require it (DEC-PRD-87).
- **Negotiated price**: tier of negotiation → no automatic price; a negotiated unit price per size group (or one) is required. Custom color → the tier price is the reference; `SetNegotiatedPrice` with `confirmed = true` stores the reference prices as the negotiated ones ("Confirmar precio de referencia"), or stores other prices. In both cases origin = `negotiated` and the line's advance goes to the agreed part.
- **Negotiation basis (DEC-COT-55)**: when `SetNegotiatedPrice` stores prices it also stores, on the line, the basis they were negotiated for: `quotation_lines.negotiated_combination_id` (the resolved combination at that moment) and `quotation_lines.negotiated_size_group_ids` (JSON, the **sorted distinct** `size_group_id`s the line's valid rows fall in; `[]` when the combination has no groups). A pure `NegotiationBasis::of(int $combinationId, list<?int> $rowGroupIds): NegotiationBasis` builds it and `matches(NegotiationBasis $other): bool` compares it. Quantities and the individual sizes are deliberately **not** part of the basis, so changing quantities or sizes within the same group keeps the price.
- **Discard** (E-22, E-72): a recalculation discards a line's stored negotiated price, with `negotiated_discarded = true` and `negotiated_discarded_reason` (`no_longer_requires_advisor` | `combination_changed` | `size_groups_changed`) and the same notice text as E-22 ("Se descartó el precio negociado de la línea …"), when **any** of these holds: the line no longer requires advisor; the line still requires advisor but its resolved combination differs from `negotiated_combination_id`; or the sorted distinct group ids of its rows differ from `negotiated_size_group_ids` (including a stored negotiated price whose group was dropped by DEC-COT-53, Decision 10). The line then requires a negotiated price again (or goes back to its automatic price when it no longer requires advisor). Other lines keep theirs (E-72: the chemises line). `UpdateQuotationDraft` deletes the discarded prices and clears the basis on save, unaudited (DEC-COT-29, C8). The agreed advance follows COT-010 unchanged: it is discarded when no negotiated line remains or when it exceeds the new negotiated subtotal; the result proposes `round(percent × negotiated subtotal)` again (E-62: 575,00).
- **Where the basis comes from**: `UpdateQuotationDraft`, `IssueQuotation` and the open-recalculation build `CalculationInput.lines.*.negotiated` from the **stored** rows and basis, never from the request payload. The live `POST /quotations/calculate` accepts `negotiated {prices, confirmed, basis {combination_id, size_group_ids}}` echoed from the editor props (the calculation writes nothing, so a tampered basis only changes a preview); it still requires `negotiate` when negotiated fields are sent (Decision 6).
- `SetNegotiatedPrice::handle(QuotationLine, {prices[], confirmed}, User)` and `SetAgreedAdvance::handle(Quotation, string $amount, User)` (both `quotations.negotiate`, both draft-only, both lock the quotation row and recalculate first): a line that does not require advisor → `ValidationException` on `price` "La línea no requiere asesora." (E-21); prices `> 0`, max 2 decimals; one price per size group of the combination when it has groups; amount `0 ≤ a ≤ negotiated subtotal` (E-62). Stored with user, date and the negotiation basis of the recalculated line (above); audits `quotations.negotiated_price_set` (line position, code, prices, `confirmed`) and `quotations.agreed_advance_set` (previous/new amount). E-19, E-62.
**Alternatives considered** (basis): a hash of the whole line input (would discard on a quantity change, contradicting DEC-COT-55); comparing only the stored negotiated price rows' `size_group_id`s (misses a new group added by a new row, because that group has no negotiated price yet).
**Rationale**: COT-010, COT-012, DEC-COT-10, DEC-COT-11, DEC-COT-12, DEC-COT-27, DEC-COT-40, DEC-COT-55, E-18..E-23, E-29, E-30, E-62, E-72.

### Decision 18: DT-03 confirmed — issue and freeze (COT-016)

**Choice**: DT-03 is **confirmed with its wording aligned to DEC-COT-45** (exploration C2): the **number** is assigned **on create** under the sequence lock (Decision 19); the **issue** runs in one transaction that takes `lockForUpdate()` on the quotation row, so two concurrent issues (or an issue and a draft save) of the same quotation serialize and the second sees `issued` and is rejected. `IssueQuotation::handle(Quotation, User)`:
1. lock the quotation; it must be `draft` (otherwise `BusinessRuleViolation`); lock the customer row when present;
2. recalculate with `CalculateQuotation` from the stored draft input;
3. reject with a `ValidationException` listing the pending lines when `complete = false`, and with an error on `customer` when the customer is inactive (E-33, E-37); nothing changes;
4. write the **frozen copy**: each line's `code`, `descriptive_name`, `normalized_selection` (PRD-011 output), `tier_label`, `price_origin`, `subtotal`, `requires_advisor`, `advisor_reason`; each row's `unit_price` and `size_group_label`; each component's `code`, `descriptive_name`, `normalized_selection`, `quantity`, `unit_price`; on the quotation `pieces_by_line`, `total`, `advance_percentage`, `required_advance`, `advance_breakdown`, `rate_value`, `rate_date`, `rate_origin`, `total_bs`, `advance_bs` (null when there is no rate, E-65), `requires_advisor`, `advisor_reasons`, `recipient_snapshot` (name, document, phone, email, contact name/phone/email — DEC-COT-49) and `advisor_snapshot` (name);
5. `issued_at = now()` (UTC); `expires_on = OperatingTime::today()->addDays(validity_days)` (local date, DEC-COT-15); `status = issued`; audit `quotations.issued` with the totals and rate;
6. create one `quotation_email_deliveries` row per distinct recipient address with status `pending` (Decision 21), all in the same transaction;
7. after commit: dispatch `SendQuotationEmail` per delivery and `QuotationIssued` (Decision 26).
From then on the quotation reads only frozen columns; no price, scheme, parameter, rate or catalog change alters it (E-36). The customer link is kept (`customer_id`).
**Rationale**: COT-016, DT-03, DEC-COT-16, DEC-COT-45, DEC-COT-49, E-33, E-36, E-37, E-65.

### Decision 19: Drafts (COT-013, COT-014)

**Choice**:
- **Number** (`QuotationNumber::next()`): `quotation_sequences` holds one row (`id = 1`, `last_number`), created by its migration. `next()` runs inside the create transaction: `SELECT … FOR UPDATE` on that row, increment, return. A discarded draft does not return its number (DEC-COT-45); `quotations.number` unique is the backstop. Display `COT-%06d` through `Quotation::displayNumber()` (`COT-000123`). Search accepts `123`, `000123` and `COT-000123`.
- `CreateQuotation::handle(array $data, User, ?AuditOrigin)` (`quotations.create`): recipient = active customer (E-33 → `customer_id`) with `include_contact` (only for a company with a contact), or prospect with `prospect_name` (1–150), `prospect_phone` (CLI-004 `PhoneNumber::parse()`, must be `isMobile()`, stored E.164 — E-68), `prospect_email` (CLI-004 email normalization, optional). Advisor default (COT-013): the customer's advisor; else the creator when the creator has `customers.portfolio`; else null. An explicit `advisor_id` must be an active user with `customers.portfolio` (`User::eligibleAdvisors()`, 002). Origin `advisor`. Lines optional at create (a draft may be saved half-done, COT-013). Audit `quotations.created` with number, recipient and advisor (E-32).
- `UpdateQuotationDraft::handle(Quotation, array $data, User)`: locks the row; draft only (E-38 → `BusinessRuleViolation` "La cotización no está en borrador; duplíquela para cambiarla."); full replacement of header and lines; existing lines are sent with `id` and keep their row (so their negotiated prices survive, Decision 17); lines are validated structurally (COT-014: rows with integer `quantity ≥ 1`, at least one, no repeated size → `lines.{i}.rows` E-35; one row without size when the product has no size order attribute; `note` ≤ 500; customizations `quantity_per_garment` integer 1–99, default 1); the draft is saved **even with calculation errors** (COT-013) and stores the calculation cache (`total`, `requires_advisor`, each line's resolved `combination_id`, which also anchors COT-024 history). Audits only `quotations.advisor_changed` and `quotations.recipient_changed` when those change (DEC-COT-29; exploration **C8**: the audited draft events are exactly the COT-023 list — created, negotiated price, agreed advance, advisor, recipient, duplicate, discard; line edits and the automatic discard of a negotiated price are not audited one by one).
- **Recipient change keeps the advisor (DEC-COT-56, E-73)**: when `UpdateQuotationDraft` receives a different recipient (prospect ↔ customer, or another customer) it writes `quotations.recipient_changed` and leaves `advisor_id` untouched; the COT-013 default (customer's advisor, else creator with `customers.portfolio`) runs **only** in `CreateQuotation` and `DuplicateQuotation`. The advisor changes only when the payload's `advisor_id` differs from the stored one (manual change): it must be an active user with `customers.portfolio` (`User::eligibleAdvisors()`, error on `advisor_id`) and writes `quotations.advisor_changed`. The editor never rewrites `advisor_id` client-side when the recipient changes.
- Recalculation on open: `QuotationController@edit` and `@show` (draft) run `CalculateQuotation` on the stored input and render its result (COT-013 "cada vez que se abre").
- Live editor calculation: `POST /quotations/calculate` (JSON) with the editor's unsaved state; `POST /quotations/selection-options` wraps 003 `ListSelectionOptions` (PRD-019); both authorize `calculate`.
**Rationale**: COT-013, COT-014, DEC-COT-19, DEC-COT-21, DEC-COT-29, DEC-COT-45, DEC-COT-56, E-32..E-35, E-38, E-68, E-73.

### Decision 20: Expiry, duplicate, cancel, discard (COT-015, COT-017..COT-019)

**Choice**:
- **D5 confirmed** — two mechanisms, as COT-017 allows:
  - read side: `QuotationStatusResolver::effective(status, expires_on, today)` (pure) and the SQL expression `CASE WHEN status = 'issued' AND expires_on < :today THEN 'expired' ELSE status END` used by the list tabs, counts, filters, customer history and detail. `:today` is `OperatingTime::today()`. The list and the detail never show an expired quotation as issued, even if the command has not run;
  - persistence: `quotations:expire` scheduled `dailyAt('00:05')->timezone('America/Caracas')` runs `ExpireQuotation` per due quotation (`issued`, `expires_on < today`), each in its own transaction with the row lock: `status = expired`, `expired_at`, audit `quotations.expired` with actor null, `AuditOrigin::console('quotations:expire')` and `context.automatic = true` (E-41), event `QuotationExpired`. Every write path on an issued quotation (cancel, resend, `006` conversion later) first calls `ExpireQuotation::ifDue()` under its own lock, so the transition is persisted and audited before the state check.
  - `OperatingTime` gains `today(): CarbonImmutable` (local date).
- `DuplicateQuotation::handle(Quotation, User)` (any state): a new draft through `CreateQuotation` internals with a new number, the same recipient (an inactive customer is kept; the draft cannot be issued until the recipient changes), the lines' **input** (selection, rows, customizations, components, notes, combo quantity), `source_quotation_id`; no negotiated price and no agreed advance; recalculated (invalid lines keep their errors, COT-018). For an issued source the input is the original draft input, which the line rows keep after issue. Audit `quotations.duplicated` on the new quotation with the source number (E-42).
- `CancelQuotation::handle(Quotation, string $reason, User)` (`quotations.cancel`): `ifDue()` first; only `issued` (E-43 → `BusinessRuleViolation` for a draft); reason required, 1–500 chars (the spec delegates the length); `cancelled_at`, `cancelled_by`, `cancel_reason`; audit `quotations.cancelled` with the reason; event `QuotationCancelled`.
- `DiscardQuotation::handle(Quotation, User)` (`quotations.create`): only `draft`; full copy (header, lines with selection and rows, negotiated prices, agreed advance) into `quotations.discarded` `old_values`; physical delete (children cascade). The discarded draft stops counting as history (COT-024, E-44). Emitted, expired, cancelled and approved quotations have no delete path (COT-019).
- The UI uses `ConfirmDialog` variant `danger` for cancel and discard (spec §8).
**Rationale**: COT-015, COT-017, COT-018, COT-019, DEC-COT-17, DEC-COT-18, DEC-COT-24, E-38, E-41..E-44.

### Decision 21: DT-06 confirmed — printable view and email (COT-021, COT-031)

**Choice**: DT-06 is **confirmed with one refinement**: the queued unit is a small job per recipient that sends the Mailable, so each recipient has its own result (DEC-COT-50).
- **Company data** (DEC-COT-46): `config/company.php` reads `COMPANY_NAME`, `COMPANY_RIF`, `COMPANY_ADDRESS`, `COMPANY_PHONE`, `COMPANY_EMAIL`, `COMPANY_LOGO_PATH` (path under `public/`); `.env.example` lists them with fictitious values. No screen, permission or audit.
- **Shared content** (D6): `resources/views/quotations/partials/document.blade.php` renders exactly the COT-021 content from the frozen columns (number, state when not issued, dates, recipient and advisor snapshots, lines with descriptive name, colors, details, customizations "sin cargo", quantities per size, unit price and subtotal in USD, total and required advance in USD, Bs equivalents with rate and date marked "referencial", and "Precios en USD con IVA incluido"); no costs, tiers or advance breakdown. Email-safe markup: table layout and inline styles; colors come from one PHP map `App\Support\Quotations\DocumentTheme` mirroring the design-system tokens, because email clients cannot use Tailwind tokens (the "no hexadecimals" rule of spec §8 applies to ERP Vue styles; this map is the single exception and is documented in `docs/ui/design-system.md`).
- **Print view**: `GET /quotations/{quotation}/print` → `QuotationPrintController` (authorize `print`; outside Inertia; `resources/views/quotations/print.blade.php` with `@media print` rules and a "Imprimir" button calling `window.print()`). A draft answers `BusinessRuleViolation` "Un borrador no tiene vista imprimible." (E-50). No server PDF (DEC-COT-20).
- **Email**: `App\Mail\QuotationMail` (HTML view = the partial inside a minimal mail layout; subject "Cotización COT-000123 — {company}"; logo embedded inline with `$message->embed()`; no attachments and no links to system pages, COT-031). `App\Jobs\SendQuotationEmail(int $deliveryId)` implements `ShouldQueue`, `afterCommit = true`, `tries = 3`, `backoff = [60, 300]`: `handle()` sends with `Mail::to($delivery->recipient_email)->send(new QuotationMail($quotation))` and marks the delivery `sent`; `failed()` marks it `failed` with a sanitized error (≤ 255 chars, no credentials) and dispatches `QuotationEmailFailed`. The issue is never rolled back by mail (E-66).
- **Recipients** (COT-031, DEC-COT-50): customer email and contact email (when `include_contact`), or the prospect email; lower-cased and de-duplicated; one delivery row per address with `batch_id` (one per issue or resend), `kind` (`issue`|`resend`), `recipient_role`, `status` `pending` → `sent`|`failed` (the interim state the user delegated, proposal P-11). No address → no row, and the detail and editor show "sin correo: entréguela por otro medio" (E-67).
- **Resend**: `ResendQuotation::handle(Quotation, User)` (`quotations.create`): `ifDue()`, only `issued` (COT-031), current recipient addresses of the customer/contact/prospect, new batch, jobs after commit. Deliveries are a record of the quotation, not audit rows (COT-023).
- Environment: `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` in `.env.example` (COT-031 "remitente y proveedor en el entorno"); production values are an operations task.
**Alternatives considered**: a queued Mailable sent to all recipients (one result for all; violates DEC-COT-50); `MessageSent` listener to mark success (needs a correlation header; the job is simpler and testable); a signed link (rejected by DEC-COT-42).
**Rationale**: COT-021, COT-031, DT-06, DEC-COT-20, DEC-COT-39, DEC-COT-41, DEC-COT-42, DEC-COT-46, DEC-COT-50, E-50, E-66, E-67.

### Decision 22: History guards (COT-024)

**Choice**: the empty 002/003 hooks are filled, each throwing the message the hook docblock already announced:
- `DeleteCustomer::ensureHasNoHistory`: any quotation with `customer_id` (any state, drafts included) → `BusinessRuleViolation` "El cliente tiene historial y no se puede eliminar. Puede desactivarlo." (E-39). A discarded draft no longer exists, so it stops counting (E-44).
- `DeleteCombination::ensureHasNoHistory`, `DeleteProduct::ensureHasNoHistory` (its product or any of its combinations), `DeleteCombo::ensureHasNoHistory`: a `quotation_lines` or `quotation_line_components` row referencing them → violation suggesting "Puede desactivarla" (E-40). Combinations referenced through a combo line component count as used (note N-4).
- `UpdateCombination::ensureEditableWithoutHistory(Combination, array $data)` (signature gains the payload): with history, a changed `code` or changed axis values → `ValidationException` on `code` / `axes` "La combinación tiene historial: cree una combinación nueva y desactive la anterior." (E-40, DEC-PRD-21). Description, restrictions and included customizations stay editable.
- `quotation_lines.combination_id` is the **resolved** combination stored at each draft save and frozen at issue (Decision 19), so a draft line counts from its first save.
- 002/003 `->todo()` markers are replaced (Decision 30).
- Exploration **C3**: the customer deactivation `ConfirmDialog` copy in `resources/js/pages/customers/Show.vue` changes from "Lo que esté en curso continúa su flujo normal." to "Las cotizaciones emitidas y lo que esté en curso continúan su flujo normal; sus borradores no se podrán emitir hasta cambiar el destinatario." — the behavior is COT-016/E-33/COT-018; only the copy is aligned.
**Rationale**: COT-024, CLI-010, PRD-012, PRD-014, DEC-PRD-21, DEC-COT-24, E-39, E-40, E-44, E-48.

### Decision 23: CSV reader, writer and template format (COT-026, DT-04 part 1)

**Choice**:
- `CsvReader` gains `readDocument(string $path, int $maxBytes, int $maxRows): CsvDocument` (`delimiter`, `records: list<{line, cells: list<string>}>`), sharing the private BOM/UTF-8/multiline code with `read()`. Delimiter detection for a document counts `;` and `,` outside quotes on the second line (the header), `;` on a tie. `read()` and its 002/003 tests are unchanged (exploration C7, D8).
- `CsvWriter::toString(list<list<string>> $rows): string`: UTF-8 BOM, `;`, CRLF, quotes when a cell contains `;`, `"`, CR or LF (DEC-COT-31). Downloads stream it with `response()->streamDownload()` and `text/csv; charset=UTF-8`.
- **Template** (`PriceTemplate`, the single definition used by export, import and the initial load):
  - Row 1, scope line: `ecolekua;precios-esquema;<nombre del esquema>` or `ecolekua;precios-combos`. The scheme is identified by **name** (unique), so a file prepared in one environment loads in another (ids differ). An import whose first row is missing, has another scope or another scheme than the one chosen is rejected whole (COT-026 "detectar una plantilla equivocada").
  - Row 2, header. Combinations: `codigo;grupo_talla;producto;nombre;estado;<label tier 1>;…;<label tier n>` (priced tiers in order; a fixed scheme has one `precio` column). Combos: `codigo_combo;componente;producto;cantidad;precio_item`.
  - Data rows: one per combination, or one per size group of a combination with groups (sorted by group order); combos one per component in component order (`componente` = 1-based position). Prices with decimal comma and 2 decimals; empty when missing (COT-025). `estado` = "Activa"/"Inactiva".
  - **Formula injection**: reference cells (`producto`, `nombre`) starting with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'`. Identity cells (`codigo`, `grupo_talla`, `codigo_combo`, `componente`) are never altered; reference columns are ignored on import, so the round trip is unaffected.
- `ExportPrices::handle(scope, ?PriceScheme, filters {category_id?, product_id?, status?})` (`pricing.view`; not audited, COT-026): combos filter by combo status. Filename `precios-<esquema-slug>-<YYYY-MM-DD>.csv` or `precios-combos-<YYYY-MM-DD>.csv`.
- Limits (the spec delegates them): file ≤ 2 MB, ≤ 5,000 data rows, UTF-8 with or without BOM.
**Rationale**: COT-026, COT-027, DEC-COT-31, DT-04, E-51, exploration D8, risk "CSV formula injection".

### Decision 24: DT-04 confirmed — validate, apply, discard, expire (COT-027..COT-029)

**Choice**: DT-04 is **confirmed**.
- `ValidatePriceImport::handle(CsvDocument, scope, ?PriceScheme, ValidationMode $mode): PriceImportPlan` — the single reader and validator for the ERP import and files 2–3 of the initial load. `PriceCell::parse()` accepts `,` or `.` decimals without thousands separator (DEC-COT-31). Row identity: `codigo` (+ `grupo_talla`) or `codigo_combo` + `componente`; reference columns ignored (COT-027). Checks: scope row and header match; tier columns equal the **current** priced tier labels in order (else whole-file rejection "La plantilla no corresponde a los tramos actuales del esquema; descargue una plantilla nueva.", E-56); code exists and its product has that scheme (or, in load mode, the scheme planned by file 1); group exists in the combination; combo and component exist; no repeated identity; price `> 0`; empty cell = no change; more than 2 decimals → rounded HALF_UP with a warning (DEC-COT-33). Warnings: rounded, inactive combination/combo, variation beyond ±30 % (DEC-COT-34). Errors carry `fila` (physical line), `columna` and `motivo` (E-54). Result: errors, or the list of changes `{target, previous (null = new), new, warnings}` and the counts new / modified / unchanged.
- `UploadPriceImport` (`pricing.manage`): validates; with errors → back with the error list (capped at 200 shown, total count given), nothing persisted; with no change → "El archivo no contiene cambios." and nothing persisted (COT-027 last bullet); otherwise a `price_imports` row `validated` with its changes, `tiers_signature` (SHA-256 of the scheme's tier ids, labels, limits and kinds) and `expires_at = now + 24 h`. The file is not stored (DEC-COT-36); `file_name` keeps the original name (max 255). Not audited at this point (COT-023 audits apply and discard).
- `ApplyPriceImport::handle(PriceImport, User)`: same user as the validator (else `BusinessRuleViolation`); `validated` and not past `expires_at` (else it is discarded as expired first); one transaction: lock the scheme row and compare `tiers_signature`; lock the affected product (or combo) rows in id order; compare each change's `previous` with the current value (a price that appeared, disappeared or changed is a conflict). Any conflict → the import becomes `discarded` (`discard_reason = conflict`, audited) in a **separate** committed transaction and the request answers "Los precios cambiaron desde la validación; suba el archivo de nuevo." (DEC-COT-35, E-58). Otherwise every change is written and audited as `pricing.price_created|updated` (or `combo_item_price_*`) with `context.price_import_id`; the import becomes `applied` with `applied_at`, audited `pricing.import_applied` with scope, scheme, file name and summary (E-53). All or nothing: an exception mid-apply rolls back every price (DoD atomic test).
- `DiscardPriceImport` (same user, `validated` only): `discarded` (`manual`), audited `pricing.import_discarded`.
- `pricing:expire-imports` scheduled hourly: validated imports past `expires_at` → `discarded` (`expired`), audited with console origin. Expiry time **24 hours** (the spec delegates it).
- History (COT-029): `GET /pricing/imports` lists applied and discarded imports (validated ones appear only to their author as "pendiente"); the detail of an applied import lists its changes with previous and new values (E-61).
**Rationale**: COT-027, COT-028, COT-029, DT-04, DEC-COT-30..DEC-COT-37, E-52..E-61.

### Decision 25: COT-007 initial price load

**Choice**:
- Command `pricing:load {--schemes=} {--prices=*} {--combo-prices=} {--author=}` (thin; prints `Archivo | Fila | Columna | Motivo`; never prints prices). `--prices` is repeatable, one template file per scheme (the bulk format is per scheme because its columns are its tiers, DT-04).
- **Phase 0**: author is an existing active internal user (CLI-018 / PRD-018 pattern); no combination price and no combo item price exists yet (one-time load, as 003 note N-4); files readable; limits of Decision 23 (5,000 rows per file).
- **File 1** `esquemas.csv` (exclusive to the load, COT-007): header `producto;esquema`; product by exact name (003 names are unique), scheme by name, active; each product once; combos are not products.
- **Phase 1**: file 1 rows, then each prices file and the combo file through `ValidatePriceImport` in load mode (schemes taken from file 1 for products it lists, from the database otherwise); rounding to 2 decimals without warning (COT-007); every error collected. Any error → nothing imported, exit 1 (E-13).
- **Phase 2**: one `DB::transaction`: `AssignPriceScheme` per product, then prices, then combo item prices, through the real Actions with `AuditOrigin::console('pricing:load')` and `auditContext {import: true, import_file: esquemas|precios|precios-combos, import_row}` (E-12).
- **Size groups** of `184`/`184-2` are defined in the ERP (COT-004 screen) after the catalog load and before this command, because neither format creates groups (COT-027 "los grupos de talla no se crean por CSV"); a prices file that names an undefined group fails with its row (Migration step 5).
- Real files are never versioned; documented location `storage/app/private/imports/` (as 002/003); deleted after the run. Tests build fictitious files at runtime.
**Rationale**: COT-007, DT-04, DEC-COT-02..DEC-COT-04, E-12, E-13, AGENTS.md §4.5.

### Decision 26: Events for `015`

**Choice** (the user delegated this; spec §10 commitment): 004 **dispatches** six events now, all implementing `ShouldDispatchAfterCommit`, with ids only (no personal data): `App\Events\Quotations\QuotationIssued(quotationId)`, `QuotationCancelled(quotationId)`, `QuotationExpired(quotationId)`, `QuotationRequiresAdvisor(quotationId, reasons)` (dispatched by `UpdateQuotationDraft`/`CreateQuotation`/`DuplicateQuotation` when the stored `requires_advisor` changes from false to true), `QuotationEmailFailed(deliveryId)`, `App\Events\Pricing\ExchangeRateFetchFailed(reason)`. **No listener** is registered in 004; `015` only subscribes. Tests assert dispatch with `Event::fake([...])`.
**Alternatives considered**: hooks only (015 would have to modify 004 Actions).
**Rationale**: spec §10, constitution §15.

### Decision 27: Lists, detail and customer history (COT-020)

**Choice**:
- `QuotationController@index`: `SegmentedTabs` Borradores / Emitidas / Vencidas / Anuladas / Aprobadas / Todas with backend counts by effective status (one grouped query); search `q` (trimmed, ≤ 100) over number, customer name and document (002 search expression), prospect name and phone (`PhoneNumber::searchFragment()`); filters `advisor_id` and `mine` ("Mis cotizaciones", DEC-COT-28), `from`/`to` (issue date for non-drafts, creation date for drafts, local days through `OperatingTime::dayStartUtc/nextDayStartUtc`), `requires_advisor`, `prospect`; `paginate(15)`; rows: number, recipient, advisor, effective status badge, date, total, "Requiere asesora" and "Prospecto" indicators. Draft totals are the cache of the last save (note N-5).
- `@show`: issued and later states from frozen columns; drafts recalculated (Decision 19). Includes deliveries (date, recipients, result) and the "sin correo" notice, source quotation link, abilities `can {update, issue, duplicate, cancel, discard, resend, negotiate, print}` computed from permission **and** state.
- `App\Http\Controllers\Customers\CustomerController::show` adds `quotations` (number, date, effective status, total; all states; prospects never appear, COT-020) when the user can `viewAny` quotations (E-46). Section placed at the `Show.vue:266` anchor.
**Rationale**: COT-020, DEC-COT-28, E-45, E-46, E-68.

### Decision 28: Frontend

**Choice**:
- Pages (TypeScript, Wayfinder helpers for every URL): `resources/js/pages/pricing/{Schemes,SchemeForm,Products,ProductPrices,Combos,ComboPrices,Settings,Imports,ImportShow}.vue`, `resources/js/pages/quotations/{Index,Editor,Show}.vue`. Components in `resources/js/components/pricing/` (`TierEditor`, `PriceTable`, `SizeGroupEditor`, `ComboPricesCard`, `RateCard`, `ImportErrors`, `ImportPreview`) and `resources/js/components/quotations/` (`RecipientPicker`, `LineCard`, `SelectionSteps`, `SizeQuantities`, `CustomizationList`, `ComboComponents`, `NegotiationPanel`, `QuotationSummary`, `DeliveryList`). Types in `resources/js/types/pricing.ts` and `quotations.ts`; amounts typed `Money = {value: string; display: string}`.
- **Editor** (spec §8, constitution §14, mobile-first): stacked `AppCard` lines; selection steps from `POST /quotations/selection-options` (auto-applied attributes shown fixed, DEC-PRD-94); per-size quantity table with `inputmode="numeric"`; customizations with quantity per garment and "sin cargo"; note; pack notice. Summary sticky at the bottom on mobile and lateral at `lg:` showing only what `POST /quotations/calculate` returns (debounced 400 ms; requests through Inertia 3 `useHttp`, no new dependency). Actions Guardar borrador, Emitir, Descartar (`ConfirmDialog`). Negotiation fields only with `can.negotiate`; "Confirmar precio de referencia" for custom color; "Anticipo acordado" with the proposed value. 44 px targets and `text-body-md` inputs.
- **Row selection**: `DataTable` gains optional `selectable` + `v-model:selected` (checkbox column, keyboard accessible, "Seleccionar todo en esta página"); used by the pricing product list for bulk scheme assignment (COT-002). Documented in `docs/ui/design-system.md` §7 with its checklist item.
- Product price page header shows the scheme `AppSelect` (assignment) and the "sin precio" marks; `products/Show.vue` and `combos/Show.vue` get a "Precios" link (and `combos/Show.vue` the read-only `ComboPricesCard`) when the user holds `pricing.view`.
- **Rate history**: `pricing/Settings.vue` gains the "Historial de tasas" section (`RateHistoryTable`, a `DataTable` over the `rates` prop with columns Fecha, Tasa, Origen, Registrada por, Estado — "Vigente" or "Reemplazada"), below `RateCard` (current rate, date, origin and "Registrar tasa manual" with `can.manage`) (Decision 11).
- **Size groups editor**: `SizeGroupEditor` calls the preview endpoint and opens `ConfirmDialog` with the backend list of deleted prices before the `PUT` (Decision 10, E-70).
- **Navigation** (`navigation.ts`, two new entries only): "Cotizaciones" (`request_quote`, `quotations.view`, group Comercial, priority 5) and "Precios" (`sell`, `pricing.view`, group Comercial, priority 35, `alsoActiveOn` the other `/pricing` sections). `useNavigation.ts` and its `MAX_PRIMARY = 4` cap are **not changed**. Consequence, not a new rule: the bottom bar shows the first 4 non-administration entries by priority (existing design-system rule), so priority 5 places "Cotizaciones" second and pushes the fourth current item to "Más". With the §4 matrix:
  - Asesora de Ventas: bottom bar **Inicio, Cotizaciones, Clientes, Productos**; **Combos** and Precios go to "Más" (today Combos is in the bar).
  - Administrador / Gerente: the same four; Combos, Catálogo and Precios in "Más".
  - Any role without `quotations.view` keeps its current bar.
  Confirmed by the user on 2026-10-13: "Cotizaciones" takes priority 5 and Combos moves to "Más" (spec §8: advisors quote mostly from the phone).
**Rationale**: spec §8, design system §7, §11, constitution §14.

### Decision 29: Background processes in development and production

**Choice**:
- `compose.yaml` gains two services reusing the `sail-8.5/app` image and the Sail runtime's `SUPERVISOR_PHP_COMMAND` override: `queue` (`php artisan queue:work --tries=3 --backoff=60`) and `scheduler` (`php artisan schedule:work`), both `depends_on: mysql` (and `mailpit` for `queue`), same volume and network. `sail up -d` starts them; `AGENTS.md` §2 gains one line saying so. `composer dev` (`artisan dev`) already starts `queue:listen` for non-Sail use; no scheduler is added to it.
- `.env.example`: `QUEUE_CONNECTION=database` (existing `jobs` table), mail variables (Decision 21), `EXCHANGE_RATE_URL`, company variables.
- Production (hosting unknown, proposal risk): one process supervisor running `queue:work` and one cron entry `* * * * * php artisan schedule:run`. Without the scheduler the system stays correct but degraded: expiry is still shown on read, no rate is fetched (manual entry and last rate remain), validated imports expire on read at apply time. Recorded in Migration / Rollout.
**Rationale**: exploration §7 risk "no job infrastructure", DT-05, DT-06.

### Decision 30: Tests updated in 001–003, and why

| Test | Current assertion | Change | Reason |
|---|---|---|---|
| `tests/Unit/PermissionNameTest.php` | 22 cases | 28, list extended with `quotations.*`, `pricing.*` | §4 (FND-017) |
| `tests/Unit/AuditActionTest.php` | 48 cases / 48 labels | 74 | COT-023 (FND-022) |
| `tests/Feature/FoundationSeederTest.php`, `Customers/PermissionMatrixSeedingTest.php`, `Products/ProductPermissionMatrixSeedingTest.php` | filters by `customers.`/`products.` prefixes | also exclude `quotations.` and `pricing.` | 001/002/003 counts stay their own |
| `tests/Feature/Roles/PermissionCatalogTest.php` | per-role sets | add each role's §4 set | §4 matrix |
| `tests/Feature/Customers/DeleteCustomerTest.php:112` | `->todo('E-19 …')` | real test `E-19 … (004 E-39)` creating a quotation | COT-024 makes it testable |
| `tests/Feature/Products/DeleteProductTest.php:378` | `->todo('E-29 …')` | real test `E-29 … (004 E-40)` | same |
| `tests/Feature/Products/ComboTest.php` | combo edit replaces components | add: component ids kept for same product (DEC-COT-44) | Decision 12 |

Any other literal found when running the suite in slice 1 is updated the same way and recorded in `tasks.md`.

## Data Flow

Live calculation and draft save:

```
Editor (Vue) ──debounced──> POST /quotations/calculate   authorize calculate (+ negotiate if negotiated fields)
                              CalculateQuotation(input)
                                batch: products, schemes+tiers, packs, rate, parameters
                                ResolveSelection(memoized loader) × size rows ──errors──> row errors
                                PieceCounter → TierSelector → PriceBook → NegotiationBasis → AdvanceCalculator → BsConverter
                                → PackCatalog (candidates) → PackAdmission (ComboMembership + SelectionRules) → PackAdvisor
                              <── JSON result (strings)                        (no writes, no API)
Editor ── PUT /quotations/{q} ──> UpdateQuotationDraft
                                    DB::transaction { lock quotation; draft?; replace lines (ids kept);
                                                      CalculateQuotation (stored negotiation + basis);
                                                      drop discarded negotiated prices and their basis;
                                                      store cache (total, requires_advisor, combination_id);
                                                      audit advisor/recipient changes only }
                                    afterCommit: QuotationRequiresAdvisor (false→true)
```

Issue and email:

```
POST /quotations/{q}/issue  authorize issue
  IssueQuotation
    DB::transaction {
      lock quotation (draft?) + customer (active?)
      CalculateQuotation(stored input) ──incomplete──> ValidationException(pending lines), nothing changes
      write frozen copy (lines, rows, components, totals, advance, rate, Bs, recipient, advisor)
      issued_at, expires_on = today + validity, status = issued, audit quotations.issued
      insert deliveries (pending), one per distinct address
    }
    afterCommit: SendQuotationEmail × deliveries ; QuotationIssued
  queue worker: SendQuotationEmail → Mail::send(QuotationMail) → sent | failed() → failed + QuotationEmailFailed
```

Exchange rate:

```
scheduler 07:00 / 07:30 / 08:00 / 09:00 America/Caracas
  exchange-rate:fetch → today's effective rate exists? ──yes──> end
                      → Http (timeout, 3 attempts) → RateParser (raw token, HALF_UP 2)
                           ok  → insert {today, value, api, source date}   (unique effective_date)
                           ko  → Log::warning + ExchangeRateFetchFailed, nothing saved
POST /pricing/exchange-rates (pricing.manage)
  DB::transaction { lock effective(today) → set superseded_at (frees key) → insert manual
                    → set superseded_by_id → audit }      unique clash with API insert → rerun once
CurrentExchangeRate: today's effective row, else latest effective row
GET /pricing/settings → rates prop = ExchangeRate::history() (all rows, superseded marked)
```

Size groups with prices (DEC-COT-53):

```
SizeGroupEditor ── POST …/size-groups/preview ──> PreviewSizeGroups (read-only)
                                                   SizeGroupPlan::diff → {requires_confirmation, deleted_prices[], confirm_token}
   requires_confirmation? ──no──> PUT …/size-groups {groups}
                          ──yes─> ConfirmDialog (grupo · tramo · valor) ──cancel──> nothing changes
                                   ──confirm──> PUT …/size-groups {groups, confirm_token}
SyncSizeGroups: DB::transaction { lock product; recompute plan;
                  token missing → 422 "confirme" | token differs → 422 "los precios cambiaron";
                  delete listed prices; keep/drop/create groups; audit size_groups_updated (deleted_prices) }
```

Bulk import:

```
GET /pricing/export (pricing.view) → PriceTemplate rows → CsvWriter (BOM, ';', comma decimal)
POST /pricing/imports (pricing.manage, file ≤ 2 MB)
  CsvReader::readDocument → ValidatePriceImport ──errors──> back with Fila/Columna/Motivo
                                                ──no change──> "sin cambios"
                                                ──ok──> price_imports(validated, changes, tiers_signature, expires_at)
POST /pricing/imports/{i}/apply (same user)
  DB::transaction { lock scheme (signature) → lock products/combos (id order) → compare previous values
                    ──conflict──> rollback; discard(conflict) in own transaction; error
                    write prices + audits (context.price_import_id); applied; audit import_applied }
```

Expiry:

```
read:   effective status = issued ∧ expires_on < today(local) ? expired : status   (lists, counts, detail)
00:05:  quotations:expire → per due row: lock → expired + audit (console, automatic) + QuotationExpired
writes: cancel / resend → ExpireQuotation::ifDue() under lock, then state check
```

## File Changes

Generated Wayfinder files (`resources/js/actions/**`, `resources/js/routes/**`) are excluded from the authored-line count.

| File | Action | Description |
|---|---|---|
| `composer.json`, `composer.lock` | Modify | `brick/math` direct `require` (`1.0.0`, approved) |
| `database/migrations/2026_10_14_000001_create_pricing_tables.php` | Create | schemes, tiers, `products.price_scheme_id`, size groups, combination prices, combo item prices, commercial parameters (seed row) |
| `database/migrations/2026_10_14_000002_create_exchange_rates_table.php` | Create | rates with generated `effective_date` unique |
| `database/migrations/2026_10_14_000003_create_quotations_tables.php` | Create | sequence (seed row), quotations, lines, rows, customizations, components, negotiated prices |
| `database/migrations/2026_10_14_000004_create_quotation_email_deliveries_table.php` | Create | deliveries |
| `database/migrations/2026_10_14_000005_create_price_imports_tables.php` | Create | imports and changes |
| `app/Enums/{PriceSchemeType,PriceTierKind,QuotationStatus,QuotationOrigin,PriceOrigin,ExchangeRateOrigin,EmailDeliveryStatus,PriceImportScope,PriceImportStatus,LineKind}.php` | Create | Decision 2 |
| `app/Enums/PermissionName.php`, `app/Enums/AuditAction.php` | Modify | +6 / +26 cases |
| `database/seeders/InitialRolePermissions.php` | Modify | §4 matrix |
| `database/seeders/PriceSchemeSeeder.php`, `DatabaseSeeder.php` | Create / Modify | four initial schemes |
| `app/Actions/Roles/SyncRolePermissions.php` | Modify | two coherence blocks |
| `app/Casts/AsBigDecimal.php`, `app/Support/Pricing/{Money,InvalidAmount,SchemeRules,SizeGroupRules,SizeGroupLabel,SizeGroupPlan,MissingPrices,RateParser,RateSnapshot}.php` | Create | Decisions 3, 8, 10, 11 |
| `app/Support/Pricing/Import/{PriceTemplate,PriceCell,PriceImportPlan,ImportError,ValidationMode}.php` | Create | Decisions 23, 24 |
| `app/Support/Csv/CsvReader.php`, `app/Support/Csv/{CsvDocument,CsvWriter}.php` | Modify / Create | Decision 23 |
| `app/Support/Quotations/Calculation/{CalculationInput,CalculationResult,LineResult,RowResult,LineFacts,PieceCounter,TierSelector,PriceBook,NegotiationBasis,AdvanceCalculator,BsConverter,PackCatalog,PackAdmission,PackAdvisor}.php` | Create | Decisions 14–17 |
| `app/Support/Quotations/{QuotationRules,QuotationNumber,QuotationStatusResolver,QuotationPresenter,DocumentTheme,Recipients}.php` | Create | Decisions 19–21, 27 |
| `app/Support/Time/OperatingTime.php` | Modify | `today()` |
| `app/Support/Products/Selection/CatalogSnapshotLoader.php` | Modify | `memoized()` cache (no price/stock strings, D1) |
| `app/Support/Products/ComboComponents.php`, `ComboRules.php` | Modify | identity-preserving replace with id-less product fallback, `components.*.id` (DEC-COT-44) |
| `app/Actions/Products/{SyncProductAttributes,UpdateCombination,UpdateCombo,DeleteCombination,DeleteCombo,DeleteProduct}.php`, `app/Actions/Customers/DeleteCustomer.php` | Modify | size-group cleanup, history guards, price copies in delete audits |
| `app/Models/{PriceScheme,PriceTier,CombinationPrice,SizeGroup,ComboItemPrice,CommercialParameters,ExchangeRate,PriceImport,PriceImportChange,Quotation,QuotationLine,QuotationLineRow,QuotationLineComponent,QuotationEmailDelivery}.php` | Create | casts, relations, scopes (`withEffectiveStatus`, `search`) |
| `app/Models/Product.php`, `Combination.php`, `Combo.php`, `ComboComponent.php`, `Customer.php` | Modify | relations to scheme, prices, quotations |
| `database/factories/*Factory.php` (one per new model) | Create | fictitious data; states `fixed()`, `negotiationLast()`, `issued()`, `expired()`, `prospect()` |
| `app/Policies/{PricingPolicy,QuotationPolicy}.php`, `app/Providers/AppServiceProvider.php` | Create / Modify | Decision 6 |
| `app/Actions/Pricing/*` | Create | `CreatePriceScheme`, `UpdatePriceScheme`, `ActivatePriceScheme`, `DeactivatePriceScheme`, `AssignPriceScheme`, `SyncCombinationPrices`, `PreviewSizeGroups`, `SyncSizeGroups`, `SizeGroupMaintenance`, `SyncComboItemPrices`, `UpdateCommercialParameters`, `FetchExchangeRate`, `RecordManualExchangeRate`, `CurrentExchangeRate`, `ExportPrices`, `ValidatePriceImport`, `UploadPriceImport`, `ApplyPriceImport`, `DiscardPriceImport`, `ExpirePriceImports`, `LoadInitialPrices` |
| `app/Actions/Quotations/*` | Create | `CalculateQuotation`, `CreateQuotation`, `UpdateQuotationDraft`, `SetNegotiatedPrice`, `SetAgreedAdvance`, `IssueQuotation`, `ExpireQuotation`, `DuplicateQuotation`, `CancelQuotation`, `DiscardQuotation`, `ResendQuotation` |
| `app/Jobs/SendQuotationEmail.php`, `app/Mail/QuotationMail.php` | Create | Decision 21 |
| `app/Events/Quotations/*`, `app/Events/Pricing/ExchangeRateFetchFailed.php` | Create | Decision 26 |
| `app/Console/Commands/{FetchExchangeRateCommand,ExpireQuotationsCommand,ExpirePriceImportsCommand,LoadInitialPricesCommand}.php` | Create | `exchange-rate:fetch`, `quotations:expire`, `pricing:expire-imports`, `pricing:load` |
| `routes/console.php` | Modify | schedule entries (Decisions 11, 20, 24) |
| `app/Http/Controllers/Pricing/*`, `app/Http/Controllers/Quotations/*` | Create | thin controllers incl. JSON calculate/options/recipients, CSV download, print |
| `app/Http/Controllers/Customers/CustomerController.php` | Modify | quotation history prop |
| `app/Http/Requests/Pricing/*`, `app/Http/Requests/Quotations/*` | Create | validation + authorize |
| `routes/web.php` | Modify | routes (Interfaces), protected by default |
| `config/company.php`, `config/services.php`, `.env.example` | Create / Modify | company data, rate URL, mail, queue |
| `compose.yaml`, `AGENTS.md` §2 | Modify | `queue` and `scheduler` services (Decision 29) |
| `lang/es/validation.php` | Modify | attribute names and messages |
| `resources/views/quotations/{print,partials/document}.blade.php`, `resources/views/mail/quotation.blade.php` | Create | Decision 21 |
| `resources/js/pages/pricing/**`, `resources/js/pages/quotations/**` | Create | Decision 28 |
| `resources/js/components/pricing/**` (incl. `RateHistoryTable`), `resources/js/components/quotations/**` | Create | Decision 28 |
| `resources/js/components/DataTable.vue` | Modify | optional row selection |
| `resources/js/pages/customers/Show.vue`, `resources/js/pages/products/Show.vue`, `resources/js/pages/combos/Show.vue`, `resources/js/components/products/ComboForm.vue` | Modify | history section and C3 copy; "Precios" links/card; component ids |
| `resources/js/types/{pricing,quotations}.ts`, `resources/js/navigation.ts` | Create / Modify | types; two nav entries |
| `docs/ui/design-system.md` | Modify | row selection, print/email document theme |
| `tests/Pest.php` | Modify | `cot` group for 004 directories; `toBeMoneyString(int $scale = 2)` expectation |
| `tests/Feature/Architecture/{MoneyHasNoFloatTest,MoneyJsonHasNoFloatTest}.php` | Create | no `float` in money code; no JSON number in 004 JSON columns and audit payloads (Decision 3) |
| `tests/Unit/Pricing/*`, `tests/Unit/Quotations/*`, `tests/Feature/Pricing/*`, `tests/Feature/Quotations/*` | Create | Testing Strategy |
| Tests in Decision 30 | Modify | literals, todos |

## Interfaces / Contracts

### Schema

| Table | Columns | Keys / constraints |
|---|---|---|
| `price_schemes` | `id`; `name` varchar(60) as_ci; `type` varchar(10); `status` varchar(20) default `active`; timestamps | unique `name` |
| `price_tiers` | `id`; `price_scheme_id` FK restrict; `label` varchar(30) as_ci; `min_quantity` int unsigned; `max_quantity` int unsigned null; `kind` varchar(12); `sort_order` smallint unsigned; timestamps | unique `(price_scheme_id, label)`; unique `(price_scheme_id, sort_order)`; CHECK `max_quantity IS NULL OR max_quantity >= min_quantity` |
| `products` (+) | `price_scheme_id` null FK restrict | index |
| `size_groups` | `id`; `combination_id` FK cascade; `sort_order`; timestamps | index `combination_id` |
| `size_group_values` | `size_group_id` FK cascade; `combination_id` FK cascade; `size_value_id` FK restrict → `attribute_values` | PK `(size_group_id, size_value_id)`; unique `(combination_id, size_value_id)` |
| `combination_prices` | `id`; `combination_id` FK cascade; `price_tier_id` FK cascade; `size_group_id` null FK cascade; `size_group_key` STORED `IFNULL(size_group_id,0)`; `unit_price` DECIMAL(10,2); `updated_by` null FK users nullOnDelete; timestamps | unique `(combination_id, price_tier_id, size_group_key)`; CHECK `unit_price > 0` |
| `combo_item_prices` | `id`; `combo_component_id` FK cascade unique; `unit_price` DECIMAL(10,2); `updated_by`; timestamps | CHECK `unit_price > 0` |
| `commercial_parameters` | `id` tinyint PK; `advance_percentage` DECIMAL(5,2); `validity_days` smallint unsigned; `updated_by` null; timestamps | CHECK `advance_percentage BETWEEN 0 AND 100`; CHECK `validity_days >= 1`; row `id=1` inserted by the migration |
| `exchange_rates` | `id`; `rate_date` date; `value` DECIMAL(14,2); `origin` varchar(10); `source_updated_at` datetime null; `recorded_by` null FK users restrict; `superseded_at` datetime null; `superseded_by_id` null FK self; `effective_date` date STORED `IF(superseded_at IS NULL, rate_date, NULL)`; `created_at` | unique `effective_date`; index `(rate_date)`; CHECK `value > 0` |
| `quotation_sequences` | `id` tinyint PK; `last_number` int unsigned | row `id=1, last_number=0` inserted by the migration |
| `quotations` | `id`; `number` int unsigned; `status` varchar(12); `origin` varchar(10); `customer_id` null FK restrict; `include_contact` bool default false; `prospect_name` varchar(150) null; `prospect_phone` varchar(20) null; `prospect_email` varchar(255) null; `created_by` FK users restrict; `advisor_id` null FK users restrict; `source_quotation_id` null FK self nullOnDelete; `notes` text null; `agreed_advance` DECIMAL(14,2) null; `agreed_advance_by` null FK users; `agreed_advance_at` null; cache `total` DECIMAL(14,2) null, `requires_advisor` bool default false; frozen `pieces_by_line` json null, `advance_percentage` DECIMAL(5,2) null, `required_advance` DECIMAL(14,2) null, `advance_breakdown` json null, `rate_value` DECIMAL(14,2) null, `rate_date` date null, `rate_origin` varchar(10) null, `total_bs`/`advance_bs` DECIMAL(18,2) null, `advisor_reasons` json null, `recipient_snapshot` json null, `advisor_snapshot` json null; `issued_at` null; `expires_on` date null; `expired_at` null; `cancelled_at` null; `cancelled_by` null FK users; `cancel_reason` varchar(500) null; timestamps | unique `number`; index `(status, expires_on)`; index `customer_id`; index `advisor_id`; CHECK `(customer_id IS NULL) <> (prospect_name IS NULL)` |
| `quotation_lines` | `id`; `quotation_id` FK cascade; `position` smallint unsigned; `kind` varchar(10); `product_id` null FK restrict; `combo_id` null FK restrict; `combination_id` null FK restrict; `selection` json; `combo_quantity` int unsigned null; `note` varchar(500) null; `negotiated_by` null FK users; `negotiated_at` null; `negotiation_confirmed` bool default false; basis `negotiated_combination_id` null FK restrict → combinations, `negotiated_size_group_ids` json null (sorted ints, DEC-COT-55); frozen `code` varchar(30) null, `descriptive_name` varchar(255) null, `normalized_selection` json null, `tier_label` varchar(30) null, `price_origin` varchar(12) null, `subtotal` DECIMAL(14,2) null, `requires_advisor` bool default false, `advisor_reason` varchar(150) null; timestamps | unique `(quotation_id, position)`; CHECK `(kind='product' AND product_id IS NOT NULL AND combo_id IS NULL) OR (kind='combo' AND combo_id IS NOT NULL AND product_id IS NULL)` |
| `quotation_line_rows` | `id`; `quotation_line_id` FK cascade; `size_value_id` null FK restrict; `size_key` STORED `IFNULL(size_value_id,0)`; `quantity` int unsigned; frozen `unit_price` DECIMAL(10,2) null, `size_group_label` varchar(60) null | unique `(quotation_line_id, size_key)`; CHECK `quantity >= 1` |
| `quotation_line_customizations` | `quotation_line_id` FK cascade; `service_product_id` FK restrict → products; `quantity_per_garment` smallint unsigned | PK both |
| `quotation_line_components` | `id`; `quotation_line_id` FK cascade; `combo_component_id` null FK nullOnDelete; `combination_id` null FK restrict; `position` smallint unsigned; `selection` json; frozen `code`, `descriptive_name`, `normalized_selection` json, `quantity` smallint unsigned, `unit_price` DECIMAL(10,2) | unique `(quotation_line_id, position)` |
| `quotation_line_negotiated_prices` | `id`; `quotation_line_id` FK cascade; `size_group_id` null FK cascade; `size_group_key` STORED; `unit_price` DECIMAL(10,2) | unique `(quotation_line_id, size_group_key)`; CHECK `unit_price > 0` |
| `quotation_email_deliveries` | `id`; `quotation_id` FK restrict; `batch_id` char(26) ULID; `kind` varchar(10); `recipient_role` varchar(10); `recipient_email` varchar(255); `status` varchar(10); `requested_by` null FK users; `sent_at` null; `failed_at` null; `error` varchar(255) null; timestamps | index `(quotation_id, batch_id)` |
| `price_imports` | `id`; `scope` varchar(20); `price_scheme_id` null FK restrict; `user_id` FK restrict; `file_name` varchar(255); `status` varchar(12); `discard_reason` varchar(10) null; `tiers_signature` char(64) null; `summary` json; `expires_at`; `applied_at` null; `discarded_at` null; timestamps | index `(status, expires_at)` |
| `price_import_changes` | `id`; `price_import_id` FK cascade; `row_number` int unsigned; `combination_id` null FK nullOnDelete; `price_tier_id` null FK nullOnDelete; `size_group_id` null FK nullOnDelete; `combo_component_id` null FK nullOnDelete; copies `code`, `descriptive_name`, `size_group_label`, `tier_label`, `component_position`; `previous_price` DECIMAL(10,2) null; `new_price` DECIMAL(10,2); `warnings` json | index `price_import_id` |

`as_ci` = `utf8mb4_0900_as_ci`. Timestamps in UTC; `rate_date`, `expires_on` are operating-timezone local dates (spec dates are calendar days in Caracas).

### Permissions and initial matrix

```php
case QuotationsView = 'quotations.view';           // 'Ver cotizaciones, su detalle, su vista imprimible y el historial de cotizaciones en la ficha del cliente'
case QuotationsCreate = 'quotations.create';       // 'Crear y editar borradores, emitirlos, reenviarlos por correo, duplicar cotizaciones y descartar borradores'
case QuotationsNegotiate = 'quotations.negotiate'; // 'Fijar o confirmar el precio de las líneas que requieren asesora y acordar su anticipo'
case QuotationsCancel = 'quotations.cancel';       // 'Anular cotizaciones emitidas'
case PricingView = 'pricing.view';                 // 'Ver esquemas, precios, precios de combos, parámetros comerciales y tasas; exportar los precios a CSV y ver el historial de importaciones'
case PricingManage = 'pricing.manage';             // 'Gestionar esquemas, asignarlos a productos, editar precios y parámetros comerciales, registrar la tasa manualmente, importar y aplicar actualizaciones masivas'
```

| Permission | Roles (`InitialRolePermissions::MATRIX`) |
|---|---|
| `quotations.view`, `pricing.view` | Administrador, Gerente, Asesora de Ventas, Finanzas |
| `quotations.create`, `quotations.negotiate`, `quotations.cancel` | Administrador, Gerente, Asesora de Ventas |
| `pricing.manage` | Administrador, Gerente |

### Audit actions (+26; 48 → 74)

| Value | Label |
|---|---|
| `pricing.scheme_created` / `_updated` / `_deactivated` / `_activated` | "Esquema de precio creado / modificado / desactivado / reactivado" |
| `pricing.product_scheme_assigned` | "Esquema de precio asignado a un producto" |
| `pricing.price_created` / `_updated` / `_deleted` | "Precio de combinación registrado / modificado / eliminado" |
| `pricing.size_groups_updated` | "Grupos de talla modificados" |
| `pricing.combo_item_price_created` / `_updated` / `_deleted` | "Precio de ítem de combo registrado / modificado / eliminado" |
| `pricing.parameters_updated` | "Parámetros comerciales modificados" |
| `pricing.exchange_rate_recorded` | "Tasa de cambio registrada manualmente" |
| `pricing.import_applied` / `pricing.import_discarded` | "Actualización masiva de precios aplicada / descartada" |
| `quotations.created` / `.issued` / `.duplicated` / `.cancelled` / `.discarded` / `.expired` | "Cotización creada / emitida / duplicada / anulada / descartada / vencida" |
| `quotations.negotiated_price_set` | "Precio negociado o confirmado en una cotización" |
| `quotations.agreed_advance_set` | "Anticipo acordado registrado" |
| `quotations.advisor_changed` / `quotations.recipient_changed` | "Asesora responsable cambiada / Destinatario de cotización cambiado" |

### Audit payloads

| Action | `old_values` | `new_values` | `context` |
|---|---|---|---|
| `pricing.scheme_*` | update: changed fields, `tiers` before; `deleted_prices` when tiers lost prices; status `{status}` | changed fields, `tiers` after | console origin for the seeder |
| `pricing.product_scheme_assigned` | `{scheme}`, `deleted_prices[{code, size_group, tier, price}]` | `{scheme}` | import marker on load |
| `pricing.price_*` | `{code, size_group, tier, price}` or — | same or — | `price_import_id` on apply; import marker on load |
| `pricing.size_groups_updated` | partition by size names; `removed_sizes`; `deleted_prices` | partition | `reason: size_removed` for DEC-COT-48 |
| `pricing.combo_item_price_*` | `{combo_code, component, product, quantity, price}` or — | same | as prices |
| `pricing.parameters_updated` | changed fields | changed fields | — |
| `pricing.exchange_rate_recorded` | previous effective `{date, value, origin}` or — | `{date, value, origin: manual}` | — |
| `pricing.import_applied` / `_discarded` | — | `{scope, scheme, file_name, summary, discard_reason?}` | console origin on expiry |
| `quotations.created` / `.duplicated` | — | `{number, recipient, advisor, origin, source_number?}` | — |
| `quotations.issued` | — | `{number, total, required_advance, advance_percentage, rate, total_bs, advance_bs, expires_on, lines_count}` | — |
| `quotations.negotiated_price_set` | previous prices or — | `{line_position, code, prices[{size_group, price}], confirmed}` | — |
| `quotations.agreed_advance_set` | `{amount}` or — | `{amount}` | — |
| `quotations.advisor_changed` / `.recipient_changed` | previous advisor / recipient | new | — |
| `quotations.cancelled` | `{status: issued}` | `{status: cancelled, reason}` | — |
| `quotations.discarded` | full draft copy | — | — |
| `quotations.expired` | `{status: issued, expires_on}` | `{status: expired}` | console origin, `automatic: true` |
| `products.combo_updated` (003, extended) | + `deleted_item_prices` | — | — |
| `products.combination_deleted`, `products.deleted`, `products.combo_deleted` (003, extended) | + `prices` / `item_prices` in the copy | — | — |

### Key PHP contracts

```php
// app/Support/Pricing
final class Money {
    public static function parse(string $input, int $maxScale): BigDecimal;      // throws InvalidAmount
    public static function round(BigDecimal $amount, int $scale = 2): BigDecimal; // HALF_UP
    public static function toStorage(BigDecimal $amount, int $scale): string;
    public static function display(BigDecimal|string $amount, int $scale = 2): string; // "1.150,00"
    public static function percentOf(BigDecimal $percent, BigDecimal $amount): BigDecimal;
}
final class SchemeRules {
    /** @param list<array{id?: int, label: string, min: int, max: ?int, kind: string}> $tiers
     *  @return array<string, string> field => message (empty when valid) */
    public static function validateTiers(array $tiers): array;
    public const RESERVED_HEADERS = ['codigo', 'grupo_talla', 'producto', 'nombre', 'estado', 'precio', 'codigo_combo', 'componente', 'cantidad', 'precio_item'];
}
final class RateParser { public static function promedio(string $rawJsonBody): ?BigDecimal; } // raw token, HALF_UP 2, null if missing/≤0/over DECIMAL(14,2)
final readonly class RateSnapshot { public function __construct(public BigDecimal $value, public CarbonImmutable $date, public ExchangeRateOrigin $origin) {} }

// app/Support/Quotations/Calculation (pure, no Eloquent)
final class PieceCounter {
    /** @param list<LineFacts> $lines @return array{lines: array<string, int>, services: array<int, int>} */
    public static function count(array $lines): array;
}
final class TierSelector { /** @param list<TierFacts> $tiers */ public static function for(array $tiers, int $quantity): ?TierFacts; }
final class AdvanceCalculator {
    public static function required(BigDecimal $percent, BigDecimal $percentBase, BigDecimal $fullBase, ?BigDecimal $agreed): AdvanceBreakdown;
    public static function proposedAgreed(BigDecimal $percent, BigDecimal $negotiatedSubtotal): BigDecimal;
}
final class BsConverter { public static function convert(BigDecimal $usd, ?RateSnapshot $rate): ?BigDecimal; }
final class PackAdvisor {
    /** DEC-COT-08, DEC-COT-47: one notice, greatest saving, tie → more units. Never called for a line with more than one unit price (DEC-COT-54). */
    public static function best(PackLineFacts $line, array $packs): ?PackNotice;
}
final class PackAdmission {
    /** DEC-PRD-42 axes (ComboMembership) + full selection under the component restriction (SelectionRules), for every row.
     *  @param list<array<string, mixed>> $rowChoices  @param list<int> $rowCombinationIds  @param list<ValueSnapshot> $palette */
    public static function admits(ComponentSnapshot $component, array $rowChoices, array $rowCombinationIds, array $palette): bool;
}
final class PackCatalog { /** @param list<int> $lineProductIds @return list<ComboSnapshot> */ public static function load(array $lineProductIds, CatalogSnapshotLoader $memoized): array; }
final readonly class NegotiationBasis {
    /** @param list<?int> $rowGroupIds */ public static function of(int $combinationId, array $rowGroupIds): self; // sorted distinct, nulls dropped
    public function matches(self $other): bool;
}

// app/Support/Pricing
final readonly class SizeGroupPlan {
    /** @param list<array{id: int, size_ids: list<int>}> $stored @param list<list<int>> $proposed @param list<int> $ungrouped */
    public static function diff(array $stored, array $proposed, array $ungrouped, Collection $prices): self;
    /** @return list<array{group: ?string, tier: string, price: string}> */ public function deletedPrices(): array;
    public function confirmToken(): ?string;   // null when nothing is deleted
}

// app/Support/Products/Selection (003, extended without price/stock references)
final class CatalogSnapshotLoader {
    public function memoized(): self;   // fresh instance with per-instance cache (D1)
}

// app/Actions/Quotations
CalculateQuotation::handle(CalculationInput $input): CalculationResult;
CreateQuotation::handle(array $data, User $actor, ?AuditOrigin $origin = null): Quotation;
UpdateQuotationDraft::handle(Quotation $quotation, array $data, User $actor): Quotation;
SetNegotiatedPrice::handle(QuotationLine $line, array $data, User $actor): void;
SetAgreedAdvance::handle(Quotation $quotation, string $amount, User $actor): void;
IssueQuotation::handle(Quotation $quotation, User $actor): Quotation;
ExpireQuotation::handle(Quotation $quotation): void;  ExpireQuotation::ifDue(Quotation $locked): bool;
DuplicateQuotation::handle(Quotation $source, User $actor): Quotation;
CancelQuotation::handle(Quotation $quotation, string $reason, User $actor): void;
DiscardQuotation::handle(Quotation $quotation, User $actor): void;
ResendQuotation::handle(Quotation $quotation, User $actor): void;

// app/Actions/Pricing (signatures follow 003: creates take ?AuditOrigin $origin = null, array $auditContext = [])
AssignPriceScheme::handle(array $productIds, PriceScheme $scheme, bool $confirmPriceRemoval, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): void;
ValidatePriceImport::handle(CsvDocument $document, PriceImportScope $scope, ?PriceScheme $scheme, ValidationMode $mode): PriceImportPlan;
ApplyPriceImport::handle(PriceImport $import, User $actor): void;   // throws BusinessRuleViolation on conflict (import discarded)
```

Calculation input (HTTP and Action):

```php
[
  'lines' => [[
    'id' => ?int,                                   // persisted line (drafts)
    'kind' => 'product'|'combo',
    'product_id' => int, 'selection' => [/* 003 product input without size */],
    'rows' => [['size_value_id' => ?int, 'quantity' => int]],
    'customizations' => [['service_product_id' => int, 'quantity_per_garment' => int]],
    'combo_id' => int, 'combo_quantity' => int, 'components' => [componentId => [/* 003 component input */]],
    'negotiated' => ['prices' => [['size_group_id' => ?int, 'price' => '29.00']], 'confirmed' => bool,
                     'basis' => ['combination_id' => int, 'size_group_ids' => list<int>]] | null, // HTTP calc only; actions use stored values
    'note' => ?string,
  ]],
  'agreed_advance' => ?string,
]
```

### Technical limits (defaults chosen here)

| Limit | Value | Business decision? |
|---|---|---|
| Line note (COT-014) | ≤ 500 characters | Technical (spec delegates it) |
| Cancel reason (COT-019) | 1–500 characters | Technical (spec delegates it) |
| Quotation observations | ≤ 2,000 characters | Technical |
| Prospect name / email / phone | 1–150 / CLI-004 / CLI-004 mobile | Technical (CLI-004 rules) |
| Scheme name / tier label | 1–60 / 1–30, as_ci | Technical |
| Tier quantities | integer 1–999,999 | Technical upper bound |
| Advance percentage / validity | 0–100 with ≤ 2 decimals / 1–365 days | Lower bounds are the spec's; scale and upper bound technical |
| Prices | `DECIMAL(10,2)`, > 0, ≤ 99,999,999.99 | Technical upper bound |
| Rate | `DECIMAL(14,2)`, > 0, ≤ 2 decimals | DT-01, DEC-COT-60 |
| Quantities per row / combos per line / customization per garment | 1–99,999 / 1–99,999 / 1–99 | Technical (spec: no business cap, COT-014) |
| CSV (COT-027, COT-007) | ≤ 2 MB, ≤ 5,000 data rows, UTF-8 (BOM optional) | Technical (spec delegates it) |
| Import errors shown | first 200, plus the total | Technical |
| Validated import expiry (COT-028) | 24 hours | Technical (spec delegates it) |
| Rate fetch retries (COT-030) | 3 HTTP attempts (2 s, 10 s backoff) per run; runs at 07:00, 07:30, 08:00, 09:00 Caracas | Technical (spec delegates it) |
| Rate HTTP timeouts | connect 5 s, total 10 s | Technical |
| Email job | 3 tries, backoff 60 s / 300 s | Technical |
| Editor recalculation debounce | 400 ms | Technical |
| Lists | `paginate(15)`; `q` trimmed, ≤ 100 | Technical (as 002/003) |

### Routes and authorization

All routes are protected by default (`RequireAuthentication`); none is added to `PublicRoutes`.

**Declaration order**: within each prefix, every static segment is declared **before** the parameterized route that could capture it, in the order of this table: `/pricing/products/scheme` before `/pricing/products/{product}`; `/pricing/schemes/create` before `/pricing/schemes/{scheme}`; `/quotations/create`, `/quotations/calculate`, `/quotations/selection-options` and `/quotations/recipients` before `/quotations/{quotation}` (and before `/{quotation}/edit`). As a backstop, `{quotation}`, `{product}`, `{scheme}`, `{combination}`, `{combo}`, `{import}` and `{line}` use `->whereNumber()`, so a future static segment declared out of order can never be bound as a model id. A route test asserts `GET /quotations/recipients` and `GET /quotations/create` reach their own actions (not `quotations.show`).

| Method | URI | Name | Ability → permission |
|---|---|---|---|
| GET | `/pricing/products` | `pricing.products.index` | PricingPolicy view |
| PUT | `/pricing/products/scheme` | `pricing.products.scheme.bulk` | manage |
| GET | `/pricing/products/{product}` | `pricing.products.show` | view |
| PUT | `/pricing/products/{product}/scheme`, `/pricing/products/{product}/prices` | `pricing.products.scheme.update` / `.prices.update` | manage |
| POST | `/pricing/combinations/{combination}/size-groups/preview` (JSON, read-only) | `pricing.size-groups.preview` | manage |
| PUT | `/pricing/combinations/{combination}/size-groups` (`groups`, optional `confirm_token`) | `pricing.size-groups.update` | manage |
| GET / POST | `/pricing/schemes`, `/pricing/schemes/create` | `pricing.schemes.index/create/store` | view / manage / manage |
| GET / PUT | `/pricing/schemes/{scheme}/edit`, `/pricing/schemes/{scheme}` | `pricing.schemes.edit/update` | manage |
| POST | `/pricing/schemes/{scheme}/activate`, `/deactivate` | `pricing.schemes.activate/deactivate` | manage |
| GET | `/pricing/combos`, `/pricing/combos/{combo}` | `pricing.combos.index/show` | view |
| PUT | `/pricing/combos/{combo}/prices` | `pricing.combos.prices.update` | manage |
| GET | `/pricing/settings` (`?rates_page=` for the rate history) | `pricing.settings` | view |
| PUT | `/pricing/settings/parameters` | `pricing.parameters.update` | manage |
| POST | `/pricing/exchange-rates` | `pricing.exchange-rates.store` | manage |
| GET | `/pricing/export` | `pricing.export` | view |
| GET / POST | `/pricing/imports` | `pricing.imports.index` / `.store` | view / manage |
| GET | `/pricing/imports/{import}` | `pricing.imports.show` | view |
| POST | `/pricing/imports/{import}/apply`, `/discard` | `pricing.imports.apply/discard` | manage (+ same user rule) |
| GET | `/quotations` | `quotations.index` | QuotationPolicy viewAny |
| GET / POST | `/quotations/create`, `/quotations` | `quotations.create` / `.store` | create |
| POST | `/quotations/calculate`, `/quotations/selection-options` (JSON) | `quotations.calculate` / `.selection-options` | calculate (+ negotiate when negotiated fields are sent) |
| GET | `/quotations/recipients` (JSON, active customers) — declared before `/quotations/{quotation}` | `quotations.recipients` | create |
| GET | `/quotations/{quotation}` | `quotations.show` | view |
| GET / PUT | `/quotations/{quotation}/edit`, `/quotations/{quotation}` | `quotations.edit` / `.update` | update |
| DELETE | `/quotations/{quotation}` | `quotations.destroy` | discard |
| POST | `/quotations/{quotation}/issue`, `/duplicate`, `/resend` | `quotations.issue/duplicate/resend` | issue / duplicate / resend |
| POST | `/quotations/{quotation}/cancel` | `quotations.cancel` | cancel |
| PUT | `/quotations/{quotation}/lines/{line}/negotiated-price`, `/quotations/{quotation}/agreed-advance` | `quotations.negotiated-price.update` / `.agreed-advance.update` | negotiate |
| GET | `/quotations/{quotation}/print` | `quotations.print` | print → view |

Nested `{line}` uses `scopeBindings()`. Console: `pricing:load`, `exchange-rate:fetch`, `quotations:expire`, `pricing:expire-imports`.

### CSV files

Bulk template (COT-026/COT-027; files 2 and 3 of COT-007): Decision 23. Example (fictitious prices):

```
ecolekua;precios-esquema;Uniformes
codigo;grupo_talla;producto;nombre;estado;Detal;7–12;13–49
110;;Camisa corporativa;Camisa corporativa · ALG-OXF Pima · …;Activa;40,00;36,00;32,00
110-1;;Camisa corporativa;…;Activa;40,00;;32,00
184;28–36;Pantalón industrial;…;Activa;25,00;23,00;21,00
184;38–44;Pantalón industrial;…;Activa;28,00;26,00;24,00
```

```
ecolekua;precios-combos
codigo_combo;componente;producto;cantidad;precio_item
K-ORO;1;Pañal antiderrame;2;30,00
```

`esquemas.csv` (COT-007 file 1 only): `producto;esquema`. Error report columns: `Archivo | Fila | Columna | Motivo`.

### Inertia props (summary)

- `quotations/Index`: `filters {status, q, advisor_id, mine, from, to, requires_advisor, prospect}`, `counts`, `quotations` (paginated rows), `options {advisors}`.
- `quotations/Editor`: `quotation` (header and line input, or empty for create), `calculation` (result of opening), `rate`, `can {negotiate, issue, discard}`, `options {advisors}`.
- `quotations/Show`: frozen copy or recalculated draft, deliveries, `no_email` flag, source, `can {...}`.
- `pricing/*`: reference data only (code, name, status), prices as `Money`, missing marks, `can {manage}`.
- `pricing/Settings`: `parameters {advance_percentage, validity_days}`, `rate` (current `{value, date, origin}` or null), `rates` (paginated history, `rates_page`; rows `{date, value: Money(2), origin, origin_label, recorded_by, effective, superseded_at, created_at}`), `can {manage}`.
- `pricing/ProductPrices`: per combination `size_groups [{id, label, size_ids}]` and `ungrouped_size_ids`; the size-group preview answer is JSON (Decision 10), not a prop.
- `quotations/Editor` lines with a negotiated price carry `negotiated {prices, confirmed, basis}` from the stored line, echoed back to `/quotations/calculate`.
- `customers/Show` (+): `quotations` when allowed.

## Testing Strategy

Pest on the MySQL `testing` database with `RefreshDatabase`; `TestCase` seeds `FoundationSeeder`; authorization tests call HTTP endpoints; **Strict TDD** (RED before each implementation). Each slice closes with `./vendor/bin/sail pint --test`, `sail composer types:check`, `sail pnpm check`, `sail pnpm types:check`, `sail pnpm build`, `sail artisan test`. Pages rendered by a slice's tests ship in the same slice (003 rule), so backend slices test redirects, JSON and database state, and page GET tests live in the UI slices.

**Test naming and filtering** (scenario IDs collide with 001–003): the AGENTS.md §10 rule stays — every scenario test name starts with its ID (`it('E-36 emitir congela precios y tasa')`), decision-only tests start with the decision ID (`DEC-COT-44 …`, `DT-03 …`). All 004 tests live in `tests/Unit/Pricing`, `tests/Unit/Quotations`, `tests/Feature/Pricing` and `tests/Feature/Quotations`, and `tests/Pest.php` assigns them the group `cot` (`pest()->group('cot')->in(...)`). A scoped run is `./vendor/bin/sail pest --group=cot --filter='E-36 '` (the trailing space avoids prefix matches). This mirrors 003, which disambiguated by directory; the group makes it one flag. The 002/003 todo replacements keep their own IDs (`E-19`, `E-29`) and cite the 004 IDs in the name.

| Layer | What to test | Approach |
|---|---|---|
| Unit | `Money` (parse with comma/dot, rejects exponent and thousands, HALF_UP), `RateParser` (raw token, >2 decimals, ≤ 0, missing), `SchemeRules` (each E-02 case, reserved headers, duplicate labels), `PieceCounter`, `TierSelector` (every tier limit), `AdvanceCalculator` (modes, E-30), `BsConverter` (E-36 values), `PackAdvisor` (DEC-COT-47 tie), `QuotationStatusResolver`, `SizeGroupLabel`, `PriceCell`, `CsvWriter` (BOM, quoting, formula escape), enums and catalog counts | Plain Pest |
| Integration (Action) | COT-009 scenarios through `CalculateQuotation`; query budget; no writes (E-31: table counts unchanged, `Http::assertNothingSent()`) | `app(CalculateQuotation::class)->handle(...)`; `DB::enableQueryLog()` |
| Integration (HTTP) | every write and authorization scenario; policies; coherence; matrix seeding | `assertSessionHasErrors`, `assertJsonValidationErrors`, `assertForbidden`, DB and audit assertions |
| Integration (DB) | unique number, unique effective rate, CHECKs, restrict FKs from quotation tables, cascade of price rows | raw inserts expecting `UniqueConstraintViolationException` / `QueryException` |
| Integration (Console/Schedule) | `exchange-rate:fetch`, `quotations:expire`, `pricing:expire-imports`, `pricing:load`; schedule timezone and times | `$this->artisan(...)`; `Http::fake`; `$this->travelTo()`; `app(Schedule::class)->events()` |
| Integration (Queue/Mail/Events) | email after commit, per-recipient result, failure does not revert issue, resend; events | `Queue::fake`, `Mail::fake`, a job run with a throwing mailer, `Event::fake` |
| Architecture | no `abort(403)`; routes protected; selection engine still has no price/stock dependency (incl. after `PackAdmission`, which lives outside it); no `float` type, `(float)`, `floatval(`, `->toFloat(` or `'float'`/`'double'` cast in `app/Support/Pricing`, `app/Support/Quotations`, `app/Actions/Pricing`, `app/Actions/Quotations`, `app/Casts/AsBigDecimal.php`, and no `float`/`double` column in the 004 migrations | existing tests + `tests/Feature/Architecture/MoneyHasNoFloatTest.php` (token scan, same technique as the selection test) |
| Architecture (JSON money) | no JSON number with a fraction anywhere in 004 JSON columns or 004 audit payloads | `tests/Feature/Architecture/MoneyJsonHasNoFloatTest.php`: runs a representative flow (prices, size-group change with deletion, combo price, manual rate, negotiated price, agreed advance, issue, discard, import apply), then decodes every 004 JSON column (`quotations.advance_breakdown`, `pieces_by_line`, snapshots, `price_imports.summary`, `price_import_changes.warnings`) and every `audit_logs` row whose action is a 004 case, and walks the arrays recursively asserting `! is_float($value)`; scenario tests additionally assert known money paths with the custom expectation `expect($v)->toBeMoneyString(2)` (`is_string` + `/^\d+\.\d{2}$/`, scale 4 for rates) declared in `tests/Pest.php` |
| E2E | — | Not available; design-system §11 checklist at 375/768/1280 px incl. print view; Mailpit manual check |

Scenario → test file map:

| Scenario / decision | Test file |
|---|---|
| E-47, DEC-COT-25 (fresh and existing DB, revocation not re-granted) | `tests/Feature/Pricing/PricingPermissionsTest.php` |
| E-01, E-02, E-03, DEC-COT-51, P-8 labels, seeder | `tests/Feature/Pricing/PriceSchemeTest.php` |
| E-04, DEC-COT-23 (scheme change) | `tests/Feature/Pricing/SchemeAssignmentTest.php` |
| E-05, E-06, E-07, E-26 (list mark) | `tests/Feature/Pricing/CombinationPriceTest.php` |
| E-08, E-70, DEC-COT-48 | `tests/Feature/Pricing/SizeGroupTest.php` (+ `tests/Unit/Pricing/SizeGroupPlanTest.php`) |
| DEC-COT-44 (with ids, without ids via `CreateCombo`/fallback, same product twice), combo price edit | `tests/Feature/Pricing/ComboItemPriceTest.php` |
| E-11 | `tests/Feature/Pricing/CommercialParametersTest.php` |
| E-63, E-64, supersede write order and API race, rate history query | `tests/Feature/Pricing/ExchangeRateTest.php` |
| E-71 (rule), DEC-COT-47 | `tests/Unit/Quotations/PackAdvisorTest.php` |
| E-09, E-10, E-14..E-18, E-23..E-31, E-49, E-65 (calc), E-69, E-71 (end to end), pack admission by order-attribute restriction, DT-02 query budget | `tests/Feature/Quotations/CalculateQuotationTest.php` |
| E-19..E-22, E-62, E-72 | `tests/Feature/Quotations/NegotiationTest.php` (+ `tests/Unit/Quotations/NegotiationBasisTest.php`) |
| E-32..E-35, E-68 (create, search), E-73 | `tests/Feature/Quotations/QuotationDraftTest.php` |
| Route order (static before `{quotation}`) | `tests/Feature/Quotations/QuotationRoutesTest.php` |
| E-36, E-37, E-33 (issue), E-65 (issue), DT-03 concurrency | `tests/Feature/Quotations/IssueQuotationTest.php` |
| E-38, E-41..E-44 | `tests/Feature/Quotations/QuotationLifecycleTest.php` |
| E-39, E-40, E-48 | `tests/Feature/Quotations/QuotationIntegrityTest.php` (+ the two 002/003 files) |
| E-45, E-46, E-68 (list) | `tests/Feature/Quotations/QuotationListTest.php` |
| E-50, E-66, E-67, E-68 (email) | `tests/Feature/Quotations/QuotationDocumentTest.php` |
| E-51, round trip | `tests/Feature/Pricing/PriceExportTest.php` |
| E-52, E-54..E-57, E-59 | `tests/Feature/Pricing/PriceImportValidationTest.php` |
| E-53, E-58, E-60, E-61, atomic apply, expiry | `tests/Feature/Pricing/PriceImportApplyTest.php` |
| E-12, E-13 | `tests/Feature/Pricing/InitialPriceLoadTest.php` |

Non-obvious test designs:
- **E-31**: count rows of every 004 table and `audit_logs` before/after; `Http::preventStrayRequests()` + `Http::assertNothingSent()`.
- **DT-02 budget**: same query count for (1 line × 1 row) and (5 lines × 6 rows) of one product; a second product adds a constant.
- **E-36**: issue with `travelTo('2026-10-12 10:00', Caracas)`; then change the price and add a rate; the show page and the frozen columns still hold 36,00 / 360,00 / 180,00 / 875,65 / 315.234,00 / 157.617,00; `expires_on = 2026-10-19`.
- **DT-03**: two `IssueQuotation` calls on the same draft — the second (after the first commits) gets `BusinessRuleViolation`; number unchanged; one `quotations.issued` row.
- **E-41**: issued, `expires_on` today; at 23:59 local the list shows "Emitida"; at 00:00:01 next day the list shows "Vencida" before the command runs; after `quotations:expire` the row is `expired` and the audit has console origin and `automatic`.
- **E-58**: validate, then change the price through the table endpoint, then apply → error, import `discarded` (`conflict`) with its audit, price still 41,00.
- **Atomic apply**: a model event listener registered in the test throws on the second price write → no price changed, import still `validated`.
- **Round trip**: export a scheme, import the file unchanged → "sin cambios", no import row.
- **E-66 failure**: run `SendQuotationEmail` with a mailer that throws (`Mail::shouldReceive('to')->andThrow(...)`) and call `failed()` → delivery `failed`, quotation still `issued`, `QuotationEmailFailed` dispatched.
- **E-63 schedule**: the `exchange-rate:fetch` event has expression `0 7 * * *` and timezone `America/Caracas`.
- **E-70**: `184` with ungrouped prices → preview lists every price (group "Sin grupo", tier, value); `PUT` without token → 422 and prices/groups unchanged, no audit; `PUT` with the token → groups saved, prices deleted, one `size_groups_updated` audit with `deleted_prices` strings, `MissingPrices` flags the combination; a price edited between preview and `PUT` → 422 "los precios cambiaron" and nothing deleted. Repeated for removing the groups and for merging 28–36 with 38–44; assigning a newly admitted ungrouped size to an existing group keeps that group's prices (N-9).
- **E-71**: a fictitious pack whose single component admits a grouped combination with two group prices above the pack item price → no `pack_notice`, line totals identical to a run with the pack inactive. Admission: a pack whose component restricts an order attribute (color) to another value gives no notice; the same pack without that restriction does (single-price line).
- **E-72**: the E-19 quotation; change the shirts fabric (another combination) keeping ≥ 50 pieces → shirts negotiated price discarded (`combination_changed`), chemises kept; add a row in another size group → `size_groups_changed`; change only quantities or a size within the same group → kept. Agreed advance discarded only when it exceeds the new negotiated subtotal (COT-010).
- **E-73**: prospect draft with the creator as advisor; change recipient to a customer with another advisor → `advisor_id` unchanged, one `recipient_changed` audit, no `advisor_changed`; a later explicit `advisor_id` of an eligible user → `advisor_changed`; an ineligible user → error on `advisor_id`.
- **Rate supersede**: an API row exists for today; manual entry → API row `superseded_at` set and `superseded_by_id` = manual id, manual row effective; a raw second insert with `superseded_at = null` for the same date raises `UniqueConstraintViolationException`.

## Threat Matrix

The change adds protected HTTP routes (covered by the 001 route-enumeration test), a CSV upload parsed as data, one outbound HTTPS call from a scheduled command to a fixed configured URL, outbound email through the configured mailer, and console commands that read operator-chosen local files. It has no shell, subprocess, VCS/PR automation, executable-file classification or process-integration boundary.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: uploaded CSVs and load files are parsed as data, never classified or executed | — | — |
| Git repository selection | N/A: no VCS automation | — | — |
| Commit state | N/A: no VCS automation | — | — |
| Push state | N/A: no VCS automation | — | — |
| PR commands | N/A: no PR automation | — | — |

Data-boundary safety is covered as product behavior instead: CSV size/row/encoding limits and formula escaping (Decision 23: RED tests for oversize, non-UTF-8, wrong scope row, `=cmd` reference cells escaped on export); the rate URL comes only from configuration and the response is never trusted as `float` (Decision 3: RED tests for malformed JSON, non-numeric and negative `promedio`); email bodies render frozen data escaped by Blade (no `{!! !!}` on user text: RED test with `<script>` in a line note and a prospect name); delivery errors are truncated and never contain credentials.

## Migration / Rollout

1. **Migrations** (new, additive, each with `down()` in reverse order): the five files in File Changes. Locally `./vendor/bin/sail artisan migrate`; rollback `./vendor/bin/sail artisan migrate:rollback --step=N`. No 001–003 migration is edited; `products.price_scheme_id` is added and dropped by the 004 migration.
2. **Composer**: `brick/math` declared directly (no version change in `composer.lock`).
3. **Seeding** (same deploy step as 001–003): `php artisan db:seed --class=FoundationSeeder --force` creates the 6 permissions and grants the §4 matrix once; `php artisan db:seed --class=PriceSchemeSeeder --force` creates the four schemes (idempotent). Commercial parameters and the number sequence come from their migrations.
4. **Processes**: development `sail up -d` starts `queue` and `scheduler`; production needs a `queue:work` supervisor and the `schedule:run` cron (Decision 29). Mail and company variables set in the environment.
5. **Go-live price load (once)**: after the catalog load (PRD-018), after Ecolekua validates Anexo A and corrects Anexo B: define the size groups of `184` and `184-2` in the ERP; place `esquemas.csv`, one template per scheme and the combo file in `storage/app/private/imports/`; run `php artisan pricing:load --schemes=… --prices=… --prices=… --combo-prices=… --author=<email>`; fix and re-run until clean; delete the files. Record the first manual or fetched rate.
6. **Order with 003**: 004 is based on `feat/003-products`; it is rebased onto `main` after 003 merges. Slices that touch 003 code (6, 15, D1 in 10) keep every 003 test green.
7. **No feature flags.**

### PR slice outline (for `sdd-tasks`; delivery strategy `ask-on-risk`, ~400 authored lines per slice)

Estimated total **~10,400–11,900** authored lines (+~400 over the first pass for DEC-COT-53..56, pack admission, rate history and the JSON-money test). Each slice is green on its own and runs the full close gate. The chain strategy (`stacked-to-main` or `feature-branch-chain`) is the user's decision before apply.

| # | Slice | Scenarios / decisions | Depends on | Gated by | Est. lines |
|---|---|---|---|---|---|
| 1 | Permissions, 26 audit cases, matrix, coherence, `cot` test group, 001–003 count tests | E-47, DEC-COT-25 | — | — | 350 |
| 2 | `brick/math`, `Money`, `AsBigDecimal`, enums, pricing migration, models, factories, `PricingPolicy`, money arch test, `toBeMoneyString()` expectation | DT-01 | 1 | — | 470 |
| 3 | Schemes and tiers, seeder | E-01..E-03, DEC-COT-51 | 2 | — | 450 |
| 4 | Scheme assignment (one and bulk) | E-04 | 3 | — | 300 |
| 5 | Combination prices, size groups with `SizeGroupPlan`, preview endpoint and confirm token, DEC-COT-48 cleanup in 003 Actions, missing-price query | E-05..E-08, E-26 (mark), E-70 | 4 | — | 600 (may split at tasks: 5a prices, 5b groups + E-70) |
| 6 | Combo item prices, `ComboComponents::replace` identity with id-less fallback | DEC-COT-44 | 2 | — | 380 |
| 7 | Commercial parameters | E-11 | 2 | — | 200 |
| 8 | Exchange rate: migration, fetch command, schedule, manual entry (ordered supersede + race rerun), current rate, history query, `scheduler` service | E-63, E-64 (fetch, manual, 403) | 2 | — | 480 |
| 9 | Pure calculation classes incl. `NegotiationBasis` and the single-unit-price rule of `PackAdvisor` | COT-008, COT-010, COT-011 unit tests, DEC-COT-47, E-71 (unit) | 2 | — | 470 |
| 10 | `CalculateQuotation`, memoized loader, `PackCatalog` + `PackAdmission`, calculate/options endpoints | E-09, E-10, E-14..E-18, E-23..E-31, E-49, E-64 (calc), E-65 (calc), E-69, E-71 (end to end) | 5, 6, 7, 8, 9 | — | 550 |
| 11 | Quotation schema (incl. negotiation basis columns), sequence, create/update draft (recipient change keeps advisor, manual advisor change), recipients endpoint, route order test | E-32..E-35, E-68 (create), E-73 | 10 | — | 530 |
| 12 | Negotiation and agreed advance, basis stored and compared | E-19..E-22, E-62, E-72 | 11 | — | 450 |
| 13 | Issue/freeze, deliveries (pending), events | E-33 (issue), E-36, E-37, E-65 (issue), DT-03 | 12 | — | 450 |
| 14 | Expire (command + read guard), duplicate, cancel, discard | E-38, E-41..E-44 | 13 | — | 450 |
| 15 | History guards, 002/003 todos | E-39, E-40, E-48 | 11 | — | 300 |
| 16 | List, search, detail, customer history (backend + pages) | E-45, E-46, E-68 (list) | 14 | — | 450 |
| 17 | Print view, email job, company config, `queue` service, resend | E-50, E-66, E-67, E-68 (email) | 13 | — | 500 |
| 18 | `CsvReader::readDocument`, `CsvWriter`, export | E-51, round trip | 5, 6 | — | 400 |
| 19 | Import validate and preview | E-52, E-54..E-57, E-59 | 18 | — | 450 |
| 20 | Apply, discard, expiry, history | E-53, E-58, E-60, E-61, atomic apply | 19 | — | 450 |
| 21 | Initial load command | E-12, E-13 | 20 | — | 450 |
| 22 | UI pricing 1: schemes, parameters, rate card and rate history, "Precios" nav | page GET tests (incl. `rates` prop) | 3, 7, 8 | — | 500 |
| 23 | UI pricing 2: product list with row selection, price table, size groups with preview `ConfirmDialog`, combo prices | page GET tests | 5, 6, 22 | — | 530 |
| 24 | UI quotation editor 1: header, recipient, lines, selection steps | page GET tests | 11 | — | 450 |
| 25 | UI quotation editor 2: size table, customizations, combos, summary, packs | — | 24 | — | 450 |
| 26 | UI quotation editor 3: negotiation, issue/discard, list and detail pages | — | 12, 16, 25 | — | 450 |
| 27 | UI imports: export dialog, upload, errors, preview, history | page GET tests | 20, 23 | — | 400 |
| 28 | Close-out: audit coverage, `MoneyJsonHasNoFloatTest` over the full flow, docs, a11y 375/768/1280 px, DoD | §12 | all | — | 250 |

No slice is gated by a business decision any more. Placement note: E-73 lands in slice 11 (it is `UpdateQuotationDraft` behavior) and E-72 in slice 12 (it needs `SetNegotiatedPrice`), because negotiation is built after drafts.

Decision needed before apply: Yes (chain strategy only)
Chained PRs recommended: Yes
400-line budget risk: High

## Open Questions

### Business decisions

**None pending.** The four gaps raised by the first design pass were confirmed by the user on 2026-10-13 and are recorded in the source spec; this design implements them:

| Decision | Scenario | Designed in |
|---|---|---|
| DEC-COT-53 — group transitions with prices: confirmation listing group, tier and value; deletion with audit; "sin precio" until reloaded | E-70 | Decision 10 (`SizeGroupPlan`, preview + confirm token) |
| DEC-COT-54 — a line with more than one unit price gets no pack notice | E-71 | Decision 15 (pack notice step 1) |
| DEC-COT-55 — negotiated price discarded when the resolved combination or the set of size groups changes; quantities and sizes within a group keep it | E-72 | Decision 17 (negotiation basis) |
| DEC-COT-56 — changing the recipient keeps the advisor; manual change only | E-73 | Decision 19 (recipient change) |

### Non-blocking notes (N-1, N-9 and N-10 confirmed by the user as DEC-COT-57..59; the rest are technical notes)

- **N-1 (confirmed by the user 2026-10-13 as DEC-COT-57)** Changing a tier from "con precio" to "negociación" when it has prices is treated like removing that tier: confirmation + deletion with audit, the DEC-COT-23 criterion, because a negotiation tier has no price (COT-001).
- **N-2** Rows with a selection error do not count toward the tier while drafting; an issuable quotation has no such rows, so issued results are unaffected.
- **N-3** Superseded: now DEC-COT-56 (Decision 19), no longer an interpretation.
- **N-4** A combination resolved inside a combo line component counts as "used in a quotation line" for COT-024 (it is part of the line's frozen selection).
- **N-5** Draft totals and the "requiere asesora" flag in lists come from the last save; opening the draft recalculates (COT-013).
- **N-6** Exploration **C6** (documentation drift): `docs/specs/003-products.md` §10 still mentions schemes "por producto o categoría"; DEC-COT-22 confirms per product. Worth aligning in 003 with the user's authorization; no design impact. COT-004 generalizes DEC-PRD-06 (any combination with the size order attribute may use groups); harmless.
- **N-7** Exploration **C9**: commit `docs/specs/004-pricing-and-quotations.md` and the modified `docs/business/business-rules.md` before slice 1 (with the user's authorization).
- **N-8** The email logo is embedded inline (CID). Some clients list inline images as attachments; if Ecolekua sees that as an attachment (COT-031 "sin adjuntos"), the fallback is a text header without logo — a template change only.
- **N-9 (confirmed by the user 2026-10-13 as DEC-COT-58)** Only assigning a size that is in **no** group (a size newly admitted, COT-004 "sin precio hasta asignarla a un grupo") to an existing group is not a "reorganization" under DEC-COT-53: the group keeps its id and prices and the size takes that group's price. **Moving a size from one group to another IS a reorganization**: DEC-COT-53 applies — confirmation, and the prices of every affected group (the group the size leaves and the group it joins) are deleted with audit. Merge and split are also reorganizations. `SizeGroupPlan::diff` keeps a stored group only when its new size set equals its stored set, or equals it plus sizes that were in no group.
- **N-10 (confirmed by the user 2026-10-13 as DEC-COT-59)** For DEC-COT-54, "más de un precio unitario" counts **distinct** unit-price values among the line's rows: two groups with the same price are one unit price and the pack check proceeds. With the initial configuration it cannot occur (DEC-COT-54 note).

### Navigation consequence (confirmed by the user 2026-10-13: Cotizaciones in the bar, Combos to "Más")

Decision 28 adds "Cotizaciones" with priority 5; by the existing 4-item bottom-bar cap the advisor's bar becomes Inicio, Cotizaciones, Clientes, Productos and **Combos moves to "Más"**. Priority 27 would keep Combos in the bar instead. No other navigation change.

### Technical follow-ups (apply phase)

- [ ] Confirm `brick/math` 1.0.0 API names used here (`BigDecimal::of`, `toScale`, `RoundingMode::HALF_UP`) against Context7 before slice 2.
- [ ] Confirm the 003 `SelectionRules::validate($product, $choice, $palette, $restriction)` signature and the exact row-choice shape (customizations included) reused by `PackAdmission` before slice 10.
- [ ] Confirm Inertia 3 `useHttp` API (request cancellation for the debounced calculation) against Context7 before slice 24.
- [ ] Confirm `pest()->group()->in()` for directory groups in Pest 4 (slice 1); fallback: `->group('cot')` per file.
- [ ] Confirm the Sail runtime honors `SUPERVISOR_PHP_COMMAND` per service in `compose.yaml` (slice 8).
- [ ] Production hosting: queue supervisor, cron and SMTP provider (operations, before go-live).
