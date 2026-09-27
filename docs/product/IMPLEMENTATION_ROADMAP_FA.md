# SOKNA Cafe V3 — Corrected Execution Roadmap

تاریخ: 2026-09-27
وضعیت: CANONICAL EXECUTION ORDER

## Rule
در هر لحظه فقط یک workstream active است. هیچ workstream به دلیل gap دیگر از صفر audit نمی‌شود. Coreهای سبز فقط در صورت defect evidence باز می‌شوند.

## G0 — Governance Repair — COMPLETE
هدف: جلوگیری از تکرار false-completion. **بسته شد.**

Deliverables:
- root `COMPONENTS.json` یا معادل machine-readable؛
- superseding ADR برای deployment separation و updater/control plane؛
- status vocabulary `CORE_COMPLETE / PRODUCT_COMPLETE / RELEASE_COMPLETE`؛
- Product Parity Gate؛
- update current migration matrix so backend-only rows are not `migrated` product claims.

Exit: agent can be asked for one component by name and knows exact scope/tests/package/dependencies; no ambiguous `closed` state remains.

## G1 — Local Web Product Parity — ACTIVE
- migrate protected admin/operator/staff/waiter product surfaces from post-UI authority into SCDS;
- complete missing Local realtime adapters/owners (guest order, waiter, order edit/cancel, settlement);
- complete `subscriber.payment` deferred adapter;
- users/settings/modules/tables/QR;
- Local domain UIs for Orders/Preparation/Inventory/Supply/Expenses/Tax/Finance/Subscribers/Integrations/Printing.

## G2 — Local Setup / Observability / Update
- WordPress-like Browser Setup Wizard;
- unified component health/log/diagnostics/support UI;
- component status/version/compatibility model;
- Local Update Center;
- Local updater + stable recovery entrypoint;
- backup/recovery/takeover UX.

## G3 — Public Edge Productization
- hosting-ready deploy package and document root;
- guest routes `/menu` and table context;
- remote staff gateway/read surfaces;
- Local outbound publish/read-model producers and heartbeat;
- Public Emergency Console;
- Public updater/rollback/recovery;
- kill switch/takeover/status/log surfaces.

## G4 — Remaining Parity
- Marketing/Campaign/Event;
- Reporting/Analytics;
- Notifications/Push;
- Theme/Media/central guest copy;
- Print template package manager;
- remaining integration/admin workflows.

## G5 — Windows Packaging Revision
- Infrastructure external compatibility matrix;
- prerequisite checker + verified online acquisition/manual fallback;
- one Windows Services installer for Runtime + Print Agent only;
- independent repair/update/uninstall semantics.

## G6 — Release Qualification v2
- independent component artifacts/versions + compatibility manifest;
- rewritten M9/M10/CI against final deployment architecture;
- Product Parity Gate green.

## G7 — Manual UAT / Production Candidate
- Local browser clean install;
- Public clean deploy;
- Windows Services clean install/repair/uninstall;
- component update/rollback/recovery;
- Public Emergency recovery;
- machine takeover;
- physical thermal printer + 24h/72h soak;
- responsive/touch/Persian IME/real browsers;
- initial handoff fault/chaos matrix.
