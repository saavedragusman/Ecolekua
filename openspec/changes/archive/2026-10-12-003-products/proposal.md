# Proposal: 003-products — Product Catalog, Combinations, Combos, Selection Resolution, Visual Assets and Initial Load

> Source of truth: `docs/specs/003-products.md` (status "Lista para Design"; PRD-001..PRD-020, E-01..E-68, DEC-PRD-01..DEC-PRD-48 all `Confirmada`; DT-01..DT-03 technical, `Propuesta`, confirmed in design).
> Exploration: `openspec/changes/003-products/exploration.md`. Its open questions Q1–Q11 are answered in the spec by DEC-PRD-35..DEC-PRD-47; Q12 (history conditions deferred to 004/006/008, E-29 as `todo`) is non-blocking.
> Scope rule (`openspec/config.yaml` → `rules.proposal`): exactly what spec 003 defines; nothing extra; no tenant/multi-company abstractions.
> Depends on: `001-foundation` (archived) and the patterns established by `002-customers` (archived).

## Intent

Ecolekua's catalog lives today in a price spreadsheet with two sheets ("Uniformes" and "Pañales para adultos"), where each row is a sellable combination identified by a code. Nothing in the ERP knows what a product, a combination or a valid customer selection is, so specs 004 (pricing and quotations), 005 (public portal), 006 (orders), 008 (inventory), 009 (reservations) and 010 (production) cannot start.

This change lets authorized internal users maintain the catalog — categories, a data-driven catalog of attributes and values (with presentation, reference tones and offered colors per fabric), products with their attributes and roles, commercial combinations keeping the existing codes, detail locations, customizations, supply mode and minimum stock, diaper combos, and visual assets — and exposes **one backend authority** that (a) lists the options available at each step of a partial selection (PRD-019) and (b) resolves and validates a full selection to a sellable combination (PRD-011). Prices are explicitly not part of this change. A one-time console command loads the existing catalog (PRD-018).

Success means: every scenario E-01..E-68 present in the spec (except E-29, deferred to 004/006/008) has a Pest test prefixed with its ID and passes on MySQL; the §4 permission matrix is applied to fresh and existing databases; and the spec DoD (§12) is satisfied.

## Scope

### In Scope

Exactly spec §3.1, grouped by requirement:

| Area | Requirements | Key decisions |
|---|---|---|
| Categories: create, edit, order, deactivate/reactivate; no delete; case-insensitive unique name | PRD-001 | DEC-PRD-18, DEC-PRD-45 |
| Attribute and value catalog: presentation text/image/color; at most one color attribute (the palette) and at most one fabric attribute; reference tone (hex) for color values; offered colors per fabric value; deactivate-only; value rename audited | PRD-002 | DEC-PRD-03, -17, -30, -32, -38 |
| Register product: name, category, business line, supply mode; optional description and portal visibility; created active; case-insensitive unique name | PRD-003 | DEC-PRD-26, -19, -45 |
| Product attributes: role axis or order, axis order, allowed values, color without allowed values when fabric is declared, own color list otherwise, custom color option for on-demand products, structure frozen while combinations exist, removal blocked while in use | PRD-004 | DEC-PRD-34, -35, -37, -41 |
| Commercial combinations: explicit creation with duplicate action, text code unique across combinations and combos, one or several values per axis, optional restriction of order attributes, no overlap between active combinations | PRD-005 | DEC-PRD-01, -02, -04, -33, -36, -39 |
| Detail locations with color from the palette; case-insensitive unique name; no price effect | PRD-007 | DEC-PRD-07, -45 |
| Customizations: admitted service products per product; included customizations per combination, independent of admitted | PRD-008 | DEC-PRD-08, -28, -47 |
| Supply mode and minimum stock: product default minimum (form proposes 6) plus optional own value per stock article (combination + size); cleared on mode change with audit | PRD-009 | DEC-PRD-09, -10, -46 |
| Diaper combos: components with product, quantity ≥ 1 and optional allowed values per attribute (axis or order); diaper line only, implicit; no service components; own code in the shared code space; case-insensitive unique name | PRD-010 | DEC-PRD-12, -13, -14, -43, -44, -45 |
| Resolve a selection (product or combo) to a combination, with normalized selection, color name/tone or custom tone/note, and the "requires advisor" flag; no price or stock lookup | PRD-011 | DT-02 |
| Edit product, combination and combo with create validations and audit; code and axis values immutable once history exists (no history in 003) | PRD-012 | DEC-PRD-21 |
| Deactivate/reactivate products, combinations and combos; product deactivation does not cascade stored state; reactivating an overlapping combination rejected; same-state no-op without audit | PRD-013 | DEC-PRD-40 |
| Restricted delete without history; product delete removes its attributes, details, customizations and combinations; blocked when part of a combo | PRD-014 | DEC-PRD-42 |
| List with Activos/Inactivos/Todos and backend counts, search by name and combination code, filters, detail page, combos list, backend pagination | PRD-015 | — |
| Backend authorization with Policies | PRD-016 | — |
| Audit of create (including import), update, deactivate, reactivate and delete | PRD-017 | — |
| One-time console load from three UTF-8 CSV files (combinations, combos, palette and offered colors), all-or-nothing, required active author, audited as import | PRD-018 | DEC-PRD-24, -25, -27 |
| Available options for a partial selection, in axis order, for products and combo components | PRD-019 | DEC-PRD-31 |
| Visual assets: product main image, optional image per value and per detail location, SVG layered templates (general or per gender) sanitized in the backend with layer detection and required-layer validation | PRD-020 | DEC-PRD-20, -29, DT-03 |
| Permissions `products.*` (6), §4 initial matrix, coherence rule | §4 | DEC-PRD-22, -23 |
| Internal ERP screens of spec §8 | §8 | — |

### Out of Scope

Exactly spec §3.2, plus items the spec explicitly assigns elsewhere:

- Prices per tier, fixed price, price of combo items, configurable tiers, VIP thresholds, size-range price exception of `184`/`184-2` (004; DEC-PRD-06, DEC-PRD-25).
- Quantity distribution by size and color within a quotation or order line (004 / 006).
- Minimum-stock rule and WhatsApp button in the portal and orders (005 / 006; DEC-PRD-11).
- Customer design or logo files (006).
- Stock levels, movements and stock items as entities (008); base garments in stock (008 / 009); material consumption (008).
- Restocking by production (010).
- VAT breakdown and invoicing (006 / future integration).
- Drawing the layered preview in the quoter (005). The ERP only shows the preview when uploading a template (PRD-020).
- Testing E-29 with a real history record (no history entity exists in 003).
- Any import screen in the ERP (DEC-PRD-24). Import of prices or costs (PRD-018).

**Commitments recorded for later specs (spec §10; not implemented here):**
- 004 / 006 / 008: add their history blocking condition to PRD-014 and the immutability of PRD-012 (DEC-PRD-21), with scenario E-29; history foreign keys toward combinations use restrict-on-delete.
- 004 / 005 / 006: use PRD-011 to validate every selection; no frontend resolves combinations.
- 004: load final prices by code and tier, the `184`/`184-2` size-range exception, combo item prices and totals, configurable tier schemes.
- 005 / 006: minimum-stock rule with WhatsApp; custom-color quotations not processed from the web.
- 006: store a copy of the descriptive name and normalized selection (including color name and tone, custom tone and note) on each order line.
- 005: option thumbnails and layered preview per PRD-020.
- 008 / 009 / 010 / 014: stock articles, fabric stock by color, base garments, restocking, below-minimum notifications.

## Capabilities

Existing capabilities in `openspec/specs/`: `authentication`, `user-management`, `password-management`, `roles-permissions`, `authorization`, `administrative-protections`, `audit`, `customers`.

### New Capabilities
- `products`: the full catalog domain of spec 003 — PRD-001..PRD-020 and E-01..E-68, including the §4 permission table, role matrix and coherence rule (DEC-PRD-23), the initial load command (PRD-018), and decision notes DEC-PRD-01..DEC-PRD-48 and DT-01..DT-03 where they refine a requirement. A single capability keeps the transcription 1:1 with the source spec and its `PRD` prefix, as `customers` does for 002.

**Numbering gaps (kept verbatim, AGENTS §4.3; do not renumber):** the spec has no PRD-006 and no scenarios E-12, E-13 or E-18. DEC-PRD-31 appears after DEC-PRD-34 in the decision table. The spec phase transcribes IDs exactly as they exist.

### Modified Capabilities
- None at requirement level. Rationale (same as 002):
  - `roles-permissions`: FND-017 allows the introducing spec to assign new permissions; spec 003 §4 is that statement. The matrix is transcribed in `products`.
  - Coherence: DEC-PRD-23 adds a `products.*` rejection path to role permission assignment, analogous to 001 DEC-022 and DEC-CLI-32, without altering any existing FND or CLI requirement. Transcribed under `products` with E-33.
  - `audit`: FND-022 allows later specs to add audited events; PRD-017 is transcribed in `products`.
  - Hard-coded permission and audit-action counts in 001/002 tests are an implementation change handled in design and tasks.

## Approach

High level only; `design.md` is the plan (AGENTS §4.2).

1. **Mirror the 001/002 pattern**: protected routes, thin controllers → FormRequest → Policies (product, combo, catalog abilities) → Actions in `app/Actions/Products/` owning `DB::transaction` and `RecordAuditEvent`. One ability gates deactivate and reactivate.
2. **Catalog as data, no rules engine** (constitution §11): attributes and values are rows; the only fixed relations are fabric → offered colors and product → allowed values / combination restrictions / combo component restrictions, all sharing one "allowed subset" mechanism (DEC-PRD-36, DEC-PRD-44).
3. **Code space guaranteed by the database** (DEC-PRD-01/02/14, DT-01): one uniqueness guarantee covering both combination and combo codes, stored as text and compared case-insensitively. Exact mechanism in design (exploration recommends a code registry table).
4. **Non-overlap of active combinations** (DEC-PRD-39): backend check inside a transaction with a product-row lock, plus a database index that blocks exact duplicates. Same check on create, edit and reactivation (DEC-PRD-40). The check lives in a pure support class reused by the importer for in-memory validation.
5. **Availability is computed, never cascaded** (PRD-013): product, combination, category and value states combine at read time, so reactivating a product restores each combination's own state.
6. **One resolution engine** for PRD-011 and PRD-019 (DT-02): loads a product's active combinations once and applies the same pure criteria for options and final validation; no price or stock dependency; query-count tested.
7. **Minimum stock** (DEC-PRD-46): product default plus optional per-article override keyed by combination and size value, without creating the 008 stock-item entity.
8. **Visual assets** (DT-03, PRD-020): Laravel filesystem storage, images validated by decoding and re-encoded; SVG sanitized by a maintained library before layer detection; serving, limits and variants decided in design.
9. **Initial load** following CLI-018: validate every row of the three files first (including intra-file duplicate codes, overlaps, colors without tone, unknown fabrics), report row/field/reason, then create everything in one transaction through the real Actions with console audit origin and an import marker.
10. **Frontend** after backend behavior is green (Strict TDD; authorization tests hit HTTP). New shared components only where missing (color picker over native input, checkbox group); spec §8 and `docs/ui/design-system.md` apply.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | New | categories, attributes, values, offered colors, detail locations, products, product attributes and allowed values, combinations and axis values, restrictions, customizations, templates, combos, components, code uniqueness, minimum-stock overrides |
| `app/Models/` | New | catalog models (avoid the names `Attribute`/`attributes`, exploration §4) |
| `app/Enums/` | New / Modified | status, business line, supply mode, presentation, role; `PermissionName` (+6), `AuditAction` (new cases) |
| `database/seeders/InitialRolePermissions.php` | Modified | §4 matrix for `products.*` |
| `app/Actions/Roles/SyncRolePermissions.php` | Modified | DEC-PRD-23 coherence check |
| `app/Policies/` | New | product, combo and catalog policies |
| `app/Actions/Products/` | New | create/update/lifecycle/delete actions, resolution and options, import |
| `app/Support/Products/` | New | shared rules, overlap and resolution logic, SVG/image handling |
| `app/Http/Controllers/Products*`, `app/Http/Requests/Products/` | New | thin controllers and FormRequests |
| `app/Console/Commands/` | New | initial catalog load command |
| `app/Support/Customers/CustomerCsvReader.php` | Possibly modified | generalized CSV reader (design decides refactor vs copy; 002 tests must stay green) |
| `routes/web.php` | Modified | catalog routes (protected) |
| `resources/js/pages/products/`, `resources/js/components/` | New | list, detail, editors, catalog, categories, combos; color picker, checkbox group |
| `resources/js/navigation.ts` | Modified | catalog entry under "Comercial" |
| `docs/ui/design-system.md` | Modified | color picker subsection if a new component is added |
| `composer.json` | Modified | SVG sanitizer dependency (pending approval) and `ext-gd` declaration |
| `tests/Feature/Products/`, `tests/Unit/` | New | E-01..E-68 tests (E-29 deferred) |
| 001/002 tests with hard-coded permission/audit counts | Modified | updated to the new catalog |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Size: forecast ~9,000–11,000 authored lines, far above the 400-line budget | High (certain) | Chained PR slices; chain strategy chosen by the user before apply (see forecast) |
| SVG sanitizer requires a new dependency; the candidate (`enshrined/svg-sanitize`) is GPL-2.0-or-later with past advisories | High | User approval required (AGENTS §2) before the SVG slice; slices without SVG proceed; pin version and verify advisories in design |
| SVG active content (XSS/XXE) and image uploads (decompression bombs, polyglots) | Med | Sanitize before layer detection, safe XML parsing with size cap, serve with CSP and `nosniff`, decode and re-encode images; E-39 test |
| Overlap rule is not expressible as a unique index | Med | Product-row lock + exact-duplicate index (DEC-PRD-39); concurrency path tested |
| Resolution/options inconsistency between ERP and portal | Med | Single engine shared by PRD-011 and PRD-019; no frontend logic |
| PRD-019 performance (N+1) | Low | Load once per product; query-count test |
| Per-article minimum override without a 008 stock-item entity | Med | Design keys overrides by combination + size value and documents the hand-off to 008 |
| Initial load column semantics for minimum stock (see Open issues) | Low | Resolve before the import slice; does not block spec or other slices |
| Importer reuse touches merged 002 code | Low | Keep 002 importer tests green; design may copy instead |
| Hard-coded permission/audit counts in 001/002 tests | High (certain) | Update in the permissions slice; prefer `PermissionName::cases()` |
| Mobile bottom bar capped at 4 destinations | Low | Navigation decision in design; may move entries to "Más" |
| Real catalog data reserved by Ecolekua leaking into git | Low | CSV not versioned when reserved; tests use fictitious fixtures except reference codes |

## Open issues

These do not block this proposal nor the spec transcription. They are reported to the orchestrator; this proposal does not resolve them.

Resolved (2026-10-07): the initial-load CSV stock minimum is the product default; rows of one product that disagree are rejected with row/field/reason; per-article own values are set later from the ERP. Recorded in the spec as **DEC-PRD-48** (PRD-018).

Resolved (2026-10-07): the owner approved `enshrined/svg-sanitize` (GPL-2.0-or-later, pinned version; internal use, not distributed) for DT-03. Recorded in the spec's technical decisions table.

Documentation drift (non-blocking for 003, reported per AGENTS §3): `docs/business/business-rules.md` §27 (last paragraph) still says Ecolekua must confirm the minimum-stock rule (DEC-PRD-11), while spec 003 records DEC-PRD-11 as `Confirmada` (2026-10-05): option B. The rule is applied in 005/006, not here; the business rules should be updated to match.

## Rollback Plan

- All schema changes are new migrations with `down()` methods; revert locally with `./vendor/bin/sail artisan migrate:rollback --step=N`. No existing 001/002 migration is edited.
- Permission rows and matrix grants touch only `products.*`; a revert removes them without affecting 001/002 permissions.
- Stored images and templates live under a dedicated storage path; removing the catalog tables and that path restores the previous state.
- Work lives on `feat/003-products`; each chained PR slice is revertible independently with `git revert` of its merge commit. Slices are ordered so backend layers (permissions → catalog schema → products → combinations → resolution) never depend on unmerged UI, and visual assets and import come last.
- The initial load runs once, in a single transaction: a failed run leaves the database unchanged.

## Dependencies

- `001-foundation` (merged): permission catalog, `InitialRolePermissions`, `SyncRolePermissions`, `RecordAuditEvent`/`AuditOrigin`, protected-by-default routing, ERP layout and design-system components.
- `002-customers` (merged): lifecycle, restricted-delete and import patterns (CLI-018).
- SVG sanitizer library: pending user approval (Open issues).
- PHP `ext-gd` (available in Sail; production hosting to be confirmed in design). No other new Composer or npm packages expected.
- MySQL 8.4 (Sail) with the `testing` database for Pest.
- For PRD-018 execution (not for design or implementation): Ecolekua validates Anexo A, Anexo B, the fabric and color file and the Los Cedros codes; the author user must exist and be active.

## Success Criteria

Mapped to spec §12 and AGENTS.md §12:

- [ ] Every scenario E-xx in the spec has at least one Pest test whose name starts with its ID, green on MySQL; E-29 is deferred to 004/006/008.
- [ ] Authorization only through Policies; no role-name checks, no `abort(403)`; every catalog route protected by default (E-03, E-04, E-26, E-42).
- [ ] Creates, edits, deletions and import run inside Actions in `app/Actions/Products/`, in a transaction when several entities are affected, and are audited with before/after values.
- [ ] Code uniqueness guaranteed at database level across combinations and combos (E-08); non-overlap of active combinations guaranteed by the backend under a product lock, with exact duplicates blocked by the database (DEC-PRD-39).
- [ ] No SVG template is stored unsanitized; a malicious-SVG test exists (E-39).
- [ ] Seeding creates the 6 `products.*` permissions and applies the §4 matrix on fresh and existing databases without re-granting revoked permissions; coherence rule enforced (E-33).
- [ ] PRD-011 and PRD-019 share one backend engine; no frontend computes options or resolves combinations.
- [ ] Seeders and fixtures use fictitious data; the load CSV is not versioned if Ecolekua considers it reserved.
- [ ] `sail artisan test`, `sail pint --test`, `sail composer types:check`, `sail pnpm check`, `sail pnpm types:check` and `sail pnpm build` pass.
- [ ] Design-system checklist (§11) verified at 375, 768 and 1280 px.
- [ ] No tenant/multi-company concepts and no unapproved dependencies in the diff.

## Review Workload Forecast

Estimated authored changed lines: **~9,000–11,000** (additions + deletions, tests included, generated Wayfinder files excluded). This updates the exploration estimate (~8,500–10,500) for scenarios E-54..E-67 added by DEC-PRD-35..DEC-PRD-47 (combination restrictions, single color attribute, structure freeze, reactivation overlap, combo line and restrictions, name uniqueness, minimum-stock overrides).

| # | Candidate slice | Est. lines |
|---|---|---|
| 1 | Permissions, audit cases, §4 matrix, DEC-PRD-23 coherence, adapted 001/002 tests (E-33) | ~400 |
| 2 | Enums, catalog schema (categories, attributes, values, offered colors, detail locations), models, factories | ~450 |
| 3 | Categories (PRD-001; E-64 part) | ~350 |
| 4 | Attributes and values (PRD-002; E-36, E-45, E-46, E-58) | ~450 |
| 5 | Detail locations and catalog audit (PRD-007 catalog part; E-64 part) | ~250 |
| 6–7 | Catalog UI: color picker, checkbox group, categories, locations, attributes and values pages, navigation | ~850 |
| 8 | Products schema, policy, create/edit/lifecycle, supply mode, minimum stock and overrides (PRD-003, -009, -013; E-01..E-04, E-20, E-25, E-26, E-64, E-65, E-66) | ~500 |
| 9 | Product attributes, roles, allowed values, custom color, structure freeze (PRD-004; E-05, E-06, E-24, E-57, E-60) | ~450 |
| 10 | Code uniqueness, combinations, restrictions, overlap, duplicate, reactivation overlap (PRD-005; E-07..E-11, E-48, E-54..E-56, E-59) | ~500 |
| 11 | Detail locations and customizations on products/combinations (PRD-007, -008; E-34, E-67) | ~250 |
| 12 | Combos (PRD-010; E-21, E-22, E-62, E-64 part) | ~400 |
| 13 | Resolve selection (PRD-011; E-14..E-17, E-19, E-23, E-43, E-44, E-47, E-49, E-50, E-63) | ~450 |
| 14 | Available options (PRD-019; E-51..E-53, E-54/E-55/E-63 options part) | ~350 |
| 15 | Restricted delete (PRD-014; E-27, E-28, E-61; E-29 `todo`) | ~300 |
| 16 | Product list, code search, detail page (PRD-015; E-30) | ~450 |
| 17 | Product edit UI: attributes, combination editor, combos editor (3 sub-units) | ~900 |
| 18 | Images (E-35, E-42) | ~400 |
| 19 | SVG templates, sanitizer, layers (E-37..E-41) — waits for dependency approval | ~450 |
| 20 | Initial load: CSV reader, three files (PRD-018; E-31, E-32) — waits for the PRD-018 open issue | ~650 (two units) |
| 21 | Close-out: docs, DoD, a11y at 375/768/1280 px | ~150 |

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

The delivery strategy is `ask-on-risk`: the chain strategy (`stacked-to-main` or `feature-branch-chain`) must be chosen by the user before apply; this proposal does not choose it. Every slice runs the full close gate (`pint --test`, `composer types:check`, `pnpm check`, `pnpm types:check`, `pnpm build`, `artisan test`) because CI runs `composer ci:check`.
