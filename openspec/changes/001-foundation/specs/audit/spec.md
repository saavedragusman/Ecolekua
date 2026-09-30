# Audit Specification

> Transcribed from `docs/specs/001-foundation.md` v1.3 (content for this domain unchanged since v1.2; §8 "Auditoría", §11 "Auditoría", §16 "Decisiones de esta Spec"). Faithful transcription — no added, removed, or reinterpreted behavior. Original IDs (FND-xxx, E-xx) preserved verbatim.

## Purpose

Defines which events must be audited, what each audit record must contain, the immutability of the audit trail, and who may query it.

## Requirements

### Requirement: FND-022 — Eventos Auditados

The system MUST record, at minimum, the following events in the audit log:

| Group | Events |
|---|---|
| Authentication | Successful login, failed login, lockout due to attempts, logout |
| Users | Creation, data modification, activation, deactivation |
| Passwords | Administrator reset, own password change |
| Roles | Creation, modification, deletion, permission assignment and removal |
| Assignments | Role assignment and removal for users |
| Authorization | Operation attempt denied for lack of permission |

Later specs MAY add their own audited events.

> Decision note (DEC-021): The first administrator is created through an interactive console command that requests first name, last name, and email, generates a temporary password that forces a change at the first login, only runs if there is not already an active user holding the Administrador role, and is recorded in the audit log as a console event. Credentials MUST NOT be stored in environment variables or in the code. (Source: `docs/specs/001-foundation.md` §16, DEC-021)

#### Scenario: E-27 — Registro de operación sensible

Source citation: (FND-022, FND-023)

- GIVEN an authorized user
- WHEN they modify another user's email address
- THEN a record is created with actor, action, affected user, date and time, source IP, and the before/after values

### Requirement: FND-023 — Contenido del Registro de Auditoría

Every audit record MUST contain:

- the user who performed the action (or the attempted email, for failed logins);
- the action performed;
- the affected entity and its identifier, when applicable;
- date and time;
- source IP address;
- previous and new values of modified fields, when applicable.

Audit records MUST NEVER contain passwords, temporary passwords, or their hashes.

When a later spec audits public operations, it MUST define how the actor is identified (e.g., customer or anonymous visitor), always preserving date, time, and source IP.

#### Scenario: E-28 — Auditoría sin contraseñas

Source citation: (FND-023)

- GIVEN a password reset or password change
- WHEN it is recorded in the audit log
- THEN the record does not contain the password or its hash

### Requirement: FND-024 — Inmutabilidad de la Auditoría

Audit records MUST NOT be modifiable or deletable from the system.

They MUST be retained indefinitely while no documented retention policy exists.

#### Scenario: E-29 — Auditoría inmutable

Source citation: (FND-024)

- GIVEN an existing audit record
- WHEN any user, including an Administrador, attempts to modify or delete it from the system
- THEN no operation exists that allows it

### Requirement: FND-025 — Consulta de Auditoría

A user with the `audit.view` permission MUST be able to query audit records, filtering at least by user, action, and date range.

> Decision note (DEC-019): Ecolekua's operating timezone is `America/Caracas`. Dates MUST be stored in UTC; the audit query MUST display and filter by date range in that timezone. (Source: `docs/specs/001-foundation.md` §16, DEC-019)

> Coverage note: no scenario in spec 001 §11 (E-01..E-30) directly names FND-025. Flagged under risks/ambiguities — not resolved here per AGENTS.md §6.
