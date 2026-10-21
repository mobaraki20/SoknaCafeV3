# SOKNA Cafe — minimal Windows/Maintenance update contract
**Date:** 2026-10-21  
**Owner:** Windows Services / existing Maintenance V4  
**Status:** implementation / UAT candidate; not a statement of real-host success

## Product constraints (binding)
- Single small cafe, single installation. No speculative multi-branch, HA, or enterprise orchestration.
- One existing Maintenance V4 engine for signed Local/Public updates; no new updater, background service, parallel installation channel, or UI redesign.
- Windows Services updates are separate and exceptional. Local/Public update paths remain independent of installing Windows Services.
- One click from the existing operator UI wherever technically supported; never expect day-to-day PowerShell, manual key generation, unpacking or service manipulation.
- Keep already healthy, registered components (especially Runtime and Print) untouched; do not stop or restart them merely because the shell changes.
- Never infer success from registry, copied files, version badge, or CI alone: verify installed executable identity, service state, transaction receipt and readback.
- Preserve signed preflight, scoped mutation, rollback, ownership, anti-downgrade and fail-closed protection. Simplicity means fewer operator steps, NOT disabled validation.
- A failed update must clearly identify what failed and present an actionable recovery path; do not silently corrupt or claim readiness.

## Phased work and acceptance
| Phase | Work | Evidence / exit gate |
|---|---|---|
| P0 — freeze & scope | Record these product decisions and distinguish independent Maintenance 0.3.10 from bundled old 0.3.9 | Document committed; Local/Public/Runtime/Print sources unchanged |
| P1 — compatibility | Align Windows shell's owned-version manifest and real 0.3.10 sources; keep independently installed valid 0.3.10 Supervisor+Engine pair without replacement | Component pairing, both SHA256 hashes, exact receipt and owned RUNNING service; missing/bad receipt fails closed |
| P2 — build & quality | Compile 1.1.20 release UI/Host and existing five-component V4 shell; run planner, signed-update and packaging gates | Dist SHA256 verified, manifest matches source, M10 + Windows qualification PASS, no suppressed failure |
| P3 — Home UAT | Install only the qualified Windows Services RC if needed; read back installed 1.1.20 shell, Maintenance pair 0.3.10, existing Runtime/Print unchanged; exercise signed Local update & recovery through actual panel | Evidence collected from Windows 10 Home machine; no Cafe deployment until pass |
| P4 — Cafe | Promote to Cafe only after Home real-host UAT with backups | Installation/restart count, rollback and remote access verified |

## Concrete incident and narrow resolution
- Windows Services 1.1.19 shipped Maintenance 0.3.9; independently installed Maintenance-only 0.3.10 is a different lifecycle.
- Existing shell planner flagged 0.3.10 as an unsupported newer component. Patch in PR #35 recognizes only exact independently installed 0.3.10 with real receipt and service/hash proof.
- An attempted full-package release build from later 0.3.10 source failed with `maintenance manifest=0.3.9 source=0.3.10`. Do **not** bypass the equality check; release a new Windows shell package/version with its own matching 0.3.10 manifest if a new shell is necessary.
- Same-version 0.3.10 built in a different package can have a different SHA256; receipt-backed, healthy standalone 0.3.10 should remain KEEP, not spuriously REPAIR, unless the operator explicitly requests repair.
- Earlier whole-repository G6/M10 failure is stale `release/compatibility-v2.json` Windows package version; keep the source-ownership version invariant by updating metadata to the actual Windows package version, never weakening G6.
- The project UI/Host csproj version defaults differ from built package; builder overrides them but set readable defaults to current package to avoid confusion.

## Release policy
- Build from a specific commit and verify real Windows EXE metadata, five component versions, both signed/planner self-tests and SHA256.
- Publish as a clearly marked **pre-release / Home UAT**, with a unique tag and the qualified EXE + SHA256 + index. Existing releases are immutable; never overwrite.
- If any required gate fails, upload diagnostic workflow artifact only; never publish an EXE labeled qualified.
- Real Home Windows 10 and downstream Local/Public through-panel tests remain separate and must be reported honestly.

## Deliberately out of scope
Local/Public code, runtime code, print-agent code, new online-update transport, new update engine, device/IP/domain rebind, UI redesign, multi-branch infrastructure, arbitrary future maintenance versions.
