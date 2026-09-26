# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.4 Preparation permission/action checkpoint:

- Head: `8d24779cd059d854614e11d6ba800c963986a6c1`
- `M5 Preparation Gate`: run `36254559171` — **SUCCESS**
- `M5 Table Draft Gate`: run `36254559182` — **SUCCESS**
- `M5 Orders Gate`: run `36254559189` — **SUCCESS**
- `M5 Sellables Gate`: run `36254559163` — **SUCCESS**
- `V3 Component Gates`: push run `36254558003` — **SUCCESS**
- M4 regression gates — **SUCCESS**

Canonical evidence:

- `docs/migration/M5_PREPARATION_AUDIT_FA.md`
- `docs/migration/M5_TABLE_DRAFT_AUDIT_FA.md`
- `docs/migration/M5_ORDERS_AUDIT_FA.md`
- `docs/migration/M5_SELLABLES_AUDIT_FA.md`

## Slice status

- F0: COMPLETE at foundation level.
- M1: COMPLETE at contract-extraction / executable-boundary level.
- M2: COMPLETE at Local Core slice level.
- M3: COMPLETE at Public Edge transport/auth/projection slice level.
- M4: COMPLETE — Guest Publish/Runtime/Remote Read Models exit gate satisfied.
- M5.1: **COMPLETE — Explicit Sellables/catalog authority exit gate satisfied.**
- M5.2: **COMPLETE — canonical Orders authority exit gate satisfied.**
- M5.3: **COMPLETE — Staff Quick Order + server-persistent Table Draft exit gate satisfied.**
- M5.4: **COMPLETE — Preparation permission/action owner exit gate satisfied.**
- M5.5: **NEXT — Inventory.**
- M6: continuous SCDS cross-cutting track.
- M7..M10: planned / not complete.

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

## Immediate continuation — M5.5 Inventory

Audit/freeze before implementation:

- `includes/inventory.php` — canonical movement/balance authority;
- inventory schema: items, balances, movements, units/conversions, recipes;
- stock-count draft/finalize owner and capability split;
- receive/waste/manual-adjustment idempotency contracts;
- historical order-accounted inventory event boundary;
- existing Deferred-safe inventory kinds and Local reconciliation behavior.

Known invariants from historical evidence:

1. inventory balance changes through the canonical Movement contract, not arbitrary balance writes;
2. sensitive inventory writes are transactional and idempotent;
3. movement idempotency keys must prevent duplicate stock effects;
4. stock-count draft editing and Local finalize are separate authorities;
5. remote count work may edit a draft but must not perform Local-only finalize;
6. current-cost/average-cost chronology must not be silently recomputed with a different ordering rule;
7. Public/Deferred owns transport/review state only; Local owns final inventory validation and commit;
8. Inventory migration must not silently pull Supply/Purchase or Finance ownership forward.

### M5 remaining dependency order

After canonical Orders:

1. Staff Quick Order + Table Draft;
2. Preparation permission/action owner;
3. Inventory;
4. Supply/Purchase;
5. Expenses;
6. Financial periods/Settlement/Reconciliation;
7. Tax/cross-domain finance integration;
8. Accommodation/Center adapters.

Do not start M7/M8/M9 implementation as a substitute for M5 domain migration.

## Failure-isolation note carried forward

M4 proved boundary-level Local-down/Public-down/Internet-or-sync-loss behavior. It did not claim packet-level NIC/DNS/proxy/browser chaos qualification; that remains explicit M10 release-qualification work.