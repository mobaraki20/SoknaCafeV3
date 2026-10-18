# F1 — Home 1.1.7 read-only evidence capture (engineering only)

Date: 2026-10-18. Scope: Windows Maintenance, no deployment. PR #25 Draft.

## Reality / provenance

- Last verified **installed** Home: Windows Services **1.1.7**, Maintenance **0.3.5**, Runtime **1.0.5**, Print **6.2.9**, Local **231ai** (2026-10-18 support bundle, with an earlier real R8 stable junction adoption).
- Published/compiled source Windows Services **1.1.12** and new Supervisor `/v1/local-updates/inactive-host-readiness` are **NOT currently installed on Home**, unless newly observed on-device evidence proves otherwise.
- Older support archive missed listener port 18080; that is **uncertainty**, not proof Apache was down. R8 adoption on Home previously demonstrated HTTP 200, php-ready and exact build.
- A prior standalone Go-based junction probe produced a **false STOP** because Go `filepath.EvalSymlinks` on native Windows junction differed from .NET `Directory.ResolveLinkTarget`. NEVER advise junction repair/reinstall based on old Go result.
- The one user-facing updater remains Local `/system` Update Center; this PowerShell capture is internal engineering evidence ONLY, NOT a competing installer, updater, or normal operator workflow.

## Safe independent collector

`windows/maintenance/tests/home-host-readonly-preflight.ps1`

Does not require Maintenance 1.1.12 or API keys. It reads Win32_Service status, local Web VERSION/inventory, advisory stable Web reparse info, local TCP listener and only `http://127.0.0.1:18080/login.php` (GET). No external network, Start/Stop-Service, installer, key/DB read, file changes, privilege elevation, rollback, sync or release activation. Outputs JSON to stdout and does not save files by itself.

**All** results unconditionally keep `upgrade_permitted=false`, `activation_allowed=false`, `panel_update_ready=false`. It does NOT verify exact signed managed-tree SHA256, Local lifecycle, MariaDB schema, real backups, cross-origin Public compatibility, or actual 1.1.12 route. Service status, version and listener are merely diagnostic observations.

This tool may be executed **only as a supervised read-only engineering capture** on Home when operator authorizes local collection; it is not auto-executed by CI on Home. In CI, use a synthetic isolated Windows folder and reject external URL.

## Qualification boundaries

1. Before any Home upgrade verify exact current installed Local version, Apache HTTP listener and build, native junction with the .NET authority, machine binding/installation ID, Local lifecycle state and recovery markers, and real backup/restore.
2. Pairing and Public Kernel architecture remain independent. The installed old Home Supervisor MUST NOT be assumed to expose new routes from GitHub source.
3. No Home inactive-slot write until valid installable signed release, installation upgrade path and real backups qualify.
4. No Code cutover (S7D), DB forward boundary (S7E) or Public deployment until separate durable transaction, rollback, WAN/PWA/queue and real host tests pass.
5. Global six CI gates passing is necessary, never sufficient; the manual UAT job is a **pending-evidence policy test**, not a host PASS.

## Next chat / responsibility

Retain PR #25 Draft; `panel_update_ready=false`. Re-run all same-HEAD gates after changes. If user later supplies Home read-only evidence, compare to last proven installed state, not checkpoint source. Continue S7D/E only via small fault-injection-qualified units; never auto install or promise a complete updater from a read-only diagnostic.
