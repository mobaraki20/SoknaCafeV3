# ADR 0004 — Independent deployment surfaces and externally acquired infrastructure

Status: Accepted — supersedes deployment composition in ADR-0002  
Date: 2026-09-27

## Context

V3 component ownership is sound, but the previous user-facing packaging decision coupled Local Web and managed Platform payload into the Windows setup. Product operation requires Local Web to behave like a conventional self-hosted web application, while Runtime/Print remain native Windows services and third-party infrastructure remains independently manageable.

## Decision

### Infrastructure
Apache/PHP/MariaDB and similar supported infrastructure are external dependencies, not SOKNA-owned component payloads.

The Windows prerequisite surface must:
- detect required dependency and compatible version;
- show installed/missing/incompatible status;
- where approved, offer verified online acquisition/install with size/progress/status;
- verify source/hash/signature according to release policy;
- always provide a manual-install fallback path;
- never imply third-party infrastructure is owned by SOKNA application lifecycle.

Third-party prerequisite binaries are not bundled in the normal SOKNA Windows Services installer.

### Windows Services
The native Windows installer owns only SOKNA Windows-side service lifecycle:
- Windows Runtime;
- Print Agent;
- shared service registration/configuration required by those owners.

It does not install or carry Local Web application files.

### Local Web
Local Web is delivered as an independent immutable web package/ZIP. Initial installation is browser-driven:
`place/extract package -> browse setup URL -> preflight -> database/config -> migrations -> initial admin/config -> lock setup`.

There is no user-facing Local Web PowerShell or EXE installer.

### Public Edge
Public Edge is an independent server deploy package with its own application lifecycle.

### Update / Recovery surfaces
- Local Web provides a central Update Center for version/health/status visibility and orchestration across components.
- Component lifecycle ownership remains with each component; Local does not become owner of Windows or Public internals.
- Public Edge provides an independent limited Emergency Console so Public health/update/rollback/recovery remains possible when Local control connectivity is unavailable.
- Broken application releases must not destroy the recovery path.

## Versioning
Local, Public, Runtime, Print Agent and supported infrastructure compatibility are independently versioned. Release manifests declare compatibility ranges.

## Consequences

ADR-0002 remains historical evidence for the earlier packaging decision but is no longer authoritative where it says Platform prerequisites and Local Web payload are composed into the canonical Windows setup.

M9/M10 packaging/release qualification must be rewritten before the next production candidate.

## Preserved boundaries

This ADR does not reopen Local business authority, Public transport ownership, Runtime business isolation or Print Agent spooler/device ownership.
