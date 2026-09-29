# ADR 0007 — Offline-first local installation

Status: Accepted  
Date: 2026-09-28

## Context

SOKNA Local Web and Windows Services must be deployable on a machine with no Internet access. Some official download hosts may also be filtered or unreachable while the machine otherwise has network access. Waiting for long generic HTTP timeouts is not acceptable, and downloading a file manually must not force the operator to perform the technical installation/configuration manually.

## Decision

The local stack is offline-capable without Public Edge.

- Local Web remains a separate ZIP and is installed through Browser Setup after the local infrastructure is ready.
- SOKNA Prerequisites Setup owns PHP, Apache and MariaDB preparation and accepts each frozen official artifact from either verified Cache, online download, manual file selection or an Offline Kit folder.
- Manual/offline artifacts are accepted only after release-lock Size + SHA-256 validation.
- Windows Services Setup accepts the frozen VC++ Runtime from a local file; it validates Size + SHA-256 and the required Microsoft Authenticode signature.
- Public Edge is optional for a purely local installation and is not a prerequisite for Local Web, MariaDB, Runtime or Print Agent.

## Download UX

Each PHP/Apache/MariaDB dependency has its own visible version/file/size/status/progress area, while the operation also has one overall progress bar.

Online acquisition:
- uses a short initial-response timeout;
- has a separate data-stall timeout;
- displays real received bytes / frozen total bytes;
- displays measured transfer rate and ETA;
- validates Content-Length when the server supplies it;
- preserves partial files for safe HTTP Range resume;
- falls back to the next approved URL;
- after approved URLs fail, offers manual file handoff without requiring the operator to restart the technical installation manually.

## Offline Kit

The canonical human-readable kit layout is documented in `packaging/offline/README_FA.md`. Third-party binaries are not committed to the repository. `packaging/offline/verify-offline-kit.ps1` verifies a prepared removable-media folder against `platform/windows/release-lock.json`.

## Security

A filename is never trusted by name alone. Hash/size verification is mandatory and fails closed. VC++ additionally requires the configured Authenticode publisher. Local Web credentials and MariaDB passwords are not part of the Offline Kit.

## UAT

Release UAT must cover:
1. machine with normal Internet access;
2. unreachable/filtered MariaDB host with manual-file fallback;
3. fully offline machine using an Offline Kit;
4. interrupted download followed by resume;
5. wrong-size and wrong-hash manual files;
6. Windows Services machine missing VC++ and receiving the runtime from local media.
