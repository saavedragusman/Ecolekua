# Roles and Permissions Specification

> Transcribed from `docs/specs/001-foundation.md` v1.2 (§8 "Roles y permisos", §11 "Roles y autorización"). Faithful transcription — no added, removed, or reinterpreted behavior. Original IDs (FND-xxx, E-xx) preserved verbatim. No new decisions in §16 v1.2 (DEC-017..DEC-021) reference this domain's requirements.

## Purpose

Defines roles, the permission catalog, role assignment to users, and how a user's effective permissions are computed. Access is always determined by permissions, never by role name.

## Requirements

### Requirement: FND-016 — Roles

The system MUST allow a user with the corresponding permission to:

- view roles and their permissions (`roles.view`);
- create roles, modify their name and description, and assign or remove permissions for a role (`roles.manage`);
- delete roles (`roles.manage`).

A role's name MUST be unique.

A role MUST NOT be deletable while it has users assigned.

The Administrador role MUST NOT be deletable or renameable.

A role's name MUST NOT grant access by itself. Effective access is determined exclusively by permissions.

#### Scenario: E-22 — Eliminación de rol con usuarios

Source citation: (FND-016)

- GIVEN a role with at least one user assigned
- WHEN an attempt is made to delete it
- THEN the system rejects the operation

#### Scenario: E-23 — Rol Administrador protegido

Source citation: (FND-016)

- GIVEN the Administrador role
- WHEN an attempt is made to delete or rename it
- THEN the system rejects the operation

### Requirement: FND-017 — Catálogo de Permisos

Permissions form a catalog defined by the system's specs. They MUST NOT be creatable, modifiable, or deletable from the interface.

Every spec that introduces protected functionality MUST declare its permissions following the `module.action` naming convention (e.g., `users.create`).

When a new permission is introduced, it MUST NOT be automatically assigned to any role, unless the spec introducing it states otherwise.

#### Scenario: E-24 — Permiso nuevo sin asignación automática

Source citation: (FND-017, FND-018)

- GIVEN a permission introduced by a later spec that does not indicate an assignment
- WHEN an Administrador attempts to execute the operation it protects
- THEN the backend rejects it until the permission is assigned to one of their roles

### Requirement: FND-018 — Asignación de Roles

An active user MUST have at least one role and MAY have several.

A user's effective permissions are the union of the permissions of all their roles.

There is NO implicit total access: no role, including Administrador, obtains permissions that have not been explicitly assigned to it.

#### Scenario: E-21 — Permisos de varios roles

Source citation: (FND-018)

- GIVEN a user with two roles that grant different permissions
- WHEN they execute an operation authorized by either of them
- THEN the backend allows it

> Cross-reference: E-13 (capability `user-management`) also verifies this requirement jointly with FND-010 (a user cannot be created without at least one role); it is transcribed there because the source spec cites both FND-010 and FND-018 together.
