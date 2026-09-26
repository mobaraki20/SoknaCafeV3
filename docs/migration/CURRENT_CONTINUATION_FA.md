# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint تأییدشده

- Head: `931ec31adc2b8769d4786d8f79fcf79ce5b4eb60`
- GitHub Actions workflow: `36217446207`
- Workflow: `V3 Component Gates`
- Result: `SUCCESS`

این checkpoint شامل contract reconciliation، Local Core parity reconciliation، ADR امنیتی Auth Projection و disposition ناظر بیرونی است.

## Slice status

- F0: COMPLETE at foundation level.
- M1: COMPLETE at contract-extraction / executable-boundary level.
- M2: COMPLETE at Local Core slice level.
- Observer reconciliation: COMPLETE for M3 blockers; EOR-06/EOR-08 remain later acceptance/governance items.
- M3: **IN PROGRESS** — Public Edge Persistence, Auth Projection and Relay Transport.
- M4..M10: planned / not complete.

M2 completion به معنی migrate شدن Orders/Preparation/Inventory/Finance نیست.

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

## Immediate continuation — M3

1. Migrate only Public-owned persistence from dev.39 baseline: installations, auth projections, Public sessions, Realtime relay queue, request nonce/replay storage, heartbeats/connectivity metadata and Deferred-safe queue.
2. Do **not** pull M4 guest publish/remote read-model tables into M3 merely because they share the historical schema file.
3. Preserve ADR 0003 remote-login semantics: Local remains identity/capability authority; Public stores only the minimal verifier projection needed for remote login.
4. Bind Realtime and Deferred implementations to the reconciled M1 contracts; keep their persistence/state machines separate.
5. Add a real MariaDB Public gate before claiming any M3 persistence milestone complete.
6. Keep Local as final Business Authority for all canonical business mutations.
