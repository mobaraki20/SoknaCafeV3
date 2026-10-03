# ADR 0004 — Independent deployment surfaces and externally acquired infrastructure

Status: **Accepted — supersedes deployment composition in ADR-0002**
Date: 2026-09-27

## Decision

### Infrastructure
Apache/PHP/MariaDB and similar supported infrastructure are external dependencies, not SOKNA-owned application payloads.

The prerequisite surface must detect required components/compatible versions, show installed/missing/incompatible state, and where approved offer verified online acquisition/install with size/progress/status. A manual-install fallback must always exist. Third-party prerequisite binaries are not bundled in the normal SOKNA Windows Services installer.

### Windows Services
The native Windows installer owns only SOKNA Windows-side service lifecycle:
- Windows Runtime;
- Print Agent;
- shared service registration/configuration required by those owners.

It does not install or carry Local Web application files.

### Local Web
Local Web is an independent immutable web package/ZIP. Initial installation is browser-driven:

`place/extract package -> browse setup URL -> preflight -> database/config -> migrations -> initial admin/config -> lock setup`

There is no user-facing Local Web PowerShell or EXE installer.

### Public Edge
Public Edge is an independent server deploy package with its own application lifecycle.

### Update / Recovery
Local Web provides a central Update Center for version/health/status visibility and orchestration across components, while lifecycle ownership stays with each component.

Public Edge provides an independent limited Emergency Console so Public health/update/rollback/recovery remains possible when Local control connectivity is unavailable.

Broken application releases must not destroy the recovery path.

## Consequences

ADR-0002 remains historical evidence but is no longer authoritative where it composes Platform prerequisite payloads and Local Web into the canonical Windows setup.

M9/M10 packaging/release qualification must be rewritten before the next production candidate.

This ADR does not reopen Local business authority, Public transport ownership, Runtime business isolation, or Print Agent spooler/device ownership.
