# SOKNA R275 - Cafe Local/Public connection checkpoint (2026-11-18)

## Single objective
Connect the already-installed Cafe Local to already-installed Public, fully reproject menu/prices/media from Local, and prove exactly one real guest test order reaches Local. No generic product redesign and no repeat install.

## Exact field evidence
- Public R275 / 35ij was successfully staged, activated and committed via authenticated Emergency on 2026-11-17 at 21:23:42-56 UTC (user-provided real Emergency state). Managed tree matches expected, no staged/pending verification.
- Local R275 / 231fl shown on Cafe PC screenshot 2026-11-18 ~00:49 Iran time; byte-level installed readback not yet available.
- Two source ZIPs were recovered from GitHub Actions run 44107836936 and SHA256 rechecked: Local f42d66e26dfee936f29c159bb7028adefcfedda43ddf2dc63e2c25d6a7d6dba9; Public 63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f.
- Historical Local/Public installation identity was independently confirmed; 4442 old Local pending projection events are NOT guest orders and must never be manually deleted.
- Last field attempt: Public owner confirmation could not complete; "independent Public owner password not initialized", HTTP 403; user reports no visible setup option. Which exact /api/v1/owner/* POST returned 403 is not established. Connection and guest delivery remain NOT PROVEN.

## Minimum phases and stop gates
0. Existing install/source verified - DONE. Do not clean install or repeat R275.
1. Owner bootstrap or login in current Public; identify exact 403 route and safe code, make setup accessible in actual UI; PRODUCTION BLOCKED until successful real owner authentication.
2. Public owner's signed consent + separate Local admin consent + one-time authority receipt - PENDING.
3. Full Local to Public sync, products/prices/categories/media, readback and ACK; only finalize URL/cutover after first verified sync - PENDING.
4. QR guest order → Local exactly once; verify receipt and final state - PENDING. This is the definition of DONE.

## Source audit on exact installed candidate ZIP
- PublicMoveConsentPage.php renders the <details data-owner-upgrade> "آماده‌سازی رمز مدیر وب عمومی"; PHP render and syntax tests passed.
- sokna-public-move.js hides owner-upgrade when challenge, return-origin or opener is invalid/expired; source feature existence does NOT prove the user can see it.
- PublicHttpKernel::publicOwnerUpgradeAction maps invalid staff admin/session/origin/site scope to 403 owner_upgrade_not_permitted.
- PublicMoveOwnerHttpAdapter may map invalid owner action to 403 owner_action_denied; do not guess which one happened.
- ExistingOwnerActivation requires Public's own valid staff admin cookie, same-origin request and a unique active installation. PublicSessionStore and PublicLoginService also require installation.remote_enabled=1. Previous pre-R275 Public support showed remote_enabled=true but the current real flag is not proven.
- R273/R275 HTTPS owner upgrade test inserted a synthetic valid staff session into disposable MariaDB: this did not exercise real /staff/login on Cafe Public.

## Next bounded step
Isolate the exact failed owner POST, HTTP status and non-sensitive code via the supported Public operator interface or sanitized logs; do NOT expose cookies, passwords, signed challenges, tokens or browser URL fragments. Confirm owner's setup path is genuinely reachable in the flow. Correct only that issue if required, then advance immediately to phases 2-4. Do not classify QA success as Cafe acceptance.

## Non-negotiable boundaries
Respect MIG-001..MIG-020 in r251/DECISIONS-FA.md; no hardcoded URLs or tenant identities. No manual SQL/epoch/secret, no Emergency in daily pairing, no alteration of Local financial/inventory/order records, no clearing 4442 Local pending events, no QR change, no unapproved deletion. Public-only unresolved work may be terminalized only on clear authenticated owner confirmation and respecting active leases. Hold unrelated print/finance/UI work until connection is proven.
