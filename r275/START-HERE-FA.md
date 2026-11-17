# R275 — Public-only pending discard with explicit owner consent

2026-11-17: user prioritizes fast connection and accepts pending PUBLIC-only loss after a warning. Never discard Local business/order/financial data. Preserve Local 4442 projection pending events; they are not orders.

Implementation: R275 is code-only based on exact R262 Local 231fg and R266 Public 35ig. The Public owner first requests authority offer without discard. If destination_pending_guest_work is returned, browser presents explicit Persian irreversible-loss warning, and ONLY affirmative response repeats authenticated CSRF-protected owner offer with discard_pending_guest_work=true. The Public database transaction terminalizes same-installation realtime queued/claimed/unknown_review and deferred pending_sync/needs_review requests, records reason and audit counts, and refuses any active leased claims. All other Local business data untouched.

Exact SHA Local 231fl package: f42d66e26dfee936f29c159bb7028adefcfedda43ddf2dc63e2c25d6a7d6dba9
Exact SHA Public 35ij package: 63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f

QA gates PASS:
- HTTPS/MariaDB owner consent, pending cancel, active lease safety, full sync + guest exactly once: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44105878595
- Native Windows direct Local 231fg->231fl and DB 82 migrations: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44106880987
- Chromium actual ZIP JS four owner popup cases: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44106881101
- Public Emergency protected HTTP 35ig->35ij code-only Stage Activate Commit, 11 migrations: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44107838897
- Earlier Public Emergency fixture failures existed on 44106358557 and 44106759328, later run with same payload passed. Real installed host wrapper not byte checked.

FIELD STATUS remains NO-GO for production: operator_install_allowed=false in R275 package proof. Live Production unchanged. Exact host Stage, recoverability, real guest test not done. Do not manually edit host, Local DB, authority epochs or QR. Stage-only protected Public actual host verification (no activate/commit) is next low-risk action before operator install qualification. Do not relabel QA ZIP as signed Production.
