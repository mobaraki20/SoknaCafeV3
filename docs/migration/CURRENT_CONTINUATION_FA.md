# SOKNA Cafe V3 — Canonical Continuation

Status: **CURRENT — PRODUCT GAP CLOSURE / PRE-RC**
Date: 2026-09-27

## Current correction

Historical RC `3.0.0-rc.1` has valuable green automated core/integration evidence, but reconciliation against the initial architecture handoff R2, real dev.26, post-UI dev.39 baseline, and V3 source proves that Product Migration is incomplete. Therefore `Manual UAT next` is no longer the correct continuation.

Canonical audit: `docs/product/MASTER_PRODUCT_INTENT_AUDIT_FA.md`.

## Keep closed / preserve

- Foundation/contracts.
- Local business/domain backend unless evidence defect.
- Public transport/auth/relay backend unless evidence defect.
- Windows Runtime core.
- Print Agent core.
- SCDS shared foundation.

## Open product work

- Local product UI/workflows.
- Local browser setup wizard.
- Local Update Center/application updater/recovery/diagnostics.
- Public deployable surfaces.
- Public Emergency Console/updater.
- Theme/Media/Publishing.
- Notifications.
- Printing UI/template package lifecycle.
- Marketing/Reporting parity.
- Infrastructure/Windows packaging revision.

M9/M10 automated evidence remains historical engineering evidence, but the final product gate is superseded until the approved architecture and product gaps are qualified. Manual UAT is HOLD until a new RC.

## Final deployment architecture

- Infrastructure = external/independent dependency.
- Windows Services installer = Runtime + Print Agent/service lifecycle only.
- Prerequisite checker detects compatible dependencies and can offer verified online acquisition with progress or manual fallback; prerequisite binaries are not bundled.
- Local Web = immutable ZIP/web package + Browser Setup Wizard; no PS1/EXE installer.
- Public Edge = independent server deploy package.
- Local Update Center = central component health/version/status/update visibility/orchestration.
- Public Emergency Console = independent limited health/update/rollback/recovery path.

ADR-0004 supersedes relevant ADR-0002 composition rules.

## Agent rule

Read `COMPONENTS.json`. Work on one component/workstream at a time. Read only its owner scope plus declared read-only contracts/dependencies. Do not reconstruct the project from chat history.

## Next order

1. Local Web product completion + Browser Setup.
2. Local Update Center/updater/recovery/diagnostics.
3. Public deployable product + Theme/Media/Publishing + Emergency Console/updater.
4. Infrastructure/Windows packaging revision.
5. Remaining Notifications/Printing UI/Marketing/Reporting parity.
6. New M9/M10 gates and RC.
7. Only then Manual UAT/fault matrix.

Detailed 45-row parity matrix and raw reference archives are preserved in the project Library.
