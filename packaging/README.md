# Packaging

`packaging` owns composition/build metadata for independently releasable V3 artifacts.

Canonical Local/Public releases are full immutable packages. Delta packages may exist only as optional transport optimization. Update, Repair and Recovery are distinct lifecycle operations.

## User-facing Windows installation

The canonical production experience is **one SOKNA Setup/bootstrapper** for the Windows-side product. It composes the compatible Platform, Windows Runtime, Print Agent and Local payloads needed by the selected installation/recovery mode.

A single user-facing installer does not make those components monolithic:
- Runtime and Print Agent remain separate deployables, owners and versions;
- component packages remain independently serviceable when compatibility permits;
- Repair may target an affected component without replacing unrelated healthy components;
- ordinary users are not required to run PowerShell or separate component installers manually.

Internal component-only packages/installers may exist for CI, development, diagnostics or controlled servicing, but they are not the normal end-user installation path.

See `docs/adr/0002-single-user-facing-windows-installer.md`.

Packaging must preserve component ownership: the Platform package must not silently become owner of active Local application files, and Local/Public packages must not mutate unrelated Runtime/Platform components unless an explicit compatibility/dependency rule requires it.
