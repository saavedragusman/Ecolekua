## Exploration: 004-pricing-and-quotations

Date: 2026-10-13. Source of truth: `docs/specs/004-pricing-and-quotations.md` (prefix COT, 43 DEC-COT all Confirmed, "Lista para Design"). Convention follows `openspec/changes/archive/2026-10-12-003-products/exploration.md` (English prose, Spanish product terms/IDs verbatim). CodeGraph has no index for this repo; evidence gathered with Read/Grep/Glob.

### 1. Current state — what 004 builds on

**Repo baseline.** 001/002/003 merged (003 not yet in `main`). No outbound HTTP/mail/queue/scheduler code exists anywhere (grep `Http::|Mail::|Mailable|Notification|Schedule::|ShouldQueue` over app, routes, tests, bootstrap returns nothing) — 004 introduces all four. Stack: Laravel 13.33, PHP ^8.4, Inertia 3, Pest 4, phpstan level 7. Queue default `database` with `jobs` table (`config/queue.php:16`); mail default `log` (`config/mail.php:17`), Mailpit in `compose.yaml:53`; `routes/console.php` has no schedule; `config/app.php:68,82` timezone=UTC, `operating_timezone=America/Caracas`.

**Selection engine (003, reuse as-is).**
- `app/Actions/Products/ResolveSelection.php` — product or combo → `ResolvedSelection` / `ResolvedCombo` (`app/Support/Products/Selection/`), carrying `requiresAdvisor` (custom color only), code, `combinationId`, normalized axes/order/details, customizations (ids only, no quantity — DEC-PRD-78).
- `app/Actions/Products/ListSelectionOptions.php` + `SelectionOptions` — editor steps (PRD-019).
- `CatalogSnapshotLoader` — ≤10 queries per product (`forProducts`, `forCombo`).
- `AttributeSpecialUse::Size` (`app/Enums/AttributeSpecialUse.php`) identifies the size attribute; `SupplyMode` (incl. `Service`) and `BusinessLine` exist in `app/Enums/`.
- `ComboMembership::includes` (axes only) and `CatalogUsage::combosIncludingCombination` — DEC-PRD-42 membership precedent for the pack notice.

**Empty hooks 004 must fill.**
- `app/Actions/Customers/DeleteCustomer.php:51` `ensureHasNoHistory`.
- `app/Actions/Products/UpdateCombination.php:116` `ensureEditableWithoutHistory` (DEC-PRD-21).
- `app/Actions/Products/DeleteCombination.php:81`, `DeleteCombo.php:46`, `DeleteProduct.php:113`.
- Pest `->todo()` markers: `tests/Feature/Customers/DeleteCustomerTest.php:112` (E-19 → COT E-39), `tests/Feature/Products/DeleteProductTest.php:378` (E-29 → COT E-40).
- `app/Actions/Products/SyncProductAttributes.php:398` stays for 008.
- `resources/js/pages/customers/Show.vue:266` — "Quotation (004) … history sections are added here"; Show.vue:312/:320 carry CLI-009/delete copy that may need a tweak.

**Reusable patterns.**
- Audit: `RecordAuditEvent` (`app/Actions/Audit/RecordAuditEvent.php`) accepts null actor + `AuditOrigin`; `AuditOrigin::console()` for import marking (CLI-018 precedent in `ImportCustomers`). `AuditAction` currently 51 cases; `tests/Unit/AuditActionTest.php` asserts the count.
- Permissions: `PermissionName` (22 cases) → +6. Coherence in `SyncRolePermissions::ensureCoherent` (`:80`). `InitialRolePermissions::MATRIX` (`database/seeders/InitialRolePermissions.php:28`) grants only newly created permissions. Role "Operario" exists (`RoleSeeder.php:32`).
- Policies: permission-only policies (`CustomerPolicy`, `ProductPolicy`, `ComboPolicy`, `CatalogPolicy` registered via `AppServiceProvider::registerCatalogPolicy`). Pricing/rate/imports are not single-model → a shared `PricingPolicy` registered the same way is the natural fit.
- CSV/import: `app/Support/Csv/CsvReader.php` (extracted from 002 in 003) — header must match expected columns exactly, detects `,`/`;`, reads a path. Command pattern: `ImportCatalog` + `Support/Products/Import/*` + `ImportReport`.
- Time: `app/Support/Time/OperatingTime.php` (day start / next day start helpers; no end-of-day expiry helper yet).
- Phones: `app/Support/Customers/PhoneNumber.php` (E.164; `isMobile()`) — prospect phone (COT-013, E-68: mobile only) can reuse `VenezuelanPhone`/`PhoneNumber`.
- Per-size rows precedent: `stock_minimum_overrides` uses `size_value_id` + generated `size_key` unique (`2026_10_07_000003_create_combinations_tables.php:75-85`); size removal cleans them with audit copy (`SyncProductAttributes:94`). Size-group prices need the same treatment.
- UI: `DataTable` (no row selection), `SegmentedTabs`, `ConfirmDialog`, `StatusBadge` (categories pending/active/done/critical/neutral cover COT-015), `AppCard/AppInput/AppSelect` (single), `AppCheckboxGroup`. `resources/js/navigation.ts` Comercial group: Clientes(10), Productos(20), Combos(25), Catálogo(30); bottom bar capped at 4.
- Views: only `resources/views/app.blade.php` exists; no portal or mail Blade views.
- Money lib: `brick/math` 1.0.0 is in `composer.lock` (Laravel dep), **not** a direct `require`.

### 2. What 004 must add (domain areas)

| Area | Reqs / scenarios | Notes |
|---|---|---|
| Price schemes & tiers | COT-001 (E-01..E-03) | Validation: contiguous from 1, only last open-ended, negotiation only last; case-insensitive unique name; cannot deactivate in use by active product; remove tier w/ prices → confirm + audit copy. Seeder with 4 initial schemes. |
| Scheme per product | COT-002 (E-04, E-27) | `products.price_scheme_id` (FK restrict); bulk assignment from product list → needs row selection (DataTable lacks it). Change scheme w/ prices → delete them (confirm + audit). |
| Combination prices + size groups | COT-003, COT-004, COT-025 (E-05..E-08, E-26) | Price per combination × tier × optional size group. Groups partition admitted sizes. Tier table UI with "sin precio". |
| Combo item prices | COT-005 (E-10, E-60) | See D3 — component identity is unstable today. |
| Commercial parameters | COT-006 (E-11) | 2 settings (advance % =50, validity days =7), audited. |
| Exchange rate | COT-030 (E-63..E-65) | `ve.dolarapi.com` fetch command, scheduled 07:00 `America/Caracas`, retries, manual entry, current-rate lookup, history. API returns JSON float `promedio` (e.g. 876.7976, 4 dp — checked 2026-10-13). |
| Calculation | COT-008..COT-012, COT-025 (E-09, E-10, E-14..E-31, E-49, E-69) | Pure DB-free rules + `CalculateQuotation` Action. |
| Quotation lifecycle | COT-013..COT-020 (E-32..E-44, E-48) | Schema, drafts, prospects, per-size line rows, issue/freeze, number, expiry, duplicate, cancel, discard, list/search/detail, customer history section. |
| Printable view + email | COT-021, COT-031 (E-50, E-66, E-67) | Shared Blade content (DT-06), queued Mailable after commit, delivery log, resend. |
| CSV export/import | COT-026..COT-029 (E-51..E-61) | Export writer, validate/preview, apply/discard/expire, history. |
| Initial load command | COT-007 (E-12, E-13) | 3 CSVs via the same `ValidatePriceImport` rules (DT-04); schemes file command-only. |
| Permissions & audit | §4, COT-022, COT-023 (E-07, E-20, E-34, E-45, E-47, E-59) | 6 permissions, matrix, coherence; ~35–45 new audit cases. |
| Integrity commitments to 002/003 | COT-024 (E-39, E-40, E-48) | See §1 hooks. |

### 3. Required changes to existing code

- 001/002/003 tests with hard-coded counts: `tests/Unit/PermissionNameTest.php`, `AuditActionTest.php`, `FoundationSeederTest`, `PermissionCatalogTest`, permission-matrix seeding tests. Prefer `::cases()`.
- `ComboComponents::replace` (`app/Support/Products/ComboComponents.php:67`) deletes all components and recreates them whenever `matches()` is false (any quantity change or reorder) → ids change → combo item prices keyed on `combo_components.id` would cascade away on any combo edit.
- `SyncProductAttributes` / `UpdateCombination` (size removal, restriction changes) must keep size groups consistent (stock-override cleanup precedent).
- `UpdateProduct`: supply mode / business line / scheme change affects draft recalculation only; issued copies frozen.
- `CustomerController::show` + quotation history section gated by `quotations.view` (Show.vue:266 anchor).
- Registration: `routes/web.php`, `routes/console.php` (first `Schedule`), `config/services.php` (rate API URL/timeouts), `.env.example` (mail/queue), `AppServiceProvider` (policy registration), `navigation.ts` (Cotizaciones, Precios; 4-item bottom bar pushes into "Más").
- Do **not** touch `app/Support/Products/Selection/*`, `ResolveSelection`, `ListSelectionOptions` — `tests/Feature/Architecture/SelectionHasNoPriceOrStockDependencyTest.php` rejects string literals matching `pric|stock|inventor|cost` there (extending `ResolvedSelection` with `'stock_with_minimum'`-like strings fails).

### 4. Design decisions and approaches

**D1 — How calculation gets selections** (COT-009 resolves each size row; DT-02 says "uses `ResolveSelection`").

| Option | Pros | Cons | Effort |
|---|---|---|---|
| A. Call `ResolveSelection` per size row | Literal DT-02, zero 003 change | ~10 queries × rows × lines per recalculation; editor recalcs on every change | Low |
| B. `forProducts()` snapshot once, then `SelectionRules::validate` directly | Constant queries | Bypasses the Action | Med |
| C. Per-calculation memoizing loader behind `ResolveSelection` | DT-02 kept, N+1 gone | Small `CatalogSnapshotLoader` change; arch test still passes (no price strings) | Low-Med |

Recommendation: C, with a query-count test like 003's.

**D2 — Product facts** (business line, supply mode, service flag, size-attribute presence) are not in `ResolvedSelection`. Recommendation: `CalculateQuotation` reads `products` by id in one query; no 003 change (avoids arch-test constraint).

**D3 — Combo item price identity.**

| Option | Verdict |
|---|---|
| Price keyed by `combo_components.id` | Breaks unless `replace` is rewritten |
| Make `ComboComponents::replace` update in place by position, keeping prices when product unchanged | Recommended — needs P-2 answered |
| Price keyed by (combo_id, position) | CSV "número de componente" is already positional; fragile on reorder |

**D4 — Exchange rate storage** (see C1). Recommendation: append-only rows with a "superseded" marker + generated unique key on (rate_date, not superseded) → satisfies "never edited/deleted", "correction supersedes" and DB uniqueness per effective rate.

**D5 — Expiry** (COT-017, E-41 needs audited state change). Daily scheduled command (system actor, console origin) **and** a read-side effective-status guard so lists never show an expired quotation as issued. Depends on a running scheduler.

**D6 — Printable view & email.** One Blade partial (DT-06) rendered by a standalone print route and by the Mailable. Email clients don't support Tailwind tokens → shared partial needs table layout + inline CSS. Print is outside Inertia but still authenticated + `quotations.view` via Policy.

**D7 — Money.** DT-01: `DECIMAL` + exact PHP arithmetic. `brick/math` is transitively present but direct use requires adding it to `composer.json` `require` → AGENTS §2 "justify and ask". Alternative: `bcmath`. HALF_UP rounding (DEC-COT-02). API JSON float must be converted via a 4-dp string, never kept as float.

**D8 — CSV.** Extend `CsvReader` (optional preamble row, dynamic tier columns, decimal-comma parsing) keeping 002/003 import tests green; add a small `CsvWriter` (`;`, comma decimal, UTF-8 BOM — DEC-COT-31). One `ValidatePriceImport` Action (DT-04) serves command and ERP.

**D9 — Price storage.** `product_prices` keyed by combination, tier, optional size group; NULL group key via the `size_key` generated-column precedent. Fixed-price schemes: a single implicit tier row avoids NULL tier keys. Size groups: table with unique (combination, size value).

### 5. Contradictions / inconsistencies

No conflict found with the constitution or the updated `business-rules.md` §2.1–2.6, §3, §25, §26.2. Worked examples (E-09, E-10, E-14, E-29, E-36, E-60, E-62, E-69) recompute correctly. Items to settle:

- **C1 — Rate per day vs corrections.** §5 says "una por día" and the DoD wants per-day DB uniqueness, but COT-030 makes a same-day manual correction a new record that supersedes the old one and forbids edits/deletes. → D4.
- **C2 — Quotation number timing.** COT-013/E-32 + DEC-COT-21 say the number is assigned on create; DT-03 describes number + issue under a lock (as if assigned at issue). Discarding drafts leaves gaps in a "correlativo global".
- **C3 — 002 CLI-009 vs COT-016/E-33.** Consolidated 002 says in-progress quotations continue after a customer is deactivated; 004 blocks issuing a draft for an inactive customer. Customers/Show.vue:312 copy ("Lo que esté en curso continúa") becomes inaccurate for drafts.
- **C4 — DoD FK wording vs PRD-014.** "FKs restrict toward clientes, combinaciones y combos" is right for quotation lines, but price rows of a combination without history must cascade (or deleting such a combination, allowed by PRD-014, would fail). Audit copy should include the prices.
- **C5 — COT-031 result vs async queue.** Spec lists `enviado|fallido`; a queued mail has an interim state.
- **C6 — Stale 003 text.** 003 §10 line 1035 says schemes "por producto o categoría"; DEC-COT-22 confirmed per product only. DEC-PRD-06 says only `184`/`184-2` vary by size; COT-004 makes size groups a generic capability (harmless generalization — note it).
- **C7 — DT-04 vs existing reader.** DT-04 "native PHP, no new deps" — `CsvReader` already exists; its exact-header match doesn't fit a dynamic tier header + preamble (D8).
- **C8 — Draft auditing boundary.** DEC-COT-29 says draft edits aren't audited, but COT-023/E-19 audit the negotiated price in a draft. Consistent only if the audited draft events are exactly the COT-023 list.
- **C9 — Repo hygiene.** `docs/specs/004-pricing-and-quotations.md` untracked and `docs/business/business-rules.md` modified (source docs not yet committed); `Zone.Identifier` files next to them.

### 6. [DEC-PENDIENTE] candidates (not resolved here)

- **P-1** `[DEC-PENDIENTE] 004/COT-021, COT-031` — Company data on the printable view/email (name, RIF, address, phone, logo, mail sender) is not defined; COT-006 has only two parameters. Where does it live (env config / settings table / Blade constant)? Blocks the print/email slice.
- **P-2** `[DEC-PENDIENTE] 004/COT-005` — What happens to combo item prices when components are added, removed or changed (product or quantity)? Blocks the combo-price slice (D3).
- **P-3** `[DEC-PENDIENTE] 004/COT-030` — Rate correction model: append-only history with unique effective rate per date (D4) vs overwrite the day's row.
- **P-4** `[DEC-PENDIENTE] 004/COT-013, DEC-COT-21` — Number format (plain integer, prefix, padding) and whether gaps from discarded drafts are acceptable (assignment at create is confirmed by DEC-COT-21/COT-013; DT-03 wording must align).
- **P-5** `[DEC-PENDIENTE] 004/COT-010, E-30` — Is the percentage advance rounded per line or on the sum of percentage-mode lines? Changes totals by cents.
- **P-6** `[DEC-PENDIENTE] 004/COT-009 step 11` — Pack notice: several matching packs (2/3/6) — warn all or best? Quantity per line or across lines of the same product? "Admite la selección" must also check component order restrictions (`ComboMembership` ignores them).
- **P-7** `[DEC-PENDIENTE] 004/COT-008, DEC-COT-14` — "Ese mismo servicio": by product or by code (`157` vs `157-1`)? Services on the Uniformes scheme reach negotiation at ≥50 units (Annex A) — confirm intended.
- **P-8** `[DEC-PENDIENTE] 004/COT-001, COT-026` — Tier labels are CSV column headers: uniqueness within a scheme and non-collision with reserved headers (código, producto, estado) are not required.
- **P-9** `[DEC-PENDIENTE] 004/COT-004` — Size removed from the product / combination restriction changed: are size groups cleaned with audit copy (like stock minimums) or is the change blocked?
- **P-10** `[DEC-PENDIENTE] 004/§5, COT-016` — Frozen copy excludes recipient/advisor display data (name, document, contacts). Does an issued quotation freeze them or read live?
- **P-11** `[DEC-PENDIENTE] 004/COT-031` — Email: interim "en cola" state; one mail to all recipients vs one per recipient; de-dup when customer and contact share an email.
- **P-12** `[DEC-PENDIENTE] 004/COT-001` — Can an existing scheme switch between tiered and fixed, and what happens to its prices?
- **P-13** `[DEC-PENDIENTE] 004/COT-030` — Rounding rule when `promedio` has >4 decimals.
- **P-14** `[DEC-PENDIENTE] 004/§4` (low) — Should `pricing.*` require `products.view`? Spec coherence only links `pricing.manage` → `pricing.view`.
- Not blocking Design (data gates): Annex A "Por confirmar" rows (`013-1`, `016-1`, `163`) and Annex B inconsistencies — validated with Ecolekua before COT-007 runs; fixtures use fictitious data.

### 7. Risks

- Calculation performance (D1).
- No job infrastructure: `compose.yaml` has no queue worker or scheduler container (not verified whether `artisan dev` starts them). Rate fetch, expiry, validated-import expiry and queued mail all need them. Production hosting unknown.
- First external call: outage must never block calculation or issue (DEC-COT-38); tests use `Http::fake`/`Mail::fake`.
- Money: no `float` anywhere (DoD); frontend shows backend strings only. Direct-dependency approval (D7).
- Concurrency: number + issue lock (DT-03), import apply conflict check with locked price rows (COT-028), product row lock already taken by 003 combination writes.
- Email styling (D6).
- UI size: mobile-first heavy editor (step selection, per-size table, sticky summary, negotiation); `DataTable` row selection is a new component; design-system doc likely needs a print-view note. No JS tests → types/build + manual 375/768/1280 px pass.
- Test naming: E-xx IDs collide across 001–004 (`--filter=E-12` hits other specs). Keep the AGENTS §10 "starts with ID" rule, add a COT marker (e.g. `COT E-12 …`) or filter by file.
- CSV safety: export reference columns opened in Excel may start with `= + - @`; consider prefix-escaping on export.
- Review load: ~28 slices → delivery strategy decision applies.

### 8. Size estimate and slicing proposal

Forecast ~9,500–11,500 authored lines (tests included, generated Wayfinder excluded) — similar to 003. 400-line budget risk: **High**. Chained PRs recommended; under ask-on-risk the user chooses `stacked-to-main` or `feature-branch-chain` (003 used the latter).

| # | Slice | Covers | ~Lines |
|---|---|---|---|
| 1 | 6 permissions, matrix, coherence, audit skeleton; adapt count tests | §4, E-47 | 350 |
| 2 | Money helper + enums + pricing schema/models/factories | DT-01 | 450 |
| 3 | Schemes & tiers (rules, Actions, seeder) | COT-001; E-01..E-03 | 450 |
| 4 | Scheme per product + bulk assignment | COT-002; E-04, E-27 | 300 |
| 5 | Combination prices + size groups + "sin precio" flag | COT-003, 004, 025; E-05..E-08 | 450 |
| 6 | Combo item prices (+ `ComboComponents::replace` change) | COT-005 | 350 |
| 7 | Commercial parameters | COT-006; E-11 | 200 |
| 8 | Exchange rate (fetch, schedule, manual, current) | COT-030; E-63..E-65 | 450 |
| 9 | Calculation pure rules (tiers, counting, advance, packs) | COT-008, 010, 011 | 450 |
| 10 | `CalculateQuotation` Action w/ selection engine | COT-009; E-09, E-14..E-17, E-24..E-31, E-49, E-69 | 450 |
| 11 | Negotiation & advisor-required | COT-012; E-18..E-23, E-62 | 350 |
| 12 | Quotation schema + draft CRUD (recipient, prospect, advisor) | COT-013, 014; E-32..E-35, E-68 | 450 |
| 13 | Issue/freeze (number, validity, rate copy) | COT-016; E-36, E-37, E-65 | 400 |
| 14 | Expire, duplicate, cancel, discard | COT-015, 017..019; E-38, E-41..E-44 | 450 |
| 15 | History guards + the two `todo`s → tests | COT-024; E-39, E-40, E-48 | 300 |
| 16 | List/search/detail + customer history section | COT-020; E-45, E-46 | 450 |
| 17 | Printable view + email | COT-021, 031; E-50, E-66, E-67 | 450 |
| 18 | CSV export + `CsvWriter`/`CsvReader` extension | COT-026; E-51 | 350 |
| 19 | Import validate + preview | COT-027; E-52, E-54..E-57, E-59 | 450 |
| 20 | Apply / discard / expiry / history | COT-028, 029; E-53, E-58, E-60, E-61 | 450 |
| 21 | Initial load command | COT-007; E-12, E-13 | 450 |
| 22–27 | UI: pricing (schemes, parameters, rate), prices tables + bulk selection, quotation editor (3 units), list/detail, import + history | §8 | ~2,500 |
| 28 | Close-out: audit coverage, docs, a11y, DoD | §12 | 200 |

Gate for every slice (002/003 lesson): `sail pint --test`, `sail composer types:check`, `sail pnpm check`, `sail pnpm types:check`, `sail pnpm build`, `sail artisan test`. Slices are ordered so a linear order is valid under either chain strategy. Commit the source docs first.

### 9. Recommendation

1. One change `004-pricing-and-quotations`, chained as above; ask for chain strategy under ask-on-risk.
2. Before Proposal/Design, resolve at least **P-2, P-3, P-4, P-5** and the `brick/math` direct-dependency decision. P-1 can wait until slice 17.
3. Settle in Design: D1 (memoizing loader), D2, D3, D4, D5, D6, D7, D8, D9.
4. Add queue + scheduler to dev/deployment notes; confirm production hosting supports them.

**Ready for Proposal**: Yes, after P-2..P-5 answers and the dependency decision; the rest can be recorded as Design decisions.
