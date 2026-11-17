# SOKNA FIN-20261118-001 — Local 231fl discount input/preview mismatch

## Observed screenshot (2026-11-18, 00:49 Windows time)
- UI Local footer `3.0.0.231fl`, table 8, discount dialog in fixed-amount mode.
- Gross: 845,000 Toman; typed: 320,000 (unit invisible); preview discount: 32,000 Toman; total: 813,000 Toman; tax: 0.
- If typed amount intended Toman, correct preview is discount 320,000 Toman and total 525,000 Toman.
- Separately, 8,450,000 Rial bottom-left is consistent with 845,000 Toman final-amount display preference; it is not itself erroneous.
- Reproducing API request/response is still needed to attribute exactly why preview differs by factor 10. **Do not assert conversion root cause**.

## Exact R275 ZIP source audit (SHA256 f42d66e26dfee936f29c159bb7028adefcfedda43ddf2dc63e2c25d6a7d6dba9)
- `public/assets/staff-app/hall-workspace.js` lines ~603-606: `discountPreview` sends parsed input as `discount_value`; `applyHallDiscount` reads input again; no x10 or /10.
- `src/Domain/Finance/SettlementService.php` lines 205-230, 263-310, 1107-1114: fixed discount is `min(subtotal,value)`, no x10 or /10.
- `public/assets/locale.js` normalizeNumber and money are not explicitly dividing by 10.
- `resources/shared-staff-ui/hall-workspace.php` input label lacks persistent unit; placeholder shows Toman only when input empty.
- `discountPreviewValid` is boolean, not cryptographically or structurally bound to current `discount_type`, input, or financial session snapshot. Normal input event invalidates it, but additional invariant check is advisable.

## Risk/QA recommendation
- Financial UX defect **OPEN**, high priority, track separate from urgent R275 Public connection repair.
- Do not apply discount on live table until displayed preview exactly matches input and intention.
- Fix should be currency-explicit and enforce preview/apply parity, not blind scaling. Test Persian digits/grouping, delayed preview, current prefilled fixed discount, 0/with tax, both monetary display modes.
- No production file or DB mutation; no R275 release change for this issue.

Full Persian report: `/Cafe/SOKNA-Workspace/ISSUES/20261118-DISCOUNT-PREVIEW-231FL/SOKNA-20261118-LOCAL-231FL-DISCOUNT-PREVIEW-MISMATCH-FA.md`.
