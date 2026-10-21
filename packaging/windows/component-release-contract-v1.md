# Windows Services — standalone component releases (engineering decision)

Scope: **future release infrastructure only**. This branch does not modify or replace the installed 1.1.19-rc.3 full installer, Windows services, Local, Public, or guest services.

## Operational ownership

- The existing **SOKNA Windows Services Manager** remains installed. Its UI is the consolidated status/health and operator control surface for Runtime, Print Agent and Maintenance. A component update must never copy over or uninstall this manager.
- One **component** has its own source version and package payload. Updating it does not increment the whole Windows Services shell/manager version. The manager must display its own version separately from the actual installed service versions (not infer everything from the package version).
- The update transaction belongs to the existing Windows install lifecycle: preflight; stop only the affected service(s); back up owned binaries; copy+verify files; register/restart as necessary; verify service; commit receipt; rollback on failure. No new updater engine.
- A component-only payload cannot silently fall back to installing missing unrelated services or replacing an unrelated healthy component. Disallow implicit downgrade.
- An installed component's latest verified update receipt / installed binary version must be surfaced in the manager: the manager's old bundled **full installer** manifest is not authoritative for independently updated installed versions.

## Release units

| Release unit | Includes | Operation |
|---|---|---|
| runtime | `SoknaRuntimeService.exe` | stop/restart **SoknaRuntime** only; preserve runtime-config and keys |
| print-agent | print worker Service + Worker bundle | stop/restart **SoknaPrintWorker**; preserve data/settings |
| maintenance | Maintenance Supervisor + Update Engine app bundle | update **as one transaction group**; Recovery Console is separately owned and unchanged |
| full | existing Windows Services installer | unchanged path for initial install and multi-component updates |

**Supervision during Print update:** Runtime 1.0.6 polls and can restart stopped Print Agent every 2 seconds. The existing lifecycle must coordinate a brief supervision suspension (or temporarily stop/restart Runtime when it was running) around Print update and rollback. Otherwise separate Print EXE cannot reliably update in-use files. This is a functional necessity, not a second watchdog.

**Maintenance self-update:** its worker cannot depend on remaining alive while its own files are replaced. Continue to use the existing elevated external Setup Host / engine boundary. Supervisor and Update Engine move together, and Recovery Console is never overwritten by a component patch.

## Current implemented preparatory capability

Existing `windows/runtime/build-component-package.ps1` and `windows/print-agent/build-component-package.ps1` remain the component compilation/publish source; they are not replaced. The new `build-component-release-v1.ps1` only wraps the resulting binary/bundle into a common **small, isolated, hash-inventoried candidate archive** for exactly one release unit. Its manifest carries source commit, source version, exact included-file list/hash and release scope. It deliberately sets `installable=false`. A Windows CI contract confirms runtime-only, print-only and maintenance-group payload boundaries; no Windows service mutation is performed.

## What must be completed before publishing a standalone EXE

1. Teach the **existing** Setup Host / component lifecycle a selective, independently versioned release plan. Keep stop/backup/rollback health checks; skip missing payload requirements for out-of-scope KEEP services.
2. Make the installed Manager read an authoritative installed-component receipt (not only the full bundle manifest), so a later Runtime 1.0.7 does not appear as an unsupported downgrade against full shell 1.1.19 / Runtime 1.0.6.
3. Package the already-used lifecycle in a small standalone EXE and run one real Windows 10 targeted upgrade plus rollback test. For Print also exercise Runtime's live supervision. When these are proven, set `installable=true` at signed/published package creation — never by editing an unqualified candidate.

Keep 1.1.19-rc.3 Home UAT independent from this work; do not release any replacement full Windows installer for this preparatory step.
