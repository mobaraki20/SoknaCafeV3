# ADR 0006 — Independent infrastructure prerequisites setup

Status: Accepted  
Date: 2026-09-28

## Context

Local Web must remain a WordPress-style web package: extract it into a prepared web root and finish installation in the browser. Windows Services must also remain independent and own only Runtime + Print Agent.

Manual preparation of PHP, Apache and MariaDB is too technical for normal operators, especially when paths, Apache/PHP binding, Windows services and recovery after an OS reinstall are involved.

## Decision

Introduce a separate user-facing helper named **SOKNA Prerequisites Setup**.

It owns only machine preparation for the external Local Web infrastructure:

- verified acquisition of release-locked PHP, Apache and MariaDB;
- installation into a user-selected root, including a non-system drive;
- managed Apache/PHP binding;
- Windows service registration for `SoknaApache` and `SoknaMariaDB`;
- persistent logs, diagnostics, state and support bundle;
- explicit Install / Repair / Recover-after-Windows-reinstall modes.

It does **not** own or install the Local Web payload, SOKNA business database/schema, migrations, admin bootstrap, Runtime or Print Agent.

Reference layout:

```text
<drive>:\SOKNA\
  Infrastructure\
    PHP\
    Apache\
    MariaDB\
    Logs\
    infrastructure-state.json
  Data\MariaDB\
  Web\
  Backups\
```

MariaDB binaries and its data directory are separate. Existing `Data\MariaDB` is preservation-critical. Repair/Recover must never initialize an existing data directory.

After Windows reinstall, Recover validates the preserved files, restores OS-level service registrations and health checks, and leaves Web/Data content intact.

Local Web database creation remains in Browser Setup.

## Failure and support contract

The helper must fail closed on download/hash mismatch, log each stage without credentials, preserve verbose MariaDB MSI logs, expose the log directory, and create a support ZIP that intentionally excludes passwords and Local Web credentials.

## Compatibility impact

ADR 0002 remains historically superseded by the final deployment-separation decision. Windows Services prerequisite UI is reduced to prerequisites that actually block Runtime/Print Agent. PHP/Apache/MariaDB move to this independent helper.

## UAT

Before release completion, validate on a clean Windows machine and a reinstall/recovery scenario using a non-system drive with preserved MariaDB data.
