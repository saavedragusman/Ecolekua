# Authentication Specification

> Transcribed from `docs/specs/001-foundation.md` v1.3 (content for this domain unchanged since v1.2; §8 "Autenticación y sesión", §11 "Autenticación y sesión", §16 "Decisiones de esta Spec"). Faithful transcription — no added, removed, or reinterpreted behavior. Original IDs (FND-xxx, E-xx) preserved verbatim.

## Purpose

Defines how an Ecolekua user authenticates with email and password, how the system reports authentication failures without leaking information, how repeated failures are throttled, and how sessions end (logout, inactivity expiry, or administrative invalidation).

## Requirements

### Requirement: FND-001 — Autenticación

The system MUST allow a registered, active user to authenticate using their email address and password.

A non-authenticated user MUST NOT be able to access protected operations.

#### Scenario: E-01 — Inicio de sesión correcto

Source citation: (FND-001)

- GIVEN an active user with no pending forced password change
- WHEN they enter their correct email and password
- THEN the system authenticates them and grants access to the operations their permissions authorize

#### Scenario: E-02 — Credenciales incorrectas

Source citation: (FND-001, FND-002)

- GIVEN a registered user
- WHEN they enter an incorrect password
- THEN the system rejects the authentication with the generic message defined by FND-002

#### Scenario: E-05 — Correo con mayúsculas y espacios

Source citation: (FND-008)

> Placement note: this scenario's normative citation in the source spec is FND-008 (email uniqueness and normalization, transcribed under capability `user-management`). The source spec places it within the "Autenticación y sesión" scenario block (E-01..E-10), and the proposal's capability mapping groups `authentication` as `E-01..E-10`. It is transcribed here, attached to FND-001, to preserve that placement; see `user-management`'s FND-008 for the requirement it actually verifies.

- GIVEN a user registered with `ana@ecolekua.com`
- WHEN they log in with ` Ana@Ecolekua.com ` (leading/trailing spaces, mixed case)
- THEN the system identifies them correctly

### Requirement: FND-002 — Mensaje de autenticación fallida

Given incorrect credentials, a nonexistent email, or an inactive user, the system MUST display the same generic error message, without revealing which of these conditions occurred.

> Decision note (DEC-018): While an email address is locked out, a login attempt with that email MUST show a specific message stating the temporary lockout due to too many attempts and the remaining minutes. The lockout applies to any email, whether it belongs to a registered account or not, so this message MUST NOT reveal whether the account exists. (Source: `docs/specs/001-foundation.md` §16, DEC-018)

#### Scenario: E-03 — Correo inexistente

Source citation: (FND-002)

- GIVEN an email that does not belong to any user
- WHEN a login is attempted with it
- THEN the system rejects the authentication with the same generic message as in E-02

#### Scenario: E-04 — Usuario inactivo

Source citation: (FND-002, FND-009)

- GIVEN an inactive user
- WHEN they attempt to log in with correct credentials
- THEN the system rejects access with the same generic message as in E-02

### Requirement: FND-003 — Limitación de intentos

After 5 consecutive failed login attempts for the same email address, the system MUST temporarily block further attempts for that email for 15 minutes.

A successful login MUST reset the failed-attempt counter.

> Decision note (DEC-017): Failed attempts MUST NOT expire merely by elapsed time; the counter only returns to 0 on a successful login or when the 15-minute lockout period ends. (Source: `docs/specs/001-foundation.md` §16, DEC-017)

> Decision note (DEC-018): While an email address is locked out, a login attempt with that email MUST show a specific message stating the temporary lockout due to too many attempts and the remaining minutes. The lockout applies to any email, whether it belongs to a registered account or not, so this message MUST NOT reveal whether the account exists. (Source: `docs/specs/001-foundation.md` §16, DEC-018)

#### Scenario: E-06 — Bloqueo por intentos fallidos

Source citation: (FND-003)

- GIVEN an email with 5 consecutive failed attempts
- WHEN a sixth attempt is made, even with the correct password, before 15 minutes have elapsed
- THEN the system rejects the attempt
- AND the lockout is recorded in the audit log

### Requirement: FND-004 — Cierre de sesión

An authenticated user MUST be able to log out. After logging out, the session MUST NOT allow access to protected operations.

#### Scenario: E-07 — Cierre de sesión

Source citation: (FND-004)

- GIVEN an authenticated user
- WHEN they log out and then attempt to access a protected operation
- THEN the system rejects the attempt and redirects them to log in

### Requirement: FND-005 — Expiración de sesión

A session MUST expire after 120 minutes of inactivity.

An expired session MUST NOT allow access to protected operations, and the user MUST authenticate again.

#### Scenario: E-08 — Sesión expirada

Source citation: (FND-005)

- GIVEN a user whose session has been inactive for more than 120 minutes
- WHEN they attempt to execute a protected operation
- THEN the system rejects the attempt and redirects them to log in

### Requirement: FND-006 — Invalidación de sesiones

When a user is deactivated, all of their open sessions MUST be invalidated immediately; the user's next request MUST be rejected.

When a user's roles change, or a role's permissions change, the change MUST take effect starting with the affected user's next request, without requiring them to log in again.

#### Scenario: E-09 — Desactivación con sesión abierta

Source citation: (FND-006)

- GIVEN a user with an open session
- WHEN an administrator deactivates them
- THEN the user's next request is rejected

#### Scenario: E-10 — Cambio de permisos en caliente

Source citation: (FND-006)

- GIVEN an authenticated user whose role loses a permission
- WHEN they attempt to execute the operation that permission previously authorized
- THEN the backend rejects it, without the user having logged in again
