# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.5 Inventory checkpoint:

- Head: `a8cc7dbf5e9f0861aa6c255c8382d431e12e1bbb`
- `M5 Inventory Gate`: PR run `36258340099` — **SUCCESS**
- `M5 Orders Gate`: PR run `36258340117` — **SUCCESS**
- `M5 Table Draft Gate`: PR run `36258340111` — **SUCCESS**
- `M5 Preparation Gate`: PR run `36258340148` — **SUCCESS**
- `M5 Sellables Gate`: PR run `36258340092` — **SUCCESS**
- `V3 Component Gates`: push run `36258337148` — **SUCCESS**
- M4 regression gates — **SUCCESS**

Canonical evidence:

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
- M5.6: **NEXT — Supply/Purchase.**
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

## Immediate continuation — M5.6 Supply/Purchase

Audit/freeze before implementation:

- `modules/Supply/domain.php` — canonical Need / Preparing / Receive owner;
- Supply-owned schema and receipt allocation audit;
- `operator/supply-needs.php`, `admin/purchases.php`, batch purchase/receive paths;
- Deferred-safe `supply.need.create`, status transitions and receipt operations;
- exact physical-receive → M5.5 Inventory movement boundary;
- unknown-item creation through the Inventory owner rather than direct Inventory table writes.

Known invariants to preserve:

1. Low Stock is a signal only; it does not itself create a purchase or stock movement.
2. Requested, Preparing and physically Received quantities are distinct business states.
3. Only physical Receive increases Inventory, and it must call the canonical Inventory movement owner.
4. Supply receipt is idempotent by request token / durable source key.
5. Partial and over-receive allocation must preserve the true Preparing/New remainder and allocation audit.
6. Batch receipt must be atomic across its intended group set.
7. Unknown catalog items may be created only through the Inventory owner as `needs_review`; Supply must not write Inventory master tables directly.
8. Deferred-safe Supply work revalidates Local actor/capability/current state before commit; conflicts become review rather than silent overwrite.
9. Supply must not become a second Inventory balance/movement authority.
10. Finance/Settlement ownership must not be pulled forward merely because receipt cost data exists.

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