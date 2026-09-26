# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint تأییدشده

- Head: `07757f037ece5c71097cafb0f3dcc1d21ae4dcdf`
- GitHub Actions workflow: `36241108219`
- Workflow: `V3 Component Gates`
- Result: `SUCCESS`

این checkpoint شامل M3 کامل در سطح Public Edge است: Public persistence/migrations، Auth Projection و session/throttle/audit، HMAC/replay guard، heartbeat/connectivity، Realtime transport، Deferred-safe transport و Public health/safe-error boundary.

## Slice status

- F0: COMPLETE at foundation level.
- M1: COMPLETE at contract-extraction / executable-boundary level.
- M2: COMPLETE at Local Core slice level.
- M3: **COMPLETE at Public Edge slice level; exit gate satisfied.**
- M4: **NEXT** — Guest Publish, Guest Runtime and Remote Read Models.
- M5..M10: planned / not complete.

تکمیل M2/M3 به معنی migrate شدن business-domain ownerهای Orders/Preparation/Inventory/Supply/Finance نیست؛ Local همچنان مرجع نهایی mutationهای کسب‌وکار است و آن ownerها در sliceهای بعدی منتقل می‌شوند.

## Observer reconciliation

- EOR-01: `REGRESSION_FIXED`
- EOR-02: `REGRESSION_FIXED`
- EOR-03: `REGRESSION_FIXED`
- EOR-04: `REGRESSION_FIXED` — this file is the single continuation authority; README/START_HERE point here.
- EOR-05: `REGRESSION_FIXED` for five proven parity items; transient authenticated-session DB failure behavior remains intentionally `PRESERVED` per M2.
- EOR-06: deferred to M10 upgrade/recovery qualification.
- EOR-07: `PRESERVED` with explicit V3 security boundary in `docs/adr/0003-public-auth-projection-strategy.md`.
- EOR-08: deferred release-governance item; not a domain-migration blocker.
- EOR-09: local/machine-bound Print Agent constraint preserved for M8; mature Pagent behavior is reuse evidence.
- EOR-10: staged-migration clarification preserved.
- EOR-11: hardening backlog; address when affected boundaries are touched.

Canonical disposition record:
`docs/reviews/EXTERNAL_OBSERVER_DISPOSITION_2026-09-26_FA.md`

## Immediate continuation — M4

1. Audit the historical Guest Publish/Guest Runtime/Remote Read Model owners from the selected dev.39 baseline before moving code.
2. Keep Local as source/publish-decision owner; Public may own immutable published snapshots/media, availability projection and remote read-model storage/runtime only.
3. Migrate `guest_publish_revisions`, `guest_active_revisions`, `guest_availability_state` and `remote_read_models` only inside M4, with explicit ownership and migration tests.
4. Preserve atomic active-revision switching and degraded read-only behavior when Local/Internet availability changes.
5. Apply SCDS rule `Audit -> Correct -> Standardize -> Migrate -> Enforce`; do not blindly copy legacy guest CSS/markup or create a second renderer authority.
6. Remote read models must remain filtered by projected capability/preparation-area scope and must never become mutation/business authority.
7. Do not start M5 business-domain movement or M7/M8/M9 implementation as a substitute for completing the M4 exit gate.

M3 evidence and exit decision:
`docs/migration/M3_PUBLIC_EDGE_AUDIT_FA.md`
