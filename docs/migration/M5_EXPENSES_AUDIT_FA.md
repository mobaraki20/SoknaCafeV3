# M5.8 — Expenses Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation head: `44aec5939e7d4ce343dfa7db89952c094528962d`
- `M5 Expenses Gate` PR run `36260585942` — **SUCCESS**
- previous full push gate before the exception-owner cleanup: `36260485473` — **SUCCESS**
- M5 Orders / Inventory / Supply / Tax / Preparation / Table Draft / Sellables regression gates on the same head — **SUCCESS**
- M4 Guest Renderer / Failure Isolation regression gates on the same head — **SUCCESS**

## Historical authority audited

- `includes/expenses.php`
- `admin/expenses.php`
- Financial Period identity helper in `includes/settlement.php`
- Expense / Financial Period schema
- Deferred `expense.create` and late-period review behavior

## Frozen Expense semantics preserved

1. Expenses are general café expenses only; Inventory/Supply purchasing cost is not duplicated here.
2. A committed expense row is immutable.
3. Reversal is append-only; the original expense remains present and unchanged.
4. Correction is atomic: one reversal row plus one replacement committed row.
5. `source_request_id` owns exactly-once create semantics.
6. A repeated source request with different category/amount/occurred time is a conflict.
7. Expense category must be active at create time.
8. Every expense is bound to the Financial Period covering its original `occurred_at`.
9. Normal create/reverse/correction into a closed period is rejected.
10. Deferred `expense.create` revalidates Local active-admin authority.
11. A Deferred event whose occurred date belongs to a closed period creates one durable `late_correction` review and performs no business mutation before approval.
12. Explicit review approval may append the late expense while preserving original occurred time and period identity; it does not reopen or rewrite the period.
13. Deferred retry replays the Local receipt/result and cannot duplicate the expense.
14. Period summary treats committed rows as positive expense and reversal rows as negative expense.

## Minimal Financial Period prerequisite introduced here

Expenses cannot be correct without period identity. M5.8 therefore introduces only the prerequisite needed by Expense and Deferred semantics:

- Jalali fiscal-year bounds;
- ensure/find the period covering a Gregorian occurred date;
- open/closed acceptance guard;
- stable period FK from Expenses and Deferred receipt/review rows.

This prerequisite deliberately does **not** implement financial close, Settlement summaries, invoice allocation or final close mutation. Those remain ordered Finance work.

The Financial Period prerequisite has its own error contract; Expenses translate period failures at their domain boundary rather than making Finance depend on Expenses.

## V3 owners

- `apps/local-web/database/migrations/0010_m5_expenses.sql`
- `apps/local-web/src/Domain/Finance/FinancialPeriodException.php`
- `apps/local-web/src/Domain/Finance/FinancialPeriodIdentityService.php`
- `apps/local-web/src/Domain/Expenses/ExpenseException.php`
- `apps/local-web/src/Domain/Expenses/ExpenseService.php`
- `apps/local-web/src/Relay/ExpenseDeferredAdapter.php`
- period-aware additions to `DeferredReceiptService.php`
- Bootstrap/Core registrations
- `tests/local-m5-expenses-selftest.php`
- `.github/workflows/m5-expenses-gate.yml`

## Exit decision

**M5.8 exit gate: SATISFIED.**

Continuation moves to **M5.9 — Financial Periods**. M5.9 owns period identity/numbering and close preconditions/evidence; final close mutation must remain blocked until M5.10 Settlement can provide the canonical financial summary.
