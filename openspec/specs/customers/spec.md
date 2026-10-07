# Customers Specification

> Transcribed from `docs/specs/002-customers.md` (status `Confirmada`; §4 permissions, §5 conceptual model, §6 requirements CLI-001..CLI-018, §7 scenarios E-01..E-41, §9 decisions DEC-CLI-01..DEC-CLI-33, DT-01). Faithful transcription: no added, removed, or reinterpreted behavior. Original IDs preserved verbatim. User-visible strings quoted from the source stay in Spanish. A scenario that cites several requirements is placed under the first cited requirement and lists the others in its `Source citation` line.

## Purpose

Defines how authorized internal users register, view, edit, deactivate, reactivate and (only without history) delete Ecolekua customers (natural persons or companies) from a single place, with backend-enforced permissions, duplicate detection, one advisor portfolio per customer, full audit, and a one-time console import of existing customers. A customer is a record managed by internal users, not an internal user of `001`; the customer's portal access is defined in `007`.

## Conceptual model (constraints on the requirements)

- A customer has: type (natural | company, DEC-CLI-01); name / business name; optional identification document (type + number, unique, DEC-CLI-02, DEC-CLI-03); phone / WhatsApp; email; optional birthday (day/month); optional company anniversary (day/month, companies only); notes; assigned advisor (internal user, 0..1, DEC-CLI-07, DEC-CLI-18); status (active | inactive); created by / creation date; contact person (0..1, companies only, DEC-CLI-05) with name, position, phone, email; address (0..1, DEC-CLI-06) with address, city, state, reference (DEC-CLI-17).
- The customer status MUST be implemented as a PHP Enum (constitution §21.1).
- Data that later specs associate to the customer (quotations, orders, payments) reference the customer; this spec does not define them.
- The customer has no classification of its own (business line or origin). The commercial origin is recorded on each order (`006`, business rules §1) (DEC-CLI-10).
- The customer has no visible code; it is identified by name and document (DEC-CLI-12).

## Requirements

### Requirement: CLI-001 — Registrar cliente

A user with `customers.create` SHALL be able to register a customer with the mandatory minimum data (DEC-CLI-04): **type**, **name or business name**, and **phone**. All other data MUST be optional. The customer MUST be created in **active** status and the action MUST be audited.

#### Scenario: E-01 — Registrar un cliente con datos válidos

Source citation: (CLI-001, CLI-016)

- GIVEN a user with `customers.create`
- WHEN the user submits valid minimum mandatory data
- THEN the customer is created in active status
- AND an audit entry is recorded with the user, the action and the created values

#### Scenario: E-02 — Rechazar un alta sin datos obligatorios

Source citation: (CLI-001)

- GIVEN a user with `customers.create`
- WHEN the user submits the form missing a mandatory datum
- THEN the backend rejects the request with a validation error on that field
- AND no customer is created

### Requirement: CLI-002 — Tipo de cliente

Every customer MUST have a type: **natural person** or **company** (DEC-CLI-01). Public entities and institutions MUST be registered as company.

Fields that depend on the type:

- Name: the person's name (natural) or the business name (company).
- Company anniversary: company only (CLI-017).
- Contact person: company only (CLI-005).

The type MUST be changeable when editing the customer (DEC-CLI-15):

- From natural to company: all data is kept.
- From company to natural: the backend MUST delete the company anniversary and the contact person in the same transaction as the type change. The audit MUST record their previous values.
- The edit screen MUST warn which data will be deleted before saving the type change.
- The identification document MUST NOT be deleted automatically. If it does not correspond to the new type (CLI-003), the backend MUST reject the save with a validation error on that field until the user changes or empties it in the same edit (DEC-CLI-15).

#### Scenario: E-23 — Cambiar una empresa a persona natural

Source citation: (CLI-002, CLI-016)

- GIVEN a company customer with anniversary and contact person loaded and a user with `customers.update`
- WHEN the user changes the type to natural person
- THEN the customer becomes a natural person, without anniversary and without contact person
- AND the audit records the type change and the deleted values

#### Scenario: E-24 — Cambio de tipo con documento incompatible

Source citation: (CLI-002, CLI-003)

- GIVEN a company customer with RIF J and a user with `customers.update`
- WHEN the user changes the type to natural person without changing the document
- THEN the backend rejects the request with a validation error on the document
- AND the customer keeps its type and its data unchanged

### Requirement: CLI-003 — Identificación del cliente

The customer MAY have an identification document composed of type and number (DEC-CLI-02):

- It is **optional** when registering and when editing the customer. It is required when confirming an order; that validation is applied by `006` (spec §10).
- Allowed types by customer type:
  - Natural person: cédula V, cédula E, or passport.
  - Company: RIF J, G, V, E, or P.
- The backend MUST reject a document type that does not correspond to the customer type, and MUST validate the number format according to the document type (DEC-CLI-30):
  - Cédula V/E: digits only, 6 to 9.
  - RIF J/G/V/E/P: 8 digits plus the check digit, which the backend MUST verify with the SENIAT algorithm.
  - Passport: letters and numbers, 5 to 20 characters.
- The user MAY type the number with dots, hyphens or spaces; the backend MUST remove them and store the number in uppercase and without separators. Uniqueness MUST be compared on that normalized form.
- The document MUST be unique per type + number (CLI-012, DEC-CLI-03).

#### Scenario: E-34 — Validar el número de documento

Source citation: (CLI-003)

- GIVEN a user with `customers.create`
- WHEN the user registers a customer with a document that does not meet its format: a cédula with fewer than 6 or more than 9 digits, a RIF with an incorrect check digit, or a passport with fewer than 5 or more than 20 characters
- THEN the backend rejects the request with a validation error on the document
- AND no customer is created

#### Scenario: E-35 — Tipo de documento según el tipo de cliente

Source citation: (CLI-003)

- GIVEN a user with `customers.create`
- WHEN the user registers a natural-person customer with a RIF J, or a company customer with a cédula
- THEN the backend rejects the request with a validation error on the document

#### Scenario: E-36 — Normalizar el documento antes de comparar

Source citation: (CLI-003, CLI-012)

- GIVEN a customer registered with a valid RIF written with hyphens
- WHEN another customer is registered with the same RIF written in lowercase, with dots and spaces
- THEN the backend recognizes it as the same document and rejects the registration as a duplicate
- AND the first customer's document is stored in uppercase and without separators

### Requirement: CLI-004 — Datos de contacto

The customer MUST have at least one contact phone usable through WhatsApp. The phone MUST be stored normalized in international format (DT-01). Only Venezuelan numbers (+58) are accepted; the backend MUST reject numbers from other countries (DEC-CLI-25). The customer's phone MUST be a mobile (04xx); the backend MUST reject landline numbers in this field (DEC-CLI-26). The email is optional; if provided, the backend MUST validate its format (DEC-CLI-04).

Technical decision DT-01 (status: proposed in the source; confirmed in `design.md`): store the phone normalized in international format (E.164), with default prefix +58.

#### Scenario: E-06 — Normalizar el teléfono

Source citation: (CLI-004) · DT-01

- GIVEN a user with `customers.create`
- WHEN the user registers a Venezuelan phone written in local format
- THEN the phone is stored in international format
- AND it is displayed in a readable format

#### Scenario: E-37 — Teléfono del cliente: solo celulares de Venezuela

Source citation: (CLI-004)

- GIVEN a user with `customers.create`
- WHEN the user registers a customer with a Venezuelan landline phone or with a number from another country
- THEN the backend rejects the request with a validation error on the phone

### Requirement: CLI-005 — Persona de contacto

A company customer MAY have **one** contact person, optional, with name, position, phone and email (DEC-CLI-05). Natural-person customers MUST NOT have a contact person.

- When registered, the contact person requires **name** and **phone**; position and email are optional (DEC-CLI-16).
- The phone MUST be normalized like the customer's phone (DT-01) and accepts Venezuelan mobiles or landlines (DEC-CLI-25, DEC-CLI-26). The email, if provided, MUST be validated for format.

#### Scenario: E-16 — Persona de contacto de una empresa

Source citation: (CLI-005)

- GIVEN a company customer without a contact person and a user with `customers.update`
- WHEN the user registers a contact person with the mandatory data
- THEN the contact person is associated with the customer and shown on the customer detail
- AND if the user tries to register a contact person on a natural-person customer, the backend rejects it with a validation error

#### Scenario: E-38 — Teléfono fijo en la persona de contacto

Source citation: (CLI-005)

- GIVEN a company customer and a user with `customers.update`
- WHEN the user registers a contact person with a Venezuelan landline phone
- THEN the contact person is saved with the normalized phone
- AND if the phone is from another country, the backend rejects it with a validation error

### Requirement: CLI-006 — Dirección

A customer of any type MAY have **one** address, optional (DEC-CLI-06).

- When registered, the address requires **address**, **city** and **state**; the reference is optional (DEC-CLI-17).
- The **state** MUST be one of a closed list of the 24 Venezuelan federal entities (23 states and the Capital District); the backend MUST reject any other value. The city is free text (DEC-CLI-33).
- The relation between this address and an order's delivery address is defined in `006` (spec §10).

#### Scenario: E-17 — Dirección del cliente

Source citation: (CLI-006)

- GIVEN a customer without an address and a user with `customers.update`
- WHEN the user registers an address with the mandatory data
- THEN the address is associated with the customer and shown on the customer detail
- AND the customer still has a single address

### Requirement: CLI-007 — Observaciones

The customer MUST have a free-text notes field, editable with `customers.update`.

> Coverage note: no scenario in source spec §7 (E-01..E-41) cites CLI-007. Not resolved here; no scenario invented.

### Requirement: CLI-008 — Editar cliente

A user with `customers.update` SHALL be able to modify the customer's data. Every modification MUST be audited with the previous and new values of the changed fields. The same validations as registration MUST apply to editing.

#### Scenario: E-05 — Editar un cliente

Source citation: (CLI-008, CLI-016)

- GIVEN an existing customer and a user with `customers.update`
- WHEN the user modifies the customer's phone
- THEN the change is saved
- AND the audit records the previous and the new value of the phone

### Requirement: CLI-009 — Desactivar y reactivar cliente

A user with `customers.deactivate` SHALL be able to deactivate an active customer and reactivate an inactive one.

- Deactivating MUST require confirmation with `ConfirmDialog` (design system §7.10, UI-07), danger variant with the verb "Desactivar"; the consequence text states that the customer will not be able to receive new quotations or orders and that anything in progress continues (DEC-CLI-09; spec §8). Reactivating MUST NOT require confirmation.
- Neither action deletes data or relations.
- Both MUST be audited.
- Deactivating an already inactive customer, or reactivating an already active one, MUST produce no changes and no audit entry, as in the Users module of `001`.
- An inactive customer MUST NOT admit new quotations or orders; quotations and orders already in progress follow their normal flow (DEC-CLI-09). These rules are applied by `004` and `006` (spec §10).
- An inactive customer MUST be editable (CLI-008) and reassignable (CLI-014) like an active one (DEC-CLI-28).

#### Scenario: E-07 — Desactivar un cliente

Source citation: (CLI-009, CLI-016)

- GIVEN an active customer and a user with `customers.deactivate`
- WHEN the user confirms the deactivation
- THEN the customer becomes inactive
- AND its data and relations are kept
- AND the action is audited

#### Scenario: E-08 — Reactivar un cliente

Source citation: (CLI-009, CLI-016)

- GIVEN an inactive customer and a user with `customers.deactivate`
- WHEN the user reactivates it
- THEN the customer becomes active
- AND the action is audited

#### Scenario: E-09 — Desactivar sin permiso

Source citation: (CLI-009, CLI-015)

- GIVEN a user with `customers.update` but without `customers.deactivate`
- WHEN the user sends the deactivation request
- THEN the backend responds 403
- AND the customer's status does not change

#### Scenario: E-40 — Cambio de estado sin efecto

Source citation: (CLI-009)

- GIVEN an inactive customer and a user with `customers.deactivate`
- WHEN the user sends the deactivation request
- THEN the customer remains inactive
- AND no audit entry is recorded

### Requirement: CLI-010 — Eliminación restringida

A user with `customers.delete` SHALL be able to delete a customer **only if it has no history**: no quotation, order, payment, or other record that references it (DEC-CLI-13).

- Intended use: duplicates, records created by mistake, and test data. Customers with history MUST NOT be deleted; they are deactivated (CLI-009). This complies with AGENTS §7.9.
- Deleting MUST require confirmation with `ConfirmDialog` (UI-07), `danger` variant and the verb "Eliminar". The delete action is visible only with `customers.delete`; the confirmation text states the action is irreversible. If the backend rejects it because of history, the reason is shown and deactivating is offered (spec §8).
- The contact person and the address are part of the customer: they MUST be deleted together with it, in the same transaction.
- The deletion MUST be audited with a copy of the previous values of the customer, its contact person and its address.
- If the customer has history, the backend MUST reject the deletion, state the reason, and suggest deactivating it.
- In this spec no dependent records exist yet. Each spec that introduces one (`004`, `006`, …) adds its blocking condition to the deletion and its test scenario. Also, the foreign keys from those history records toward the customer MUST use a delete restriction, so that the database prevents the deletion even if the validation is missing. The contact person and the address are not history and MUST NOT block deletion.

#### Scenario: E-10 — Eliminar un cliente sin historial

Source citation: (CLI-010, CLI-016)

- GIVEN a company customer without history, with a contact person and an address, and a user with `customers.delete`
- WHEN the user confirms the deletion
- THEN the customer, its contact person and its address cease to exist
- AND the audit keeps who deleted it, when, and a copy of the previous values of the customer, its contact person and its address

#### Scenario: E-18 — Eliminar sin permiso

Source citation: (CLI-010, CLI-015)

- GIVEN a customer without history and a user with `customers.update` and `customers.deactivate`, but without `customers.delete`
- WHEN the user sends the deletion request
- THEN the backend responds 403
- AND the customer still exists

#### Scenario: E-19 — Eliminación bloqueada por historial

Source citation: (CLI-010)

> DEFERRED to `004` / `006` (source spec §7 note and §12 DoD). In `002` no dependent records exist, so this scenario is transcribed but not testable here. It is tested in each spec that introduces a dependent record (for example "customer with a quotation" in `004` and "customer with an order" in `006`).

- GIVEN a customer with at least one dependent record and a user with `customers.delete`
- WHEN the user sends the deletion request
- THEN the backend rejects it and states the reason
- AND the customer and its history still exist

### Requirement: CLI-011 — Listado y búsqueda

A user with `customers.view` SHALL be able to list customers:

- with **Activos**, **Inactivos** and **Todos** views (`SegmentedTabs`, design system §7.11), whose counts are computed by the backend;
- with the default view used in the Users list of `001`;
- with search by name or business name, document number and phone;
- with the **Mis clientes** filter, available to users with `customers.portfolio`, which shows only the customers assigned to the user and combines with the status views and the search (DEC-CLI-08);
- paginated in the backend.

The last purchase date and the order by recent activity are added in `006`, when orders exist (spec §10).

#### Scenario: E-11 — Buscar clientes

Source citation: (CLI-011)

- GIVEN customers registered with different names, documents and phones
- WHEN a user with `customers.view` searches by a fragment of the name, the document or the phone
- THEN the list returns only the matching customers

#### Scenario: E-12 — Vistas por estado

Source citation: (CLI-011)

- GIVEN active and inactive customers
- WHEN a user with `customers.view` selects the Inactivos view
- THEN the list shows only the inactive customers
- AND the counts of each view match the backend data

#### Scenario: E-15 — Visibilidad y filtro Mis clientes

Source citation: (CLI-011, CLI-014)

- GIVEN two advisors with `customers.view` and `customers.portfolio`, each with assigned customers
- WHEN one of them queries the list without a filter
- THEN the user sees the customers of both portfolios and those without an advisor
- AND when applying the Mis clientes filter, the user sees only the customers assigned to that user

### Requirement: CLI-012 — Detección de duplicados

When registering or editing, the backend MUST check whether another customer, active or inactive, already has the same document or the same phone (DEC-CLI-03):

- **Document**: unique per document type + number. If it already exists, the backend MUST reject the save with a validation error. The database MUST guarantee uniqueness even if the validation is missing.
- **Phone**: MAY repeat. If it already exists, the backend MUST NOT save on the first attempt: it returns a warning that identifies the matching customer or customers. The user MAY confirm, and then it is saved. The comparison is only against the main phone of other customers (not against contact persons) and is only made when the phone is new or changed; editing other data MUST NOT repeat the warning (DEC-CLI-27).

#### Scenario: E-13 — Documento duplicado

Source citation: (CLI-012)

- GIVEN a customer, active or inactive, registered with a document type and number
- WHEN another customer is registered or edited with the same type and number
- THEN the backend rejects the request with a validation error on the document
- AND no customer is created or modified

#### Scenario: E-14 — Teléfono duplicado

Source citation: (CLI-012)

- GIVEN a customer registered with a phone
- WHEN another customer is registered with the same phone without confirming
- THEN the backend does not create the customer and returns a warning that identifies the matching customer
- AND if the user resubmits the request confirming, the customer is created
- AND when editing another customer to give it that same phone, the backend returns the same warning and only saves with confirmation
- AND when editing other data of a customer whose phone already matches another customer's, without changing the phone, the backend saves without a warning (DEC-CLI-27)

### Requirement: CLI-013 — Ficha del cliente

A user with `customers.view` SHALL be able to consult the customer detail with its data, status, contact person, address, assigned advisor and notes. The detail MUST be structured in sections so that `004` and `006` add the quotation, order and payment history without redesigning it.

> Coverage note: no scenario in source spec §7 cites CLI-013 directly; E-16, E-17, E-20 and E-33 verify that specific data is shown on the detail. Not resolved here; no scenario invented.

### Requirement: CLI-014 — Asesora asignada y visibilidad

The customer MAY have **one** assigned advisor: an internal user responsible for its portfolio (DEC-CLI-07).

- The assignment and each reassignment MUST be audited with the previous and the new advisor (CLI-016).
- Assignment rules (DEC-CLI-18):
  - Only **active** users with `customers.portfolio` MAY be assigned advisors.
  - When registering a customer, it MUST be automatically assigned to the creating user if the user has `customers.portfolio`. If the user does not have it, the customer is created without an advisor.
  - Assigning or reassigning after registration MUST require `customers.assign`. The backend MUST reject an advisor that does not meet the first rule.
  - The assigned advisor is optional: a user with `customers.assign` MAY leave the customer without an advisor.
  - If the assigned advisor is deactivated or loses `customers.portfolio`, their customers MUST remain assigned to them; the detail and the list flag them as "asesora no disponible" and a user with `customers.assign` reassigns them manually. There is no automatic unassignment (DEC-CLI-29).
- The assignment MUST NOT restrict visibility: every user with `customers.view` sees all customers (DEC-CLI-08). The portfolio is used as a list filter (CLI-011).

The assignment MUST NOT, by itself, determine sales attribution or commissions (business rules §20); that rule is defined in `006` / `014`.

#### Scenario: E-25 — Asignación automática al crear

Source citation: (CLI-014)

- GIVEN a user with `customers.create` and `customers.portfolio`
- WHEN the user registers a customer
- THEN the customer is assigned to that user
- AND if the user does not have `customers.portfolio`, the customer is left without an advisor

#### Scenario: E-26 — Reasignar la asesora

Source citation: (CLI-014, CLI-016)

- GIVEN a customer assigned to an advisor, another active advisor with `customers.portfolio`, and a user with `customers.assign`
- WHEN the user reassigns the customer to the other advisor
- THEN the customer is assigned to the new advisor
- AND the audit records the previous and the new advisor

#### Scenario: E-27 — Reasignar sin permiso

Source citation: (CLI-014, CLI-015)

- GIVEN a user with `customers.update` but without `customers.assign`
- WHEN the user sends a request to change a customer's advisor
- THEN the backend responds 403
- AND the customer's advisor does not change

#### Scenario: E-28 — Asesora no elegible

Source citation: (CLI-014)

- GIVEN a user with `customers.assign`
- WHEN the user tries to assign a customer to an inactive user or one without `customers.portfolio`
- THEN the backend rejects the request with a validation error
- AND the customer's advisor does not change

#### Scenario: E-33 — Asesora no disponible

Source citation: (CLI-014)

- GIVEN a customer assigned to an advisor who is later deactivated
- WHEN a user with `customers.view` consults the detail or the list
- THEN the customer remains assigned to that advisor
- AND it is indicated that the advisor is not available ("asesora no disponible")

### Requirement: CLI-015 — Autorización en backend

Every customer route and action MUST validate the corresponding permission through Policies in the backend. Hiding a button does not replace this validation (AGENTS §7.2). Authorization denials MUST be produced by Policy, not by manual `abort(403)` (AGENTS §7.2).

The system MUST provide the following permissions, following the `modulo.accion` convention defined in `001` (if `001` separates activating and deactivating into distinct permissions, this table is aligned with that convention in `design.md`):

| Permission | Allows |
|---|---|
| `customers.view` | View the list and the detail of all customers, whether or not they are in the user's portfolio (DEC-CLI-08) |
| `customers.create` | Register customers |
| `customers.update` | Edit the customer's data, its contact person and its address |
| `customers.deactivate` | Deactivate and reactivate customers |
| `customers.delete` | Delete customers without history (CLI-010) |
| `customers.assign` | Assign and reassign a customer's advisor (CLI-014) |
| `customers.portfolio` | Be able to have a portfolio: be the assigned advisor of customers (CLI-014) |

Initial assignment to the `001` roles (DEC-CLI-11). The code MUST verify the permission, never the role name (AGENTS §7.3); afterwards it is managed from the roles screen.

| Permission | Administrador | Gerente | Asesora de Ventas | Finanzas | Supervisor de Producción · Operario · Responsable de Calidad |
|---|---|---|---|---|---|
| `customers.view` | yes | yes | yes | yes | no |
| `customers.create` | yes | yes | yes | no | no |
| `customers.update` | yes | yes | yes | no | no |
| `customers.deactivate` | yes | yes | no | no | no |
| `customers.delete` | yes | no | no | no | no |
| `customers.assign` | yes | yes | no | no | no |
| `customers.portfolio` | no | no | yes | no | no |

**Coherence rule** (DEC-CLI-32, analogous to DEC-022 of `001`): when assigning permissions to any role, `customers.create`, `customers.update`, `customers.deactivate`, `customers.delete`, `customers.assign` and `customers.portfolio` MUST require `customers.view`. An assignment that does not comply MUST be rejected and the role MUST NOT change. The initial matrix above already complies.

`001` assigns all permissions to the Administrador only when creating the role (`RoleSeeder`). `design.md` MUST define how this matrix is applied to an existing database.

Other 403 scenarios are placed under the requirement they first cite: E-09 (CLI-009), E-18 (CLI-010), E-27 (CLI-014).

#### Scenario: E-03 — Registrar sin permiso

Source citation: (CLI-015)

- GIVEN an authenticated user without `customers.create`
- WHEN the user sends a customer registration request
- THEN the backend responds 403
- AND no customer is created

#### Scenario: E-04 — Consultar sin permiso

Source citation: (CLI-015)

- GIVEN an authenticated user without `customers.view`
- WHEN the user requests the list or the detail of a customer
- THEN the backend responds 403

#### Scenario: E-41 — Coherencia de permisos de clientes

Source citation: (§4, DEC-CLI-32)

- GIVEN a user with permission to manage roles and a role without `customers.view`
- WHEN the user tries to assign it `customers.update` (or any other `customers.*` permission other than `customers.view`) without `customers.view`
- THEN the backend rejects the assignment with a validation error
- AND the role's permissions do not change

### Requirement: CLI-016 — Auditoría

The following MUST be audited: registration (including import, CLI-018), editing, deactivation, reactivation, deletion, and advisor assignment or reassignment (CLI-014), recording user, action, affected customer, date and time, IP, and values before and after (AGENTS §7.8).

> Coverage note: CLI-016 has no scenario placed directly under it; its audit outcomes are asserted in E-01, E-05, E-07, E-08, E-10, E-23, E-26 and E-29 (which cite CLI-016 and are placed under their first cited requirement), and E-40 (no audit entry on a no-op change). Not resolved here; no scenario invented.

### Requirement: CLI-017 — Fechas conmemorativas

The customer MAY register optional commemorative dates, expressed only as day and month (no year) (DEC-CLI-14):

- **Customer birthday**: available for any customer type.
- **Company anniversary**: available only for company customers. The backend MUST reject this datum on other types.

The backend MUST validate that the day and month form a valid date; 29 February is accepted (DEC-CLI-31). The dates are shown on the detail and edited with `customers.update`.

This spec MUST NOT generate reminders. Birthday reminders to sales advisors, Management and Administration are defined by the Notifications spec (spec §10).

#### Scenario: E-20 — Registrar el cumpleaños del cliente

Source citation: (CLI-017)

- GIVEN a user with `customers.update` and a customer of any type
- WHEN the user registers a birthday with a valid day and month
- THEN the date is saved without a year
- AND it is shown on the customer detail

#### Scenario: E-21 — Rechazar una fecha conmemorativa inválida

Source citation: (CLI-017)

- GIVEN a user with `customers.update`
- WHEN the user registers a birthday or an anniversary with a non-existent day and month combination
- THEN the backend rejects the request with a validation error on that field

#### Scenario: E-22 — Aniversario solo para empresas

Source citation: (CLI-017)

- GIVEN a user with `customers.update`
- WHEN the user registers a company anniversary on a company customer
- THEN the date is saved
- AND if the user tries it on a customer of another type, the backend rejects it with a validation error

### Requirement: CLI-018 — Carga inicial de clientes existentes

The technical team SHALL be able to load the list of Ecolekua's existing customers exactly once through a console command, with no screen in the ERP (DEC-CLI-19). The list keeps growing during development; the load is executed once, with the final list, when the system goes to production.

- Each imported customer MUST go through the same validations as registration (CLI-001 to CLI-006, CLI-012, CLI-017).
- Each created customer MUST be audited as a registration, identifying that it comes from the import.
- The file MUST be CSV encoded in UTF-8 (DEC-CLI-20). The columns are defined in `design.md` from the fields of the conceptual model. If the file is not valid UTF-8 or lacks the expected columns, the command MUST import nothing and state the reason.
- **All or nothing** (DEC-CLI-21): the command MUST first validate all rows, including matches between rows of the same file. If any fails, it MUST import no customer and deliver a report with row number, field and reason. If all are valid, it MUST import them in a single transaction.
- **Repeated phones** (DEC-CLI-22), in the file or against the database: the report MUST list them as a warning and the command MUST NOT import. They are imported only if the command is run again with an explicit confirmation option, after reviewing them.
- **Advisor** (DEC-CLI-23): the file has an optional column with the advisor's email. If provided, it MUST correspond to an active user with `customers.portfolio`; otherwise the row is invalid. If empty, the customer is left without an advisor. The automatic assignment of CLI-014 MUST NOT apply in the import.
- **Author** (DEC-CLI-24): the command MUST require an active internal user to be indicated, who appears as creator of each customer and as author of its audit record, marked as import. If the user does not exist or is inactive, the command MUST import nothing.
- The source file contains real customer data: it MUST NOT be versioned in the repository nor saved in Engram (AGENTS §4.5, §11).

#### Scenario: E-29 — Importar un archivo válido

Source citation: (CLI-018, CLI-016)

- GIVEN a UTF-8 CSV with valid rows, some with an eligible advisor email, and an active internal user indicated as author
- WHEN the import command is run
- THEN all customers are created, active, with their advisor when the row indicates one
- AND each customer shows as created by the indicated user and has its audit record marked as import

#### Scenario: E-30 — Importación con una fila inválida

Source citation: (CLI-018)

- GIVEN a CSV in which a row does not meet the registration validations (for example, without a phone or with an already registered document)
- WHEN the import command is run
- THEN no customer is created
- AND the report states the row number, the field and the reason

#### Scenario: E-31 — Teléfonos repetidos en la importación

Source citation: (CLI-018, CLI-012)

- GIVEN a valid CSV with a phone that already exists in the database or repeats in the file
- WHEN the command is run without the confirmation option
- THEN no customer is created and the report lists the matches as a warning
- AND when run with the confirmation option, all customers are created

#### Scenario: E-32 — Asesora o autor no válidos en la importación

Source citation: (CLI-018)

- GIVEN a CSV whose advisor column indicates an inactive user or one without `customers.portfolio`, or a non-existent or inactive author
- WHEN the import command is run
- THEN no customer is created
- AND the report states the reason

#### Scenario: E-39 — Importación con un archivo ilegible

Source citation: (CLI-018)

- GIVEN a file that is not valid UTF-8 or that lacks expected columns
- WHEN the import command is run
- THEN no customer is created
- AND the command states the reason

## Decisions index (source spec §9)

All decisions are status **Confirmada** (2026-09-30) unless noted. Each is applied in the requirement that cites it.

| ID | Decision (confirmed option) | Applied in |
|---|---|---|
| DEC-CLI-01 | Customer types: natural and company; public entities registered as company | CLI-002 |
| DEC-CLI-02 | Document optional on create; required to confirm an order (`006`); natural: cédula V/E or passport; company: RIF J/G/V/E/P | CLI-003 |
| DEC-CLI-03 | Document unique per type + number (block); phone warns and allows saving with confirmation | CLI-003, CLI-012 |
| DEC-CLI-04 | Mandatory data: type + name/business name + phone; email optional | CLI-001, CLI-004 |
| DEC-CLI-05 | One optional contact person, companies only | CLI-005 |
| DEC-CLI-06 | One optional address per customer | CLI-006 |
| DEC-CLI-07 | One advisor per customer | CLI-014 |
| DEC-CLI-08 | Everyone with `customers.view` sees all customers, with "Mis clientes" filter | CLI-011, CLI-014 |
| DEC-CLI-09 | Inactive customer: no new quotations or orders; in-progress ones continue | CLI-009 |
| DEC-CLI-10 | Customer is not classified; origin lives on each order | Conceptual model |
| DEC-CLI-11 | Initial permission matrix per role | CLI-015 |
| DEC-CLI-12 | No visible customer code | Conceptual model |
| DEC-CLI-13 | Delete only customers without any history | CLI-010 |
| DEC-CLI-14 | `002` only stores and shows commemorative dates; reminders belong to Notifications | CLI-017 |
| DEC-CLI-15 | Type change allowed; company to natural removes anniversary and contact person with audit; incompatible document rejected until changed or emptied | CLI-002 |
| DEC-CLI-16 | Contact person requires name + phone | CLI-005 |
| DEC-CLI-17 | Address requires address + city + state; reference optional | CLI-006 |
| DEC-CLI-18 | Advisor assignment rules | CLI-014 |
| DEC-CLI-19 | One-time initial load by technical console command | CLI-018 |
| DEC-CLI-20 | Import file: CSV in UTF-8 | CLI-018 |
| DEC-CLI-21 | Import all or nothing | CLI-018 |
| DEC-CLI-22 | Repeated phones in import: warning, import only with explicit confirmation option | CLI-018 |
| DEC-CLI-23 | Optional advisor-email column in import | CLI-018 |
| DEC-CLI-24 | Import requires an active internal author user | CLI-018 |
| DEC-CLI-25 | Venezuela (+58) phones only | CLI-004, CLI-005 |
| DEC-CLI-26 | Customer phone mobile only; contact person mobile or landline | CLI-004, CLI-005 |
| DEC-CLI-27 | Duplicate-phone warning only against other customers' main phone, when new or changed | CLI-012 |
| DEC-CLI-28 | Inactive customers are editable and reassignable | CLI-009 |
| DEC-CLI-29 | Unavailable advisor: customers stay assigned, flagged "asesora no disponible", manual reassignment | CLI-014 |
| DEC-CLI-30 | Document validation: cédula 6–9 digits; RIF 8 digits + check digit; passport 5–20 alphanumerics; stored uppercase without separators | CLI-003 |
| DEC-CLI-31 | 29 February is valid in commemorative dates | CLI-017 |
| DEC-CLI-32 | `customers.*` write/assign/portfolio permissions require `customers.view` | CLI-015 |
| DEC-CLI-33 | Address state is a closed list of the 24 Venezuelan federal entities; city is free text | CLI-006 |
| DT-01 | Phone stored normalized in E.164 with default prefix +58 (technical; proposed, confirmed in `design.md`) | CLI-004 |

## Commitments for later specs (not requirements of this change)

Recorded from source spec §10; not implemented here:

- `004`: add "has quotations" as a CLI-010 blocking condition, with its E-19 test.
- `006`: add "has orders or payments" as a CLI-010 blocking condition, with its E-19 test; require an identification document to confirm an order (CLI-003, DEC-CLI-02).
- `004` / `006`: reject new quotations and orders for inactive customers without affecting those in progress (CLI-009, DEC-CLI-09).
- `006` / `011`: define whether the customer address (CLI-006) is proposed as the order delivery address and whether the order keeps its own copy.
- `006`: show the last purchase date in the list and the detail and allow ordering by recent activity (no inactivity filter).
- Notifications: customer birthday reminders (CLI-017), recipients by permission, channel, lead time, anniversary, anti-saturation rule, and 29 February handling in non-leap years (DEC-CLI-14, DEC-CLI-31).
