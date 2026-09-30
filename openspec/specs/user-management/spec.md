# User Management Specification

> Transcribed from `docs/specs/001-foundation.md` v1.3 (§8 "Usuarios", §11 "Usuarios y contraseñas", §16 "Decisiones de esta Spec"). Faithful transcription — no added, removed, or reinterpreted behavior. Original IDs (FND-xxx, E-xx) preserved verbatim. DEC-023 (v1.3) refines FND-010 and FND-011; the spec adds no E-xx scenario for it, so its tests are named by decision id (`DEC-023 …`).

## Purpose

Defines the identity, uniqueness, status, administration, and historical-integrity rules for Ecolekua's internal users (staff accounts), independent of authentication and password mechanics.

## Requirements

### Requirement: FND-007 — Identidad del Usuario

Every user MUST have, at minimum:

- an identifier;
- a first name;
- a last name;
- an email address;
- a securely stored password;
- a status (active or inactive);
- a forced-password-change indicator;
- a creation date;
- an update date.

Additional fields MUST only be defined when a concrete functional need arises.

> Coverage note: no scenario in spec 001 §11 (E-01..E-30) directly names FND-007. Flagged under risks/ambiguities — not resolved here per AGENTS.md §6.

### Requirement: FND-008 — Unicidad del Correo Electrónico

The email address is the login identifier and MUST be unique across all users, active and inactive.

The email address MUST be normalized (no leading/trailing spaces, lowercase) before being stored and before being compared.

An inactive user retains their email address; it MUST NOT be reusable for another user.

#### Scenario: E-12 — Correo duplicado

Source citation: (FND-008)

- GIVEN an existing user, active or inactive, with a given email address
- WHEN an attempt is made to create or modify another user with that same email address
- THEN the system rejects the operation

> Cross-reference: E-05 (capability `authentication`) also verifies this requirement's normalization behavior during login; it is transcribed there because the source spec places it within the "Autenticación y sesión" scenario block.

### Requirement: FND-009 — Estado del Usuario

A user MUST be either active or inactive.

An inactive user MUST NOT be able to log in or execute protected operations.

> Cross-reference: E-04 (capability `authentication`) verifies this requirement jointly with FND-002; it is transcribed there because the source spec cites both FND-002 and FND-009 together and places the scenario within the "Autenticación y sesión" block.

### Requirement: FND-010 — Gestión de Usuarios

A user with the corresponding permissions MUST be able to:

- view the user list and detail (`users.view`);
- create users (`users.create`);
- modify a user's first name, last name, and email address (`users.update`);
- activate and deactivate users (`users.deactivate`);
- reset a user's password (`users.reset_password`);
- assign and remove roles for a user (`users.assign_roles`).

Physical deletion of users is NOT permitted.

Every new user MUST be created in active status, with a temporary password, and with at least one role assigned.

> Decision note (DEC-020): An administrator with the corresponding permissions MAY modify their own data and reset their own password from user management, the same as with any other user. The only restrictions on themselves are those in FND-021: they MUST NOT deactivate themselves or modify their own roles. (Source: `docs/specs/001-foundation.md` §16, DEC-020)

> Decision note (DEC-023, refines FND-010 and FND-011): users list by status. The user list MUST offer the views Active (default), Inactive and All. The filtering and the count of each view MUST be resolved by the backend. Physical deletion of users does not exist (BR-FND-005); removal remains deactivation. (Source: `docs/specs/001-foundation.md` §16, DEC-023; no E-xx scenario)

#### Scenario: E-11 — Creación de usuario

Source citation: (FND-010, FND-014)

- GIVEN a user with `users.create`
- WHEN they create a user with first name, last name, email, temporary password, and at least one role
- THEN the created user is active and has a forced password change pending

#### Scenario: E-13 — Usuario sin rol

Source citation: (FND-010, FND-018)

- GIVEN a user with `users.create`
- WHEN they attempt to create a user with no roles
- THEN the system rejects the operation

### Requirement: FND-011 — Historial de Usuarios Inactivos

Deactivating a user MUST NOT delete or alter their history. Historical actions performed by that user MUST remain associated with their identity.

#### Scenario: E-18 — Historial de usuario inactivo

Source citation: (FND-011)

- GIVEN a user who performed actions recorded in the audit log
- WHEN they are deactivated
- THEN their audit records retain the reference to their identity
