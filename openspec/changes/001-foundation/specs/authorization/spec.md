# Authorization Specification

> Transcribed from `docs/specs/001-foundation.md` v1.2 (§8 "Autorización", "Protección por Defecto y Operaciones Públicas", §11 "Roles y autorización"). Faithful transcription — no added, removed, or reinterpreted behavior. Original IDs (FND-xxx, E-xx) preserved verbatim. No new decisions in §16 v1.2 (DEC-017..DEC-021) reference this domain's requirements.

## Purpose

Defines that authorization is always validated in the backend, and that every operation is protected by default unless a spec explicitly declares it public.

## Requirements

### Requirement: FND-019 — Autorización en Backend

Every protected operation MUST validate, in the backend, the user's authentication and, when applicable, their permissions.

Hiding an option in the frontend does NOT constitute an authorization measure.

A non-authenticated user attempting to access a protected operation MUST be rejected and directed to log in.

An authenticated user without the required permission MUST receive an access-denied response, with no effect of the operation executed.

#### Scenario: E-19 — Usuario sin permiso

Source citation: (FND-019)

- GIVEN an authenticated user without the required permission
- WHEN they attempt to execute the operation directly against the backend
- THEN they receive access denied
- AND the operation produces no effects
- AND the attempt is recorded in the audit log

#### Scenario: E-20 — Usuario con permiso

Source citation: (FND-019)

- GIVEN an authenticated user with the required permission
- WHEN they execute the operation
- THEN the backend allows it

### Requirement: FND-026 — Protección por Defecto y Operaciones Públicas

Every operation of the system is protected by default.

An operation MAY only be public if a spec explicitly declares it as such. This spec does not declare any public operation other than login.

Public operations MUST NOT grant access to protected operations or to internal data not declared by the spec that defines them.

Authentication of portal visitors or customers, if it exists, is defined in specs 015 and 016 and does NOT grant roles or permissions from this spec's model.

#### Scenario: E-30 — Protección por defecto

Source citation: (FND-026)

- GIVEN the set of routes and operations registered in the system
- WHEN a non-authenticated visitor attempts to access any of them that has not been declared public by a spec
- THEN the system rejects it and directs them to log in
