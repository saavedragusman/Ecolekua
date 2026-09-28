# Ecolekua Business Glossary

## Cliente

Person or organization that purchases Ecolekua products or services.

A customer may have:

- contact information;
- billing information;
- delivery information;
- purchase history;
- quotations;
- orders.

---

## Asesora

Commercial user responsible for managing sales directly.

Advisor-generated sales may be used for commission calculations.

Automatic website sales must be distinguishable from advisor-generated sales.

---

## Venta Web

Sale generated automatically through the public web portal without direct advisor attribution.

Website sales are excluded from advisor commission calculations unless a future business rule explicitly changes this behavior.

---

## Cotización

Commercial proposal containing products, quantities, prices, customization and commercial conditions.

A quotation may later become an order.

---

## Pedido

Confirmed commercial request from a customer.

An order may contain:

- products;
- variants;
- quantities;
- customization;
- prices;
- deposit/payment information;
- delivery requirements.

---

## Anticipo

Amount paid by the customer before production begins.

For the initial business rule, orders containing 1–6 pieces require a 50% deposit.

The exact payment policy must remain configurable where practical.

---

## Producto

Commercial item sold by Ecolekua.

Examples include ecological/reusable diapers and uniforms.

---

## Variante

Specific variation of a product.

Possible attributes include:

- size;
- color;
- model;
- material;
- customization.

---

## Personalización

Modification requested by the customer.

Examples:

- embroidery;
- sublimation;
- colors;
- specific design.

---

## Material

Raw material required to manufacture a product.

Examples may include:

- fabric;
- thread;
- sublimation material;
- embroidery supplies;
- other production inputs.

---

## Stock

Physical quantity currently available in inventory.

---

## Stock Disponible

Quantity that can currently be committed to new operations after considering existing reservations.

Conceptually:

```text
Disponible = Stock físico - Stock reservado
```

Additional inventory states may exist in the future.

---

## Reserva de Material

Commitment of available material to a specific order.

A reservation reduces material availability for other orders without necessarily consuming the physical material immediately.

---

## Movimiento de Inventario

Traceable event that changes inventory.

Examples:

- purchase;
- receipt;
- consumption;
- adjustment;
- waste;
- return;
- transfer.

---

## Falta de Material

Condition where required material cannot be completely reserved.

The affected order may be temporarily blocked from progressing into production until the required material becomes available.

---

## Orden de Producción

Internal production document generated from a confirmed order.

It defines what must be manufactured and through which operations.

---

## Operación

Individual production activity.

Initial operations include:

- corte;
- sublimación;
- bordado;
- costura.

---

## Estación

Production area or operational station associated with an operation.

---

## Operario

Employee who performs production work.

An employee may be authorized to perform one or more operations.

---

## Asignación

Assignment of a production operation or work unit to an employee.

---

## Tiempo Estándar

Expected amount of time required to perform an operation under normal conditions.

---

## Tiempo Real

Actual time recorded while an employee performs an operation.

---

## Cronómetro

Production timing mechanism used to record actual work time.

Typical lifecycle:

```text
Pendiente
→ En ejecución
→ Pausado
→ En ejecución
→ Finalizado
```

---

## Pausa

Temporary interruption of an active production operation.

A pause must have a reason.

Examples:

- machine failure;
- lunch;
- lack of material;
- waiting for another operation;
- other authorized reason.

---

## Lote

Group of units manufactured together as part of a production process.

---

## Control de Calidad / QA

Process used to determine whether produced units satisfy quality requirements.

---

## Defecto

Condition that causes a produced unit to fail a quality requirement.

---

## Reproceso

Additional production work required to correct a defective unit.

A defect affecting 5 units of a 100-unit batch should generate rework for those 5 units, not automatically the entire batch.

---

## Merma

Material or production loss that cannot be incorporated into the final product.

Merma should be traceable where operationally relevant.

---

## Entrega

Process of delivering completed products to the customer.

---

## Fecha Comprometida

Date by which an order is expected to be ready or delivered according to the commercial agreement.

---

## Comisión

Compensation attributed to an advisor according to applicable business rules.

Automatic website sales are not included in advisor commission calculations under the initial rules.

---

## Dashboard

Management interface that aggregates operational information into actionable indicators.

---

## SaaS

Software as a Service architecture in which the platform can support one or more business organizations.

---

## Usuario

Authenticated person who interacts with the system.

---

## Rol

Set of permissions associated with a user.

Examples may include:

- Administrador.
- Gerente.
- Asesora de venta.
- Supervisor de Producción.
- Operario.
- Responsable de Calidad.
- Finanzas.

---

## Permiso

Specific capability granted to a user through authorization rules.

---

## Auditoría

Historical record of relevant business actions performed within the system.