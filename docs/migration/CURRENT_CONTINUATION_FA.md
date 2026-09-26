# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.8 Expenses checkpoint:

- Head: `44aec5939e7d4ce343dfa7db89952c094528962d`
- `M5 Expenses Gate`: PR run `36260585942` — **SUCCESS**
- M5 Orders / Inventory / Supply / Tax / Preparation / Table Draft / Sellables regressions — **SUCCESS**
- M4 regressions — **SUCCESS**

Canonical evidence:

- `docs/migration/M5_EXPENSES_AUDIT_FA.md`
- `docs/migration/M5_TAX_AUDIT_FA.md`
- `docs/migration/M5_SUPPLY_AUDIT_FA.md`
- `docs/migration/M5_INVENTORY_AUDIT_FA.md`
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
- M5.5: **COMPLETE — Inventory exit gate satisfied.**
- M5.6: **COMPLETE — Supply/Purchase exit gate satisfied.**
- M5.7: **COMPLETE — Tax exit gate satisfied.**
- M5.8: **COMPLETE — Expenses exit gate satisfied.**
- M5.9: **NEXT — Financial Periods.**
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

## Immediate continuation — M5.9 Financial Periods

Audit/freeze before implementation:

- `financial_period_for_date_locked()` and `financial_period_issue_invoice_locked()` in historical `includes/settlement.php`
- `admin/financial_periods.php`
- `financial_period_close_overrides`
- Deferred period/review close blockers
- business-day-aware invoice numbering

Known invariants:

1. Financial Periods follow Jalali fiscal-year bounds.
2. A financial document issued in the after-midnight business-day tail belongs to the same operational date/period as the café shift.
3. Invoice/reversal display numbers share the period's locked monotonic sequence and use `I-<jalali-year>-<6 digits>` / `R-...`.
4. Closed periods reject new normal financial documents.
5. Late Deferred work never silently reopens or rewrites a closed period.
6. Normal close is blocked by pending Local reviews and, when paired, unknown/pending Public Deferred state.
7. An override is Admin-only, reasoned and durably audited.
8. Final close also depends on canonical Settlement totals and other Finance blockers; until M5.10 Settlement exists, M5.9 must not write `status='closed'` as a standalone approximation.
9. M5.9 may own close-readiness evidence and override records; M5.10 activates final close with canonical Settlement summary.

### M5 remaining dependency order

After canonical Orders:

1. Staff Quick Order + Table Draft — COMPLETE;
2. Preparation permission/action owner — COMPLETE;
3. Inventory — COMPLETE;
4. Supply/Purchase — COMPLETE;
5. Tax — COMPLETE;
6. Expenses — COMPLETE;
7. Financial Periods — NEXT;
8. Settlement/Reconciliation;
9. Accommodation/Center adapters.

Do not start M7/M8/M9 implementation as a substitute for M5 domain migration.

## Failure-isolation note carried forward

M4 proved boundary-level Local-down/Public-down/Internet-or-sync-loss behavior. It did not claim packet-level NIC/DNS/proxy/browser chaos qualification; that remains explicit M10 release-qualification work.