# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-27

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Remote V3 integration branch: `architecture/v3-foundation`
- Remote base used by the local continuation: `510a3433470c1f97de84fd3534165f883d0718fc`
- Local continuation branch: `work/local-migration`
- Durable cross-chat handoff: `docs/migration/AGENT_HANDOFF_FA.md` and `docs/migration/AGENT_HANDOFF.json`

## وضعیت اجرایی واقعی

تمام ownerهای اصلی migration از M1 تا M9 در V3 پیاده‌سازی شده‌اند. M10 نیز در workspace به integration-candidate رسیده و qualificationهای قابل‌اجرای محلی سبزند. تنها تأییدهای نهاییِ وابسته به محیط بیرونی هنوز باید روی CI/Windows واقعی اجرا شوند.

Local continuation commits after remote base:

- `9f8bb67` — M5.10 settlement token regression fix
- `7188328` — M5.11 Settlement integration adapters
- `2e9c3b9` — M6 canonical SCDS foundation
- `0489e16` — M7 Windows Runtime + Local trigger owner
- `69ea035` — M8 durable Print Agent + Local print owner
- `f9aedc6` — M9 immutable packaging + unified setup lifecycle

M10 working tree adds release qualification, secure business recovery, crash-safe migration replay, canonical Local public/setup surfaces, Windows setup stabilization, release lock/version metadata, and explicit manual-UAT blockers.

## Slice status

- F0: **COMPLETE** — V3 foundation/ownership contracts.
- M1: **COMPLETE** — executable cross-component contracts.
- M2: **COMPLETE** — Local Core.
- M3: **COMPLETE** — Public Edge persistence/auth/relay.
- M4: **COMPLETE** — Guest publish/runtime/remote read/failure isolation.
- M5.1: **COMPLETE** — Sellables/catalog authority.
- M5.2: **COMPLETE** — canonical Orders.
- M5.3: **COMPLETE** — Staff Quick Order + Table Draft.
- M5.4: **COMPLETE** — Preparation permission/action owner.
- M5.5: **COMPLETE** — Inventory.
- M5.6: **COMPLETE** — Supply/Purchase.
- M5.7: **COMPLETE** — Tax owner + immutable Order snapshots.
- M5.8: **COMPLETE** — Expenses + minimal period identity prerequisite.
- M5.9: **COMPLETE** — Financial Period numbering/preflight.
- M5.10: **COMPLETE at implementation level** — Settlement/Reconciliation + final Financial Period close. Remote Settlement gate was proven green after the request-token regression fix.
- M5.11: **COMPLETE at implementation level / final integration CI pending** — Accommodation, Subscriber and Center adapters over canonical Settlement/Local owners.
- M6: **COMPLETE at shared-foundation level / progressive consumer migration remains a continuous rule** — SCDS tokens/components/registry owner and enforcement gate.
- M7: **COMPLETE at implementation level / Windows build CI pending on final candidate** — Runtime contract/service + Local trigger owner.
- M8: **COMPLETE at implementation level / Windows build and physical-printer UAT pending on final candidate** — separate durable Print Agent + Local printing owner.
- M9: **COMPLETE at implementation level / Windows installer build CI pending on final candidate** — immutable component lifecycle, compatibility, rollback/repair and unified Setup composition.
- M10: **INTEGRATION CANDIDATE** — automated local qualification passed; MariaDB/Windows CI and real-device UAT remain explicit final evidence.

## M10 qualification already passed locally

- `python3 tests/m10-release-qualification.py`
- `python3 tests/m9-packaging-gate.py`
- `python3 tests/m8-print-agent-gate.py`
- `python3 tests/m7-runtime-gate.py`
- `python3 tests/scds-m6-gate.py`
- `python3 tests/runtime-print-v1-contract.py`
- `python3 tests/runtime-print-contract-audit.py`
- PHP syntax check across `apps/**/*.php` and `tests/**/*.php`
- JSON parse check across repository JSON files
- `git diff --check`

A prior combined local command timed out only because several tests were chained under one execution deadline. The same tests were rerun individually and passed; that timeout is not a product failure.

## Final automated evidence still required

The final integration-candidate CI must prove:

1. MariaDB migration stack and all M5 domain gates, including M5.11 adapters.
2. M10 encrypted Business Backup -> empty-target restore -> machine takeover.
3. M10 crash-safe migration replay and migration-byte drift rejection after execution starts.
4. Windows Runtime build.
5. Windows Print Agent build.
6. SetupHost/SetupUI publish.
7. PowerShell parse for platform/packaging owners.
8. Inno Setup source compile.
9. Full V3 component/failure-isolation regressions.

## Manual production-release blockers

CI must **not** fabricate PASS evidence for the following. Their canonical state is `release/manual-uat-status.json`:

- Windows clean install / New-Recover-Repair-Uninstall on a real supported x64 machine;
- real thermal-printer Persian RTL/restart/retry/no-duplicate-print UAT;
- responsive touch/mobile + Persian IME/focus/contrast UAT.

These may remain `pending` while automated engineering qualification is completed, but a Production release must not claim them passed without real evidence.

## Next exact continuation

1. Commit the complete M10 integration candidate locally.
2. Push the accumulated local continuation (`M5.11` through `M10`) to `architecture/v3-foundation` as one integration candidate rather than phase-by-phase pushes.
3. Run the complete GitHub CI suite.
4. Fix only evidence-based failures, preferably as one stabilization pass.
5. When automated CI is green, record exact run IDs in the final qualification audit and update this file to `M10 COMPLETE — automated qualification satisfied`.
6. Keep the three manual UAT items pending until real evidence exists.
7. After final durable state exists, remove temporary snapshot branches/artifacts if desired and prepare merge/release handoff.

## Continuity rule

Do not reconstruct the project from chat history. If a new agent/chat continues the work, use:

- `docs/migration/AGENT_HANDOFF_FA.md`;
- `docs/migration/AGENT_HANDOFF.json`;
- the durable V3 workspace archive in Library `/SoknaCafeV3-Handoff/`;
- Git history on `work/local-migration` inside that archive.

## Frozen architecture rules carried to release

- Local Web is the sole canonical business-state authority.
- Public owns transport/safe projections, never a full Local business clone.
- Runtime owns Windows supervision/integration, never business decisions or direct business-table writes.
- Print Agent remains a separate machine-bound durable device/spooler owner.
- M5.11 integration destinations are adapters over canonical Settlement/Local owners, not alternate Finance authorities.
- Business backup excludes machine-bound Runtime/Print/TLS/Center signing identity; recovered machines reprovision them.
- Installer is componentized; Local AppRoot is not a monorepo copy.
- Historical committed rows/snapshots are not reinterpreted by later configuration changes.
- Unknown/corrupt/incompatible recovery or package state fails closed.
