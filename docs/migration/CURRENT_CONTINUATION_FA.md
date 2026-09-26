# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.3 Staff Quick Order + Server-persistent Table Draft checkpoint:

- Head: `b7c021ff7cd318bd40ee6212c2385c540aad3632`
- `M5 Table Draft Gate`: run `36254200135` — **SUCCESS**
- `M5 Orders Gate`: run `36254200164` — **SUCCESS**
- `M5 Sellables Gate`: run `36254200133` — **SUCCESS**
- `V3 Component Gates`: push run `36254196611` — **SUCCESS**
- M4 Guest Renderer / Failure Isolation regression gates — **SUCCESS**

Canonical evidence:

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
- M5.4: **NEXT — Preparation permission/action owner.**
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

## Immediate continuation — M5.4 Preparation permission/action owner

Historical authority to audit/preserve:

- `docs/handoffs/PHASE6A_HANDOFF_FA.md`
- `docs/architecture-migration-r2/PHASE6A_CHECKPOINT_FA.md`
- `includes/preparation_permissions.php`
- `waiter/api_feed.php`
- `waiter/api_action.php`
- `user_preparation_areas`
- `order_preparation_claims`
- existing `preparation_adjustments` behavior only where required by the Preparation action boundary
- historical Local/Public projection of Preparation scopes and `preparation.mutate`

Frozen requirements:

1. Preparation visibility and mutation authority are separate server-side concepts.
2. `preparation` only: assigned areas visible and actionable.
3. `shift_supervision` only: all areas visible, none actionable.
4. `shift_supervision + preparation`: all areas visible, only assigned areas actionable.
5. Admin role alone: all areas visible, no Preparation mutation authority.
6. Browser/Public projection may filter, but Local revalidates active user/capabilities/assigned areas on every mutation.
7. Read/feed behavior is side-effect free.
8. Mutation never normalizes an invalid/unassigned area into an authorized one.
9. `preparation.mutate` remains Realtime/Local-required and is excluded from Deferred-safe transport.
10. Do not pull Inventory, Finance, Settlement or Print lifecycle forward merely to implement Preparation permission/action ownership.

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