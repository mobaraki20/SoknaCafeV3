# SOKNA Maintenance V4 — Windows P1

Status: engineering P1 implementation. This is not a production/real-cafe UAT claim.

## Frozen parent

- Repository: `mobaraki20/SoknaCafeV3`
- Parent: `windows-services-v1.0.17`
- Parent commit: `bdb753db850b2f3d89fe3f451baa4ee9a7fae8ee`

## P1 ownership

P1 introduces the independent Windows-owned maintenance authority skeleton only:

- `SoknaMaintenanceSupervisor` Windows service / executable
- durable Supervisor state + append-only JSONL journal
- single-owner data-root lock
- loopback authenticated read-only control plane
- `SoknaMaintenanceRecoveryConsole` read-only operator shell independent of Local Web

P1 intentionally does **not** implement application update activation, database migration, backup creation, restore mutation, Remote Vault upload, or self-update.

## Stable Windows identities

- Service name: `SoknaMaintenanceSupervisor`
- Protocol: `maintenance_control_protocol=1.0`
- Default listener: `http://127.0.0.1:17631/`
- Default data root: `%ProgramData%\SOKNA\Maintenance`
- Discovery descriptor: `%ProgramData%\SOKNA\Maintenance\control-plane.json`
- Durable state: `%ProgramData%\SOKNA\Maintenance\supervisor-state.json`
- Durable journal: `%ProgramData%\SOKNA\Maintenance\maintenance-journal.jsonl`
- Single-instance lock: `%ProgramData%\SOKNA\Maintenance\supervisor.lock`
- Secrets root: `%ProgramData%\SOKNA\Maintenance\secrets`

The default port is frozen for protocol discovery in P1. Real cafe-host collision remains a real-host install gate; callers must still read `control-plane.json` rather than hard-code the port.

## Authentication

Loopback is transport scope, not authentication.

P1 uses `sokna-hmac-sha256-v1`. Every authenticated GET requires exactly one of each header:

- `X-Sokna-Maintenance-Protocol: 1.0`
- `X-Sokna-Timestamp`
- `X-Sokna-Nonce`
- `X-Sokna-Key-Id`
- `X-Sokna-Body-SHA256`
- `X-Sokna-Signature`

Canonical signing input is UTF-8:

`METHOD + "\n" + PATH_AND_QUERY + "\n" + PROTOCOL + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + BODY_SHA256`

Signature is lower-case hex HMAC-SHA256. P1 accepts a 90-second clock-skew window and rejects nonce replay. Supported key IDs are `web-adapter` and `recovery-console`.

Keys are generated at runtime and are never release payload. P1 fails closed with secrets ACL restricted to SYSTEM/Administrators. The future Local Web adapter identity is not granted until the Web service identity is explicitly discovered/qualified; unauthenticated loopback is never a fallback.

## P1 read-only routes

All `/v1/*` non-GET requests are rejected with HTTP 405 / `READ_ONLY_P1` before any mutation can occur.

- `GET /v1/health` — Supervisor readiness, instance/generation, journal head, process identity
- `GET /v1/status` — authoritative Supervisor state and unbound Local-release placeholder
- `GET /v1/components` — Supervisor + observed Runtime/Print service presence/state/version where readable
- `GET /v1/operations/active` — P1 idle/no-mutation state
- `GET /v1/backups` — explicit `not_implemented_p1` state
- `GET /v1/recovery` — recovery-required state and P1-safe read-only actions
- `GET /v1/journal/head` — durable journal sequence only

Envelope family:

```json
{
  "ok": true,
  "protocol": "1.0",
  "operation_id": null,
  "state": "ready",
  "code": "OK",
  "retryable": false,
  "requires_recovery_console": false,
  "data": {}
}
```

## Recovery Console

Commands:

- `status`
- `health`
- `components`
- `recovery`
- `journal`

The console authenticates directly to Supervisor using the Windows-owned recovery-console key and does not depend on Apache/PHP/Local Web. If Supervisor discovery/control-plane is unavailable but durable state exists, it provides a clearly degraded `OFFLINE_READ_ONLY_FALLBACK`; it never enables mutation in P1.

## Qualification boundary

`windows/maintenance/tests/p1-qualification.ps1` qualifies on a Windows runner:

- exact loopback binding and default port contract
- HMAC authentication and nonce replay rejection
- non-GET mutation fence
- durable state/journal
- same-data-root single-instance exclusion
- secret ACL fail-closed posture
- Recovery Console authenticated operation without Web
- offline read-only fallback
- real Windows Service Control Manager create/start/stop/restart for the P1 executable

This is **Windows CI runtime/SCM evidence**, not real cafe-host UAT. Real installed-machine service account/port collision/reboot/AV/file-lock/disk-full and later MariaDB/Public/restore gates remain separate.
