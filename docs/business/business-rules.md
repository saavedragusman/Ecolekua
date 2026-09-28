# Ecolekua Business Rules

## 1. Sales Channels

Ecolekua supports at least two commercial origins:

1. Web
2. Advisor/direct sales

The order must preserve its sales origin.

The origin must be available for reporting and commission calculations.

---

# 2. Pricing Tiers

The initial commercial pricing model contains three quantity ranges.

### Tier 1 — Retail

```text
1–7 pieces
```

The standard product price applies.

A 50% deposit is automatically required.

---

### Tier 2 — Wholesale

```text
8–30 pieces
```

The applicable wholesale discount must be calculated automatically according to configured pricing rules.

The exact discount percentage must be configurable and must not be hardcoded without approval.

A 50% deposit is automatically required.
---

### Tier 3 — VIP / Direct Negotiation

```text
More than 30 pieces
```

The system must not automatically finalize the commercial price.

The customer must be redirected to an advisor through the configured WhatsApp channel or equivalent commercial contact mechanism.

The exact negotiation process is a business configuration.

---

# 3. Deposit

Orders in the 1–7 and 8-30 quantity range initially require:

```text
50% deposit
```

The deposit must be represented as a financial transaction associated with the order.

The system must distinguish:

- order total;
- amount paid;
- amount pending;
- deposit requirement.

---

# 4. Order Confirmation

An order must not enter normal production flow until its required commercial conditions have been satisfied.

At minimum, the system must verify:

- valid customer;
- valid products;
- quantities;
- prices;
- confirm pay;
- required deposit/payment condition;
- required delivery information;
- material availability/reservation condition.

---

# 5. Material Requirements

When an order is confirmed, the system must determine the required materials.

Material requirements must be based on:

- product;
- variant;
- quantity;
- applicable material consumption rules.

Future specifications will define the exact consumption formulas.

---

# 6. Material Reservation

When sufficient material is available:

```text
Required Material ≤ Available Material
```

the system should reserve the required quantity for the order.

Example:

```text
Required: 20 m
Available: 50 m

Reserved: 20 m
Remaining available: 30 m
```

The reservation must be associated with the order.

---

# 7. Material Shortage

When required material cannot be completely reserved:

```text
Required Material > Available Material
```

the system must:

1. identify the missing material;
2. record the shortage;
3. prevent the order from incorrectly entering a production state that requires the unavailable material;
4. notify the responsible finanzas according to the configured notification mechanism;
5. include the material and missing quantity in the notification.

Example:

```text
Required: 50 m
Available: 30 m

Missing: 20 m
```

The system must not silently create a negative material availability unless a future explicit business rule authorizes this behavior.

---

# 8. Inventory States

The system should distinguish at least:

```text
Physical Stock
Reserved Stock
Available Stock
Consumed Stock
Waste
```

Inventory values must not be modified arbitrarily.

Changes should be represented by inventory movements.

---

# 9. Production Order

A confirmed commercial order may generate one or more production orders depending on future production design.

Every production order must maintain traceability to the commercial order.

---

# 10. Production Operations

Initial operations include:

```text
Corte
Sublimación
Bordado
Costura
```

Each operation may be:

- pending;
- assigned;
- in progress;
- paused;
- completed;
- rejected/rework required.

---

# 11. Employee Assignment

A supervisor assigns production operations to employees.

An employee may only receive operations they are authorized to perform.

The system must preserve:

- employee;
- operation;
- assignment date;
- status;
- production order.

---

# 12. Production Timing

An assigned operation must support:

```text
Start
Pause
Resume
Finish
```

The system must calculate actual productive time from the recorded events.

---

# 13. Pause Reasons

A production pause requires a reason.

Initial examples:

- machine breakdown;
- lunch;
- lack of material;
- waiting;
- other authorized reason.

The list should be configurable.

---

# 14. Standard vs Actual Time

Each applicable production operation may have:

```text
Standard Time
Actual Time
```

The system must preserve both values.

Management reporting can later compare them to determine productivity indicators.

---

# 15. Quality Control

Completed production must be subject to applicable quality controls.

The quality process must record:

- quantity inspected;
- quantity approved;
- quantity rejected;
- defect information;
- responsible production operation;
- responsible employee where applicable.

---

# 16. Partial Rework

Quality rejection must operate at the affected quantity level whenever possible.

Example:

```text
Produced: 100
Defective: 5
Approved: 95
```

The system must send only the 5 defective units to rework.

It must not automatically return all 100 units to production.

---

# 17. Rework Traceability

Every rework action must identify:

- original production order;
- original operation;
- defect;
- affected quantity;
- responsible employee/operation;
- rework status;
- final result.

---

# 18. Delivery

Orders must have a committed delivery date when applicable.

Management must be able to identify orders approaching or exceeding their delivery deadline.

Future specifications will define the exact traffic-light thresholds.

---

# 19. Advisor Commissions

Advisor commissions are calculated from eligible advisor-generated sales.

Automatic website sales are excluded from advisor commission calculations under the initial business rules.

The commission percentage must be configurable.

---

# 20. Website Sales Attribution

Orders generated automatically by the website must have a distinct sales origin.

They must not be attributed to an advisor merely because an advisor exists in the system.

---

# 21. WhatsApp Notifications

WhatsApp is an external notification channel.

The system may notify responsible users when important events occur.

Initial candidate events include:

- material shortage;
- order requiring commercial attention;
- delivery risk;
- production exception.

The external WhatsApp provider must be abstracted from the core business logic.

---

# 22. Order Status

Order status must reflect actual operational state.

The system must avoid using a single generic status to represent multiple unrelated processes.

Commercial status, production status, payment status and delivery status may need to be represented independently.

---

# 23. Auditability

Important changes must be traceable.

At minimum, the system should be able to identify:

```text
Who
What
When
Which record
```

for important business operations.

---

# 24. Data Integrity

Operations affecting multiple related records must be transactional when required.

Examples:

- order confirmation + material reservation;
- inventory consumption;
- production completion;
- quality rejection + rework creation.

---

# 25. Configuration

The following values should be configurable where appropriate:

- advance payment percentages;
- volume-based prices;
- volume discounts;
- standard times;
- pause reasons;
- configurable statuses;
- production parameters;
- commission percentage;
- notifications;
- standard production times;
- material consumption.

---

# 26. Future Rules

Any business behavior not explicitly defined in this document must not be invented during implementation.

It should be introduced through a specification or approved business decision.