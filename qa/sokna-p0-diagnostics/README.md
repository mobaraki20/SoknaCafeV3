# SOKNA P0 — evidence-first host diagnosis (QA only)

**Status:** engineering QA / NOT AN INSTALL PACKAGE. No Local, Public, database or Windows service mutation.

## Verified incident
- Reported active Local R112/231dg; Public R149/35hh. Separate host readback required.
- Exact R149B Local release SHA-256: 968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4.
- R112 is listed among 43 exact baselines; two PACKAGE_INTEGRITY_VERIFIED / STAGED pairs appeared on 2026-11-08; no Activate or Commit for them.
- Supervisor top-level status LOCAL_HOST_PRECONDITIONS_UNPROVEN. The exact host blocker cannot be determined from the uploaded report.
- R149B nested ZIP omits host_blockers and host_blockers_available. Source in QA R150 contains both. Source-only fixes were not included in the actual release artifact.
- NO_BACKUP_STATE is an independent backup/restore risk, not a proven direct root cause.

## What this branch adds
- A pure PHP test of reason propagation, unknown-state handling, Stage-vs-install, and path redaction.
- A Python check for source files and nested final web.zip in the exact release ZIP, intended to be applied **after** the final artifact is sealed.
- A GitHub Actions source + nested ZIP positive/negative regression fixture. CI run 42513602946: PASS.
- Historical R149B negative control confirmed by a local read-only test of that archive, without modifying it.

## Required next steps (still OPEN)
1. Have standard Support return signed host_blockers plus stage/activate receipts, or mark evidence UNKNOWN. Do not require user-run scripts.
2. Integrate the Python gate against the **actual** final release ZIP in the real builder workflow, not just the fixture.
3. Diagnose actual host blockers, attest Maintenance/Engine versions and repair only the proven condition, with no readiness bypass.
4. Validate backup/restore on an isolated database copy before any migration; then test full R112→target panel install/rollback/forward recovery.
5. Distinguish source CI, sealed archive verification, field canary, and production approval. This QA provides only diagnostic-contract evidence.

Do not install this branch or manually copy PHP to the cafe host.
