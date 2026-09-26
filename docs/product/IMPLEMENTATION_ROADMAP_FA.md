# SOKNA V3 — Product Completion Roadmap

Status: **CANONICAL EXECUTION ORDER**
Date: 2026-09-27

Only one workstream is active at a time. Closed backend/core owners are reopened only for an evidence-based defect or explicit contract change.

## P1 — Local Web Product Completion
Complete the real Local product on existing domain owners:
Operator/Quick Order/Tables/Preparation, Orders/current bill/Settlement/Invoices, Inventory/Supply/Expenses, Financial Period/Tax, Subscribers/Accommodation/Personnel/Center, Users/Permissions/Settings/Modules, printing management entry surface.

All user-facing migration consumes SCDS and preserves approved dev.39 observable behavior unless an explicit redesign/behavior-change decision exists.

## P2 — Local Browser Setup
Immutable ZIP/web package; browser preflight, DB connection/config, migrations, initial admin/config, health check, setup lock. No user-facing Local PS1/EXE.

## P3 — Local Lifecycle / Operations Center
Component Update Center, Local application updater using V3 lifecycle primitives, recovery/LKG path that survives broken releases, unified Health/Logs/Diagnostics/Support Bundle, and Public status/log projection when reachable.

## P4 — Public Edge Product Completion
Deployable entrypoints; Guest/table-token parity; remote staff surfaces; Theme/Media/atomic Publishing; Public updater; independent Emergency Console for limited health/logs/update/rollback/recovery.

## P5 — Windows/Infrastructure Packaging Revision
Windows Services installer owns Runtime + Print Agent only. Detect compatible external prerequisites; offer verified acquisition with progress where policy allows or manual fallback. Do not bundle third-party infrastructure or Local Web.

## P6 — Remaining parity
Notifications; print template package lifecycle; Marketing/Campaign/Event; Reporting/Analytics; module manager/health semantics; machine takeover/Public re-enrollment UX.

## P7 — New Release Qualification
Replace/supersede M9/M10 gates for final architecture. Require immutable artifacts, compatibility manifests, parity closure, recovery and failure-isolation evidence. Produce a new RC.

## P8 — Manual UAT
Only after the new RC: Windows lifecycle, Local browser clean setup, Public deploy/update/emergency recovery, physical thermal-printer soak, responsive/touch/Persian IME, connectivity/fault matrix, updater with open operations, rollback and machine takeover.
