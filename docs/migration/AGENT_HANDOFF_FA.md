# SOKNA V3 — Agent Handoff / Continuation

> **HISTORICAL / SUPERSEDED CONTINUATION NOTICE (2026-09-27):** این فایل evidence تاریخی M10 است و entrypoint فعلی پروژه نیست. برای ادامه از `START_HERE.md` و `docs/migration/CURRENT_CONTINUATION_FA.md` استفاده کن. مرحلهٔ فعال فعلی G1 است و Manual UAT هنوز HOLD است.


آخرین به‌روزرسانی: 2026-09-27

این فایل مرجع ادامه‌ی پروژه در صورت تغییر چت/ایجنت است. به تاریخچه‌ی گفتگو متکی نباش؛ وضعیت زیر را از خود Git و فایل‌های repo تأیید کن.

## Repository state

- Repository: `mobaraki20/SoknaCafeV3`
- Local working branch: `work/local-migration`
- Remote integration branch: `architecture/v3-foundation`
- Full integration candidate: `41d2aafc4f440a544b29e2632da387ed6314e8bb` (tree exactly matched local `35a950298a4ca77788ee2eff43d5504705f5e924`)
- Remote stabilization after candidate: `61eab5dcf4af6e8c8620f21ed0906a03ed11d64e` (M5.10 test forward-compatible with M5.11 Accommodation adapter)
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
8. `06f241e` — `docs: make cross-agent continuation durable`
9. `d4fd538` — `test(m5.10): forward-compat Settlement with M5.11 adapter owner`
10. `9c6426e` — `fix(rc): stabilize runtime print installer and recovery qualification`

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


## Integration-candidate CI evidence (2026-09-27)

Candidate `41d2aafc4f440a544b29e2632da387ed6314e8bb` triggered the complete M4-M10 suite.

PASS on candidate:

- M4 Guest Renderer / Failure Isolation
- M5 Sellables / Orders / Table Draft / Preparation / Inventory / Supply / Tax / Expenses / Financial Periods
- M5.11 Integration Adapters
- M6 SCDS
- M9 Packaging
- V3 Component Gates

Evidence-based failures and current local fixes:

1. M5 Settlement: old M5.10 test still required Accommodation to be unavailable after M5.11. Fixed in local `d4fd538` and remote `61eab5dc...`; now asserts Accommodation cannot bypass its canonical transfer owner.
2. M7 Runtime: RFC3339 explicit-offset `requested_at` was inserted directly into MariaDB DATETIME. Fixed in `9c6426e`: explicit offset is required, canonical instant is normalized to UTC, DB representation uses `Y-m-d H:i:s`.
3. M8 Print Agent: ContractAcceptance executable was invoked without required `--case A18 --results ...` arguments. Fixed in `9c6426e`.
4. M10 Inno: invalid `SetupArchitecture` directive. Removed in `9c6426e`; supported `ArchitecturesAllowed/ArchitecturesInstallIn64BitMode` remain.
5. M10 manual-UAT workflow: heredoc was not a YAML literal block. Fixed in `9c6426e`; it validates only the explicit blocker contract and does not fabricate hardware PASS.
6. M10 recovery: MariaDB rejected `SHOW KEYS ... ORDER BY`. Fixed in `9c6426e` by sorting `Seq_in_index` in PHP after a valid `SHOW KEYS`.

Local post-fix checks PASS:

- PHP lint for changed PHP files
- `python3 tests/m7-runtime-gate.py`
- `python3 tests/m8-print-agent-gate.py`
- `python3 tests/m9-packaging-gate.py`
- `python3 tests/m10-release-qualification.py`
- workflow YAML parse
- `git diff --check`

Next exact action: publish the `9c6426e` stabilization delta (plus this handoff update) to `architecture/v3-foundation`, then re-run M5 Settlement, M7, M8 and M10 and inspect only evidence-based failures.

## Important architectural boundaries

- Do not recreate a second authority for Orders, Inventory, Supply, Tax, Expenses, Financial Periods, Settlement, Runtime or Printing.
- M5.11 integration destinations are adapters over canonical Settlement, not alternate settlement owners.
- Business backup excludes machine-bound Runtime/Print/TLS identities; recovered machines must reprovision them. SOKNA Center/Core is retired and has no machine identity.
- Public owns transport/projection boundaries; Local owns canonical business state.
- Installer is componentized; Local AppRoot must not become a copy of the monorepo.
- Historical committed rows/snapshots must not be reinterpreted by later configuration changes.
- M10 qualification must fail closed on unknown/corrupt/incompatible recovery or component state.

## Recovery if the workspace itself is unavailable

Use the durable workspace archive saved alongside this handoff. It contains the V3 repository including `.git`. The companion legacy snapshot contains `SoknaCafe` at the audited historical baseline. Restore the V3 archive, run `git status`, and continue from `work/local-migration` rather than reconstructing work from chat messages.
