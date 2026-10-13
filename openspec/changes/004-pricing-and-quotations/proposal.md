# Proposal: 004-pricing-and-quotations — Price Schemes, Prices, Exchange Rate, Quotation Calculation, ERP Quotations, Printable View, Email and Bulk CSV Price Updates

> Source of truth: `docs/specs/004-pricing-and-quotations.md` (status "Lista para Design"; COT-001..COT-031, E-01..E-69, DEC-COT-01..DEC-COT-45 all `Confirmada`; DT-01..DT-06 technical, `Propuesta`, confirmed in design). DEC-COT-44 (combo item prices kept for components whose product is unchanged) and DEC-COT-45 (number stored as integer, shown `COT-000123`, gaps accepted) were added 2026-10-13. DT-01 records the approval to declare `brick/math` as a direct Composer dependency.
> Exploration: `openspec/changes/004-pricing-and-quotations/exploration.md`. Of its candidates, P-2 and P-4 are answered by DEC-COT-44 and DEC-COT-45; P-3 and P-5 are already defined by the spec (see Open issues); P-1 and P-6..P-14 remain open.
> Scope rule (`openspec/config.yaml` → `rules.proposal`): exactly what spec 004 defines; nothing extra; no tenant/multi-company abstractions.
> Depends on: `001-foundation` (archived), `002-customers` (archived), `003-products` (archived on `feat/003-products`, not yet merged to `main`).

## Intent

Ecolekua quotes by hand from the price spreadsheet ("Uniformes" and "Pañales para adultos"): the advisor picks the price column by quantity, adds up, computes the advance and prints. The rules that decide the correct price (tier by total pieces per business line, fixed-price items, combo item prices, size-group prices of `184`/`184-2`, customizations without charge, services sold alone) live only in people's heads. `003` delivered the catalog without prices, so the ERP cannot answer "how much does this cost", and `005`, `006`, `007`, `014`, `015` and `016` cannot start.

This change makes the backend the **single authority** that computes tier, unit price, subtotals, total, required advance and its bolívar equivalent from configurable final prices (COT-009, a side-effect-free operation reused later by the portal in `005`), and lets advisors create, issue, email, print and consult multi-line quotations for registered customers or prospects, ready to be converted into orders in `006`. Prices are loaded once by console command and maintained afterwards in the ERP, including a bulk CSV export/validate/apply cycle. The official exchange rate is fetched daily from `ve.dolarapi.com` with a manual fallback.

Success means: every scenario E-01..E-73 (E-70..E-73 added by DEC-COT-53..DEC-COT-56 on 2026-10-13) has a Pest test whose name starts with its ID and passes on MySQL; the two 002/003 `->todo()` scenarios become real tests (COT E-39, E-40); the §4 permission matrix applies to fresh and existing databases; no amount uses `float` anywhere; and the spec DoD (§12) is satisfied.

## Scope

### In Scope

Exactly spec §3.1, grouped by requirement:

| Area | Requirements | Key decisions |
|---|---|---|
| Price schemes: tiered (contiguous from 1, only last open-ended, negotiation only last) or fixed price; case-insensitive unique name; deactivation blocked while assigned to an active product; tier removal with prices requires confirmation and deletes them with audit; initial four schemes | COT-001 | DEC-COT-05, -23, -26 |
| Scheme per product, from the product page or in bulk from the list; scheme change with prices requires confirmation and deletes them; combos have no scheme | COT-002 | DEC-COT-22, -23 |
| Final USD price per combination and tier (or single fixed price), > 0, max 2 decimals, identified by code; per-product table | COT-003 | DEC-COT-02, -03, -04 |
| Size groups: partition of the combination's admitted sizes, own price per tier; new size without group stays without price | COT-004 | DEC-PRD-06 |
| Combo item prices: one price per item; combo price = Σ item × quantity, not stored; prices kept on component edit when the product is unchanged | COT-005 | DEC-COT-06, -44 |
| Commercial parameters: advance percentage (initial 50 %), validity days (initial 7), audited | COT-006 | DEC-COT-15 |
| One-time console price load from three UTF-8 CSV files (schemes per product; prices by code/tier/size group; combo item prices), all-or-nothing, required active author, audited as import, files 2 and 3 share the bulk-update format | COT-007 | DEC-COT-02, -03, -04, DT-04 |
| Daily exchange rate: scheduled fetch 07:00 `America/Caracas`, `promedio` stored as that day's rate, failure logged, last saved rate as fallback, manual entry with `pricing.manage`, append-only history where a correction supersedes | COT-030 | DEC-COT-01, -38, -43, DT-05 |
| Piece counting per business line and per service sold alone; fixed-price, customizations and combos do not count | COT-008 | DEC-COT-07, -13, -14 |
| Side-effect-free calculation: per-size-row PRD-011 validation, tier, unit price per size group, combos, subtotals, total, advance, Bs equivalent, pack notice, "requires advisor" indicators, completeness | COT-009 | DEC-COT-08, -39, DT-02 |
| Required advance by supply mode; agreed advance for negotiated lines (USD amount between 0 and the negotiated subtotal) | COT-010 | DEC-COT-10, -11, -40 |
| Added customizations with quantity per garment, without charge | COT-011 | DEC-COT-13 |
| Lines requiring an advisor (negotiation tier, custom color); negotiated or confirmed price with `quotations.negotiate`; discarded when the line stops requiring it | COT-012 | DEC-COT-12, -27 |
| Register a quotation (draft) for an active customer or a prospect; responsible advisor; origin "asesora"; number assigned on create, integer shown `COT-000123`, gaps accepted; recalculated while draft | COT-013 | DEC-COT-19, -21, -45 |
| Lines and per-size quantities; line-level selection; combo lines; free note | COT-014 | DEC-COT-09 |
| States borrador → emitida → vencida / anulada / aprobada (aprobada is set by `006`); badges | COT-015 | DEC-COT-17, -18 |
| Issue: recalculate, reject when incomplete or customer inactive, freeze copy (lines, totals, advance, rate, Bs), dates, then queue the email after commit | COT-016 | DEC-COT-16, DT-03 |
| Automatic expiry at end of the expiry day in the operating timezone; never shown as issued once expired | COT-017 | — |
| Duplicate in any state to a recalculated draft, without negotiated prices or agreed advance | COT-018 | — |
| Cancel an issued quotation with a mandatory reason; discard a draft with audit copy | COT-019 | DEC-COT-24 |
| List with state tabs and backend counts, search, filters ("Mis cotizaciones", dates, requires advisor, prospect), detail, customer history section | COT-020 | DEC-COT-28 |
| Printable view (browser print/PDF) for non-draft quotations | COT-021 | DEC-COT-20 |
| Automatic HTML email on issue to customer and contact (or prospect), delivery log, manual resend | COT-031 | DEC-COT-41, -42, DT-06 |
| Backend authorization with Policies; COT-009 from the ERP requires `quotations.create` | COT-022 | — |
| Audit of the events listed in COT-023 (drafts: key events only) | COT-023 | DEC-COT-29 |
| Integrity with customers and products: quotations count as history (CLI-010, PRD-012, PRD-014), restrict-on-delete FKs, discarded drafts stop counting | COT-024 | DEC-COT-24 |
| Lines without price: "sin precio" error, issue blocked, missing prices flagged in the price list | COT-025 | — |
| Bulk CSV: export template per scheme or combo items; validate and preview without applying; apply or discard atomically with conflict check; validated imports expire; import history | COT-026..COT-029 | DEC-COT-30..-37 |
| Permissions `quotations.*` (4) and `pricing.*` (2), §4 initial matrix, coherence rule | §4 | DEC-COT-25 |
| Internal ERP screens of spec §8 | §8 | — |

### Out of Scope

Exactly spec §3.2, plus items the spec assigns elsewhere:

- Public portal quoter, WhatsApp button, commercial contact number, layered preview, visitor quotations and origin "web" (`005`; `005` reuses COT-009 and defines its public access).
- Conversion to order and the "aprobada" transition, customer registration from a prospect, advance as a transaction, USD/Bs payments, final sale origin (`006`).
- VAT breakdown and invoicing (`006` / future integration).
- Stock levels, minimum-stock rule and stock-based routing (`008` / `005`); COT-009 does not read stock.
- Customer design or logo files (`006`).
- Costs and margins; manual discounts (DEC-COT-27); server-side PDF (DEC-COT-20); on-screen percentage price adjustment (DEC-COT-37); storing the imported file (DEC-COT-36).
- Advisor commissions (`014`); internal and WhatsApp notifications (`015`); dashboard indicators (`016`).
- Optional layered preview in the editor (spec §8, not a requirement).

**Commitments recorded for later specs (spec §10; not implemented here):** `005` uses COT-009 for every portal calculation; `006` converts only issued, valid quotations, copies frozen lines/advance/rate and adds "customer/combination with orders" to CLI-010/PRD-014; `006`/`014` carry origin and responsible advisor; `007` shows issued quotations with the printable content; `008` adds "combination with stock movements" to PRD-014; `015` subscribes to Laravel events on issue, cancel, expiry, requires-advisor, email failure and rate-fetch failure (design decides whether 004 dispatches these events now or leaves hooks only, within spec scope); `016` quotation indicators.

## Capabilities

Existing capabilities in `openspec/specs/`: `authentication`, `user-management`, `password-management`, `roles-permissions`, `authorization`, `administrative-protections`, `audit`, `customers`, `products`.

### New Capabilities
- `pricing-and-quotations`: the full domain of spec 004 — COT-001..COT-031 and E-01..E-69, including the §4 permission table, role matrix and coherence rule, the initial load command (COT-007), and decision notes DEC-COT-01..DEC-COT-45 and DT-01..DT-06 where they refine a requirement. A single capability keeps the transcription 1:1 with the source spec and its `COT` prefix, as `customers` (002) and `products` (003) do.

**ID ordering (kept verbatim, AGENTS §4.3; do not renumber):** requirements are not in numeric order in the source — COT-030 follows COT-007 and COT-031 follows COT-021. There are no numbering gaps in COT-001..COT-031 or E-01..E-69. Scenario IDs E-xx collide with 001–003; the spec phase keeps them verbatim and design decides the test-name/file convention that keeps `--filter` usable (exploration §7).

### Modified Capabilities
- None at requirement level. Rationale (same as 002 and 003):
  - `customers` (CLI-010, E-19 `todo`) and `products` (PRD-012, PRD-014, E-29 deferred ⏳) explicitly delegate their history condition to the spec that creates history ("each spec that creates history adds this condition"). Spec 004 states it in COT-024 with E-39 and E-40; it is transcribed in `pricing-and-quotations`. Whether archive annotates the ⏳/`todo` markers in the consolidated `products` and `customers` specs is an archive-time decision.
  - DEC-COT-44 changes how combo components are persisted on edit (identity preserved), not the PRD-010 requirements.
  - `roles-permissions` / `audit`: FND-017 and FND-022 allow later specs to add permissions, coherence paths and audited events; §4 and COT-023 are transcribed in the new capability.
  - Hard-coded permission and audit-action counts in 001–003 tests are an implementation change handled in design and tasks.

## Approach

High level only; `design.md` is the plan (AGENTS §4.2).

1. **Mirror the 001–003 pattern**: protected routes, thin controllers → FormRequest → Policies (a permission-only `PricingPolicy` for non-model abilities, plus a quotation policy) → Actions in `app/Actions/Pricing/` and `app/Actions/Quotations/` owning `DB::transaction` and `RecordAuditEvent`.
2. **Money is exact** (DT-01): `DECIMAL` columns, `brick/math` declared as a direct dependency (approved), HALF_UP rounding at the points the spec defines (prices on load, advance once on the total, Bs equivalents); the API float `promedio` is converted through a string, never kept as `float`. The frontend only displays backend strings.
3. **Configuration, not a rules engine** (constitution §11): schemes and tiers are rows; quantity is the only pricing condition. Price rows keyed by combination, tier and optional size group (exploration D9).
4. **One calculation engine** (COT-009, DT-02): pure DB-free rules for counting, tier selection, advance and pack notice, wrapped by `CalculateQuotation`, which resolves each size row through 003's `ResolveSelection` without modifying `app/Support/Products/Selection/*` (the 003 architecture test forbids price/stock strings there). The N+1 risk is handled in design (exploration D1 recommends a per-calculation memoizing loader, with a query-count test).
5. **Issue freezes a copy** inside one locked transaction (DT-03); the number is assigned on create per DEC-COT-45 (design aligns DT-03 wording). Email is a queued Mailable dispatched after commit (DT-06); a mail failure never rolls back the issue.
6. **Expiry** via a daily scheduled command with system actor and console audit origin, plus a read-side effective-status guard so lists and detail never show an expired quotation as issued (exploration D5).
7. **Exchange rate** (COT-030, DT-05): first outbound HTTP call and first `Schedule` entry; append-only history where a same-day manual correction supersedes the previous record; per-day uniqueness of the effective rate guaranteed in the database (exploration D4). Never called from calculation or issue.
8. **CSV** (DT-04): one `ValidatePriceImport` Action serves the console load and the ERP import; extend the existing `CsvReader` (preamble row, dynamic tier columns, decimal comma) keeping 002/003 import tests green, and add a small writer (`;`, decimal comma, UTF-8 BOM). Apply re-checks stored previous values under lock.
9. **Combo price identity** (DEC-COT-44): `ComboComponents::replace` changes from delete-and-recreate to preserving components whose product is unchanged, so their prices survive quantity/order edits.
10. **Integrity hooks**: fill the empty history guards left by 002/003 and convert their `->todo()` markers into COT E-39/E-40 tests.
11. **Printable view and email share one Blade content partial** (DT-06), rendered by an authenticated print route (outside Inertia, authorized by Policy) and by the Mailable; email-safe markup (table layout, inline CSS).
12. **Frontend** after backend behavior is green (Strict TDD; authorization tests hit HTTP): mobile-first quotation editor, price tables with "sin precio" marks, bulk row selection (new `DataTable` capability), scheme editor, parameters and rate, import preview and history; spec §8 and `docs/ui/design-system.md` apply.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | New | schemes, tiers, `products.price_scheme_id`, combination prices, size groups, combo item prices, commercial parameters, exchange rates, price imports and changes, quotations, lines, size rows, customizations, combo component selections, email deliveries |
| `app/Models/`, `app/Enums/` | New / Modified | pricing and quotation models; scheme type, tier type, quotation status/origin, price origin, rate origin, delivery result; `PermissionName` (+6), `AuditAction` (new cases) |
| `app/Actions/Pricing/`, `app/Actions/Quotations/`, `app/Support/Pricing/`, `app/Support/Quotations/` | New | configuration, rate, import, calculation, lifecycle, expiry, duplicate, email |
| `app/Policies/`, `app/Providers/AppServiceProvider.php` | New / Modified | pricing and quotation policies and their registration |
| `database/seeders/InitialRolePermissions.php`, `app/Actions/Roles/SyncRolePermissions.php` | Modified | §4 matrix and coherence check |
| `database/seeders/` | New | four initial schemes, initial parameters; fictitious prices/rates only |
| `app/Support/Products/ComboComponents.php` | Modified | preserve component identity when the product is unchanged (DEC-COT-44) |
| `app/Support/Products/` loader | Possibly modified | memoization for calculation (design decides, D1) |
| `app/Actions/Customers/DeleteCustomer.php`, `app/Actions/Products/{UpdateCombination,DeleteCombination,DeleteCombo,DeleteProduct}.php` | Modified | fill empty history hooks (COT-024) |
| `app/Actions/Products/SyncProductAttributes.php`, `UpdateCombination.php` | Possibly modified | size-group consistency on size/restriction changes (pending P-9) |
| `app/Support/Csv/CsvReader.php` | Modified | preamble, dynamic headers, decimal comma; new CSV writer |
| `app/Console/Commands/`, `routes/console.php` | New / Modified | price load, rate fetch, quotation expiry, validated-import expiry; first scheduler entries |
| `app/Mail/`, `resources/views/` | New | queued Mailable, shared printable/email Blade partial, print page |
| `config/services.php`, `.env.example` | Modified | rate API URL/timeouts, mail sender, queue |
| `composer.json` | Modified | `brick/math` as direct `require` (approved, DT-01) |
| `routes/web.php`, `app/Http/Controllers/`, `app/Http/Requests/` | New / Modified | protected pricing, quotation, print, import routes |
| `app/Http/Controllers/CustomerController.php`, `resources/js/pages/customers/Show.vue` | Modified | quotation history section gated by `quotations.view` |
| `resources/js/pages/pricing/`, `resources/js/pages/quotations/`, `resources/js/components/`, `resources/js/navigation.ts` | New / Modified | ERP screens of §8; `DataTable` row selection; navigation entries (4-item bottom bar) |
| `docs/ui/design-system.md` | Possibly modified | row selection and print-view notes |
| `tests/Feature/Pricing/`, `tests/Feature/Quotations/`, `tests/Unit/` | New | E-01..E-69 and COT-009 unit coverage |
| 001–003 tests with hard-coded counts; `DeleteCustomerTest`, `DeleteProductTest` todos | Modified | adapt counts (prefer `::cases()`); todos become real tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Size: ~9,500–11,500 authored lines, far above the 400-line budget | High (certain) | Chained PR slices; chain strategy chosen by the user before apply (see forecast) |
| No job infrastructure yet: queue worker and scheduler absent from `compose.yaml`; production hosting unknown | High | Design documents dev and deployment setup; expiry also guarded on read; rate fallback never blocks issue |
| First external call (rate API) and first outbound mail | Med | Integration only from the scheduled command / after commit; `Http::fake`, `Mail::fake`; mail-failure-does-not-revert test |
| Money precision (`float` leak from API JSON or frontend) | Med | `brick/math` + `DECIMAL`; string conversion of `promedio`; DoD check that no amount uses `float` |
| Calculation performance (PRD-011 per size row × lines, recalculated on every edit) | Med | Memoized loading in design (D1); query-count test |
| Concurrency: number/issue lock, import apply conflict under lock, 003 product-row locks | Med | DT-03/DT-04 locks inside transactions; concurrency tests |
| Touching 003 code (`ComboComponents::replace`, history hooks, selection loader) while 003 is unmerged | Med | Keep all 003 tests green; do not modify `Selection/*`; rebase order documented |
| Email client rendering of the shared partial | Med | Table layout + inline CSS; manual check in Mailpit |
| UI size: mobile-first editor, per-size table, sticky summary, row selection | Med | UI slices after backend; manual 375/768/1280 px pass (no JS tests) |
| Open business items P-1, P-6..P-14 | Low | Resolved on 2026-10-13 (DEC-COT-46..52 and design notes; see Open issues) |
| E-xx ID collisions across specs in test filters | Low | Test-name/file convention decided in design |
| CSV formula injection when exported files are opened in Excel | Low | Design evaluates escaping reference columns on export |
| Hard-coded permission/audit counts in 001–003 tests | High (certain) | Updated in the permissions slice |
| Real Ecolekua prices leaking into git or Engram | Low | Load CSVs not versioned; fixtures and seeders fictitious (AGENTS §4.5) |

## Open issues

These did not block this proposal nor the spec transcription. Details and context: `exploration.md` §6.

**Resolved or already defined by the spec (not open):**
- P-2 → DEC-COT-44 (2026-10-13). P-4 → DEC-COT-45 (2026-10-13); design aligns DT-03 wording with number-on-create.
- P-3 → defined by COT-030: stored rates are never edited or deleted; a correction is a new manual record of the same day that supersedes the previous one as effective, audited.
- P-5 → defined by COT-009 step 9 and COT-010: the required advance (sum of its three parts) is rounded once to 2 decimals.
- `brick/math` direct dependency → approved (DT-01, 2026-10-13).

**Resolved on 2026-10-13, after this proposal was written (recorded in the source spec §9 and the delta spec):**
- P-1 → DEC-COT-46: company data in application configuration (env values), no ERP screen.
- P-6 → DEC-COT-47: a single notice for the pack with the greatest estimated saving (tie → more units). Quantity per line and selection check (order attributes included) were already defined by COT-009 step 11.
- P-7 → already defined: a service is a product (003 PRD-008), so the tier counts per product. Services on the Uniformes scheme are initial-load data validated with Ecolekua (Annex A).
- P-8 → technical (design): tier labels unique within a scheme and distinct from the fixed CSV headers, because COT-027 identifies columns by label.
- P-9 → DEC-COT-48: a removed size leaves its group with audit; an empty group is deleted with its prices and audit (as DEC-PRD-52 B).
- P-10 → DEC-COT-49: recipient and advisor display data frozen on issue; the customer link is kept.
- P-11 → DEC-COT-50: one email per recipient, no repeated addresses, own result each. Interim "pending" delivery state is technical (design).
- P-12 → DEC-COT-51: scheme type set on creation, never changed; use another scheme and COT-002.
- P-13 → technical (design): `promedio` rounded HALF_UP to 4 decimals (DT-01, DEC-COT-02).
- P-14 → DEC-COT-52: `pricing.*` does not require `products.view`; pricing screens show only catalog reference data.

No open business items remain for Design.

**Design notes (non-blocking, from exploration §5):** price rows of a combination without history must cascade on delete while quotation-line FKs restrict (C4); the Customers/Show.vue copy about in-progress items after deactivation must reflect that drafts of an inactive customer cannot be issued (C3); `003` §10 still mentions schemes "por producto o categoría" (C6, documentation drift); the audited draft events are exactly the COT-023 list (C8). Annex A "Por confirmar" rows and Annex B inconsistencies are data validated with Ecolekua before COT-007 runs, not before Design.

**Repo hygiene:** `docs/specs/004-pricing-and-quotations.md` is untracked and `docs/business/business-rules.md` is modified; commit the source documents first (with the user's authorization).

## Rollback Plan

- All schema changes are new migrations with `down()` methods; revert locally with `./vendor/bin/sail artisan migrate:rollback --step=N`. No existing 001–003 migration is edited; `products.price_scheme_id` is added by a new migration and dropped by its `down()`.
- Permission rows and matrix grants touch only `quotations.*` and `pricing.*`; a revert removes them without affecting earlier permissions.
- The `ComboComponents::replace` change and the filled history hooks are isolated in their own slices; reverting them restores 003 behavior (combo item prices would then be lost on edit, so revert the combo-price slice together).
- Scheduled commands, queued mail and the HTTP client are registered in their own slices; removing the schedule entries disables rate fetch and expiry without touching data (expiry stays correct on read).
- Work lives on `feat/004-pricing-and-quotations`; each chained PR slice is revertible independently with `git revert` of its merge commit. Slices are ordered so backend layers (permissions → money/schema → configuration → rate → calculation → quotations → print/email → CSV → load) never depend on unmerged UI.
- The initial price load and each bulk import apply run in a single transaction: a failed run leaves prices unchanged.

## Dependencies

- `001-foundation` (merged): permission catalog, `InitialRolePermissions`, `SyncRolePermissions`, `RecordAuditEvent`/`AuditOrigin`, protected-by-default routing, ERP layout and design-system components.
- `002-customers` (merged): customer, contact person, assigned advisor, `customers.portfolio`, phone/email normalization (CLI-004), CLI-010/CLI-013 hooks, CSV import pattern.
- `003-products` (archived, **not yet merged to `main`**): catalog, combinations, combos, `ResolveSelection` (PRD-011), `ListSelectionOptions` (PRD-019), `CsvReader`, history hooks. 004 must be based on or rebased after 003's merge; the chain strategy accounts for it.
- `brick/math` as a direct Composer requirement (approved, DT-01). No other new Composer or npm packages expected (DT-04, DT-05, DT-06).
- Queue worker and scheduler running in development and production (to be documented in design); Mailpit in development.
- External: `https://ve.dolarapi.com/v1/dolares/oficial` (consulted only by the scheduled command).
- MySQL 8.4 (Sail) with the `testing` database for Pest.
- For COT-007 execution (not for design or implementation): Ecolekua validates Annex A and corrects Annex B; the author user must exist and be active; catalog load (PRD-018) done first.

## Success Criteria

Mapped to spec §12 and AGENTS.md §12:

- [ ] Every scenario E-01..E-73 has at least one Pest test whose name starts with its ID, green on MySQL; 002 E-19 and 003 E-29 `todo`s are replaced by COT E-39/E-40 tests.
- [ ] COT-009 has unit tests for tier limits, counting per business line and per service, size groups, combos, fixed price, advance by mode, pack notices, Bs equivalents and rounding.
- [ ] No amount uses `float` in PHP, TypeScript or the database; the frontend performs no arithmetic on amounts.
- [ ] No test calls the real rate API or sends real email (`Http::fake`, `Mail::fake`); a test proves a mail failure does not revert the issue.
- [ ] Authorization only through Policies; no role-name checks, no `abort(403)`; every route protected by default.
- [ ] Creates, issues, cancellations, discards, imports, rates and price changes run inside Actions in `app/Actions/Pricing/` and `app/Actions/Quotations/`, in a transaction when several entities are affected, and are audited per COT-023.
- [ ] Quotation number uniqueness and per-day effective-rate uniqueness guaranteed in the database; FKs restrict toward customers, combinations and combos from quotation data.
- [ ] Bulk import round-trip test (export then import unchanged → no changes) and atomic-apply test (forced mid-apply failure → no price changed).
- [ ] Seeding creates the 6 permissions and applies the §4 matrix on fresh and existing databases without re-granting revoked permissions; coherence rule enforced (E-47).
- [ ] Seeders and fixtures use fictitious prices and rates; load CSVs are not versioned.
- [ ] `sail artisan test`, `sail pint --test`, `sail composer types:check`, `sail pnpm check`, `sail pnpm types:check` and `sail pnpm build` pass.
- [ ] Design-system checklist (§11) verified at 375, 768 and 1280 px, including the printable view.
- [ ] No tenant/multi-company concepts and no unapproved dependencies in the diff.

## Review Workload Forecast

Estimated authored changed lines: **~9,500–11,500** (additions + deletions, tests included, generated Wayfinder files excluded), similar to 003. Source: exploration §8.

| # | Candidate slice | Covers | Est. lines |
|---|---|---|---|
| 1 | Permissions, matrix, coherence, audit skeleton; adapt count tests | §4; E-47 | ~350 |
| 2 | Money helper, enums, pricing schema, models, factories | DT-01 | ~450 |
| 3 | Schemes and tiers (rules, Actions, seeder) | COT-001; E-01..E-03 | ~450 |
| 4 | Scheme per product + bulk assignment | COT-002; E-04, E-27 | ~300 |
| 5 | Combination prices, size groups, "sin precio" flag | COT-003, -004, -025; E-05..E-08 | ~450 |
| 6 | Combo item prices + `ComboComponents::replace` change | COT-005; DEC-COT-44 | ~350 |
| 7 | Commercial parameters | COT-006; E-11 | ~200 |
| 8 | Exchange rate (fetch, schedule, manual, current) | COT-030; E-63..E-65 | ~450 |
| 9 | Calculation pure rules (tiers, counting, advance, packs) | COT-008, -010, -011 | ~450 |
| 10 | `CalculateQuotation` with the selection engine | COT-009; E-09, E-10, E-14..E-17, E-24..E-31, E-49, E-69 | ~450 |
| 11 | Negotiation and advisor-required | COT-012; E-18..E-23, E-62 | ~350 |
| 12 | Quotation schema + draft CRUD (recipient, prospect, advisor) | COT-013, -014; E-32..E-35, E-68 | ~450 |
| 13 | Issue/freeze (number, validity, rate copy) | COT-016; E-36, E-37 | ~400 |
| 14 | Expire, duplicate, cancel, discard | COT-015, -017..-019; E-38, E-41..E-44 | ~450 |
| 15 | History guards + the two `todo`s → tests | COT-024; E-39, E-40, E-48 | ~300 |
| 16 | List/search/detail + customer history section | COT-020; E-45, E-46 | ~450 |
| 17 | Printable view + email (DEC-COT-46, DEC-COT-50) | COT-021, -031; E-50, E-66, E-67 | ~450 |
| 18 | CSV export + reader/writer | COT-026; E-51 | ~350 |
| 19 | Import validate + preview | COT-027; E-52, E-54..E-57, E-59 | ~450 |
| 20 | Apply / discard / expiry / history | COT-028, -029; E-53, E-58, E-60, E-61 | ~450 |
| 21 | Initial load command | COT-007; E-12, E-13 | ~450 |
| 22–27 | UI: pricing (schemes, parameters, rate), price tables + bulk selection, quotation editor (3 units), list/detail, import + history | §8 | ~2,500 |
| 28 | Close-out: audit coverage, docs, a11y, DoD | §12 | ~200 |

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

The delivery strategy is `ask-on-risk`: the chain strategy (`stacked-to-main` or `feature-branch-chain`) must be chosen by the user before apply; this proposal does not choose it. Slices are ordered so a linear order is valid under either strategy. Every slice runs the full close gate (`pint --test`, `composer types:check`, `pnpm check`, `pnpm types:check`, `pnpm build`, `artisan test`).
