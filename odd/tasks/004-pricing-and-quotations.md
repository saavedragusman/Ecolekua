# 004 — Precios y cotizaciones (documento ODD)

> Objetivo: terminar la spec 004 (fases 9–28) bajo ODD con Gentle-AI, partiendo de las fases 0–8 ya implementadas con SDD.
> Rama: cadena `feat/004-pricing-and-quotations-<fase>-<slug>` (feature-branch-chain). La Fase 9a parte de `fix/004-exchange-rate-hardening`.
> Entrega: ask-on-risk, cadena feature-branch-chain, `size:exception` aceptado para toda PR > 400 líneas (decisión del usuario 2026-10-13).
> Runner: `./vendor/bin/sail artisan test` (completa) y `./vendor/bin/sail pest --group=cot --filter='E-36 '` (acotada; el espacio final evita `E-3` vs `E-36`).
> Excepción transitoria (AGENTS.md §4.2): el diseño técnico de 004 NO se copia aquí; vive congelado en openspec.

## 1. Fuente

| Documento                                      | Ruta                                                            | Commit en que se leyó  |
| ---------------------------------------------- | --------------------------------------------------------------- | ---------------------- |
| Spec de producto (comportamiento)              | `docs/specs/004-pricing-and-quotations.md`                      | `ed014ef` (2026-10-14) |
| Diseño técnico (congelado, solo lectura)       | `openspec/changes/004-pricing-and-quotations/design.md`         | `ed014ef` (2026-10-14) |
| Tareas de origen (históricas)                  | `openspec/changes/004-pricing-and-quotations/tasks.md`          | `1147e85`              |
| Progreso de fases 0–8 (histórico, no se migra) | `openspec/changes/004-pricing-and-quotations/apply-progress.md` | `1147e85`              |

Todo `openspec/changes/004-pricing-and-quotations/` es de solo lectura. Si cambia algo del diseño, se registra en la sección "Cambios al diseño", nunca en openspec.

## 2. Alcance

Spec 004 completa, sin nada fuera de ella:

- Requisitos: COT-001..COT-031.
- Escenarios: E-01..E-73.
- Decisiones de negocio: DEC-COT-01..DEC-COT-60.
- Decisiones técnicas: DT-01..DT-06.
- Requisitos de otras specs tocados por 004: CLI-010, CLI-013, PRD-011, PRD-012, PRD-014, DEC-PRD-21, DEC-PRD-94, FND-017, FND-022.

Pendiente de este documento: fases 9–28 (escenarios E-09, E-10, E-12..E-25, E-27..E-46, E-48..E-62, E-65..E-69, E-71..E-73, más las mitades de E-20, E-26, E-60, E-64 y E-70 que quedan; ver la tabla de cobertura en §6).

## 3. Decisiones

Todas confirmadas. No hay `[DEC-PENDIENTE]` abiertos (puerta 1 de AGENTS.md §4.3).

| Grupo                                                                                                                                | Estado                  | Dónde está registrado                              |
| ------------------------------------------------------------------------------------------------------------------------------------ | ----------------------- | -------------------------------------------------- |
| DEC-COT-01..DEC-COT-59                                                                                                               | Confirmada              | `docs/specs/004` §9                                |
| DEC-COT-60: la tasa de cambio tiene 2 decimales en todas partes (API redondea HALF_UP a 2; la carga manual rechaza más de 2 con 422) | Confirmada (2026-10-14) | `docs/specs/004` §9; commit `ed014ef`              |
| DT-01..DT-06                                                                                                                         | Confirmada              | `design.md` (congelado)                            |
| Estrategia de entrega (cadena de PRs, `size:exception`)                                                                              | Confirmada (2026-10-13) | `tasks.md` de openspec, "Review Workload Forecast" |

Si una tarea descubre un comportamiento que ni la spec ni el diseño definen, se detiene esa parte y se levanta un `[DEC-PENDIENTE]` (AGENTS.md §5–§6).

## 4. Diseño técnico

**Fuente:** `openspec/changes/004-pricing-and-quotations/design.md` (congelado, excepción transitoria de AGENTS.md §4.2). Las tareas de §5 citan las "Decisions" (1–30), el "Data Flow", "Interfaces / Contracts", la "Testing Strategy" y el "Migration / Rollout" de ese archivo.

### Cambios al diseño

Cada cambio lleva su motivo y su DEC o DT. Ninguno se escribe en openspec.

| ID    | Cambio                                                                              | Motivo                                                                                                                                                                          | DEC / DT   | Origen                                                                               |
| ----- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | ------------------------------------------------------------------------------------ |
| CD-01 | `combination_prices.size_group_key` es una columna generada `VIRTUAL`, no `STORED`. | MySQL 8.4 rechaza `ON DELETE CASCADE` sobre la columna base de una columna generada `STORED` (error 1215). El índice único de celda y la colisión de `NULL` se comportan igual. | Decision 4 | Fase 2, tarea 2.8 (`9a221fb`); estaba pendiente de anotarse en `design.md` al cierre |

Las desviaciones menores de las fases 0–8 (elección de archivos de test, nombres de helpers) quedan en `apply-progress.md` como historia y no cambian el diseño.

## 5. Tareas

### Convenciones (heredadas de `tasks.md`, adaptadas a ODD)

- **Test-first (TDD estricto):** RED (test Pest cuyo nombre empieza por su ID de escenario o decisión, p. ej. `it('E-36 emitir congela precios y tasa')`) → GREEN → REFACTOR. Los tests de autorización golpean HTTP, nunca la UI. Todos los tests de 004 llevan el grupo Pest `cot`.
- **La puerta** (última tarea de cada fase): `sail pint --test`, `sail composer types:check`, `sail pnpm check`, `sail pnpm types:check`, `sail pnpm build`, `sail artisan test`.
- **Commits (AGENTS.md §4.4):** el agente crea la rama de la fase, hace el commit de la unidad de trabajo (Conventional Commit en español, citando la spec) y anota el hash en esta tabla. No hace push ni merge.
- **Revisión (RDD):** después de cada commit, `gentle-ai review assess --cwd . --agent claude-code --base-ref <último límite revisado> --committed-only --json`; se registra el nivel y el resultado.
- **Verificador independiente** antes de cerrar una fase de alto riesgo (migraciones, transacciones de emisión e importación, guardas de historial).
- **Base de datos:** nunca `migrate:fresh` sin preguntar; usar `migrate`, `migrate:rollback --step=N` y la base `testing`.
- **Privacidad:** sin precios, clientes ni datos de empresa reales en seeds, factories, tests o fixtures. Los CSV se generan en tiempo de ejecución.
- **Ruta:** todas las tareas son inline salvo que se dispare un trigger de delegación; si eso pasa, se anota en la fila.
- `sail` significa `./vendor/bin/sail`.

### Fases 0–8 (cerradas con SDD)

| Fase | Contenido                                                                                                                                   | Commit(s)                                                                                                                                   |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| 0    | Spec fuente, business rules y artefactos SDD                                                                                                | `c6b2602`, `ac64817` (0.4: abrir la PR tracker solo después del merge de 003, sigue pendiente y sin acción)                                 |
| 1    | Permisos, auditoría, matriz y coherencia [COT-022, COT-023, E-47, DEC-COT-25, DEC-COT-52]                                                   | `300a2fd`                                                                                                                                   |
| 2    | `brick/math`, `Money`, enums, esquema de precios y `PricingPolicy` [DT-01, COT-022]                                                         | `fb7abb0`, `9a221fb`                                                                                                                        |
| 3    | Esquemas y tramos [COT-001, E-01, E-02, E-03, DEC-COT-23, DEC-COT-51, DEC-COT-57]                                                           | `74882d5`, `e8db78b` (forecast recalculado: `40d6dfe`)                                                                                      |
| 4    | Asignación de esquema, uno y masivo [COT-002, E-04, E-27]                                                                                   | `27f884d`                                                                                                                                   |
| 5a   | Precios por combinación [COT-003, E-05, E-06, E-07, DEC-COT-32]                                                                             | `d69a4b0`                                                                                                                                   |
| 5b   | Grupos de talla, combinaciones sin precio, confirmación y limpieza [COT-004, COT-025, E-08, E-26, E-70, DEC-COT-48, DEC-COT-53, DEC-COT-58] | `180ec79`, `ad864b8`, `588d48c`, `f10d3c7`                                                                                                  |
| 6    | Precios de ítems de combo e identidad de componentes [COT-005, DEC-COT-06, DEC-COT-44]                                                      | `e7df039`, `32aed64`                                                                                                                        |
| 7    | Parámetros comerciales [COT-006, E-11]                                                                                                      | `1d31d13`                                                                                                                                   |
| 8    | Tasa de cambio manual, API y programación; endurecimiento y 2 decimales [COT-030, E-63, E-64, DT-01, DT-05, DEC-COT-60]                     | `b1065fe`, `18ba13b`, `190a9f9`, `ed014ef`, `1147e85` (la 8.6 queda cumplida con el servicio `scheduler` bajo el profile de `compose.yaml`) |

### Seguimiento de fases 9–28

| Fase       | Rama (`feat/004-pricing-and-quotations-…`) | IDs                                                                              | Ruta   | Estado    | Commit |
| ---------- | ------------------------------------------ | -------------------------------------------------------------------------------- | ------ | --------- | ------ |
| 9a         | `9a-calculation-counting-advance`          | COT-008, COT-010, E-30 (unit)                                                    | inline | pendiente | —      |
| 9b         | `9b-calculation-packs-basis`               | DEC-COT-47, DEC-COT-54, DEC-COT-55, DEC-COT-59, E-71 (unit), E-72 (unit)         | inline | pendiente | —      |
| 10a        | `10a-calculation-core-engine`              | COT-009, COT-011, COT-025, DT-02, E-09, E-10, E-14..E-18, E-23..E-28, E-49, E-69 | inline | pendiente | —      |
| 10b        | `10b-calculate-endpoints`                  | COT-009, COT-010, COT-022, E-20, E-29, E-30, E-31, E-64, E-65, E-71              | inline | pendiente | —      |
| 11a-schema | `11a-quotation-schema`                     | COT-013, COT-014                                                                 | inline | pendiente | —      |
| 11a-create | `11a-quotation-create`                     | COT-013, E-32, E-33, E-68, DEC-COT-45                                            | inline | pendiente | —      |
| 11b        | `11b-quotation-draft-update`               | COT-013, COT-014, E-34, E-35, E-73, DEC-COT-29, DEC-COT-56                       | inline | pendiente | —      |
| 12a        | `12a-negotiated-price`                     | COT-012, E-19, E-20, E-21, E-22                                                  | inline | pendiente | —      |
| 12b        | `12b-agreed-advance-basis`                 | COT-010, E-62, E-72, DEC-COT-55                                                  | inline | pendiente | —      |
| 13a        | `13a-issue-freeze`                         | COT-016, DT-03, E-33, E-36, E-37, E-65                                           | inline | pendiente | —      |
| 13b        | `13b-issue-deliveries-events`              | COT-016, DEC-COT-49                                                              | inline | pendiente | —      |
| 14a        | `14a-quotation-expiry`                     | COT-017, E-41                                                                    | inline | pendiente | —      |
| 14b        | `14b-duplicate-cancel-discard`             | COT-015, COT-018, COT-019, E-38, E-42, E-43, E-44                                | inline | pendiente | —      |
| 15         | `15-history-guards`                        | COT-024, CLI-010, PRD-012, PRD-014, E-39, E-40, E-48                             | inline | pendiente | —      |
| 16a        | `16a-quotation-list`                       | COT-020, E-45, E-68                                                              | inline | pendiente | —      |
| 16b        | `16b-quotation-detail-customer-history`    | COT-020, CLI-013, E-46                                                           | inline | pendiente | —      |
| 17a        | `17a-print-view`                           | COT-021, E-50                                                                    | inline | pendiente | —      |
| 17b        | `17b-email-resend`                         | COT-031, E-66, E-67, E-68                                                        | inline | pendiente | —      |
| 18a        | `18a-csv-reader-writer`                    | COT-026, DT-04                                                                   | inline | pendiente | —      |
| 18b        | `18b-price-export`                         | COT-026, E-51, E-60, DEC-COT-31                                                  | inline | pendiente | —      |
| 19a        | `19a-import-validate`                      | COT-027, E-54, E-55, E-56, E-57                                                  | inline | pendiente | —      |
| 19b        | `19b-import-upload`                        | COT-027, E-52, E-59                                                              | inline | pendiente | —      |
| 20a        | `20a-import-apply`                         | COT-028, E-53, E-58, E-60                                                        | inline | pendiente | —      |
| 20b        | `20b-import-discard-expiry-history`        | COT-029, E-61                                                                    | inline | pendiente | —      |
| 21a        | `21a-initial-load-validate`                | COT-007, E-13                                                                    | inline | pendiente | —      |
| 21b        | `21b-initial-load-apply`                   | COT-007, E-12                                                                    | inline | pendiente | —      |
| 22         | `22-ui-pricing-1`                          | COT-001, COT-006, COT-030                                                        | inline | pendiente | —      |
| 23a        | `23a-ui-product-prices`                    | COT-002, COT-003, COT-025, DEC-COT-52                                            | inline | pendiente | —      |
| 23b        | `23b-ui-size-groups-combos`                | COT-004, COT-005, E-70, DEC-COT-44                                               | inline | pendiente | —      |
| 24         | `24-ui-editor-1`                           | COT-013                                                                          | inline | pendiente | —      |
| 25         | `25-ui-editor-2`                           | COT-014, COT-011, COT-009                                                        | inline | pendiente | —      |
| 26         | `26-ui-editor-3`                           | COT-012, COT-015, COT-016, COT-018, COT-019, COT-020, COT-031                    | inline | pendiente | —      |
| 27         | `27-ui-imports`                            | COT-026..COT-029, E-61                                                           | inline | pendiente | —      |
| 28         | `28-closeout`                              | COT-023, DoD                                                                     | inline | pendiente | —      |

Cada rama parte de la rama de la fase anterior; la 9a parte de `fix/004-exchange-rate-hardening`.

### Phase 9: Pure calculation classes (design slice 9)

9a = tasks 9.1–9.4; 9b = tasks 9.5–9.8. Covers COT-008, COT-009 (steps), COT-010, COT-011 (counting), COT-012 (basis), DEC-COT-47, DEC-COT-54, DEC-COT-59, E-30 (unit), E-71 (unit), E-62 (advance unit), E-72 (basis unit). No Eloquent, no HTTP.

- [x] 9.1 RED — Create `tests/Unit/Quotations/PieceCounterTest.php`: counts valid rows of non-service goods in tiered schemes by business line; fixed-price lines, combo lines and customizations excluded (DEC-COT-07, DEC-COT-13); services counted apart by product id (DEC-COT-14); rows with errors excluded (N-2). `TierSelectorTest.php`: every tier limit (1, 6, 7, 12, 13, 49, 50, open-ended), a fixed scheme's single tier. Expected: fail. [COT-008]
- [x] 9.2 GREEN — Create `app/Support/Quotations/Calculation/LineFacts.php`, `TierFacts.php`, `PieceCounter.php`, `TierSelector.php`. Run: GREEN.
- [x] 9.3 RED — Create `tests/Unit/Quotations/AdvanceCalculatorTest.php`: `E-30` modes (on-demand and service lines at percent, stock/combo lines at 100 %, agreed part) round once HALF_UP on the sum; parts of the breakdown add up to the required advance; `proposedAgreed` rounds; `BsConverterTest.php`: E-36 values (36,00 × 875,65 = 31.523,40, rounded per amount; DEC-COT-60), null when no rate. Expected: fail. [COT-010, E-30]
- [x] 9.4 GREEN — Create `app/Support/Quotations/Calculation/AdvanceCalculator.php`, `AdvanceBreakdown.php`, `BsConverter.php` (uses `RateSnapshot`). Run: GREEN. Gate + commit 9a: `feat(004): clases puras de cálculo de cotización (conteo, tramos, anticipo y Bs) [COT-008, COT-010, E-30]`.
- [ ] 9.5 RED — Create `tests/Unit/Quotations/PackAdvisorTest.php`: `E-71` rule unit (a line passed with more than one distinct unit price is never advised; two groups with the same price count as one, DEC-COT-59), greatest saving wins, tie → more units (DEC-COT-47), pack price must be below the unit price, line quantity ≥ pack quantity, `packs_fit = intdiv`, estimated saving string. `NegotiationBasisTest.php`: `E-72` basis of combination and sorted distinct group ids (nulls dropped), `matches` ignores quantities and sizes within a group. Expected: fail. [DEC-COT-47, DEC-COT-54, DEC-COT-55, DEC-COT-59]
- [ ] 9.6 GREEN — Create `app/Support/Quotations/Calculation/PackAdvisor.php`, `PackLineFacts.php`, `PackNotice.php`, `NegotiationBasis.php`. Run: GREEN.
- [ ] 9.7 REFACTOR — Confirm `MoneyHasNoFloatTest` still passes; types check.
- [ ] 9.8 Slice-close gate (the gate). Commit 9b: `feat(004): clases puras de cálculo de packs y base de negociación [DEC-COT-47, DEC-COT-54, DEC-COT-55, DEC-COT-59, E-71, E-72]`.

### Phase 10a: Memoized loader and `CalculateQuotation` core (design slice 10, part 1)

Covers COT-009 steps 1-8, COT-011, COT-012 (requires-advisor flag), COT-025, DT-02, E-09, E-10, E-14..E-18, E-23..E-28, E-49, E-69. Produces lines, tiers, unit prices, subtotals, total and `requires_advisor`; advance, Bs, packs and endpoints arrive in Phase 10b.

- [ ] 10a.1 RED (loader) — Add to `tests/Feature/Products/SelectionQueryBoundTest.php` (or the existing DT-02 test file; create `tests/Feature/Quotations/MemoizedLoaderTest.php` if absent): `memoized()` returns a fresh instance, caches `forProducts()` per id fetching only missing ids, the default instance behavior is unchanged; `SelectionHasNoPriceOrStockDependencyTest` stays green. Expected: fail. [D1]
- [ ] 10a.2 GREEN (loader) — Edit `app/Support/Products/Selection/CatalogSnapshotLoader.php` to add `memoized()` (no string matching `pric|stock|inventor|cost`, no pricing import). Run the 003 selection suite: GREEN.
- [ ] 10a.3 RED (calculation core) — Create `tests/Feature/Quotations/CalculateQuotationTest.php` through `app(CalculateQuotation::class)->handle(...)` with factories (fictitious schemes/prices): `E-09` price per size group in a line (rows of `184` in two groups, two unit prices); `E-10` combo line `Σ(item price × qty) × combo_quantity`; `E-14..E-17` tier by business-line total, boundaries, mixed lines, fixed-price lines excluded; `E-25` services counted apart by product; `E-49` normalized selection output; `E-18` custom color requires advisor with reference price; `E-23` combos never require advisor; `E-24` customizations "sin cargo" and included customizations without price; `E-27` product without scheme → "sin precio"; `E-26` combination without price in the tier → "sin precio", no invented value, `complete = false`; `E-28` one `ResolveSelection` call per row and selection errors keyed `lines.{i}.rows.{j}.`; `E-69` inactive scheme or a product assigned to an inactive scheme → "sin precio"; DT-02 budget: (1 line × 1 row) and (5 lines × 6 rows) of one product issue the same number of queries, a second product adds a constant. Expected: fail. [listed IDs, DT-02]
- [ ] 10a.4 GREEN (calculation core) — Create `app/Support/Quotations/Calculation/CalculationInput.php`, `CalculationResult.php`, `LineResult.php`, `RowResult.php`, `PriceBook.php` (combination prices, size groups + sizes and combo item prices; candidate-pack ids are added in Phase 10b), and `app/Actions/Quotations/CalculateQuotation.php` (steps 1-5 and the subtotal/total part of step 6 of Decision 14, using the Phase 9 pure classes; `new ResolveSelection($loader->memoized())` primed with all product ids; errors returned, not thrown). Run `sail pest --group=cot --filter='CalculateQuotation'`: GREEN.
- [ ] 10a.5 REFACTOR — Confirm `SelectionHasNoPriceOrStockDependencyTest` and `MoneyHasNoFloatTest` pass; run the full 003 suite.
- [ ] 10a.6 Slice-close gate (the gate). Commit: `feat(004): calcular líneas de cotización sin escribir datos (tramos, grupos de talla, combos) [COT-009, COT-011, COT-025, E-09, E-10, E-14..E-18, E-23..E-28, E-49, E-69]`.

### Phase 10b: Advance, Bs, packs and calculate/options endpoints (design slice 10, part 2)

Covers COT-009 steps 9-11, COT-010 (in the engine), COT-022 (calculate), DEC-COT-54, E-20 (403 on calculate), E-29, E-30, E-31, E-64 (calc), E-65 (calc), E-71.

- [ ] 10b.1 Confirm the signature and the row-choice shape (customizations included) of `SelectionRules::validate($product, $choice, $palette, $restriction)` and `ComboMembership::includes` in the 003 code via CodeGraph/Read (read-only: `app/Support/Products/Selection/SelectionRules.php`, `app/Support/Products/ComboMembership.php`); record the outcome. [design follow-up]
- [ ] 10b.2 RED (advance, Bs, packs) — Extend `tests/Feature/Quotations/CalculateQuotationTest.php`: `E-29` advance by supply mode; `E-30` advance rounding once; `E-31` calculation writes nothing (row counts of every 004 table and `audit_logs` before/after, `Http::assertNothingSent()`); `E-64 (calc)` / `E-65 (calc)` no rate → `rate: null`, Bs amounts null; `E-71` end to end: a fictitious pack over a grouped combination with two group prices above the pack item price → no `pack_notice`, totals identical to a run with the pack inactive; pack admission: a pack whose component restricts an order attribute (color) to another value gives no notice, the same pack without that restriction does (single-price line); the DT-02 query budget still holds with packs (same count for 1×1 and 5×6, constant per extra product/pack). Expected: fail. [E-29, E-30, E-31, E-64, E-65, E-71]
- [ ] 10b.3 GREEN (advance, Bs, packs) — Create `app/Support/Quotations/Calculation/PackCatalog.php` (single-component active combos query + `forCombo()`), `PackAdmission.php` (axes via `ComboMembership` + full selection via `SelectionRules::validate`); extend `PriceBook.php` with candidate-pack item prices (still one query); extend `app/Actions/Quotations/CalculateQuotation.php` with steps 6-7 (advance, Bs, rate, `requires_advisor` reasons, `complete`) and step 11 (pack notices). Run `sail pest --group=cot --filter='CalculateQuotation'`: GREEN.
- [ ] 10b.4 RED (endpoints) — Create `tests/Feature/Quotations/CalculateEndpointTest.php`: `POST /quotations/calculate` returns amounts as `{value, display}` strings and the rate `{value, date, origin}`; `E-20 (calculate)` a payload with `negotiated` or `agreed_advance` from a user with `quotations.create` but without `quotations.negotiate` → 403 audited, and a user without `quotations.create` → 403; `POST /quotations/selection-options` wraps `ListSelectionOptions` and requires `calculate`; malformed structure → 422; business problems come back as errors, not 422. Expected: fail. [COT-022, E-20]
- [ ] 10b.5 GREEN (endpoints) — Create `app/Policies/QuotationPolicy.php` (all abilities of Decision 6, auto-discovered), `app/Http/Controllers/Quotations/CalculationController.php` (`calculate`, `selectionOptions`), `app/Http/Requests/Quotations/CalculateQuotationRequest.php`, `SelectionOptionsRequest.php`, routes `POST /quotations/calculate`, `POST /quotations/selection-options` (declared before any `{quotation}` route). Run: GREEN.
- [ ] 10b.6 REFACTOR — Confirm `SelectionHasNoPriceOrStockDependencyTest` (incl. `PackAdmission` outside the engine) and `MoneyHasNoFloatTest` pass; run the full 003 suite.
- [ ] 10b.7 Slice-close gate (the gate). Commit: `feat(004): anticipo, equivalente en Bs, packs y endpoints de cálculo de cotización [COT-009, COT-010, COT-022, E-20, E-29, E-30, E-31, E-64, E-65, E-71]`.

### Phase 11a: Quotation schema, number sequence and create (design slice 11, part 1)

11a-schema = tasks 11a.1, 11a.2 (commit `feat(004): esquema de cotizaciones [COT-013, COT-014]`); 11a-create = tasks 11a.3–11a.6. Covers COT-013, COT-022 (create), DEC-COT-19, DEC-COT-21, DEC-COT-45, DEC-COT-49 (recipient data), E-32, E-33 (create), E-68 (create).

- [ ] 11a.1 RED (schema) — Create `tests/Feature/Quotations/QuotationSchemaTest.php` (raw inserts): `quotations.number` unique; `quotation_sequences` seed row `last_number = 0`; CHECK recipient is a customer xor a prospect; line kind/product/combo CHECK; unique `(quotation_id, position)`; unique `(quotation_line_id, size_key)` including two `NULL` sizes colliding; `quantity >= 1`; negotiated price `> 0` and unique per group key; restrict FKs: customers, products, combos, combinations, service products cannot be deleted while referenced; children cascade with the quotation. Expected: fail. [Decision 4, COT-013, COT-014, COT-024]
- [ ] 11a.2 GREEN (schema) — Create `database/migrations/2026_10_14_000003_create_quotations_tables.php` (sequence with seed row, quotations, lines incl. negotiation basis columns, rows, customizations, components, negotiated prices), models `app/Models/Quotation.php` (`displayNumber()` → `COT-000123`, `withEffectiveStatus` scope, `search` scope, relations), `QuotationLine.php`, `QuotationLineRow.php`, `QuotationLineComponent.php`, relation additions in `app/Models/Customer.php`, factories (`QuotationFactory` states `issued()`, `expired()`, `prospect()`; line/row/component factories). Verify `migrate:rollback --step=1` and `migrate` on the local DB. Run: GREEN. Gate + commit 11a-schema.
- [ ] 11a.3 RED (number + create) — Create `tests/Feature/Quotations/QuotationDraftTest.php`: `E-32` create for an active customer assigns the next number under the sequence lock, audit `quotations.created` with number/recipient/advisor, discarded drafts do not return numbers (DEC-COT-45); advisor default = customer's advisor else creator with `customers.portfolio` else null (COT-013); explicit `advisor_id` must be an active user with `customers.portfolio` → 422 on `advisor_id`; `E-33 (create)` inactive customer → 422 on `customer_id`; `include_contact` only for a company with a contact; `E-68 (create)` prospect with name, mobile phone (stored E.164) and optional email; landline phone → 422 on `prospect_phone`; a quotation needs exactly one recipient; lines optional at create; without `quotations.create` → 403 audited. Expected: fail. [E-32, E-33, E-68, DEC-COT-45]
- [ ] 11a.4 GREEN (create) — Create `app/Support/Quotations/QuotationNumber.php` (`next()` under `FOR UPDATE`), `QuotationRules.php`, `app/Actions/Quotations/CreateQuotation.php`, `app/Http/Requests/Quotations/StoreQuotationRequest.php`, `app/Http/Controllers/Quotations/QuotationController.php` (`store`), route `POST /quotations` (static segments before `{quotation}`; redirect asserted by path, `quotations.edit` page ships in Phase 24). Run: GREEN.
- [ ] 11a.5 REFACTOR — Controllers thin; `NoManualForbiddenTest` and `ProtectedByDefaultTest` pass; types check.
- [ ] 11a.6 Slice-close gate (the gate); harness: migrate/rollback/migrate on the local DB. Commit: `feat(004): esquema de cotizaciones, número consecutivo y creación de borradores [COT-013, E-32, E-33, E-68]`.

### Phase 11b: Update draft, recipient/advisor rules, recipients endpoint (design slice 11, part 2)

Covers COT-013, COT-014, COT-022 (update), DEC-COT-29, DEC-COT-56, E-34, E-35, E-73.

- [ ] 11b.1 RED (update) — Add to `QuotationDraftTest.php`: `E-34` save with calculation errors keeps the draft and stores the calculation cache (`total`, `requires_advisor`, each line's resolved `combination_id`); `E-35` a repeated size row, quantity < 1, no rows, or a size row mixed with a no-size row → 422 on `lines.{i}.rows`; line note ≤ 500, customizations `quantity_per_garment` 1-99 default 1; `E-38`-style draft-only guard is covered in Phase 14 but a non-draft update here → rejected with the Spanish message; existing lines sent with `id` keep their row; `E-73` recipient change (prospect → customer with another advisor, or another customer) leaves `advisor_id` untouched and writes one `quotations.recipient_changed`, no `advisor_changed`; a later explicit `advisor_id` of an eligible user writes `quotations.advisor_changed`, an ineligible user → 422 on `advisor_id`; line edits and automatic negotiated-price discards are not audited; `QuotationRequiresAdvisor` is dispatched only when the stored flag changes false → true (`Event::fake`). Create `tests/Feature/Quotations/QuotationRoutesTest.php`: `GET /quotations/create` and `GET /quotations/recipients` reach their own actions, not `quotations.show`; `{quotation}` routes use `whereNumber`. Expected: fail. [E-34, E-35, E-73, DEC-COT-29, DEC-COT-56]
- [ ] 11b.2 GREEN (update + recipients) — Create `app/Actions/Quotations/UpdateQuotationDraft.php` (row lock, full replacement, stored calculation cache), `app/Support/Quotations/Recipients.php`, `app/Events/Quotations/QuotationRequiresAdvisor.php`, `app/Http/Requests/Quotations/UpdateQuotationRequest.php`, controller `update` and `recipients` (JSON active customers, `create` ability), routes `PUT /quotations/{quotation}` and `GET /quotations/recipients`. Run: GREEN.
- [ ] 11b.3 REFACTOR — Controllers thin; `NoManualForbiddenTest` and `ProtectedByDefaultTest` pass; types check.
- [ ] 11b.4 Slice-close gate (the gate). Commit: `feat(004): editar borradores de cotización y cambio de destinatario o asesora [COT-013, COT-014, E-34, E-35, E-73]`.

### Phase 12: Negotiation and agreed advance (design slice 12)

12a = `SetNegotiatedPrice`, E-19, E-20, E-21, E-22 (the corresponding parts of 12.1/12.2). 12b = `SetAgreedAdvance`, E-62, E-72 and the discard-by-basis edits (the rest of 12.1/12.2, 12.3, 12.4). Covers COT-010, COT-012, DEC-COT-10..12, DEC-COT-27, DEC-COT-40, DEC-COT-55, E-19, E-20, E-21, E-22, E-62, E-72.

- [ ] 12.1 RED — Create `tests/Feature/Quotations/NegotiationTest.php`: `E-19` 50+ uniform pieces (negotiation tier) require a negotiated price per size group; `SetNegotiatedPrice` with `quotations.negotiate` stores prices, user, date and the negotiation basis (`negotiated_combination_id`, sorted `negotiated_size_group_ids`), audits `quotations.negotiated_price_set` with strings; the line's advance goes to the agreed part; `E-20` without `quotations.negotiate` the endpoint → 403 audited and nothing stored; `E-21` a line that does not require advisor → 422 on `price` "La línea no requiere asesora."; price ≤ 0 or > 2 decimals → 422; one price per size group required; custom color: `confirmed = true` stores the reference prices as negotiated (origin `negotiated`, `negotiation_confirmed`); `E-22` quantities reduced below the threshold → negotiated price discarded with `negotiated_discarded` and the Spanish notice, other lines keep theirs, saving the draft deletes the discarded prices unaudited; `E-62` `SetAgreedAdvance` amount `0 ≤ a ≤ negotiated subtotal` (422 on `amount`), proposed value `round(percent × negotiated subtotal)` (575,00 example), agreed advance discarded when no negotiated line remains or it exceeds the new negotiated subtotal, audits `quotations.agreed_advance_set` previous/new; draft-only for both; `E-72` shirts line negotiated, change the shirts fabric (another combination, still ≥ 50) → discarded with `combination_changed`, the chemises line keeps its price; add a row in another size group → `size_groups_changed`; change only quantities or a size within the same group → kept; a stored negotiated price whose group was dropped (5b) is discarded; the basis used by `UpdateQuotationDraft`/open-recalculation comes from stored rows, never from the request payload. Edit `tests/Feature/Quotations/CalculateQuotationTest.php` to cover the live calculate echo of `negotiated.basis`. Expected: fail. [E-19..E-22, E-62, E-72, DEC-COT-55]
- [ ] 12.2 GREEN — Create `app/Actions/Quotations/SetNegotiatedPrice.php`, `SetAgreedAdvance.php` (both lock the quotation, draft-only, recalculate first), `app/Http/Requests/Quotations/NegotiatedPriceRequest.php`, `AgreedAdvanceRequest.php`, `app/Http/Controllers/Quotations/NegotiationController.php`, routes `PUT /quotations/{quotation}/lines/{line}/negotiated-price` (`scopeBindings()`) and `PUT /quotations/{quotation}/agreed-advance`; edit `app/Actions/Quotations/CalculateQuotation.php` and `UpdateQuotationDraft.php` to apply discard-by-basis and the stored-basis rule. Run: GREEN.
- [ ] 12.3 REFACTOR — Move shared basis building to one place used by calculate, update and open; types check.
- [ ] 12.4 Slice-close gate (the gate). Commit: `feat(004): precio negociado, anticipo acordado y descarte por cambio de base [COT-010, COT-012, E-19..E-22, E-62, E-72]`.

### Phase 13: Issue and freeze (design slice 13)

13a = `IssueQuotation` frozen copy, validation, concurrency, route (E-33 issue, E-36, E-37, E-65 issue, DT-03; steps 1-5 of Decision 18). 13b = migration 000004, `QuotationEmailDelivery`, recipient de-duplication into pending delivery rows, `QuotationIssued` after-commit event (the delivery and event assertions of 13.1, tasks 13.2-13.4). Covers COT-016, DT-03, DEC-COT-16, DEC-COT-45, DEC-COT-49, E-33 (issue), E-36, E-37, E-65 (issue), Decisions 18, 21 (delivery rows), 26 (`QuotationIssued`). High risk (transaction + concurrency): independent verifier before closing.

- [ ] 13.1 RED — Create `tests/Feature/Quotations/IssueQuotationTest.php`: `E-36` with `travelTo('2026-10-12 10:00', Caracas)` issue freezes line code/name/selection/tier/origin/subtotal, row unit prices and group labels, component data, `pieces_by_line`, total 36,00, advance 180,00 breakdown, rate 875.65, `total_bs` 315.234,00, `advance_bs` 157.617,00, recipient and advisor snapshots, `issued_at`, `expires_on = 2026-10-19`, audit `quotations.issued`; afterwards changing the price, scheme, parameters and adding a rate leaves the show payload and frozen columns unchanged (expect `toBeMoneyString`); `E-37` incomplete quotation (line without price, advisor-required line without negotiated/confirmed price, missing agreed advance) → 422 listing pending lines, nothing changes, status stays `draft`; `E-33 (issue)` inactive customer → error on `customer`, nothing changes; `E-65 (issue)` with no rate → issues with `rate_*`, `total_bs`, `advance_bs` null; `DT-03` two `IssueQuotation` calls on the same draft: the second (after the first commits) gets `BusinessRuleViolation`, number unchanged, one `quotations.issued` row; one `pending` delivery row per distinct recipient address (customer, contact, prospect, lower-cased, de-duplicated; none when no address); `QuotationIssued` dispatched after commit (`Event::fake`); without `quotations.create` → 403 audited. Expected: fail. [E-33, E-36, E-37, E-65, DT-03]
- [ ] 13.2 GREEN — Create `database/migrations/2026_10_14_000004_create_quotation_email_deliveries_table.php`, `app/Models/QuotationEmailDelivery.php` (+ factory), `app/Events/Quotations/QuotationIssued.php`, `app/Actions/Quotations/IssueQuotation.php` (steps 1-7 of Decision 18; jobs are dispatched in Phase 17, this phase only creates the pending rows), `app/Http/Controllers/Quotations/QuotationController.php` (`issue`), route `POST /quotations/{quotation}/issue`. Run: GREEN.
- [ ] 13.3 REFACTOR — Move frozen-copy building to a small private class if `IssueQuotation` exceeds one screen of logic; types check.
- [ ] 13.4 Slice-close gate (the gate); harness: migrate/rollback/migrate on the local DB. Commit: `feat(004): emitir y congelar la cotización con precios, tasa y destinatario [COT-016, E-33, E-36, E-37, E-65, DT-03]`.

### Phase 14: Expire, duplicate, cancel, discard (design slice 14)

14a = `QuotationStatusResolver`, `OperatingTime::today()`, `ExpireQuotation` (`handle`, `ifDue`), `quotations:expire` + schedule, effective-status scope, `QuotationExpired`, E-41. 14b = `DuplicateQuotation`, `CancelQuotation`, `DiscardQuotation`, `QuotationCancelled`, E-38, E-42, E-43, E-44. Covers COT-015, COT-017, COT-018, COT-019, DEC-COT-17, DEC-COT-18, DEC-COT-24, E-38, E-41, E-42, E-43, E-44, Decision 20.

- [ ] 14.1 RED — Create `tests/Unit/Quotations/QuotationStatusResolverTest.php` (pure: issued past `expires_on` is expired, boundaries by local date). Create `tests/Feature/Quotations/QuotationLifecycleTest.php`: `E-38` updating a non-draft → `BusinessRuleViolation` "La cotización no está en borrador; duplíquela para cambiarla."; `E-41` issued with `expires_on` today: at 23:59 local the effective status is "Emitida", at 00:00:01 the next day it is "Vencida" before the command runs; `quotations:expire` then persists `expired`, `expired_at`, audit `quotations.expired` with actor null, console origin and `context.automatic`, dispatches `QuotationExpired`; write paths (cancel) persist a due expiry before the state check; the schedule is `dailyAt('00:05')` in `America/Caracas`; `E-42` duplicate of an issued or expired quotation → new draft with new number, same recipient (inactive kept), same line input, `source_quotation_id`, no negotiated price or agreed advance, recalculated, audit `quotations.duplicated`; duplicate defaults advisor like create; `E-43` cancel only `issued` (draft → `BusinessRuleViolation`), reason 1-500 required, `cancelled_at/by`, audit `quotations.cancelled`, `QuotationCancelled`; needs `quotations.cancel`; `E-44` discard only drafts, full copy in `quotations.discarded` `old_values`, physical delete with cascade, the discarded draft stops counting as history; issued/expired/cancelled/approved have no delete path (assert route returns the rule violation). Expected: fail. [E-38, E-41..E-44]
- [ ] 14.2 GREEN — Create `app/Support/Quotations/QuotationStatusResolver.php`; edit `app/Support/Time/OperatingTime.php` to add `today()`; create `app/Actions/Quotations/ExpireQuotation.php` (`handle`, `ifDue`), `DuplicateQuotation.php`, `CancelQuotation.php`, `DiscardQuotation.php`, `app/Events/Quotations/QuotationExpired.php`, `QuotationCancelled.php`, `app/Console/Commands/ExpireQuotationsCommand.php`, `app/Http/Requests/Quotations/CancelQuotationRequest.php`, controller actions `duplicate`, `cancel`, `destroy`, routes `POST /quotations/{quotation}/duplicate`, `POST /quotations/{quotation}/cancel`, `DELETE /quotations/{quotation}`; schedule entry in `routes/console.php`; `Quotation::scopeWithEffectiveStatus` SQL expression. Run: GREEN.
- [ ] 14.3 REFACTOR — Make every issued-quotation write path call `ExpireQuotation::ifDue()` through one place; types check.
- [ ] 14.4 Slice-close gate (the gate). Commit: `feat(004): vencimiento, duplicado, anulación y descarte de cotizaciones [COT-015, COT-017, COT-018, COT-019, E-38, E-41..E-44]`.

### Phase 15: History guards and 002/003 todos (design slice 15)

Covers COT-024, CLI-010, PRD-012, PRD-014, DEC-PRD-21, DEC-COT-24, E-39, E-40, E-48, Decision 22. High risk (guards over existing 002/003 data): independent verifier before closing.

- [ ] 15.1 RED — Create `tests/Feature/Quotations/QuotationIntegrityTest.php`: `E-39` customer with any quotation (draft included) cannot be deleted ("El cliente tiene historial y no se puede eliminar. Puede desactivarlo."), a customer whose only draft was discarded can; `E-40` product, combination, combo (and a combination used only inside a combo line component, N-4) with a quotation line cannot be deleted ("Puede desactivarla"); `E-48` with history, changing a combination's `code` or axis values → 422 on `code`/`axes` "La combinación tiene historial: cree una combinación nueva y desactive la anterior."; description, restrictions and included customizations stay editable. Replace the `->todo()` in `tests/Feature/Customers/DeleteCustomerTest.php` (E-19) and `tests/Feature/Products/DeleteProductTest.php` (E-29) with real tests named `E-19 … (004 E-39)` and `E-29 … (004 E-40)` creating a quotation. Delete audits (`products.combination_deleted`, `products.deleted`, `products.combo_deleted`) include `prices`/`item_prices` copies and the price rows go with the combination/component by cascade. Expected: fail. [E-39, E-40, E-48]
- [ ] 15.2 GREEN — Edit `app/Actions/Customers/DeleteCustomer.php`, `app/Actions/Products/DeleteProduct.php`, `DeleteCombination.php`, `DeleteCombo.php` (fill `ensureHasNoHistory`, add price copies to the audit payload) and `app/Actions/Products/UpdateCombination.php` (`ensureEditableWithoutHistory(Combination, array $data)`). Run the new tests, `sail pest tests/Feature/Customers/DeleteCustomerTest.php tests/Feature/Products`: GREEN.
- [ ] 15.3 REFACTOR — Share the "has quotation history" query between the guards; types check.
- [ ] 15.4 Slice-close gate (the gate). Commit: `feat(004): bloquear el borrado y la edición de identidad con historial de cotizaciones [COT-024, E-39, E-40, E-48]`.

### Phase 16: Quotation list, search, detail and customer history (design slice 16)

16a = `index`, `QuotationPresenter`, `Index.vue`, types, E-45, E-68 (list). 16b = `show`, `Show.vue`, customer history prop and section with the Decision 22 dialog copy, E-46. Covers COT-020, DEC-COT-28, E-45, E-46, E-68 (list), Decision 27 (backend + `Index`/`Show` pages).

- [ ] 16.1 RED — Create `tests/Feature/Quotations/QuotationListTest.php`: `E-45` tabs Borradores / Emitidas / Vencidas / Anuladas / Aprobadas / Todas with counts by effective status (an issued quotation past expiry counts as expired before the command runs); search `q` by `123`, `000123`, `COT-000123`, customer name/document, prospect name/phone (`PhoneNumber::searchFragment()`); filters `advisor_id`, `mine`, `from`/`to` (issue date for non-drafts, creation date for drafts, local days), `requires_advisor`, `prospect`; paginate 15; row indicators; `E-46` customer show includes `quotations` (all states, prospects never) only when the user can `viewAny`; `E-68 (list)` prospect appears with the "Prospecto" indicator and searchable by phone; the Show payload for issued quotations reads frozen columns only, for drafts recalculates, includes deliveries, the "sin correo" flag and `can {...}` computed from permission and state; GET pages render for `quotations.view` and 403 otherwise. Expected: fail. [E-45, E-46, E-68, COT-020]
- [ ] 16.2 GREEN — Edit `app/Http/Controllers/Quotations/QuotationController.php` (`index`, `show`), create `app/Support/Quotations/QuotationPresenter.php`, edit `app/Http/Controllers/Customers/CustomerController.php` (`quotations` prop) and `resources/js/pages/customers/Show.vue` (history section at the existing anchor; deactivation `ConfirmDialog` copy per Decision 22), create `resources/js/pages/quotations/Index.vue`, `resources/js/pages/quotations/Show.vue`, `resources/js/types/quotations.ts`, routes `GET /quotations` and `GET /quotations/{quotation}`. Run: GREEN.
- [ ] 16.3 REFACTOR — Check the pages against the design-system checklist (`docs/ui/design-system.md` §11) at 375/768/1280 px; `sail pnpm check`.
- [ ] 16.4 Slice-close gate (the gate) plus browser check of `GET /quotations`. Commit: `feat(004): listado, búsqueda y detalle de cotizaciones e historial en el cliente [COT-020, E-45, E-46, E-68]`.

### Phase 17: Print view, email, company data, resend (design slice 17)

17a = `config/company.php`, `DocumentTheme`, document partial, print view and route, design-system theme note, E-50 and the XSS escaping test for print. 17b = `QuotationMail`, `SendQuotationEmail`, `QuotationEmailFailed`, `ResendQuotation`, dispatch after commit from `IssueQuotation`, `queue` service, `.env.example` mail/queue entries, E-66, E-67, E-68 (email). Covers COT-021, COT-031, DEC-COT-20, DEC-COT-39, DEC-COT-41, DEC-COT-42, DEC-COT-46, DEC-COT-50, E-50, E-66, E-67, E-68 (email), Decisions 21, 26 (`QuotationEmailFailed`), 29 (queue).

- [ ] 17.1 RED — Create `tests/Feature/Quotations/QuotationDocumentTest.php`: `E-50` `GET /quotations/{quotation}/print` for an issued quotation renders the COT-021 content from frozen columns (number, dates, recipient/advisor snapshots, lines, "sin cargo", quantities per size, USD amounts, Bs marked "referencial", "Precios en USD con IVA incluido", no costs/tiers/advance breakdown); a draft → rule violation "Un borrador no tiene vista imprimible."; requires `quotations.view`; XSS: `<script>` in a line note and prospect name is escaped in print and email; `E-66` issue dispatches one `SendQuotationEmail` per delivery after commit (`Queue::fake`); the job sends one Mailable per recipient (`Mail::fake`) and marks `sent`; with a mailer that throws, `failed()` marks the delivery `failed` with a sanitized error ≤ 255 chars without credentials, the quotation stays `issued` and `QuotationEmailFailed` is dispatched; `tries = 3`, `backoff = [60, 300]`; `E-67` no address → no delivery row and the detail shows "sin correo: entréguela por otro medio"; `E-68 (email)` prospect email receives it, lower-cased and de-duplicated with customer/contact addresses; resend: only `issued` (after `ifDue()`), current addresses, new batch, `kind = resend`, needs `quotations.create`; subject "Cotización COT-000123 — {company}", logo embedded inline, no attachments, no links to system pages. Expected: fail. [E-50, E-66, E-67, E-68]
- [ ] 17.2 GREEN — Create `config/company.php` (reads `COMPANY_*`), `app/Support/Quotations/DocumentTheme.php`, `resources/views/quotations/partials/document.blade.php`, `resources/views/quotations/print.blade.php`, `resources/views/mail/quotation.blade.php`, `app/Mail/QuotationMail.php`, `app/Jobs/SendQuotationEmail.php`, `app/Events/Quotations/QuotationEmailFailed.php`, `app/Actions/Quotations/ResendQuotation.php`, `app/Http/Controllers/Quotations/QuotationPrintController.php`, controller action `resend`, routes `GET /quotations/{quotation}/print` and `POST /quotations/{quotation}/resend`; edit `app/Actions/Quotations/IssueQuotation.php` to dispatch the jobs after commit; edit `.env.example` (company, mail, `QUEUE_CONNECTION=database`). Run: GREEN.
- [ ] 17.3 GREEN (infra) — Edit `compose.yaml` to add the `queue` service (`php artisan queue:work --tries=3 --backoff=60`, `depends_on: mysql, mailpit`) and edit `docs/ui/design-system.md` to document the document theme map as the single hex-color exception. [Decision 21, 29]
- [ ] 17.4 REFACTOR — Check the print view at 375/768/1280 px and `@media print`; types check.
- [ ] 17.5 Slice-close gate (the gate); harness: `sail up -d`, issue a fictitious quotation, confirm the message in Mailpit (no real addresses). Commit: `feat(004): vista imprimible, correo por destinatario y reenvío de cotizaciones [COT-021, COT-031, E-50, E-66, E-67]`.

### Phase 18: CSV reader/writer, template and export (design slice 18)

18a = `CsvReader::readDocument`, `CsvDocument`, `CsvWriter` and their unit tests (the reader/writer half of 18.1/18.2). 18b = `PriceTemplate`, `ExportPrices`, export endpoint, E-51, E-60 export half (the rest of 18.1/18.2, 18.3, 18.4). Covers COT-026, DEC-COT-31, DT-04 (part 1), E-51, E-60 (export half), Decision 23.

- [ ] 18.1 RED — Create `tests/Unit/Pricing/CsvWriterTest.php` (BOM, `;`, CRLF, quoting of `;`/`"`/CR/LF) and `tests/Unit/Pricing/CsvReaderDocumentTest.php` (`readDocument` returns delimiter, records with physical line, BOM/UTF-8 handling, delimiter detection on the second line counting `;` and `,` outside quotes with `;` on a tie, size and row limits (2 MB, 5,000 rows), non-UTF-8 rejected); assert the existing `CsvReader::read()` tests of 002/003 are untouched. Create `tests/Feature/Pricing/PriceExportTest.php`: `E-51` export of the scheme "Uniformes" has the scope row `ecolekua;precios-esquema;Uniformes`, header `codigo;grupo_talla;producto;nombre;estado;Detal;7–12;13–49` with no negotiation column, `184` in two rows (one per group), the missing `110-1` 7-12 cell empty, decimal comma; filters category/product/status; fixed scheme has one `precio` column; combos export one row per component (`codigo_combo;componente;producto;cantidad;precio_item`, `E-60` export half: the pañal at 30,00); formula injection: reference cells starting with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'` and identity cells are never altered; filename `precios-<slug>-<YYYY-MM-DD>.csv`; not audited; requires `pricing.view` (403 otherwise); round trip: exported file is re-readable by `readDocument`. Expected: fail. [E-51, E-60, DEC-COT-31]
- [ ] 18.2 GREEN — Edit `app/Support/Csv/CsvReader.php` (add `readDocument`, share private BOM/UTF-8/multiline code, keep `read()` unchanged); create `app/Support/Csv/CsvDocument.php`, `CsvWriter.php`, `app/Support/Pricing/Import/PriceTemplate.php`, `app/Actions/Pricing/ExportPrices.php`, `app/Http/Requests/Pricing/ExportPricesRequest.php`, `app/Http/Controllers/Pricing/PriceExportController.php` (`streamDownload`, `text/csv; charset=UTF-8`), route `GET /pricing/export`. Run the new tests and the 002/003 CSV tests: GREEN.
- [ ] 18.3 REFACTOR — Types check; confirm `CustomerCsvReader`/catalog importer tests unchanged.
- [ ] 18.4 Slice-close gate (the gate). Commit: `feat(004): exportar la plantilla CSV de precios y lector de documentos [COT-026, E-51, E-60]`.

### Phase 19: Import validate and preview (design slice 19)

19a = `PriceCell`, `PriceImportPlan`, `ImportError`, `ValidationMode`, `ValidatePriceImport` and Action-level tests for E-54, E-55, E-56, E-57. 19b = migration 000005, `PriceImport`/`PriceImportChange` models and factories, `PricingPolicy` registration, `UploadPriceImport`, upload endpoint, E-52, E-59, limits and 403 tests. Covers COT-027, DEC-COT-30..DEC-COT-36, DT-04, E-52, E-54, E-55, E-56, E-57, E-59, Decision 24 (validate/upload).

- [ ] 19.1 RED — Create `tests/Unit/Pricing/PriceCellTest.php` (comma/dot decimals, no thousands separators, empty = no change, rounding HALF_UP with a warning above 2 decimals, `<= 0` rejected) and `tests/Feature/Pricing/PriceImportValidationTest.php`: `E-52` valid file yields a preview with counts new/modified/unchanged and nothing applied; `E-54` errors carry `fila`, `columna`, `motivo` (unknown code, product of another scheme, undefined group, repeated identity, price ≤ 0), whole file rejected and nothing persisted, first 200 errors shown plus the total; `E-55` wrong scope row or another scheme than chosen → whole-file rejection; `E-56` tier columns differ from the current priced tier labels → "La plantilla no corresponde a los tramos actuales del esquema; descargue una plantilla nueva."; `E-57` rounding and variation beyond ±30 % and inactive combination/combo produce warnings, not errors (DEC-COT-33, DEC-COT-34); `E-59` file with no changes → "El archivo no contiene cambios." and no import row; reference columns ignored even if edited; `;` or `,` separator accepted; oversize (> 2 MB or > 5,000 rows) and non-UTF-8 rejected; a `validated` import row stores `tiers_signature`, `expires_at = now + 24 h`, `file_name`, the changes with previous/new values, and the file is not stored; not audited at upload; `pricing.manage` required (403 otherwise). Expected: fail. [E-52, E-54..E-57, E-59]
- [ ] 19.2 GREEN — Create `database/migrations/2026_10_14_000005_create_price_imports_tables.php`, `app/Models/PriceImport.php`, `PriceImportChange.php` (+ factories), register `PriceImport` in `PricingPolicy` registration (`AppServiceProvider`), `app/Support/Pricing/Import/PriceCell.php`, `PriceImportPlan.php`, `ImportError.php`, `ValidationMode.php`, `app/Actions/Pricing/ValidatePriceImport.php`, `UploadPriceImport.php`, `app/Http/Requests/Pricing/UploadPriceImportRequest.php`, `app/Http/Controllers/Pricing/PriceImportController.php` (`store`), route `POST /pricing/imports`. Run: GREEN; verify migrate/rollback/migrate on the local DB.
- [ ] 19.3 REFACTOR — Types check; ensure the validator is the single reader shared with Phase 21 (no copy of rules).
- [ ] 19.4 Slice-close gate (the gate). Commit: `feat(004): validar y previsualizar la actualización masiva de precios por CSV [COT-027, E-52, E-54..E-57, E-59]`.

### Phase 20: Apply, discard, expiry and history (design slice 20)

20a = `ApplyPriceImport` and its endpoint, E-53, E-58, E-60 apply half, atomic apply. 20b = `DiscardPriceImport`, `ExpirePriceImports`, `pricing:expire-imports` + schedule, history index/show, E-61. Covers COT-028, COT-029, DEC-COT-35, DEC-COT-36, DT-04, E-53, E-58, E-60, E-61, Decision 24 (apply/discard/expire/history). High risk (bulk write of stored prices): independent verifier before closing 20a.

- [ ] 20.1 RED — Create `tests/Feature/Pricing/PriceImportApplyTest.php`: `E-53` apply by the same user writes every change, audited `pricing.price_created|updated` with `context.price_import_id`, import `applied` with `applied_at`, audit `pricing.import_applied` with scope, scheme, file name and summary (strings); another user → rejected; expired import is discarded as expired first; `E-58` validate, then change the price through the table endpoint, then apply → error "Los precios cambiaron desde la validación; suba el archivo de nuevo.", import `discarded` (`conflict`) with its audit committed in a separate transaction, price still 41,00; tier-signature change between validate and apply → conflict; atomic apply: a model event listener registered in the test throws on the second price write → no price changed and the import still `validated`; `E-60` combo prices round trip: export, change the pañal to 31,00, import and apply → the pañal is 31,00 and a line of 1 kit calculates 157,00; `E-61` history lists applied and discarded imports (validated ones only to their author as "pendiente") and the detail of an applied import lists changes with previous and new values; discard by the same user audited `pricing.import_discarded`; `pricing:expire-imports` (hourly schedule) discards validated imports past `expires_at` with console origin; view-only user can read history but not apply (403). Expected: fail. [E-53, E-58, E-60, E-61]
- [ ] 20.2 GREEN — Create `app/Actions/Pricing/ApplyPriceImport.php`, `DiscardPriceImport.php`, `ExpirePriceImports.php`, `app/Console/Commands/ExpirePriceImportsCommand.php`, controller actions `index`, `show`, `apply`, `discard` in `app/Http/Controllers/Pricing/PriceImportController.php`, routes `GET /pricing/imports`, `GET /pricing/imports/{import}`, `POST /pricing/imports/{import}/apply|discard`, schedule entry in `routes/console.php`. Run: GREEN.
- [ ] 20.3 REFACTOR — Share the price-write code with `SyncCombinationPrices`/`SyncComboItemPrices` only if it keeps the audit shapes identical; types check.
- [ ] 20.4 Slice-close gate (the gate). Commit: `feat(004): aplicar, descartar y consultar actualizaciones masivas de precios [COT-028, COT-029, E-53, E-58, E-60, E-61]`.

### Phase 21: Initial price load command (design slice 21)

21a = command shell, phase 0 guards, `esquemas.csv`, phase 1 collected validation and error table, E-13. 21b = phase 2 transaction through the real Actions with console origin and import context, E-12. Covers COT-007, DEC-COT-02..DEC-COT-04, E-12, E-13, Decision 25.

- [ ] 21.1 RED — Create `tests/Feature/Pricing/InitialPriceLoadTest.php` with runtime-generated fictitious CSVs in a temporary directory: `E-12` `pricing:load --schemes --prices --combo-prices --author` loads schemes per product (file 1 `producto;esquema`), prices and combo item prices in one transaction through the real Actions with `AuditOrigin::console('pricing:load')` and `auditContext {import: true, import_file, import_row}`; prices with more than 2 decimals rounded without warning; `E-13` any error in any file → nothing imported, exit code 1, table `Archivo | Fila | Columna | Motivo`, prices never printed; phase-0 guards: author must be an existing active internal user, existing prices abort the one-time load, unreadable files, row limits; a prices file naming an undefined size group fails with its row; combos are not products in file 1; a product listed twice fails. Expected: fail. [E-12, E-13, COT-007]
- [ ] 21.2 GREEN — Create `app/Actions/Pricing/LoadInitialPrices.php` (phase 1 collect, phase 2 transaction; `ValidatePriceImport` in load mode), `app/Console/Commands/LoadInitialPricesCommand.php`. Run: GREEN.
- [ ] 21.3 REFACTOR — Types check; confirm no real file or price is committed.
- [ ] 21.4 Slice-close gate (the gate); harness: run `pricing:load` against runtime-generated fictitious CSVs on a throwaway local DB (ask the user before any reset). Commit: `feat(004): comando de carga inicial de precios [COT-007, E-12, E-13]`.

### Phase 22: UI pricing 1 — schemes, parameters, rate card and history, nav (design slice 22)

Covers spec §8 (schemes, parameters, rate), Decisions 11 (history), 28.

- [ ] 22.1 RED — Create `tests/Feature/Pricing/PricingPagesTest.php` (page GET tests): `GET /pricing/schemes`, `/pricing/schemes/create`, `/pricing/schemes/{scheme}/edit` render for `pricing.view`/`manage` and 403 otherwise; `GET /pricing/settings` returns `parameters`, `rate` (current `{value, date, origin}` or null), `rates` paginated with `rates_page` (15 per page, rows `{date, value: Money(2), origin, origin_label, recorded_by, effective, superseded_at, created_at}` including superseded rows) and `can {manage}`; user without `pricing.view` gets no "Precios" navigation entry. Expected: fail. [spec §8, Decision 11]
- [ ] 22.2 GREEN — Create `app/Http/Controllers/Pricing/PriceSchemeController.php` (`index`, `create`, `edit`), `PricingSettingsController.php` (`show`), routes `GET /pricing/schemes`, `/pricing/schemes/create` (before `{scheme}`), `/pricing/schemes/{scheme}/edit`, `GET /pricing/settings`; pages `resources/js/pages/pricing/Schemes.vue`, `SchemeForm.vue`, `Settings.vue`; components `resources/js/components/pricing/TierEditor.vue`, `RateCard.vue`, `RateHistoryTable.vue`; `resources/js/types/pricing.ts` (`Money`); edit `resources/js/navigation.ts` ("Precios", `sell`, `pricing.view`, group Comercial, priority 35, `alsoActiveOn` other `/pricing` sections; "Cotizaciones" is added in Phase 24); `ConfirmDialog` for tier removal with prices (`confirm_price_removal`). Run: GREEN.
- [ ] 22.3 REFACTOR — Design-system checklist at 375/768/1280 px; tokens only, 44 px targets; `sail pnpm check`.
- [ ] 22.4 Slice-close gate (the gate) plus browser check of `/pricing/schemes` and `/pricing/settings`. Commit: `feat(004): pantallas de esquemas, parámetros y tasa de cambio con su historial [COT-001, COT-006, COT-030]`.

### Phase 23a: UI pricing 2a — product list with row selection and price table (design slice 23, part 1)

Covers spec §8, COT-002 (bulk UI), COT-003, COT-025 (marks), DEC-COT-52, Decision 28.

- [ ] 23a.1 RED — Extend `tests/Feature/Pricing/PricingPagesTest.php`: `GET /pricing/products` (product rows with the "Incompleta" marker from `MissingPrices`, no `products.view` required — DEC-COT-52), `GET /pricing/products/{product}` (per combination prices as `Money`, missing marks, scheme select, `can {manage}`), static `PUT /pricing/products/scheme` route not captured by `{product}`; `products/Show.vue` exposes a "Precios" link only with `pricing.view`. Expected: fail.
- [ ] 23a.2 GREEN — Edit `app/Http/Controllers/Pricing/ProductPriceController.php` (`index`, `show`), routes `GET /pricing/products`, `/pricing/products/{product}`; edit `resources/js/components/DataTable.vue` (optional `selectable` + `v-model:selected`, checkbox column, keyboard accessible, "Seleccionar todo en esta página") and `docs/ui/design-system.md` §7 (row selection + checklist item); create pages `resources/js/pages/pricing/Products.vue`, `ProductPrices.vue` and component `resources/js/components/pricing/PriceTable.vue`; edit `resources/js/pages/products/Show.vue` ("Precios" link). Run: GREEN.
- [ ] 23a.3 REFACTOR — Design-system checklist; `sail pnpm check`.
- [ ] 23a.4 Slice-close gate (the gate) plus manual bulk scheme assignment and price table flow. Commit: `feat(004): lista de precios de productos con selección de filas y tabla de precios [COT-002, COT-003, COT-025]`.

### Phase 23b: UI pricing 2b — size groups editor and combo prices (design slice 23, part 2)

Covers spec §8, COT-004, COT-005, E-70 (dialog), DEC-COT-44 (form ids), Decision 28.

- [ ] 23b.1 RED — Extend `tests/Feature/Pricing/PricingPagesTest.php`: `GET /pricing/products/{product}` also carries per combination `size_groups [{id, label, size_ids}]` and `ungrouped_size_ids`; `GET /pricing/combos` and `/pricing/combos/{combo}` (price per item and computed combo price `Σ price × quantity`, never stored); `combos/Show.vue` exposes the read-only price card and a "Precios" link only with `pricing.view`; the 003 combo form payload carries component `id`s. Expected: fail.
- [ ] 23b.2 GREEN — Edit `app/Http/Controllers/Pricing/ProductPriceController.php` (size group props) and `ComboPriceController.php` (`index`, `show`), routes `GET /pricing/combos`, `/pricing/combos/{combo}`; create pages `resources/js/pages/pricing/Combos.vue`, `ComboPrices.vue` and components `resources/js/components/pricing/SizeGroupEditor.vue` (calls the preview endpoint, opens `ConfirmDialog` danger variant listing "grupo · tramo · valor", sends `confirm_token`), `ComboPricesCard.vue`; edit `resources/js/pages/pricing/ProductPrices.vue` (mount the editor), `resources/js/pages/combos/Show.vue`, `resources/js/components/products/ComboForm.vue` (send component `id`s, DEC-COT-44). Run: GREEN.
- [ ] 23b.3 REFACTOR — Design-system checklist; `sail pnpm check`.
- [ ] 23b.4 Slice-close gate (the gate) plus manual size-group preview and combo price flow. Commit: `feat(004): editor de grupos de talla con confirmación y precios de combos [COT-004, COT-005, E-70]`.

### Phase 24: UI editor 1 — header, recipient, lines, selection steps (design slice 24)

Covers COT-013, spec §8, Decisions 19 (create/edit pages), 28.

- [ ] 24.1 Confirm the Inertia 3 `useHttp` API (request cancellation for the debounced calculation) against Context7 for the installed version; record the outcome. [design follow-up]
- [ ] 24.2 RED — Create `tests/Feature/Quotations/QuotationPagesTest.php`: `GET /quotations/create` and `GET /quotations/{quotation}/edit` render the Editor props (`quotation`, `calculation` recalculated on open, `rate`, `can {negotiate, issue, discard}`, `options {advisors}`) for `quotations.create` and 403 otherwise; a non-draft edit page → rule violation; "Cotizaciones" navigation entry (`request_quote`, `quotations.view`, group Comercial, priority 5) appears for `quotations.view` only; with the §4 matrix the advisor bottom bar is Inicio, Cotizaciones, Clientes, Productos (Combos to "Más"); `useNavigation.ts` and `MAX_PRIMARY = 4` unchanged. Expected: fail. [COT-013, Decision 28]
- [ ] 24.3 GREEN — Edit `app/Http/Controllers/Quotations/QuotationController.php` (`create`, `edit`), routes `GET /quotations/create` (before `{quotation}`) and `GET /quotations/{quotation}/edit`; create `resources/js/pages/quotations/Editor.vue`, components `resources/js/components/quotations/RecipientPicker.vue`, `LineCard.vue`, `SelectionSteps.vue` (steps from `POST /quotations/selection-options`, auto-applied attributes fixed, DEC-PRD-94); edit `resources/js/navigation.ts` ("Cotizaciones"). Run: GREEN.
- [ ] 24.4 REFACTOR — Mobile-first check at 375 px; `sail pnpm check`.
- [ ] 24.5 Slice-close gate (the gate) plus manual create-draft flow on a phone viewport. Commit: `feat(004): editor de cotización (destinatario, líneas y pasos de selección) [COT-013]`.

### Phase 25: UI editor 2 — sizes, customizations, combos, summary, packs (design slice 25)

Covers COT-014, COT-011, COT-009 (display), DEC-COT-08, Decision 28. UI-only; backend behavior is proven in Phases 10a-12.

- [ ] 25.1 RED — Extend `tests/Feature/Quotations/QuotationPagesTest.php` with a contract test that the Editor props carry per-line `rows`, `customizations`, `components` and combo quantity from the stored draft, and the calculation result keys the components consume (`tier_label`, `price_origin`, `pack_notice`, `pieces_by_line`, `total`, `required_advance`, `total_bs`, `advance_bs`, `rate`, `complete`). Expected: fail where props are missing.
- [ ] 25.2 GREEN — Create `resources/js/components/quotations/SizeQuantities.vue` (`inputmode="numeric"`, one row per size), `CustomizationList.vue` ("sin cargo", quantity per garment), `ComboComponents.vue`, `QuotationSummary.vue` (sticky at the bottom on mobile, lateral at `lg:`, shows only what `POST /quotations/calculate` returns, debounced 400 ms via `useHttp` with cancellation, pack notice, "sin tasa"); extend `LineCard.vue` and `Editor.vue`; extend `resources/js/types/quotations.ts`. No frontend arithmetic on amounts (strings only). Run: GREEN.
- [ ] 25.3 REFACTOR — Design-system checklist at 375/768/1280 px; `sail pnpm check`.
- [ ] 25.4 Slice-close gate (the gate) plus manual editor flow with the sticky summary. Commit: `feat(004): cantidades por talla, personalizaciones, combos y resumen en el editor [COT-014, COT-011]`.

### Phase 26: UI editor 3 — negotiation, issue/discard, list and detail pages (design slice 26)

Covers COT-012, COT-015, COT-016, COT-018, COT-019, COT-020, COT-031 (UI), Decision 28.

- [ ] 26.1 RED — Extend `tests/Feature/Quotations/QuotationPagesTest.php`: Editor and Show payloads include `negotiated {prices, confirmed, basis}` from the stored line, the proposed agreed advance and `agreed_advance_discarded`; Show `can {update, issue, duplicate, cancel, discard, resend, negotiate, print}` follows permission and state; deliveries and the "sin correo" notice render for issued quotations. Expected: fail where props are missing.
- [ ] 26.2 GREEN — Create `resources/js/components/quotations/NegotiationPanel.vue` (visible only with `can.negotiate`; "Confirmar precio de referencia"; "Anticipo acordado" with the proposed value), `DeliveryList.vue`; complete `resources/js/pages/quotations/Index.vue` and `Show.vue` actions (Guardar borrador, Emitir, Descartar/Anular with `ConfirmDialog` danger, Duplicar, Reenviar, Imprimir); edit `resources/js/pages/quotations/Editor.vue`. Run: GREEN.
- [ ] 26.3 REFACTOR — Design-system checklist; `sail pnpm check`.
- [ ] 26.4 Slice-close gate (the gate) plus manual issue/negotiate/cancel flow. Commit: `feat(004): negociación, emisión, anulación y descarte desde la interfaz [COT-012, COT-015, COT-016, COT-019]`.

### Phase 27: UI imports — export dialog, upload, errors, preview, history (design slice 27)

Covers COT-026..COT-029 (UI), E-61 (history UI), Decision 28.

- [ ] 27.1 RED — Extend `tests/Feature/Pricing/PricingPagesTest.php`: `GET /pricing/imports` (history with "pendiente" only for the author) and `GET /pricing/imports/{import}` (changes with previous/new values, warnings) render for `pricing.view` and 403 otherwise; apply/discard buttons only for the author with `can.manage`; the upload error list is capped at 200 with the total. Expected: fail.
- [ ] 27.2 GREEN — Create `resources/js/pages/pricing/Imports.vue`, `ImportShow.vue`, components `resources/js/components/pricing/ImportErrors.vue`, `ImportPreview.vue`, an export dialog (scope, scheme, filters) on the pricing pages; add routes' page actions in `app/Http/Controllers/Pricing/PriceImportController.php`. Run: GREEN.
- [ ] 27.3 REFACTOR — Design-system checklist; `sail pnpm check`.
- [ ] 27.4 Slice-close gate (the gate) plus manual upload/preview/apply flow with a fictitious CSV. Commit: `feat(004): pantallas de exportación, carga, vista previa e historial de importaciones [COT-026..COT-029]`.

### Phase 28: Close-out (design slice 28)

Covers spec §12, Definition of Done (AGENTS.md §12), COT-023 coverage, Decision 3 (JSON money). Adapted to ODD: the coverage table and the evidence live in this document (§6), not in openspec; there is no `/sdd-verify` or `/sdd-archive`.

- [ ] 28.1 RED — Create `tests/Feature/Architecture/MoneyJsonHasNoFloatTest.php`: run a representative flow (prices, size-group change with deletion, combo price, manual rate, negotiated price, agreed advance, issue, discard, import apply), decode every 004 JSON column (`quotations.advance_breakdown`, `pieces_by_line`, snapshots, `price_imports.summary`, `price_import_changes.warnings`) and every `audit_logs` row whose action is a 004 case, walk the arrays recursively asserting `! is_float($value)`. Create `tests/Feature/Pricing/AuditCoverageTest.php` asserting each of the 26 new `AuditAction` cases is written by at least one tested flow. Expected: fail where a money value is serialized as a number or a case is unused.
- [ ] 28.2 GREEN — Fix any `Money::toStorage()` omission found and any unused or mislabeled audit case. Run both tests: GREEN.
- [ ] 28.3 Coverage audit — For each scenario E-01..E-73 and DEC-COT-01..DEC-COT-60, confirm a test whose name starts with its ID exists (`sail pest --group=cot --filter='E-<n> '`); record gaps and add missing tests. Update the coverage table in §6 with the observed test names.
- [ ] 28.4 Docs — Edit `AGENTS.md` only with the user's approval (queue/scheduler note if missing; 004 conventions), `docs/ui/design-system.md` (row selection and document theme if not complete), and the spec's decision/status sections only if the user asks. Record production notes (queue supervisor, `schedule:run` cron, SMTP provider, first manual rate, go-live price-load steps from design Migration / Rollout).
- [ ] 28.5 A11y and responsive pass — design-system §11 checklist at 375/768/1280 px for every 004 page and the print view; Mailpit check of the quotation email.
- [ ] 28.6 DoD — Tick the AGENTS.md §12 list: all scenarios green, `sail artisan test`, `sail pint --test`, `sail pnpm build`, this document complete; independent verifier over the whole feature document; then AGENTS.md §4.3 gate 3 (human confirmation).
- [ ] 28.7 Slice-close gate (the gate). Commit: `docs(004): cierre de la spec con auditoría de cobertura y notas de despliegue [COT-023]`.

## 6. Evidencia

### Fases 0–8

La evidencia RED/GREEN y los resultados de la puerta de las fases 0–8 están en `openspec/changes/004-pricing-and-quotations/apply-progress.md` (histórico, congelado).

### Cobertura de escenarios (E-xx → tarea)

| Escenario | Tarea(s)      | Escenario | Tarea(s)                   |
| --------- | ------------- | --------- | -------------------------- |
| E-01      | 3.3 ✔         | E-38      | 14.1                       |
| E-02      | 3.1, 3.3 ✔    | E-39      | 15.1                       |
| E-03      | 3.3 ✔         | E-40      | 15.1                       |
| E-04      | 4.1 ✔         | E-41      | 14.1                       |
| E-05      | 5a.1 ✔        | E-42      | 14.1                       |
| E-06      | 5a.1 ✔        | E-43      | 14.1                       |
| E-07      | 5a.1 ✔        | E-44      | 14.1                       |
| E-08      | 5b.3 ✔        | E-45      | 16.1                       |
| E-09      | 10a.3         | E-46      | 16.1                       |
| E-10      | 10a.3         | E-47      | 1.7 ✔                      |
| E-11      | 7.1 ✔         | E-48      | 15.1                       |
| E-12      | 21.1          | E-49      | 10a.3                      |
| E-13      | 21.1          | E-50      | 17.1                       |
| E-14      | 10a.3         | E-51      | 18.1                       |
| E-15      | 10a.3         | E-52      | 19.1                       |
| E-16      | 10a.3         | E-53      | 20.1                       |
| E-17      | 10a.3         | E-54      | 19.1                       |
| E-18      | 10a.3         | E-55      | 19.1                       |
| E-19      | 12.1          | E-56      | 19.1                       |
| E-20      | 10b.4, 12.1   | E-57      | 19.1                       |
| E-21      | 12.1          | E-58      | 20.1                       |
| E-22      | 12.1          | E-59      | 19.1                       |
| E-23      | 10a.3         | E-60      | 18.1, 20.1                 |
| E-24      | 10a.3         | E-61      | 20.1                       |
| E-25      | 10a.3         | E-62      | 9.3, 12.1                  |
| E-26      | 5a.3 ✔, 10a.3 | E-63      | 8.4 ✔                      |
| E-27      | 10a.3         | E-64      | 8.4 ✔, 10b.2 (calc)        |
| E-28      | 10a.3         | E-65      | 10b.2 (calc), 13.1 (issue) |
| E-29      | 10b.2         | E-66      | 17.1                       |
| E-30      | 9.3, 10b.2    | E-67      | 17.1                       |
| E-31      | 10b.2         | E-68      | 11a.3, 16.1, 17.1          |
| E-32      | 11a.3         | E-69      | 10a.3                      |
| E-33      | 11a.3, 13.1   | E-70      | 5b.3 ✔, 23b.2 (dialog)     |
| E-34      | 11b.1         | E-71      | 9.5 (unit), 10b.2          |
| E-35      | 11b.1         | E-72      | 9.5 (basis), 12.1          |
| E-36      | 13.1          | E-73      | 11b.1                      |
| E-37      | 13.1          |           |                            |

✔ = test en verde en una fase cerrada. Resultado: 73 de 73 escenarios asignados a una tarea.

### Fases 9–28

Por cada fase cerrada: RED observado, GREEN, resultado de la puerta, nivel de `gentle-ai review assess` y resultado de la revisión.

#### 9a (tareas 9.1–9.4)

| Tarea   | Test                                                                                                                                         | RED observado                                                                                                          | GREEN                                             |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| 9.1/9.2 | `tests/Unit/Quotations/PieceCounterTest.php` (7), `TierSelectorTest.php` (13 con datasets)                                                   | 20 errores: `Class "App\Support\Quotations\Calculation\PieceCounter"`/`LineFacts`/`TierSelector`/`TierFacts` not found | 20/20                                             |
| 9.3/9.4 | `tests/Unit/Quotations/AdvanceCalculatorTest.php` (6: E-29, E-30 ×2, E-62 ×2, COT-010), `BsConverterTest.php` (5: E-36 ×3, DEC-COT-60, E-65) | 11 errores: `AdvanceCalculator`/`BsConverter` not found; los 20 de 9.1 siguen verdes                                   | 31/31; `MoneyHasNoFloatTest` verde (44/44 juntos) |

Notas de diseño técnico (no cambian `design.md`): `LineFacts` lleva `kind`, `productId`, `businessLine`, `isService`, `schemeType` (null = sin esquema) y `rows {quantity, valid}`. No lleva personalizaciones, así que DEC-COT-13 se cumple por construcción; el caso de punta a punta es E-24 en la 10a.3. `TierFacts::contains()` resuelve el tramo único del esquema fijo (1..∞) sin ningún caso especial. `AdvanceBreakdown.agreed` es null cuando no hay líneas negociadas.

## 7. Siguiente paso

1. Puerta 2 (AGENTS.md §4.3): el humano aprueba este documento (tareas 9–28).
2. Con la aprobación: commit de este documento, rama `feat/004-pricing-and-quotations-9a-calculation-counting-advance` desde `fix/004-exchange-rate-hardening` y tarea 9.1 (RED).

## Log

- **L1 (2026-10-15, usuario):** "Hola continuemos la la 004 con ODD y gentle-ia"
- **L2 (2026-10-15, usuario):** "ya Ajusté el agents.md podes hacer los commits y seguir con la opcion B" con estas condiciones: "1. openspec/changes/004-… queda congelado: solo lectura, no se edita más. Es la fuente del diseño técnico de 004. 2. odd/tasks/004-pricing-and-quotations.md contiene: - Fuente: docs/specs/004 + openspec/changes/004/design.md (congelado). - Alcance y estado de decisiones. - Fases 0–8 cerradas: una línea por fase con su commit, sin copiar apply-progress. - Tareas 9–28 migradas desde tasks.md, citando IDs. - Sección "Cambios al diseño": si algo del diseño cambia, se anota aquí con su motivo y DEC, nunca en openspec. 3. Es una excepción solo para 004. Desde 005 el documento ODD lleva el diseño completo."
- **L3 (2026-10-15, migración):** las tareas 9–28 se copiaron de `tasks.md` sin cambiar requisitos. Adaptaciones de proceso, no de comportamiento: "Commit (requires user authorization)" pasa a "Commit" (AGENTS.md §4.4); la 9.4 cierra la 9a con su propio commit (el plan de la cadena ya separaba 9a y 9b); la 28.3 apunta a DEC-COT-60 y a §6 de este documento; la 28.6 reemplaza `/sdd-verify` y `/sdd-archive` por un verificador independiente y la puerta 3; la 28.4 ya no edita AGENTS.md sin aprobación. CD-01 registra el desvío pendiente de la tarea 2.8.
