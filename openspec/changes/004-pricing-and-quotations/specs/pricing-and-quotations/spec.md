# Pricing and Quotations Specification

> Transcribed from `docs/specs/004-pricing-and-quotations.md` (status "Lista para Design"; §4 permissions, §5 conceptual model, §6 requirements COT-001..COT-031, §7 scenarios E-01..E-73, §9 decisions DEC-COT-01..DEC-COT-59, technical decisions DT-01..DT-06). Faithful transcription: no added, removed, or reinterpreted behavior. Original IDs preserved verbatim and kept in source order (COT-030 follows COT-007; COT-031 follows COT-021). User-visible strings and example data quoted from the source stay in Spanish; amounts keep the source notation (decimal comma, e.g. `36,00`; thousands separator `.`). A scenario that cites several requirements is placed under the first cited requirement and lists the others in its `Source citation` line (same convention as the `products` capability). Scenario IDs E-xx repeat IDs of other specs; they identify scenarios of this capability only. The source's §8 (Interfaz), §10 (commitments), §11 (traceability), §12 (DoD) and Annexes A and B are not requirements of this capability; Annex A and B are data for the initial load (COT-007) and are validated with Ecolekua before it runs.

## Purpose

Defines how Ecolekua's backend becomes the single authority that calculates what a customer's request costs (tier, unit price, subtotals, total, required advance and its equivalent in bolívares) from configurable final prices in USD, and how advisors register, issue, email, print and consult multi-line quotations in the ERP for a registered customer or a prospect, ready to be converted into an order by `006`. It covers price schemes and tiers, prices per combination, size group and combo item, commercial parameters, the daily official exchange rate, the one-time console load of prices (COT-007), the single side-effect-free calculation operation (COT-009) that the portal in `005` will reuse, the quotation lifecycle, bulk price updates by CSV, permissions `quotations.*` and `pricing.*`, and audit. There is no cart: the quotation fulfills that function, with several lines, each with its own selection. The portal quoter, order conversion, payments, VAT breakdown, stock, commissions and notifications belong to later specs.

## Conceptual model (constraints on the requirements)

Describes **what** information exists, not its implementation.

```text
Price scheme                         (Uniformes, Pañales de adulto, Protector y absorbentes, Precio fijo)
 ├── name, type (tiered | fixed price), status
 └── Tier (1..n, ordered)            only in tiered schemes
      ├── label                      (Detal, 7–12, 13–49, Especial…)
      ├── minimum quantity           (the first starts at 1)
      ├── maximum quantity           (empty only in the last)
      └── type                       (with price | negotiation)   negotiation can only be the last

Product (from 003)
 └── assigned price scheme           (0..1; without a scheme it cannot be quoted)

Combination price                    per combination (code from 003) and tier
 ├── optional size group             (e.g., 28–36 / 38–44 in 184)
 └── final price in USD              (decimal, 2 decimals)

Combo item price                     per combo component (from 003)
 └── final price in USD per unit

Commercial parameters                advance percentage, validity days

Exchange rate                        (one per day)
 ├── date, value (Bs per USD)
 ├── origin                          (api | manual)
 ├── source update date              (api)
 └── registered by                   (manual)

Price import                         (bulk update by CSV, COT-027 to COT-029)
 ├── scope                           (combination prices of one scheme | combo item prices)
 ├── user, date, file name
 ├── status                          (validated | applied | discarded)
 ├── summary                         (new, modified, unchanged prices)
 └── Change (0..n)                   affected price, previous value (empty if it did not exist), new value, warnings

Quotation
 ├── number                          (global sequential, DEC-COT-21)
 ├── recipient: customer (002, with optional contact person) or prospect (name, phone, optional email)
 ├── created by, responsible advisor, origin (asesora | web)
 ├── status                          (borrador | emitida | vencida | anulada | aprobada)
 ├── issue date, expires on, cancellation reason, source quotation (if it is a duplicate)
 ├── observations
 ├── agreed advance of the negotiated lines: amount, user, date
 ├── copy at issue: pieces per business line, total, advance %, required advance,
 │                  rate (value and date), total and advance in Bs
 ├── requires advisor + reasons
 ├── Email delivery (0..n)           date, recipients, result (enviado | fallido)
 └── Line (1..n, ordered)
      ├── type (product | combo)
      ├── reference to the combination or combo, code, descriptive name (copy)
      ├── normalized selection (copy of PRD-011: values, colors with name and tone,
      │                         details, custom color with tone and note)
      ├── Quantity per size (1..n)   size + quantity; a single row without size if the product does not have it as an order attribute
      ├── Added customization (0..n)  service and quantity per garment, without price (DEC-COT-13)
      ├── Combo component (combo)    selection per component, quantity per kit, item price
      ├── applied tier, price origin (tramo | fijo | combo | negociado)
      ├── applied unit price (per size group when applicable)
      ├── negotiated price: value, user, date     (only if it requires advisor)
      ├── subtotal
      └── note
```

- Scheme types, tier types, quotation statuses, quotation origin, price origin, rate origin and email delivery result MUST be implemented as PHP Enums (constitution §21.1).
- All amounts MUST be **exact decimals**, never `float` (AGENTS §8). Prices in USD with 2 decimals (DEC-COT-01, DEC-COT-02).
- Schemes and prices are **configuration**, not a rules engine (constitution §11): there are no formulas, margins or conditions other than quantity.
- The quotation MUST store copies (name, selection, applied prices, rate) so that later changes to the catalog, prices or rate do not alter an issued quotation (commitment of `003` §10).

## Common example data (scenarios)

Authorization tests hit the backend through HTTP. **The prices and rates in the examples are fictitious**; the codes are real only as a reference of the format. Common data of the examples, unless the scenario says otherwise:

- Scheme "Uniformes": Detal 1–6 · 7–12 · 13–49 · Negociación 50+.
- Scheme "Pañales de adulto": Detal 1–19 · Especial 20+. Scheme "Protector y absorbentes": Detal 1–6 · Especial 7+.
- `110` camisa (bajo pedido): 40,00 / 36,00 / 32,00. Chemise `155-1` (bajo pedido): 20,00 / 18,00 / 16,00. Pantalón `184` (stock con mínimo): 28–36 a 25,00 / 23,00 / 21,00 y 38–44 a 28,00 / 26,00 / 24,00.
- Pañal `11` (stock con mínimo): Detal 50,00 · Especial 20,00. Absorbente `16` (stock con mínimo): Detal 40,00 · Especial 15,00. "Pack 6 absorbentes": 1 componente absorbente × 6, a 15,00 por ítem.
- Bordado pequeño `157` (servicio, esquema Uniformes): 3,00 / 2,00 / 1,50.
- Pañal infantil `001G` (stock agotable): precio fijo 3,50.
- Anticipo: 50 %. Vigencia: 7 días. Tasa vigente: 875,65 Bs por USD.

## Decisions carried by this capability

All **Confirmada** by the user on 2026-10-12, except DEC-COT-44..DEC-COT-59 (2026-10-13) and DEC-COT-60 (2026-10-14). For each decision the table keeps the topic and the confirmed resolution as written in the source; the discarded alternatives are omitted, except for DEC-COT-44..DEC-COT-56, which are transcribed in full.

| ID | Decision | Confirmed resolution |
|---|---|---|
| DEC-COT-01 | Moneda | **A con tasa**. Precios y cotizaciones en USD; se guarda la tasa oficial del día de `ve.dolarapi.com/v1/dolares/oficial` (campo `promedio`) (COT-030) |
| DEC-COT-02 | Precisión y redondeo | **A**: precios con 2 decimales, redondeados al cargarlos (mitad hacia arriba); subtotales y total exactos; anticipo y equivalentes en Bs redondeados a 2 decimales |
| DEC-COT-03 | Columna "más de 50 unidades" de uniformes | **A**: se descarta |
| DEC-COT-04 | Precio fijo de la sección "Varios" | **B**: "Precio 4" tal cual, ya es final |
| DEC-COT-05 | Umbral VIP en la línea de pañales | **A**: sin VIP al inicio; se añade por configuración |
| DEC-COT-06 | Precio de los combos | **A**: un precio fijo por ítem en cada combo; qué columna (P1–P4) usa cada kit es dato de la carga |
| DEC-COT-07 | ¿Las unidades de los combos cuentan para el tramo? | **A**: no |
| DEC-COT-08 | Sugerir packs | **B**: aviso informativo (COT-009, paso 11) |
| DEC-COT-09 | Color en la línea o por talla | **A**: en la línea |
| DEC-COT-10 | Anticipo por tipo de producto | **A**: por modo: bajo pedido y servicios, %; stock y combos, pago completo |
| DEC-COT-11 | Anticipo de las líneas negociadas | **B**: lo acuerda la asesora, como monto en USD entre 0 y el subtotal negociado, con el % vigente como propuesta |
| DEC-COT-12 | Precio de las líneas que requieren asesora | **A**: precio negociado en el ERP con `quotations.negotiate` |
| DEC-COT-13 | Personalizaciones añadidas | **C**: sin cargo siempre en uniformes. Son especificación para producción, con cantidad por prenda; los servicios solo tienen precio cuando se venden solos. Se actualiza business rules §26.2 |
| DEC-COT-14 | Tramo de un servicio vendido solo | **A**: la cantidad total de ese mismo servicio en la cotización |
| DEC-COT-15 | Vigencia de la cotización | Días naturales, configurable: **7 días** |
| DEC-COT-16 | Precios al emitir | **A**: congelados hasta el vencimiento |
| DEC-COT-17 | Estados | Borrador → Emitida → Vencida / Anulada / Aprobada. "Aprobada" reemplaza a "Convertida": el cliente aceptó y la cotización se convirtió en pedido (`006`) |
| DEC-COT-18 | Cambiar una emitida | **A**: no se edita; se duplica |
| DEC-COT-19 | Destinatario de la cotización del ERP | **B**: cliente registrado activo o prospecto con nombre, teléfono y correo opcional; `006` exige el cliente al convertir |
| DEC-COT-20 | Documento para el cliente | **A**: vista imprimible, más envío por correo (COT-031) |
| DEC-COT-21 | Numeración | **A**: correlativo global |
| DEC-COT-22 | Asignación de esquemas | **A**: por producto, con asignación en bloque |
| DEC-COT-23 | Quitar un tramo o cambiar el esquema con precios | **A**: se permite con confirmación; los precios se eliminan con auditoría |
| DEC-COT-24 | Borradores | Se descartan (eliminación con copia en la auditoría); mientras existen cuentan como historial |
| DEC-COT-25 | Permisos iniciales | Matriz de §4 |
| DEC-COT-26 | Límites iniciales | Uniformes 13–49 y negociación desde 50; pañales especial desde 20 (business rules §2.2 actualizadas por el usuario) |
| DEC-COT-27 | Descuentos manuales | **A**: no existen |
| DEC-COT-28 | Visibilidad | **A**: todas, con filtro "Mis cotizaciones" |
| DEC-COT-29 | Auditoría de los borradores | **A**: solo eventos clave |
| DEC-COT-30 | Permiso para la actualización masiva | **A**: `pricing.manage` |
| DEC-COT-31 | Formato del CSV | **A**: exporta con `;`, coma decimal y UTF-8 con BOM; importa con `;` o `,` y coma o punto decimal |
| DEC-COT-32 | Celda vacía | **A**: sin cambio |
| DEC-COT-33 | Más de 2 decimales en el archivo | **A**: se redondea con advertencia |
| DEC-COT-34 | Variación grande | **A**: advertir sobre ±30 %, sin bloquear |
| DEC-COT-35 | Precios cambiados entre validar y aplicar | **A**: se rechaza y se sube de nuevo |
| DEC-COT-36 | Guardar el archivo importado | **A**: no |
| DEC-COT-37 | Ajuste porcentual en pantalla | **A**: no; se calcula en el CSV |
| DEC-COT-38 | Falla de la API de la tasa | **A**: última tasa guardada con su fecha + registro manual con `pricing.manage`; nunca se bloquea la emisión |
| DEC-COT-39 | Equivalente en Bs | **A**: se muestra al cliente como referencia (vista imprimible y correo) |
| DEC-COT-40 | Forma del anticipo acordado | **A**: monto en USD |
| DEC-COT-41 | Envío por correo | **A**: automático al emitir, a cliente y persona de contacto (o prospecto); sin correo se emite igual con aviso; reenvío manual |
| DEC-COT-42 | Contenido del correo | **A**: cotización completa en el cuerpo (HTML) |
| DEC-COT-43 | Hora de consulta y fecha de la tasa | Consulta diaria a las 7:00 a. m. `America/Caracas`; el valor obtenido es la tasa de ese día, aunque `fechaActualizacion` de la fuente sea otra (confirmada 2026-10-12) |
| DEC-COT-44 | Precios de un combo al editar sus componentes (opciones: A) Se conserva el precio de los componentes cuyo producto no cambia, aunque cambien su cantidad u orden; un componente nuevo o con otro producto queda sin precio · B) Cualquier cambio borra todos los precios del combo · C) No se editan los componentes de un combo con precios) | **Confirmada** (2026-10-13): **A**. El combo no se puede cotizar hasta cargar los precios que falten (COT-025) |
| DEC-COT-45 | Formato del número y huecos (opciones: A) Huecos aceptados; se muestra con prefijo y ceros (`COT-000123`) y se guarda como entero · B) Huecos aceptados; entero simple · C) Sin huecos; número al emitir) | **Confirmada** (2026-10-13): **A**. Un borrador descartado no libera su número; la auditoría registra el descarte (DEC-COT-24) |
| DEC-COT-46 | Datos de Ecolekua en la vista imprimible y el correo (opciones: A) Archivo de configuración con valores de entorno; los cambia el equipo técnico · B) Pantalla de parámetros editable y auditada · C) Fijos en la plantilla) | **Confirmada** (2026-10-13): **A**. Sin pantalla, permiso ni auditoría en `004` |
| DEC-COT-47 | Varios packs aplicables a una línea (opciones: A) Un aviso por pack, ordenados por ahorro · B) Un solo aviso: el pack con mayor ahorro estimado; si empatan, el de más unidades · C) Un aviso con la mejor mezcla de packs) | **Confirmada** (2026-10-13): **B** |
| DEC-COT-48 | Grupos de talla al quitar una talla (opciones: A) La talla sale de su grupo con auditoría; un grupo vacío se elimina con sus precios y auditoría · B) Se rechaza quitar una talla que pertenece a un grupo) | **Confirmada** (2026-10-13): **A**, igual que DEC-PRD-52 B |
| DEC-COT-49 | Datos del destinatario y la asesora en una emitida (opciones: A) Se congelan al emitir; el vínculo con el cliente se mantiene · B) Se leen siempre los actuales) | **Confirmada** (2026-10-13): **A** |
| DEC-COT-50 | Correos de una cotización (opciones: A) Un solo correo con todos los destinatarios · B) Un correo por destinatario) | **Confirmada** (2026-10-13): **B**, sin repetir direcciones |
| DEC-COT-51 | Cambio de tipo de un esquema (opciones: A) El tipo se fija al crear · B) Solo sin productos asignados · C) Siempre, borrando sus precios) | **Confirmada** (2026-10-13): **A**; se usa otro esquema y COT-002 |
| DEC-COT-52 | ¿`pricing.*` exige `products.view`? (opciones: A) Sí · B) No; las pantallas de precios muestran solo código, nombre y estado del catálogo) | **Confirmada** (2026-10-13): **B** |
| DEC-COT-53 | Precios al cambiar los grupos de talla (opciones: A) Con confirmación se eliminan con auditoría (como DEC-COT-23) · B) Los grupos heredan los precios sin grupo · C) Se rechaza con precios cargados) | **Confirmada** (2026-10-13): **A**, para pasar a grupos, quitarlos y fusionar o reorganizar. El `ConfirmDialog` lista grupo, tramo y valor. Escenario E-70 |
| DEC-COT-54 | Aviso de pack en una línea con varios precios unitarios (opciones: A) Sin aviso · B) Comparar con el menor precio · C) Ahorro fila por fila) | **Confirmada** (2026-10-13): **A**. Hoy no ocurre: los combos son de pañales (DEC-PRD-43) y en pañales la talla es eje (COT-014). Escenario E-71 |
| DEC-COT-55 | Precio negociado si cambia la combinación o los grupos de una línea que sigue requiriendo asesora (opciones: A) Se descarta, con el mismo aviso que E-22 · B) Se conserva y la asesora lo revisa) | **Confirmada** (2026-10-13): **A**. Cambiar solo cantidades o tallas dentro del mismo grupo no lo descarta. Escenario E-72 |
| DEC-COT-56 | Asesora al cambiar el destinatario de un borrador (opciones: A) Se conserva; se cambia a mano · B) Se vuelve a aplicar la regla por defecto) | **Confirmada** (2026-10-13): **A**. Escenario E-73 |
| DEC-COT-57 | Convertir en negociación un tramo con precios | Se trata como quitar el tramo (DEC-COT-23) — **Confirmada** (2026-10-13) |
| DEC-COT-58 | Asignar o mover tallas entre grupos con precios | Mover una talla de un grupo a otro es reorganizar (DEC-COT-53: confirmación y borrado de los precios de los grupos afectados); solo asignar una talla que no estaba en ningún grupo conserva los precios del grupo — **Confirmada** (2026-10-13) |
| DEC-COT-59 | "Más de un precio unitario" en DEC-COT-54 | Se cuentan valores distintos: dos grupos con el mismo precio son un único precio — **Confirmada** (2026-10-13) |
| DEC-COT-60 | Decimales de la tasa de cambio | 2 decimales en todo: la API se redondea HALF_UP a 2 y la carga manual rechaza más de 2 — **Confirmada** (2026-10-14). Replaces the 4 decimals of COT-030 and DT-01 |

## Technical decisions carried by this capability

Status in the source: **Propuesta**, confirmed in `design.md`.

- DT-01: amounts in MySQL `DECIMAL` and exact arithmetic in PHP (e.g., `brick/math`, which Laravel already installs), never `float`. Rates with 2 decimals (DEC-COT-60). Approved dependency (2026-10-13): `brick/math` is declared as a direct dependency in `composer.json`, with the version that Laravel already installs (AGENTS §2).
- DT-02: COT-009 is implemented as a reusable Action (`app/Actions/Quotations/CalculateQuotation`) that uses `ResolveSelection` from `003` and does not write to the database.
- DT-03: issuing and the sequential number are assigned inside a transaction with locking, so that two simultaneous operations do not repeat a number.
- DT-04: reading and validating the price CSV is a single Action (`app/Actions/Pricing/ValidatePriceImport`) used by the initial load command (COT-007) and by the ERP import (COT-027). Reading with native PHP functions, no new dependencies. The conflict check of COT-028 compares the previous values stored in the import with the current ones, inside the transaction and with locking.
- DT-05: the rate is obtained with Laravel's HTTP client from a scheduled command (Scheduler, `dailyAt('07:00')->timezone('America/Caracas')`), with bounded timeout and retries; tests simulate the API (`Http::fake`). No new dependencies.
- DT-06: the email is a queued Mailable dispatched after commit (`afterCommit`); the printable view and the email share the same Blade view of the content. No new dependencies.

## Requirements

### Requirement: §4 — Permisos `quotations.*` y `pricing.*` y coherencia (DEC-COT-25, DEC-COT-28, DEC-COT-30)

The system MUST define the following permissions, verified in the backend; code MUST check the permission, never the role name (AGENTS §7.3):

| Permission | Allows |
|---|---|
| `quotations.view` | View quotations, their detail, their printable view and the quotation history in the customer's record |
| `quotations.create` | Create and edit drafts, issue them, resend them by email, duplicate quotations and discard drafts |
| `quotations.negotiate` | Set or confirm the price of the lines that require an advisor and agree their advance (COT-012) |
| `quotations.cancel` | Cancel issued quotations |
| `pricing.view` | View schemes, prices, combo prices, commercial parameters and rates; export the prices to CSV (COT-026) and view the import history (COT-029) |
| `pricing.manage` | Manage schemes, assign them to products, edit prices and commercial parameters, register the rate manually (COT-030), import and apply bulk updates (COT-027, COT-028) |

Initial assignment to the roles of `001` (DEC-COT-25):

| Permission | Administrador | Gerente | Asesora de Ventas | Finanzas | Supervisor de Producción | Responsable de Calidad | Operario |
|---|---|---|---|---|---|---|---|
| `quotations.view` | yes | yes | yes | yes | no | no | no |
| `quotations.create` | yes | yes | yes | no | no | no | no |
| `quotations.negotiate` | yes | yes | yes | no | no | no | no |
| `quotations.cancel` | yes | yes | yes | no | no | no | no |
| `pricing.view` | yes | yes | yes | yes | no | no | no |
| `pricing.manage` | yes | yes | no | no | no | no | no |

- **Coherence** (analogous to DEC-022, DEC-CLI-32 and DEC-PRD-23): `quotations.create`, `quotations.negotiate` and `quotations.cancel` MUST require `quotations.view`; `pricing.manage` MUST require `pricing.view`. An assignment that does not comply MUST be rejected and the role MUST NOT change. The `pricing.*` permissions do not require `products.view`: the pricing screens show only the catalog reference data (code, name and status) (DEC-COT-52).
- All users with `quotations.view` MUST see all quotations; "Mis cotizaciones" is a filter (DEC-COT-28, same as DEC-CLI-08).
- `design.md` defines how this matrix is applied to an existing database, as in `002` and `003`.

#### Scenario: E-47 — Coherencia de permisos

Source citation: (§4)

- GIVEN a user who manages roles
- WHEN the user assigns `quotations.negotiate` to a role without `quotations.view`, or `pricing.manage` to a role without `pricing.view`
- THEN the assignment is rejected and the role does not change

### Requirement: COT-001 — Esquemas de precio

A user with `pricing.manage` SHALL be able to create, edit, deactivate and reactivate price schemes.

- **Tiered**: an ordered list of contiguous tiers that starts at 1, with no gaps or overlaps. Each tier has a label, a minimum quantity and a maximum quantity; only the last one has an empty maximum (open-ended). The backend MUST reject a scheme that does not comply.
- The last tier MAY be a **negotiation** tier: it has no price and the line that falls in it requires an advisor (COT-012, business rules §2.2). No other tier can be one.
- **Fixed price**: a single price per combination, without tiers. Its units do not count toward the tier of their business line (business rules §2.1, §2.5).
- The name MUST be unique, case-insensitively.
- The type (tiered or fixed price) is set when the scheme is created and MUST NOT change. To change type, another scheme is created and the products are reassigned (COT-002) (DEC-COT-51).
- Changing limits or labels does not touch the loaded prices. Adding a tier leaves the combinations of those products without a price in that tier until it is loaded (COT-025). Removing a tier with loaded prices requires confirmation and deletes those prices, with audit of the previous values (DEC-COT-23). Turning a tier with loaded prices into a negotiation tier is treated the same as removing it (DEC-COT-57).
- A scheme assigned to any active product MUST NOT be deactivated; the backend MUST indicate the products.
- Changes do not affect issued quotations (COT-016).
- **Initial configuration** (business rules §2.2–§2.5, DEC-COT-26, DEC-COT-05):

| Esquema | Tipo | Tramos |
|---|---|---|
| Uniformes | Por tramos | Detal 1–6 · 7–12 · 13–49 · Negociación 50 en adelante |
| Pañales de adulto | Por tramos | Detal 1–19 · Especial 20 en adelante |
| Protector y absorbentes | Por tramos | Detal 1–6 · Especial 7 en adelante |
| Precio fijo | Precio fijo | — |

The adult diaper line starts without a negotiation tier. If Ecolekua requests it, it is enough to add it to the corresponding scheme; it requires no code changes.

#### Scenario: E-01 — Crear un esquema por tramos válido

Source citation: (COT-001, COT-023)

- GIVEN a user with `pricing.manage`
- WHEN the user creates the scheme "Uniformes" with the tiers 1–6, 7–12, 13–49 and 50 onwards as negotiation
- THEN the scheme is created active with its four tiers
- AND the audit is recorded with the created values

#### Scenario: E-02 — Tramos inválidos

Source citation: (COT-001)

- GIVEN a user with `pricing.manage`
- WHEN the user saves a scheme that starts at 2, or with a gap between 6 and 8, or with two tiers that include 12, or with a negotiation tier that is not the last, or with an intermediate tier without a maximum
- THEN the backend rejects the save with an error on the tiers and nothing changes

#### Scenario: E-03 — Esquema en uso no se desactiva

Source citation: (COT-001)

- GIVEN the scheme "Uniformes" assigned to the camisa corporativa, which is active
- WHEN deactivation is attempted
- THEN the backend rejects it and indicates the products that use it

### Requirement: COT-002 — Esquema de cada producto

A user with `pricing.manage` SHALL assign to each product an active scheme (DEC-COT-22). The assignment MAY be made in the product's record or in bulk from the list (several products at once).

- A product without a scheme, or with an inactive scheme, MUST NOT be quotable (COT-025).
- Changing the scheme of a product with loaded prices requires confirmation and deletes its prices, with audit of the previous values; the combinations stay without a price until loaded again (DEC-COT-23).
- Combos do not have a scheme: their price comes from their items (COT-005).

#### Scenario: E-04 — Asignar esquema a varios productos

Source citation: (COT-002)

- GIVEN a user with `pricing.manage` and three products without a scheme
- WHEN the user assigns "Uniformes" to them from the list in a single action
- THEN the three have that scheme and each assignment is audited

#### Scenario: E-27 — Producto sin esquema

Source citation: (COT-002, COT-025)

- GIVEN an active product without an assigned scheme
- WHEN a line of that product is calculated
- THEN the line returns the error "sin precio"

### Requirement: COT-003 — Precios por combinación y tramo

A user with `pricing.manage` SHALL register and edit the **final price** in USD (profit and VAT included) of each active or inactive combination in each tier with a price of its scheme, or its single price if the scheme is fixed price (business rules §2.1).

- The price MUST be a decimal greater than zero with at most 2 decimals (DEC-COT-02).
- The price is identified by the combination's **code** (`003`).
- Editing is a table per product: one row per combination and one column per tier.
- Each change MUST be audited with the previous and the new value (constitution §10).
- A price change applies to new calculations and to drafts on their next recalculation; never to issued quotations (COT-016).
- Prices are not calculated from costs or margins (business rules §2.1).

#### Scenario: E-05 — Registrar y editar precios

Source citation: (COT-003, COT-023)

- GIVEN the combination `110` without prices and a user with `pricing.manage`
- WHEN the user registers 40,00 / 36,00 / 32,00 and then changes the detal to 41,00
- THEN the prices are saved per tier
- AND the audit records the previous detal 40,00 and the new one 41,00

#### Scenario: E-06 — Precio inválido

Source citation: (COT-003)

- GIVEN a user with `pricing.manage`
- WHEN the user registers a price of 0, negative, or with more than 2 decimals
- THEN the backend rejects it with an error on the price

#### Scenario: E-07 — Precios sin permiso

Source citation: (COT-003, COT-022)

- GIVEN a user with `pricing.view` but without `pricing.manage`
- WHEN the user sends a request to change a price
- THEN the backend responds 403 and the price does not change

### Requirement: COT-004 — Precio por grupo de talla

A combination whose product has Talla as an order attribute MAY split its prices into **size groups** (DEC-PRD-06).

- Each group is a set of size values admitted by the combination and has its own price in each tier.
- If a combination has groups, each admitted size MUST belong to exactly one group. The backend MUST reject groups that overlap or that leave sizes without a group.
- Only `184` and `184-2` use it in the initial configuration (28–36 / 38–44). The customer sees the normal list of sizes; the price of each size row comes from its group.
- If a size is added to the combination or to the product and the combination has groups, the size stays without a price until it is assigned to a group (COT-025).
- If a size is removed from the product or from the combination (`003`), the size leaves its group, with a copy in the audit. If the group becomes empty, the group and its prices are deleted, with an audit of the previous values (DEC-COT-48, same as DEC-PRD-52 B).
- Moving from ungrouped prices to groups, removing the groups, or merging or reorganizing groups with loaded prices MUST require confirmation. The `ConfirmDialog` lists the prices that will be deleted (group, tier and value); on confirmation they are deleted with an audit of the previous values and the combination stays without a price until the new ones are loaded (COT-025) (DEC-COT-53, same criterion as DEC-COT-23). Moving a size from one group to another is a reorganization: the prices of both affected groups are deleted. Only assigning to a group a size that was in no group keeps that group's prices, and the size takes its price (DEC-COT-58).

#### Scenario: E-08 — Grupos de talla válidos

Source citation: (COT-004)

- GIVEN the combination `184`, which admits sizes 28 to 44
- WHEN the groups 28–36 and 38–44 are defined with their prices
- THEN the groups are saved
- AND a group that repeats 36, or that leaves 44 without a group, is rejected

#### Scenario: E-70 — Cambiar los grupos de talla de una combinación con precios

Source citation: (COT-004, DEC-COT-53)

- GIVEN the combination `184` with prices loaded without size groups and a user with `pricing.manage`
- WHEN the user defines the groups 28–36 and 38–44
- THEN the `ConfirmDialog` lists the prices that will be deleted (group, tier and value)
- AND on confirmation, those prices are deleted with an audit of the previous values, the groups are saved and the combination stays without a price until the prices of each group are loaded (COT-025)
- AND without confirmation nothing changes
- AND the same happens when removing the groups or when merging or reorganizing groups with prices

#### Scenario: E-09 — Precio por grupo de talla en una línea

Source citation: (COT-004, COT-009)

- GIVEN a line of `184` with 2 units in size 34 and 2 in size 40 (4 uniform pieces)
- WHEN it is calculated
- THEN size 34 is quoted at 25,00 and size 40 at 28,00, in the Detal tier
- AND the subtotal of the line is 106,00

### Requirement: COT-005 — Precios de los combos

A user with `pricing.manage` SHALL register the **final price per unit** of each component of a combo (business rules §2.4, commitment of `003`).

- Each combo has a single price per item, which does not depend on the quantity of combos ordered (DEC-COT-06).
- The price of the combo is the sum of the price of each item multiplied by its quantity. The system MUST NOT store a separate total.
- Packs are combos of a single component with their own price per item (DEC-PRD-13).
- A combo with any component without a price MUST NOT be quotable (COT-025).
- When editing the components of the combo (`003`), the price of each component whose product does not change is kept, even if its quantity or order changes. A new component, or one with another product, stays without a price (DEC-COT-44).
- Each change MUST be audited.

#### Scenario: E-10 — Precio del combo

Source citation: (COT-005, COT-009)

- GIVEN the "Kit Oro antiderrame" with 2 pañales at 30,00, 3 absorbentes at 20,00 and 1 protector at 35,00 per item
- WHEN a line with 2 kits is calculated
- THEN the subtotal of the line is 310,00 (155,00 per kit)

### Requirement: COT-006 — Parámetros comerciales

A user with `pricing.manage` SHALL edit:

- **Advance percentage**: integer or decimal between 0 and 100. Initial value: **50 %** (business rules §3).
- **Quotation validity** in calendar days: integer ≥ 1. Initial value: **7 days** (DEC-COT-15).

Changes MUST be audited and apply to the quotations issued afterwards.

#### Scenario: E-11 — Parámetros comerciales

Source citation: (COT-006, COT-023)

- GIVEN a user with `pricing.manage`
- WHEN the user changes the advance to 40 % and the validity to 10 days
- THEN the values are saved and audited
- AND a percentage of 120 or a validity of 0 are rejected

### Requirement: COT-007 — Carga inicial de precios

The technical team SHALL load the prices of the current list a single time through a console command, without a screen in the ERP, following the pattern of PRD-018 and CLI-018. It runs after the catalog load (PRD-018).

- **Source**: UTF-8 CSV files prepared from the sheets "Uniformes" and "Pañales para adultos" and validated by Ecolekua:
  1. schemes of each product (product → scheme);
  2. prices by code and tier, with optional size group;
  3. prices of the items of each combo.
  The columns are defined in `design.md`. The correspondence between list columns and tiers is in Annex A of the source.
- In the sections with VAT, the columns **with VAT** are loaded; in "Varios", "Precio 4" is loaded as it is, because it is already the final price (DEC-COT-04). Prices are rounded to 2 decimals when loaded (DEC-COT-02).
- The cost, the "Opciones 1/2/3" (DEC-PRD-13) and the "más de 50" column of uniforms (DEC-COT-03) are not loaded.
- Each record goes through the same validations of COT-001 to COT-005. Codes MUST exist in the catalog.
- **All or nothing**: the command first validates all rows, including codes repeated in the file. If any fails, it imports nothing and delivers a report with file, row, field and reason. If all are valid, it imports in a single transaction.
- The command requires an active internal user as author. Each record is audited as a creation marked as import.
- The file contains real Ecolekua prices: it is not versioned or stored in Engram (AGENTS §4.5).
- Before running it, Ecolekua validates the data of Annex A and corrects the inconsistencies of Annex B. They are data, not design decisions: they do not block Design or implementation.
- Files 2 and 3 use the same format and the same validations as the bulk update (COT-026, COT-027), so that there is a single price format in the system (DT-04). File 1 (schemes) is exclusive to the initial load.

#### Scenario: E-12 — Importar precios válidos

Source citation: (COT-007, COT-023)

- GIVEN the catalog loaded, the three valid CSV files and an active internal user as author
- WHEN the command is run
- THEN the scheme assignments, the prices by tier and size group and the prices of the combo items are created in a single transaction
- AND each creation is audited as an import

#### Scenario: E-13 — Importación con errores

Source citation: (COT-007)

- GIVEN a CSV with a code that does not exist in the catalog, a price with text, or a repeated code
- WHEN the command is run
- THEN nothing is imported
- AND the report indicates file, row, field and reason

### Requirement: COT-008 — Conteo de piezas para el tramo

The tier MUST be determined by the **total pieces of each business line** in the quotation (business rules §2.1):

- The quantities of the lines of **products that are not services** with a **tiered** scheme count, added up per business line (uniforms on one side, diapers on the other), even if they are different products and use different schemes.
- The lines of products with a fixed-price scheme do not count.
- Customizations added to a garment do not count (DEC-COT-13).
- The units of combos do not count (DEC-COT-07).
- **Services sold alone** (DEC-COT-14): they do not add to their business line. The tier of a service is chosen with the total quantity of **that same service** in the quotation (e.g., 20 small embroideries `157` → tier 13–49 of its scheme).
- The total of the business line applies to each product within its own scheme: with 4 diapers and 3 absorbentes 3XG/4XG (7 units of diapers), the diapers are quoted in the tier of 7 of their scheme (Detal) and the absorbentes in the tier of 7 of theirs (Especial).

#### Scenario: E-14 — Tramo por el total de la línea de negocio

Source citation: (COT-008, COT-009)

- GIVEN a quotation with 5 camisas `110`, 5 chemises `155-1` and 3 pantalones `184` size 32
- WHEN it is calculated
- THEN the uniform pieces are 13
- AND the three lines are quoted in the tier 13–49: 32,00, 16,00 and 21,00

#### Scenario: E-15 — Mismo total, esquemas distintos

Source citation: (COT-008, COT-009)

- GIVEN a quotation with 4 pañales `11` and 3 absorbentes `16`
- WHEN it is calculated
- THEN the diaper pieces are 7
- AND the pañales are quoted at Detal (50,00) and the absorbentes at Especial (15,00)

#### Scenario: E-16 — Pedido mixto

Source citation: (COT-008, COT-009)

- GIVEN a quotation with 15 camisas `110`, 10 pañales `11` and 2 pañales infantiles `001G`
- WHEN it is calculated
- THEN uniforms counts 15 pieces (tier 13–49) and diapers counts 10 (Detal)
- AND the pañales infantiles are quoted at the fixed price 3,50 and do not count toward the diaper tier

#### Scenario: E-17 — Límite de tramo

Source citation: (COT-008, COT-009)

- GIVEN a quotation with 6 camisas `110`
- WHEN a seventh unit is added
- THEN the line moves from the Detal tier (40,00) to the 7–12 tier (36,00)

#### Scenario: E-25 — Servicio vendido solo

Source citation: (COT-008, COT-009, DEC-COT-14)

- GIVEN a quotation with 10 camisas `110` and a line of 20 small embroideries `157` on garments of the customer
- WHEN it is calculated
- THEN the uniform pieces are 10 and the camisas are quoted at 7–12 (36,00)
- AND the embroideries are quoted with their own quantity, in the tier 13–49 (1,50): subtotal 30,00

#### Scenario: E-49 — Los combos no cuentan para el tramo

Source citation: (COT-008, DEC-COT-07)

- GIVEN a quotation with 1 "Kit Oro antiderrame" (6 units) and 15 pañales `11`
- WHEN it is calculated
- THEN the diaper pieces are 15 and the pañales are quoted at Detal (50,00)

### Requirement: COT-009 — Calcular una cotización

The backend SHALL offer a single operation, **without side effects**, that receives a list of lines and returns the complete calculation. It is used by the quotation editor of the ERP (COT-013) and by the portal quoter (`005`). No frontend calculates prices, tiers, advances or equivalents in Bs (AGENTS §7.1, §9).

**Input**: lines, each with a product or combo, selection (as PRD-011), quantities per size (or a quantity without size), added customizations with their quantity per garment, selection of each component in combos and, if it exists, the negotiated price of the line; and the agreed advance of the negotiated lines, if it exists.

**Process**:

1. Each line is validated with PRD-011, once for each size row. An invalid row produces an error on that line and row. Order attributes with a single possible value are applied automatically (DEC-PRD-94).
2. Pieces are counted per business line and per service (COT-008).
3. For each product line, the tier of the product's scheme that contains the total that corresponds to it is taken, or the fixed price.
4. The unit price of each size row is that of the combination resolved in that tier, or that of its size group (COT-004).
5. The added customizations and those included in the combination **have no price** (COT-011).
6. A combo is quoted with the price of its items (COT-005): subtotal = Σ (item price × component quantity) × quantity of combos.
7. If the tier of a line is a negotiation tier, or the selection has a custom color (PRD-011, "requires advisor" indicator), the line **requires an advisor** (COT-012).
8. Subtotal of the line = Σ (unit price × quantity of each row). Total = Σ subtotals of the lines with a price.
9. Required advance according to COT-010, rounded to 2 decimals.
10. Equivalent in Bs of the total and the advance with the current rate (COT-030), rounded to 2 decimals. If there is no saved rate at all, "sin tasa" is reported and it is not calculated.
11. **Pack notice** (DEC-COT-08): for each product line, if there is an active single-component combo (pack) whose component admits the combination and the selection of the line (DEC-PRD-42), the quantity of the line reaches at least the quantity of the pack and the price per item of the pack is lower than the unit price applied to the line, the calculation returns an **informational notice** with the pack, how many packs fit and the estimated saving. If several packs apply, a single notice is returned: the one for the pack with the greatest estimated saving and, on a tie, the one with more units (DEC-COT-47). A line with more than one unit price (size groups) has no pack notice (DEC-COT-54); distinct values are counted, so two groups with the same price count as one (DEC-COT-59). The line does not change; the advisor decides whether to replace it.

**Output**:

- per line: code, descriptive name, applied tier (label), price origin, unit price per size row, subtotal, customizations (no charge), "requires advisor" indicator with its reason, pack notices and errors per field;
- per quotation: pieces per business line, total, advance percentage, required advance with its breakdown (automatic, stock, agreed), current rate with its date and origin, total and advance in Bs, "requires advisor" indicator with the list of reasons and an indicator of whether the quotation is complete (without errors and without lines pending a price).

The operation MUST NOT query stock (`008`), MUST NOT call the rate API and MUST NOT save anything.

#### Scenario: E-18 — Tramo de negociación

Source citation: (COT-009, COT-012)

- GIVEN a quotation with 30 camisas `110` and 20 chemises `155-1` (50 uniform pieces)
- WHEN it is calculated
- THEN both lines are left without an automatic price, marked "requiere asesora" with the reason "50 piezas de uniformes o más"
- AND the quotation indicates that it requires an advisor
- AND with 49 pieces, the lines are quoted in the tier 13–49

#### Scenario: E-23 — Color personalizado

Source citation: (COT-009, COT-012)

- GIVEN a line of 10 camisas `110` with color Personalizado
- WHEN it is calculated
- THEN the line shows 36,00 as a reference price and is "requiere asesora" with the reason "color personalizado"
- AND the quotation cannot be issued until a user with `quotations.negotiate` confirms that price or registers another one and agrees the advance

#### Scenario: E-24 — Personalización añadida sin cargo

Source citation: (COT-009, COT-011)

- GIVEN a line of 10 camisas `110` with small embroidery, 2 per garment
- WHEN it is calculated
- THEN the uniform pieces are 10 (the embroidery does not count)
- AND the embroidery appears with no charge with its quantity per garment, and the subtotal of the line is 360,00
- AND in the combination `139-1`, which includes vinyl, the vinyl is not charged either

#### Scenario: E-28 — Selección inválida en una fila de talla

Source citation: (COT-009, PRD-011)

- GIVEN a line of `110-1` (dama) whose combination restricts sizes to XS–XL
- WHEN it is calculated with 3 units in M and 2 in 2XL
- THEN the 2XL row returns an error on the size and the M row is calculated

#### Scenario: E-29 — Anticipo según el modo

Source citation: (COT-009, COT-010)

- GIVEN a quotation with 5 camisas `110` (bajo pedido), 2 pantalones `184` size 32 (stock con mínimo) and 2 pañales infantiles `001G` (stock agotable)
- WHEN it is calculated
- THEN uniforms counts 7 pieces (tier 7–12): camisas 180,00 and pantalones 46,00; pañales infantiles 7,00; total 233,00
- AND the required advance is 143,00: 90,00 (50 % of the camisas) + 46,00 + 7,00 (stock products are paid in full)

#### Scenario: E-31 — El cálculo no guarda nada

Source citation: (COT-009)

- GIVEN a user with `quotations.create`
- WHEN the user invokes the calculation with three lines
- THEN the user receives the complete result
- AND no quotation is created or modified and the rate API is not queried

#### Scenario: E-65 — Sin ninguna tasa guardada

Source citation: (COT-009, COT-016, COT-030)

- GIVEN a system without saved rates and a complete draft
- WHEN it is calculated and issued
- THEN the calculation reports "sin tasa" and the quotation is issued without an equivalent in Bs

#### Scenario: E-69 — Aviso de pack

Source citation: (COT-009, DEC-COT-08)

- GIVEN the active "Pack 6 absorbentes", at 15,00 per item
- WHEN a quotation with only 6 absorbentes `16` (Detal, 40,00) is calculated
- THEN the line stays at 240,00 and the calculation returns a notice with the pack, 1 possible pack and an estimated saving of 150,00
- AND with 5 absorbentes there is no notice

#### Scenario: E-71 — Sin aviso de pack con varios precios unitarios

Source citation: (COT-009, DEC-COT-54)

- GIVEN an active pack whose component admits the combination of a line, and that combination has size groups with different prices
- WHEN a quotation is calculated with that line in a quantity enough for the pack and a pack price per item lower than every unit price of the line
- THEN the calculation returns no pack notice for that line
- AND the line is calculated the same as without packs

### Requirement: COT-010 — Anticipo

The required advance depends on the supply mode of the product and on whether the line is negotiated (business rules §3, DEC-COT-10, DEC-COT-11):

| Líneas | Anticipo |
|---|---|
| Productos **bajo pedido** y **servicios**, con precio automático | Porcentaje vigente (COT-006) × subtotal |
| Productos de **stock con mínimo** y **stock agotable**, y **combos**, con precio automático | 100 % del subtotal: se pagan completos |
| Líneas con **precio negociado o confirmado** (COT-012), de cualquier modo | **Anticipo acordado** por la asesora |

- **Agreed advance**: a user with `quotations.negotiate` registers a single amount in USD for the set of negotiated lines of the quotation, between 0 and its subtotal. The system proposes the current percentage × negotiated subtotal; the advisor MAY change it. It is saved with user and date and is audited. If the negotiated lines change and the amount exceeds the new negotiated subtotal, the agreed advance is discarded and proposed again.
- A quotation with negotiated lines MUST NOT be issued without an agreed advance.
- Required advance = sum of the three parts.
- In `004` the advance is a **value of the quotation**. Its registration as a transaction, payments and balance belong to `006`.

#### Scenario: E-30 — Anticipo con redondeo

Source citation: (COT-010, DEC-COT-02)

- GIVEN an advance of 50 % and made-to-order (bajo pedido) lines for 100,01
- WHEN it is calculated
- THEN the required advance is 50,01 (rounding up at the half)

#### Scenario: E-62 — Anticipo acordado de las líneas negociadas

Source citation: (COT-010, COT-012, COT-023)

- GIVEN the quotation of E-19 (negotiated lines for 1.150,00) and a user with `quotations.negotiate`
- WHEN the user consults the proposed advance and registers 400,00
- THEN the system proposed 575,00 (50 %), and the required advance becomes 400,00
- AND the agreed advance saves user and date and is audited, and the quotation can be issued
- AND a negative amount or one greater than 1.150,00 is rejected

### Requirement: COT-011 — Personalizaciones en una línea

- A product line MAY carry added customizations among those admitted by the product (PRD-008), validated by PRD-011. Products of the diaper line do not admit customizations (DEC-PRD-98).
- Added customizations are **not charged** (DEC-COT-13, business rules §26.2): they are a specification for production. Those included in the combination are not charged either (PRD-008).
- Each added customization has a **quantity per garment** (integer ≥ 1, default 1) and applies to all the units of the line. If only some garments carry it, they are quoted in separate lines.
- The location and design of the customization are described in the note of the line; the design file belongs to `006`.
- A service sold alone, as its own line, does have a price according to its scheme (COT-008).

Scenario coverage: E-24 (placed under COT-009, which cites COT-011).

### Requirement: COT-012 — Líneas que requieren asesora y precio negociado

A line requires an advisor when it falls in a negotiation tier (50 pieces of uniforms or more in the initial configuration) or has a custom color (business rules §2.2, §26.1). Combos never admit a custom color (DEC-PRD-87).

- **Negotiation tier**: the line has no automatic price. A user with `quotations.negotiate` registers a **negotiated unit price** for the line (and for each size group if the combination has them).
- **Custom color**: the line shows the price of the tier as a reference. A user with `quotations.negotiate` confirms that price or registers a negotiated one, because the advisor agrees fabric, price and delivery with the customer (§26.1).
- Negotiated lines have an agreed advance (COT-010).
- A quotation with any line that requires an advisor without a negotiated or confirmed price, or without an agreed advance, MUST NOT be issued.
- The negotiated price saves the value, the user and the date, and is audited.
- If a change in the quotation makes the line stop requiring an advisor (e.g., pieces drop from 50 to 40), the negotiated price is discarded and the line goes back to the automatic price. The calculation indicates it and the editor warns about it.
- If the line still requires an advisor but its resolved combination or its set of size groups changes, the negotiated price is also discarded, with the same notice. Changing only the quantities, or sizes within the same group, does not discard it. The agreed advance follows the rule of COT-010 (DEC-COT-55).
- A negotiated price sent for a line that does not require an advisor MUST be rejected.
- There is no other manual price adjustment (DEC-COT-27).
- In the portal (`005`), a quotation that requires an advisor is not processed and refers the customer to WhatsApp.

#### Scenario: E-19 — Precio negociado

Source citation: (COT-012, COT-023)

- GIVEN the quotation of E-18 in draft and a user with `quotations.negotiate`
- WHEN the user registers 29,00 on the camisas line and 14,00 on the chemises line
- THEN the subtotals are 870,00 and 280,00
- AND each negotiated price saves user and date and is audited
- AND the quotation still cannot be issued because the agreed advance is missing (E-62)

#### Scenario: E-20 — Negociar sin permiso

Source citation: (COT-012, COT-022)

- GIVEN a user with `quotations.create` but without `quotations.negotiate`
- WHEN the user sends a negotiated price or an agreed advance
- THEN the backend responds 403 and the quotation does not change

#### Scenario: E-21 — Precio negociado en una línea que no lo requiere

Source citation: (COT-012)

- GIVEN a quotation with 10 camisas `110`
- WHEN a user with `quotations.negotiate` sends a negotiated price for that line
- THEN the backend rejects it with an error indicating that the line does not require an advisor

#### Scenario: E-22 — Deja de requerir asesora

Source citation: (COT-012)

- GIVEN the quotation of E-19 with negotiated prices
- WHEN the chemises are reduced to 10 (40 uniform pieces)
- THEN the negotiated prices and the agreed advance are discarded and the lines go back to the tier 13–49
- AND the calculation indicates that negotiated prices were discarded

#### Scenario: E-72 — Cambia la combinación de una línea negociada

Source citation: (COT-012, DEC-COT-55)

- GIVEN the quotation of E-19 with negotiated prices
- WHEN the fabric of the shirts line is changed, resolving another combination, and the quotation still has 50 uniform pieces or more
- THEN the negotiated price of that line is discarded and the line requires a negotiated price again
- AND the calculation indicates that a negotiated price was discarded
- AND the negotiated price of the chemises line is kept
- AND changing only the quantities of the shirts, without changing combination or size group, does not discard its negotiated price

### Requirement: COT-013 — Registrar una cotización

A user with `quotations.create` SHALL create a quotation in **borrador** state for (DEC-COT-19):

- a registered and **active** **customer** of `002`, with an optional contact person if the customer is a company; or
- a **prospect**, with a mandatory **name** and **phone** and an optional **email**. The phone and the email are validated and normalized with the same rules as CLI-004. The prospect is not registered as a customer nor compared with existing customers; when the quotation is converted into an order, `006` requires registering or choosing the customer (§10).

In addition:

- The responsible advisor is, by default, the advisor assigned to the customer; if there is no customer or it has no advisor, the user who creates the quotation if they have `customers.portfolio`; otherwise it stays empty. It MAY be changed to an active user with `customers.portfolio`.
- The origin is **asesora** for quotations created in the ERP. `005` creates those with origin **web**.
- The number is assigned on creation (DEC-COT-21). It is stored as an integer and shown with a prefix and six digits, e.g. `COT-000123`. A discarded draft does not release its number, so the numbering may have gaps (DEC-COT-45).
- The editor uses PRD-019 to show the options of each step and COT-009 to recalculate on every change. The draft is saved even if it has errors, to continue it later.
- While it is a draft, the quotation is recalculated with the current prices and rate every time it is opened or modified.
- In a draft, the recipient MAY be changed (from prospect to customer or the other way around, or to another customer). Changing the recipient keeps the responsible advisor; it is changed only manually (DEC-COT-56).

#### Scenario: E-32 — Crear una cotización para un cliente

Source citation: (COT-013, COT-023)

- GIVEN an active customer with an assigned advisor and a user with `quotations.create`
- WHEN the user creates a quotation
- THEN it is created as a draft, with a number, origin "asesora" and the customer's advisor as responsible
- AND the creation is audited

#### Scenario: E-33 — Cliente inactivo

Source citation: (COT-013, COT-016)

- GIVEN an inactive customer
- WHEN a quotation is attempted to be created for the customer, or a draft of the customer to be issued
- THEN the backend rejects the action with an error on the customer

#### Scenario: E-34 — Crear sin permiso

Source citation: (COT-013, COT-022)

- GIVEN a user with `quotations.view` but without `quotations.create`
- WHEN the user sends a request to create a quotation
- THEN the backend responds 403 and nothing is created

#### Scenario: E-68 — Cotización para un prospecto

Source citation: (COT-013, COT-020)

- GIVEN a user with `quotations.create`
- WHEN the user creates a quotation for the prospect "Taller Ejemplo" with the phone 0414-1234567 and an email
- THEN it is created as a draft with the phone normalized (+584141234567), without creating any customer
- AND when it is issued, the email is sent to the prospect
- AND a landline or foreign phone, or a prospect without a name, is rejected
- AND the quotation is found by searching for the name or phone of the prospect, and does not appear in the record of any customer

#### Scenario: E-73 — Cambiar el destinatario conserva la asesora

Source citation: (COT-013, DEC-COT-56)

- GIVEN a draft for a prospect whose responsible advisor is the user who created it, and an active customer with another assigned advisor
- WHEN the recipient of the draft is changed to that customer
- THEN the responsible advisor stays the same
- AND a user can change it manually to an active user with `customers.portfolio`

### Requirement: COT-014 — Líneas y cantidades

- A quotation MUST have between 1 and n lines, in the order defined by the user. Each line is a product or a combo with its own selection.
- **Quantities per size**: if the product has Talla as an order attribute, the line has one row per size with its quantity (integer ≥ 1), at least one row and no repeated sizes. Otherwise, the line has a single quantity.
- The other order attributes (color included) and the details belong to the line: all the size rows share the same selection. Another color is another line (DEC-COT-09).
- In products with size as an axis (diapers) each size is a different combination and, therefore, a different line.
- A combo line has the quantity of combos and the selection of each component (PRD-010).
- Each line admits a free note (maximum length in `design.md`).
- There is no cap on lines or quantities; the negotiation tiers cover high volumes.

#### Scenario: E-35 — Cantidades por talla

Source citation: (COT-014)

- GIVEN a user editing a line of `110`
- WHEN the user saves a row with quantity 0, or two rows with size M, or no rows
- THEN the backend rejects the line with an error on the quantities

### Requirement: COT-015 — Estados de la cotización

| Estado | Significado | Se llega desde |
|---|---|---|
| Borrador | La asesora la está armando; se guarda a medias y se continúa después. Precios y tasa vigentes, recalculados. No se ha enviado al cliente | Alta, duplicado |
| Emitida | Cerrada, con precios y tasa congelados hasta su vencimiento, y enviada al cliente | Borrador (COT-016) |
| Vencida | Pasó su fecha de vencimiento sin aprobarse | Emitida (COT-017) |
| Anulada | Cancelada por Ecolekua o por el cliente | Emitida (COT-019) |
| Aprobada | El cliente la aceptó y se convirtió en pedido | Emitida, en `006` |

- No other transition exists (DEC-COT-17). An issued, expired, cancelled or approved quotation MUST NOT be edited (DEC-COT-18): to change it, it is duplicated (COT-018).
- Badges: borrador → `neutral`, emitida → `active`, vencida → `pending`, anulada → `neutral`, aprobada → `done`.

#### Scenario: E-38 — Emitida no se edita

Source citation: (COT-015, DEC-COT-18)

- GIVEN an issued quotation
- WHEN a line, a quantity or the recipient is attempted to be modified
- THEN the backend rejects the change

### Requirement: COT-016 — Emitir una cotización

A user with `quotations.create` SHALL issue a draft. The backend MUST:

1. recalculate with COT-009;
2. reject the issue if there are errors, lines without a price (COT-025), lines that require an advisor without a negotiated or confirmed price, negotiated lines without an agreed advance (COT-012), or if the customer is inactive;
3. save a **frozen copy** of each line (name, selection, unit prices, tier, price origin, subtotals), of the total, of the current advance percentage, of the required advance, of the current rate (value and date) and of the total and the advance in Bs (DEC-COT-16), and of the recipient data (name, document, phone, email and contact person) and the advisor shown by the detail page, the printable view and the email; the link to the customer is kept (DEC-COT-49). If there is no saved rate at all, it issues without an equivalent in Bs;
4. set the issue date and the expiration date (issue + current validity days);
5. move to **emitida** and audit it;
6. after confirming the transaction, queue the email sending (COT-031).

Steps 1 to 5 happen in one transaction. From that moment on, no change in prices, schemes, parameters, rates or catalog alters the quotation.

#### Scenario: E-36 — Emitir congela precios y tasa

Source citation: (COT-016, COT-023)

- GIVEN a complete draft with 10 camisas `110` (36,00; total 360,00; advance 180,00) and the current rate 875,65
- WHEN it is issued and afterwards the price of `110` in 7–12 changes to 38,00 and a new rate is saved
- THEN the issued quotation still shows 36,00, total 360,00 and advance 180,00
- AND it keeps the rate 875,65 with its date, total 315.234,00 Bs and advance 157.617,00 Bs
- AND it expires 7 days after the issue, and the issue is audited

#### Scenario: E-37 — Emitir con pendientes

Source citation: (COT-016)

- GIVEN a draft with a line without a price, or with a line that requires an advisor without a negotiated price or without an agreed advance, or with a selection error
- WHEN issuing is attempted
- THEN the backend rejects it, indicates the pending lines and the draft does not change

### Requirement: COT-017 — Vencimiento

- An issued quotation expires at the end of the day of its expiration date, in Ecolekua's operating timezone.
- On expiring it moves to **vencida**, without user intervention. The mechanism (scheduled task or check on query) is defined in `design.md`, with the condition that the list and the record never show as issued an expired quotation.
- An expired quotation is not converted into an order (`006`). It is duplicated to quote again with the current prices.

#### Scenario: E-41 — Vencimiento

Source citation: (COT-017)

- GIVEN an issued quotation that expires today
- WHEN the day ends in Ecolekua's timezone
- THEN the quotation appears as vencida in the list and in the record
- AND the change is audited

### Requirement: COT-018 — Duplicar

A user with `quotations.create` SHALL duplicate any quotation, in any state. The result is a new **borrador** with the same recipient, lines, quantities, customizations and notes, recalculated with the current prices and rate. Negotiated prices and the agreed advance are **not** copied. If a line is no longer valid (inactive combination, inactive value), it is copied with its error so that the user corrects it. If the customer is inactive, the draft is created and cannot be issued until the recipient is changed. The copy saves a reference to the source quotation and is audited.

#### Scenario: E-42 — Duplicar con precios vigentes

Source citation: (COT-018)

- GIVEN the expired quotation of E-41 with 10 camisas `110` at 36,00, and the current price of `110` in 7–12 at 38,00
- WHEN it is duplicated
- THEN a new draft is created with the same lines, at 38,00, with a reference to the source quotation
- AND the negotiated prices and the agreed advance of the original are not copied

### Requirement: COT-019 — Anular y descartar

- A user with `quotations.cancel` SHALL cancel an **issued** quotation, indicating a mandatory reason (maximum length in `design.md`). It moves to **anulada** and is audited. It requires `ConfirmDialog`.
- A user with `quotations.create` SHALL **discard** a draft: it is deleted together with its lines, with a copy in the audit (DEC-COT-24). It requires `ConfirmDialog`.
- Issued, expired, cancelled and approved quotations MUST NOT be deleted.

#### Scenario: E-43 — Anular

Source citation: (COT-019, COT-023)

- GIVEN an issued quotation and a user with `quotations.cancel`
- WHEN the user cancels it with a reason
- THEN it moves to anulada and the audit records the reason
- AND cancelling without a reason, or cancelling a draft, is rejected

#### Scenario: E-44 — Descartar un borrador

Source citation: (COT-019, COT-024)

- GIVEN a draft that is the only quotation of a customer
- WHEN a user with `quotations.create` discards it
- THEN the draft and its lines are deleted and the audit keeps a copy
- AND the customer can again be deleted if it has no other history

### Requirement: COT-020 — Listado, búsqueda y ficha

A user with `quotations.view` SHALL be able to:

- list quotations with `SegmentedTabs` by status (Borradores, Emitidas, Vencidas, Anuladas, Aprobadas, Todas) and counts calculated by the backend;
- search by number, by customer name or document, and by prospect name or phone;
- filter by responsible advisor ("Mis cotizaciones"), date range, "requiere asesora" and "prospecto";
- see the record with recipient, advisor, status, dates, lines with their detail, pieces per business line, total, advance with its breakdown, rate and equivalents in Bs, referral reasons and email deliveries;
- see the history of quotations of the customer in its record (section foreseen in CLI-013). The quotations of prospects do not appear in any customer record;
- see the list paginated in the backend.

#### Scenario: E-45 — Consultar sin permiso

Source citation: (COT-020, COT-022)

- GIVEN an authenticated user without `quotations.view`
- WHEN the user requests the list, the detail or the printable view of a quotation
- THEN the backend responds 403

#### Scenario: E-46 — Historial en la ficha del cliente

Source citation: (COT-020, CLI-013)

- GIVEN a customer with two quotations, one issued and one expired, and a user with `quotations.view` and `customers.view`
- WHEN the user opens the customer's record
- THEN the quotations section shows both with number, date, status and total

### Requirement: COT-021 — Vista imprimible

A user with `quotations.view` SHALL open the **printable view** of an **issued**, expired, cancelled or approved quotation, to print it or save it as PDF from the browser (DEC-COT-20). No PDF is generated on the server.

- Content: Ecolekua's data, number, status if it is not issued, issue and expiration dates, recipient, advisor, lines with descriptive name, colors, details, customizations (no charge), quantities per size, unit price and subtotal in USD, total and required advance in USD, their equivalent in Bs with the rate and its date ("referencial"), and the note "Precios en USD con IVA incluido".
- Ecolekua's data (name, RIF, address, phone, email and logo) is read from the application configuration; the technical team maintains it, with no screen in the ERP (DEC-COT-46).
- It MUST NOT show costs, internal tiers or the internal breakdown of the advance.
- A draft has no printable view.
- It replaces printing paper copies.

#### Scenario: E-50 — Vista imprimible

Source citation: (COT-021, DEC-COT-20)

- GIVEN the issued quotation of E-36 and a user with `quotations.view`
- WHEN the user opens the printable view
- THEN the user sees number, dates, recipient, lines with quantities per size, prices, total and advance in USD, and their equivalent in Bs with the rate and its date, without costs or internal tiers
- AND a draft has no printable view

### Requirement: COT-030 — Tasa de cambio del día

Prices and quotations are in USD (DEC-COT-01). The system SHALL store the **official rate of the day** (Bs per USD) as a reference for quotations.

- **Automatic retrieval** (DEC-COT-43): a scheduled task queries `https://ve.dolarapi.com/v1/dolares/oficial` **every day at 7:00 a. m., Caracas time** (`America/Caracas`). The `promedio` field, rounded to 2 decimals (DEC-COT-60), is saved as **the rate of that day**, with the date of the query in Caracas; `fechaActualizacion` is saved only as a source datum and does not change the date of the rate. If the rate of that day already exists, it is not duplicated. The retries after a failure are defined in `design.md`.
- **Fallback** (DEC-COT-38): if the API does not respond, responds with an error or does not bring a positive value, the task saves nothing and the failure is recorded. The system uses the **last saved rate** and always shows it with its date. A user with `pricing.manage` MAY manually register the rate of the day (value > 0, up to 2 decimals, DEC-COT-60); it has origin "manual", its author and audit. If the API later brings the rate of that same day, it does not replace the manual one.
- The current rate is that of the current day or, if it does not exist, the most recent one. The prices screen and the quotation editor show it with its date and origin.
- The absence of a rate MUST NOT ever prevent calculating or issuing a quotation (COT-016).
- The integration is decoupled (constitution §15): the API is queried only from the scheduled task, never during calculation or issue.
- Saved rates MUST NOT be edited or deleted; a correction is a new manual record of the same day, which replaces the previous one as current and is audited.

#### Scenario: E-63 — Tasa del día desde la API

Source citation: (COT-030)

- GIVEN the API responds `{"promedio": 875.6505, "fechaActualizacion": "2026-10-09T00:00:00-04:00"}`
- WHEN the scheduled task runs on 2026-10-12 at 7:00 a. m. Caracas time
- THEN the rate 875,65 (the `promedio` rounded to 2 decimals, DEC-COT-60) is saved as the rate of 2026-10-12, with origin "api" and the source date (2026-10-09) as a separate datum
- AND running the task again that day does not create another rate
- AND the task is scheduled at 7:00 in the `America/Caracas` zone, not in UTC

#### Scenario: E-64 — Falla la API y tasa manual

Source citation: (COT-030, COT-023)

- GIVEN the last saved rate is from the previous day and the API responds with an error
- WHEN the task is run and then a quotation is calculated
- THEN no rate is saved, the failure is recorded and the calculation uses the previous day's rate showing its date
- AND a user with `pricing.manage` manually registers the rate of the day; it has origin "manual", its author and audit, and becomes the current one
- AND a user without `pricing.manage` receives 403 when attempting it, and a value of 0, negative or with more than 2 decimals is rejected

### Requirement: COT-031 — Envío por correo

On issue, the quotation SHALL be sent automatically by email (DEC-COT-41, DEC-COT-42).

- **Recipients**: the email of the customer and that of its contact person, if they have them; or the email of the prospect. One email is sent per recipient, without repeating addresses, and each one has its own result (DEC-COT-50).
- **Content**: the complete quotation in the body of the email (HTML), with the same content as the printable view. No attachments or links to system pages.
- If there is no email at all, the quotation is issued anyway and the record and the editor show the notice "sin correo: entréguela por otro medio".
- The sending is done in the background after confirming the issue; an email failure MUST NOT ever revert the issue (constitution §15, AGENTS §7.11).
- Each sending MUST be recorded in the quotation with date, recipients and result (enviado or fallido). A failed sending is shown in the record.
- A user with `quotations.create` MAY **resend** an issued quotation to the current recipients of the customer or the prospect. The resend is recorded the same way as the sending.
- Expired, cancelled and approved quotations are not resent.
- The sender and the email provider are configured in the environment (`design.md`); in development Mailpit is used.

#### Scenario: E-66 — Envío por correo al emitir

Source citation: (COT-031)

- GIVEN a company customer with an email and a contact person with an email
- WHEN its quotation is issued
- THEN after confirming the issue an email is sent to both with the complete quotation in the body, and the quotation records the sending with date, recipients and result
- AND if the sending fails, the quotation stays issued and the record shows the failed sending
- AND a user with `quotations.create` resends it and a second sending is recorded

#### Scenario: E-67 — Emitir sin correo

Source citation: (COT-031)

- GIVEN a customer without an email and without a contact person with an email
- WHEN its quotation is issued
- THEN the quotation stays issued, no email is sent and the record shows the notice "sin correo"

### Requirement: COT-022 — Autorización en backend

Every route and action of prices, rates and quotations MUST validate the corresponding permission with Policies in the backend (AGENTS §7.2). The operation of COT-009, when invoked by the ERP, requires `quotations.create`; `005` defines its public access.

Scenario coverage: E-07, E-20, E-34, E-45 and E-59 (placed under COT-003, COT-012, COT-013, COT-020 and COT-027, which cite COT-022).

### Requirement: COT-023 — Auditoría

The following MUST be audited, with user, action, affected record, date and time, IP and values before and after (AGENTS §7.8):

- creations, edits, deactivations and reactivations of schemes and tiers; assignment of a scheme to products;
- creations, edits and deletions of combination prices, size group prices and combo item prices, including the initial load;
- application of a bulk update (COT-028): each changed price, with the reference to the import, and the import itself with its user, scope and summary; also its discard;
- changes of commercial parameters; manual registration of rates;
- creation, issue, negotiated or confirmed price, agreed advance, change of responsible advisor or recipient, duplicate, cancellation, discard and expiry of quotations.

Edits of lines in a draft are not audited one by one (DEC-COT-29): the draft is a working document and its content is frozen and audited on issue. Email sendings are recorded in the quotation (COT-031), not in the audit.

Scenario coverage: E-01, E-05, E-11, E-12, E-19, E-32, E-36, E-41, E-43, E-53, E-62 and E-64 (placed under the first requirement each one cites; each cites COT-023).

### Requirement: COT-024 — Integridad con clientes y productos

- **Customers** (commitment of `002`, CLI-010): a customer with at least one quotation, in any state, MUST NOT be deleted; deactivation is suggested. Scenario E-19 of `002` (here E-39).
- **Products** (commitment of `003`, PRD-012, PRD-014, DEC-PRD-21): a combination or a combo used in any quotation line has history. They MUST NOT be deleted, and the code and the axis values of that combination MUST NOT be modified. Scenario E-29 of `003` (here E-40).
- Foreign keys use delete restriction.
- A discarded draft (COT-019) stops counting as history.
- Deactivating a customer, a product or a combination does not modify issued quotations. An affected draft shows the error on its next recalculation and cannot be issued until corrected.

#### Scenario: E-39 — Cliente con cotizaciones no se elimina

Source citation: (COT-024, CLI-010)

- GIVEN a customer without orders with an issued quotation, and a user with `customers.delete`
- WHEN the user attempts to delete the customer
- THEN the backend rejects it, indicates the reason and suggests deactivating it

#### Scenario: E-40 — Combinación con historial

Source citation: (COT-024, PRD-012, PRD-014)

- GIVEN the combination `110` used in a quotation line
- WHEN deleting it, changing its code or changing its axis values is attempted
- THEN the backend rejects each change and suggests creating a new combination and deactivating the previous one
- *Covers scenario E-29 of `003`.*

#### Scenario: E-48 — Borrador con combinación desactivada

Source citation: (COT-024)

- GIVEN a draft with a line of `110-4` and an issued quotation with the same combination
- WHEN `110-4` is deactivated
- THEN the draft shows an error on that line on its next recalculation and cannot be issued
- AND the issued quotation does not change

### Requirement: COT-025 — Líneas sin precio

A line has no price when its product has no scheme or has an inactive one, its combination has no price in the tier that corresponds to it, its size does not belong to any size group, or a combo component has no price.

- COT-009 returns a **"sin precio"** error on that line, without inventing a value.
- The quotation MUST NOT be issued with lines without a price.
- The price list (COT-003) marks the active combinations without a price in any tier, so that the team completes them.

#### Scenario: E-26 — Línea sin precio

Source citation: (COT-025)

- GIVEN the combination `110-1` without a price in the tier 7–12
- WHEN a line of 8 units of `110-1` is calculated
- THEN the line returns the error "sin precio" and no invented value
- AND the quotation cannot be issued
- AND the price list marks `110-1` as incomplete

### Requirement: COT-026 — Exportar la plantilla de precios

A user with `pricing.view` SHALL download a CSV with the current prices, which serves as a template to edit them.

- **Scope of the export** (one per file):
  - **Combination prices of one scheme** (choosing the scheme is mandatory, because the columns depend on its tiers). Optional filters: category, product and status of the combination. One row per combination, or per size group if the combination has them (COT-004).
  - **Combo item prices**: one row per component of each combo. Optional filter: status of the combo.
- **Combination columns**: code, size group (empty if it has none), and as a reference the product, the descriptive name and the status; then one column for each tier with a price of the scheme, with its label as header, or a single price column if the scheme is fixed price. Negotiation tiers have no column.
- **Combo columns**: combo code, component number and, as a reference, the product of the component and its quantity; then the price per item.
- Price cells without a value are exported empty, so that the team completes them (COT-025).
- The first row of the file indicates the exported scope and scheme, so that the import detects a wrong template (detail in `design.md`).
- Format (DEC-COT-31): separator `;`, decimal comma and UTF-8 with BOM, so that it opens directly in Ecolekua's Spanish Excel.
- The export is not audited (it is a query).

#### Scenario: E-51 — Exportar la plantilla de un esquema

Source citation: (COT-026)

- GIVEN a user with `pricing.view`, the scheme "Uniformes" and the combinations `110` (with its three prices), `110-1` (without a price in 7–12) and `184` (with groups 28–36 and 38–44)
- WHEN the user exports the prices of the scheme "Uniformes"
- THEN the CSV uses `;` and decimal comma, and has the columns of code, size group, references and one column per tier with a price (Detal, 7–12, 13–49), without a column for the negotiation tier
- AND `184` appears in two rows, one per size group
- AND the cell of `110-1` in 7–12 is empty

#### Scenario: E-60 — Precios de combos por CSV

Source citation: (COT-026, COT-027, COT-028)

- GIVEN the "Kit Oro antiderrame" with the pañal at 30,00 per item
- WHEN the prices of combos are exported, the pañal is changed to 31,00, imported and applied
- THEN the pañal of the kit is at 31,00 and a line of 1 kit is calculated at 157,00

### Requirement: COT-027 — Importar y validar

A user with `pricing.manage` SHALL upload a CSV with the format of COT-026. The backend MUST validate it completely **without applying anything** and return a preview.

**Interpretation rules**:

- Either `;` or `,` is accepted as separator and either comma or dot as decimal separator, without thousands separator (DEC-COT-31).
- Rows are identified by **code** (and size group) or by **combo code and component number**. The reference columns (product, name, status, quantity) are ignored even if they were edited.
- **Empty price cell = no change.** Prices are not deleted by CSV; that is done in the table of COT-003 (DEC-COT-32).
- **Absent rows = no change.** The file MAY contain only the rows to be modified.
- A price that is empty in the system and has a value in the file is a **new price**.
- Size groups are not created or modified by CSV; they are defined in COT-004.

**Validations** (the same as COT-003, COT-004 and COT-005, plus those of the file):

- the file is a CSV in the expected encoding, does not exceed the maximum size or number of rows (`design.md`) and its header corresponds to the chosen scope and scheme;
- the tier columns match the **current** tiers of the scheme. If the scheme changed after exporting, the whole file is rejected and a new template is requested;
- each code exists, belongs to a product with that scheme and, if it brings a size group, the group exists in that combination; each combo and component number exist;
- there are no repeated rows for the same code and size group, or combo and component;
- each price is a number greater than zero. If it has more than 2 decimals, it is rounded according to DEC-COT-02 and the preview marks it as a warning (DEC-COT-33).

**Result**:

- **All or nothing**: if there is any error, nothing can be applied. The response lists each error with row, column and reason, and the user corrects the file and uploads it again.
- If there are no errors, the import is left **validated** and the preview shows:
  - the summary: new, modified and unchanged prices;
  - each change with code, descriptive name, size group, tier, previous value, new value and percentage variation;
  - the warnings: rounded values, inactive combinations or combos affected and variations greater than ±30 % (DEC-COT-34).
- Warnings do not prevent applying; errors do.
- A file without any change is reported as such and does not generate an applicable import.

#### Scenario: E-52 — Importar y previsualizar

Source citation: (COT-027)

- GIVEN the template of E-51 with the detal of `110` changed from 40,00 to 42,00, the price of `110-1` in 7–12 completed with 37,00 and the other rows unchanged
- WHEN a user with `pricing.manage` imports it
- THEN the import is left validated and no price changes yet
- AND the preview shows 1 modified price (`110` Detal: 40,00 → 42,00, +5 %), 1 new price (`110-1` 7–12: 37,00) and the rest unchanged
- AND the same file saved with `,` and decimal point gives the same result

#### Scenario: E-54 — Archivo con errores

Source citation: (COT-027)

- GIVEN a CSV with a code that does not exist, a code of another scheme, a repeated row and a price "abc"
- WHEN it is imported
- THEN the response lists the four errors with row, column and reason
- AND the import cannot be applied and no price changes

#### Scenario: E-55 — Celdas vacías y filas ausentes

Source citation: (COT-027)

- GIVEN `110` with prices 40,00 / 36,00 / 32,00
- WHEN a file is imported that only contains the row of `110` with the detal at 41,00 and the other two cells empty
- THEN the preview shows only the change of the detal
- AND when applying, 7–12 and 13–49 of `110` stay at 36,00 and 32,00, and the combinations that were not in the file do not change

#### Scenario: E-56 — Plantilla desactualizada

Source citation: (COT-027)

- GIVEN a template exported when "Uniformes" had three tiers with a price, and afterwards a fourth tier with a price was added
- WHEN that template is imported
- THEN the backend rejects the whole file and indicates that a new template must be downloaded

#### Scenario: E-57 — Redondeo y advertencias

Source citation: (COT-027, DEC-COT-33, DEC-COT-34)

- GIVEN `110` with detal 40,00 and `110-4` inactive
- WHEN a file is imported with the detal of `110` at 44,4449 and a price change in `110-4`
- THEN the preview shows 44,44 with the rounding warning, and the inactive combination warning on `110-4`
- AND a change from 40,00 to 400,00 is marked as a variation greater than ±30 %
- AND the warnings do not prevent applying

#### Scenario: E-59 — Importar sin permiso

Source citation: (COT-027, COT-022)

- GIVEN a user with `pricing.view` but without `pricing.manage`
- WHEN the user sends a price CSV to import, or attempts to apply a validated import
- THEN the backend responds 403 and nothing changes
- AND that user can export the template

### Requirement: COT-028 — Aplicar la importación

The same user who validated the import SHALL **apply** or **discard** it from the preview. Applying requires `ConfirmDialog` with the summary of changes.

- On applying, the backend MUST check that no affected price has changed since the validation (through the table of COT-003 or through another import) and that the scheme still has the same tiers. If something changed, it rejects the application, marks the import as discarded and asks to upload the file again (DEC-COT-35).
- All the changes are applied in **a single transaction**: either all are applied or none.
- The import moves to **aplicada**. Each changed price is audited with previous and new value and with the reference to the import (COT-023).
- As in COT-003: the new prices apply to new calculations and to drafts on their next recalculation, never to issued quotations (COT-016).
- A validated import that is not applied expires after the time defined in `design.md` and moves to discarded.
- There is no "undo": to revert, the prices are exported, the values are corrected and imported again.

#### Scenario: E-53 — Aplicar la importación

Source citation: (COT-028, COT-023)

- GIVEN the validated import of E-52
- WHEN the same user applies it
- THEN the detal of `110` is 42,00 and `110-1` has 37,00 in 7–12, in a single transaction
- AND the import is left applied and each changed price is audited with the reference to the import
- AND a quotation issued earlier with `110` at 40,00 does not change

#### Scenario: E-58 — Conflicto al aplicar

Source citation: (COT-028, DEC-COT-35)

- GIVEN a validated import that changes the detal of `110` from 40,00 to 42,00
- WHEN before applying it another user changes that price to 41,00 in the prices table, and then applying is attempted
- THEN the backend rejects the application, the import is left discarded and the price stays at 41,00

### Requirement: COT-029 — Historial de importaciones

A user with `pricing.view` SHALL consult the list of applied and discarded imports, with date, user, scope, file name and summary, and the detail of the changes of each applied import (previous and new value). The original file is not stored (DEC-COT-36).

#### Scenario: E-61 — Historial de importaciones

Source citation: (COT-029)

- GIVEN an applied import and a discarded one
- WHEN a user with `pricing.view` consults the history
- THEN the user sees both with date, user, scope, file and summary
- AND in the applied one the user sees each change with its previous and new value
