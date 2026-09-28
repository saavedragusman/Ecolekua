# Password Management Specification

> Transcribed from `docs/specs/001-foundation.md` v1.2 (§8 "Contraseñas", §11 "Usuarios y contraseñas", §16 "Decisiones de esta Spec"). Faithful transcription — no added, removed, or reinterpreted behavior. Original IDs (FND-xxx, E-xx) preserved verbatim.

## Purpose

Defines how passwords are stored, the minimum password policy, the temporary-password-and-forced-change flow used by administrators, and how a user changes their own password.

## Requirements

### Requirement: FND-012 — Seguridad de Contraseñas

Passwords MUST NEVER be stored or recorded in plain text, including in audit records and technical logs.

Secure hashing mechanisms provided by the framework or by recognized libraries MUST be used.

> Coverage note: no scenario in spec 001 §11 (E-01..E-30) directly names FND-012. Related: E-28 (capability `audit`) verifies that audit records omit passwords/hashes, which supports but does not fully test this requirement (hashing mechanism itself is untested by a named scenario). Flagged under risks/ambiguities — not resolved here per AGENTS.md §6.

### Requirement: FND-013 — Política de Contraseñas

Every password MUST have at least 10 characters.

When changing their password, a user MUST NOT be able to reuse their current password.

#### Scenario: E-16 — Contraseña demasiado corta

Source citation: (FND-013)

- GIVEN any operation that sets a password
- WHEN the password has fewer than 10 characters
- THEN the system rejects the operation

### Requirement: FND-014 — Contraseña Temporal y Cambio Obligatorio

When an administrator creates a user or resets their password, they assign a temporary password and the user is flagged with a pending forced password change.

While the forced change is pending, the user MAY only change their password or log out. Any other protected operation MUST be rejected.

Resetting a user's password MUST invalidate all of that user's open sessions.

> Decision note (DEC-021): The first administrator is created through an interactive console command that requests first name, last name, and email, generates a temporary password that forces a change at the first login, only runs if there is not already an active user holding the Administrador role, and is recorded in the audit log as a console event. Credentials MUST NOT be stored in environment variables or in the code. (Source: `docs/specs/001-foundation.md` §16, DEC-021)

#### Scenario: E-14 — Cambio obligatorio de contraseña

Source citation: (FND-014)

- GIVEN a user with a pending forced password change
- WHEN they log in and attempt to execute any operation other than changing their password or logging out
- THEN the system rejects it until they change their password

#### Scenario: E-15 — Restablecimiento de contraseña

Source citation: (FND-014)

- GIVEN a user with an open session
- WHEN an administrator resets their password
- THEN the user's sessions are invalidated
- AND on their next login they must change their password

### Requirement: FND-015 — Cambio de Contraseña Propia

Every authenticated user MUST be able to change their own password by providing their current password. This operation does NOT require additional permissions.

#### Scenario: E-17 — Cambio de contraseña propia

Source citation: (FND-015)

- GIVEN an authenticated user
- WHEN they change their password, correctly providing the current one
- THEN the new password becomes active
- AND WHEN the current password provided is incorrect, THEN the change is rejected
