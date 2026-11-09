# Update safety implementation — 2026-11-09

Base: `02dd6431a3fe2952ea1da2e3e04e625c4b75ab78`. Changes are engineering QA,
not a production release or permission to install on the cafe.

## Implemented boundaries

- The real Engine resolves the dump executable, validates the live numeric/string
  DB port and rejects non-InnoDB tables before Apache stop. The backup repeats
  those checks after stop. Preflight creates neither a dump nor credential file.
- The shared final-archive gate recomputes inventory, managed tree, VERSION,
  migration head, SQL fingerprint and DAG from the final bytes. It rejects
  Windows case collisions and does not require publisher signing (U-06).
- Production field validation requires release-controlled external receipts bound
  to the exact final ZIP SHA, two distinct supported source identities, committed
  target readback, business invariants, both recovery boundaries and real cafe
  restore evidence. HOME_CANARY plus production flags is rejected.
- The R149B builder runs this structural gate on its finished ZIPs. Normally it
  emits only a non-installable candidate. Historical positive Stage fixtures can
  only be produced with `SOKNA_R149B_DISPOSABLE=YES` inside `RUNNER_TEMP`;
  their evidence explicitly says no production qualification. No release upload
  is performed by the new workflow.

External receipt validation is a consistency check, not cryptographic attestation.
Its trust boundary is the release-controlled CI input and human verification of
source receipts. It never authorizes deployment or replaces the installed
Supervisor's baseline, backup, mutation lease and activation checks. Synthetic
receipts in unit tests are never user/host acceptance.

## Remaining field gates (do not mark PASS from CI)

1. Live cafe ledger, residual R125 proof and `DatabaseMutationPerformed` state.
2. Actual cafe backup restore to an isolated target and business/queue validation.
3. Qualified maintenance deployment and signed internal Recovery reconciliation.
4. Exact final Local package on two distinct full baselines, followed by home and
   cafe field acceptance; Public Routine/Emergency remains independent.
5. Wire this gate into every other historical builder/publisher after auditing
   their schemas; only the R149B path is integrated by this change.

No Local/Public canonical application source or historical SQL is edited. No
marker is deleted, no `ready` boolean is forced, and no services are installed.
The historical packaging register referenced by `windows/maintenance/AGENTS.md`
was not accessible under its id or exact-title search in this session. Its history
has not been fabricated or replaced. The 2026-11-09 incident audit and current
host reports were used for these bounded QA changes; production remains blocked.
