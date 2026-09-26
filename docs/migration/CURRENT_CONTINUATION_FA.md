# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint تأییدشده

- Contract reconciliation commit: `ebceeacf21ebdc6996634d113f6cac4a3d5a7083`
- GitHub Actions workflow: `36216627352`
- Workflow: `V3 Component Gates`
- Result: `SUCCESS`

این checkpoint، EOR-01 تا EOR-03 را در سطح contract/evidence می‌بندد:

- capability map با dev.39 reconcile شد؛
- HMAC observable taxonomy با baseline reconcile شد؛
- fixture مستقل با provenance دقیق dev.39 به gate اضافه شد.

## Slice status

- F0: COMPLETE at foundation level.
- M1: COMPLETE at contract-extraction / executable-boundary level.
- M2: COMPLETE at Local Core slice level.
- M3: NEXT ordered implementation slice after observer reconciliation.
- M4..M10: planned / not complete.

M2 completion به معنی migrate شدن Orders/Preparation/Inventory/Finance نیست.

## Observer reconciliation

- EOR-01: `REGRESSION_FIXED`
- EOR-02: `REGRESSION_FIXED`
- EOR-03: `REGRESSION_FIXED`
- EOR-04: this file is the single continuation authority; top-level entrypoints must point here.
- EOR-05: proven Local Core parity items are being restored; transient authenticated-session DB failure behavior remains intentionally preserved pending an explicit security decision.
- EOR-06: deferred to upgrade/recovery qualification; not an M3 design blocker.
- EOR-07: must be closed before Public login/auth projection implementation in M3.
- EOR-08: release-governance item; not a domain-migration blocker.
- EOR-09: M8 constraint remains local/machine-bound Print Agent with mature behavior reuse; no Public/Internet control.
- EOR-10: informational staging clarification.
- EOR-11: lower-priority hardening items.

## Immediate continuation

1. Finish EOR-04/EOR-05 parity and evidence.
2. Close EOR-07 with an explicit auth-projection ADR before migrating Public login.
3. Start M3 Public Edge persistence/auth projection/relay transport on the reconciled M1 contracts.
4. Keep Realtime and Deferred stores/state machines separate.
5. Keep Local as final Business Authority.
