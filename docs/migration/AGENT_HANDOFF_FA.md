# SOKNA V3 — Agent Handoff / Continuation

آخرین به‌روزرسانی: 2026-09-27

این فایل مرجع ادامه‌ی پروژه در صورت تغییر چت/ایجنت است. به تاریخچه‌ی گفتگو متکی نباش؛ وضعیت زیر را از خود Git و فایل‌های repo تأیید کن.

## Repository state

- Repository: `mobaraki20/SoknaCafeV3`
- Local working branch: `work/local-migration`
- Remote integration base: `origin/architecture/v3-foundation` @ `510a3433470c1f97de84fd3534165f883d0718fc`
- Historical baseline repo: `mobaraki20/SoknaCafe` @ `a46435cca57df5bd5b9770efd0bb95390528aa05`
- Historical baseline is also available in the companion workspace snapshot.

## Local committed continuation after remote base

In order:

1. `9f8bb67` — `test(m5.10): use valid staff order request token`
2. `7188328` — `feat(m5.11): migrate settlement integration adapters`
3. `2e9c3b9` — `feat(m6): establish canonical SCDS shared UI foundation`
4. `0489e16` — `feat(m7): implement Windows Runtime contract and Local trigger owner`
5. `69ea035` — `feat(m8): migrate durable Print Agent and Local print owner`
6. `f9aedc6` — `feat(m9): implement immutable packaging and unified setup lifecycle`
7. `d404d92` — `feat(m10): qualify recovery installer and release lifecycle`

These commits are local continuation commits; do not recreate them from chat prose.

## Current phase

**M10 — Release Qualification / Recovery / Installer stabilization**

M10 implementation is committed locally at `d404d9238c6c40ac214da13245a7da0d50c4eaa4`.

### Main M10 additions/changes

- `apps/local-web/src/Domain/Recovery/` — authenticated encrypted Business Backup/Restore owner.
- `apps/local-web/tools/` — setup/recovery/print provisioning tools.
- `apps/local-web/public/` — canonical Local Web public root used by installer/Apache.
- `apps/local-web/src/Core/Migrations.php` — crash-safe statement journal/replay and migration hash protection.
- `platform/windows/setup-sokna.ps1` — V3 paths, Runtime config-based service setup, canonical Local public root.
- `platform/windows/remove-owned-services.ps1` — owned-service uninstall cleanup.
- `packaging/windows/**` — V3 componentized installer/shell payload and setup owners.
- `VERSION.txt` — current release line `3.0.0-rc.1`.
- `platform/windows/provider-candidate.json` + `platform/windows/release-lock.json` — candidate/release lock.
- `.github/workflows/m10-release-qualification.yml` — final automated qualification workflow.
- `tests/m10-release-qualification.py` — lifecycle/recovery/installer contract qualification.
- `tests/local-m10-release-selftest.php` — MariaDB backup/restore/takeover/crash-safe migration qualification.
- `docs/release/M10_MANUAL_UAT_FA.md` + `release/manual-uat-status.json` — physical/Windows UAT remains explicit and fail-closed.

## Automated checks already run locally on this working tree

PASS:

- `python3 tests/m10-release-qualification.py`
- `python3 tests/m9-packaging-gate.py`
- `python3 tests/m8-print-agent-gate.py`
- `python3 tests/m7-runtime-gate.py`
- `python3 tests/scds-m6-gate.py`
- `python3 tests/runtime-print-v1-contract.py`
- `python3 tests/runtime-print-contract-audit.py`
- PHP syntax check across all `apps/**/*.php` and `tests/**/*.php`
- JSON parse check across repo JSON files
- `git diff --check`

The earlier long combined command timed out because multiple tests were run sequentially under one tool deadline. The tests above were then rerun individually and passed. Do not treat that timeout as a product/test failure.

## Checks not executable in the current Linux workspace

These are intentionally deferred to the single integration-candidate CI run:

1. MariaDB qualification:
   - `php tests/local-mysql-migration-selftest.php`
   - `php tests/local-m10-release-selftest.php`
   - encrypted backup -> empty-target restore -> machine takeover
   - crash-safe migration replay/hash drift blocking
2. Windows build/source qualification:
   - .NET build/publish for Runtime, Print Agent, SetupHost, SetupUI
   - PowerShell parse
   - Inno Setup compile

Reason: the current workspace does not provide local MariaDB, `dotnet`, or `pwsh` executables. The GitHub M10 workflow provides the required environments.

## Manual release blockers — must NOT be fabricated by CI

`release/manual-uat-status.json` intentionally remains `pending` for:

- `windows_clean_install`
- `physical_thermal_printer`
- `responsive_touch_persian_ime`

These require real Windows/hardware interaction before a production release. Automated engineering completion may proceed while these remain explicitly pending; do not silently mark them passed.

## Next exact steps

1. Re-check current `git status` and `git diff --check`.
2. Review M10 working-tree diff for security/recovery correctness; do not remove fail-closed UAT policy.
3. M10 is already committed locally; do not recreate it from prose.
4. Update/verify canonical continuation docs (`CURRENT_CONTINUATION_FA.md`, matrix, README/START_HERE as appropriate) to reflect M5.11/M6/M7/M8/M9/M10 actual state.
5. Produce one integration candidate from the local branch and push it to GitHub only once.
6. Run the full CI suite, especially `M10 Release Qualification`.
7. Fix only evidence-based CI defects; rerun until green.
8. Preserve the three manual UAT blockers as pending until real evidence is supplied.
9. After green automated CI, create/update the final release/merge handoff and remove temporary snapshot branches only after the durable final state exists.

## Important architectural boundaries

- Do not recreate a second authority for Orders, Inventory, Supply, Tax, Expenses, Financial Periods, Settlement, Runtime or Printing.
- M5.11 integration destinations are adapters over canonical Settlement, not alternate settlement owners.
- Business backup excludes machine-bound Runtime/Print/TLS/Center signing identities; recovered machines must reprovision them.
- Public owns transport/projection boundaries; Local owns canonical business state.
- Installer is componentized; Local AppRoot must not become a copy of the monorepo.
- Historical committed rows/snapshots must not be reinterpreted by later configuration changes.
- M10 qualification must fail closed on unknown/corrupt/incompatible recovery or component state.

## Recovery if the workspace itself is unavailable

Use the durable workspace archive saved alongside this handoff. It contains the V3 repository including `.git`. The companion legacy snapshot contains `SoknaCafe` at the audited historical baseline. Restore the V3 archive, run `git status`, and continue from `work/local-migration` rather than reconstructing work from chat messages.
