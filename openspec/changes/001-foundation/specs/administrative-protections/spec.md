# Administrative Protections Specification

> Transcribed from `docs/specs/001-foundation.md` v1.2 (§8 "Protecciones administrativas", §11 "Protecciones administrativas", §16 "Decisiones de esta Spec"). Faithful transcription — no added, removed, or reinterpreted behavior. Original IDs (FND-xxx, E-xx) preserved verbatim.

## Purpose

Defines the safeguards that prevent the system from being left without administrative capability, and the restrictions preventing a user from acting on their own administrative status.

## Requirements

### Requirement: FND-020 — Protección contra Pérdida de Administración

The system MUST prevent any operation that would leave the system without at least one active user holding both the `users.assign_roles` and `roles.manage` permissions. This includes:

- deactivating that user;
- removing their roles;
- removing those permissions from their roles.

> Decision note (DEC-021): The first administrator is created through an interactive console command that requests first name, last name, and email, generates a temporary password that forces a change at the first login, only runs if there is not already an active user holding the Administrador role, and is recorded in the audit log as a console event. Credentials MUST NOT be stored in environment variables or in the code. (Source: `docs/specs/001-foundation.md` §16, DEC-021)

#### Scenario: E-25 — Último administrador

Source citation: (FND-020)

- GIVEN a single active user holding `users.assign_roles` and `roles.manage`
- WHEN an attempt is made to deactivate them, remove their roles, or remove those permissions from their roles
- THEN the system rejects the operation

### Requirement: FND-021 — Acciones sobre Uno Mismo

A user MUST NOT be able to:

- deactivate themselves;
- modify their own roles.

> Decision note (DEC-020): An administrator with the corresponding permissions MAY modify their own data and reset their own password from user management, the same as with any other user. The only restrictions on themselves are those in this requirement (FND-021): they MUST NOT deactivate themselves or modify their own roles. (Source: `docs/specs/001-foundation.md` §16, DEC-020)

#### Scenario: E-26 — Acciones sobre uno mismo

Source citation: (FND-021)

- GIVEN a user with administrative permissions
- WHEN they attempt to deactivate themselves or modify their own roles
- THEN the system rejects the operation
