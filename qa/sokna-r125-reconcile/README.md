# R125 proof reconciliation — independent QA branch, not a production release

This code addresses the proven residual \`routine-local-lifecycle-before.json\`
blocker after R125 rollback to Local R112. The 2026-11-09 One Support
archive reported the residual 85,165-byte proof, its SHA256
\`c2762ce01e2e41b8d688c98e5824bb86758c7fc3509f414a6392e05bf4b59d28\`,
a terminal \`RECOVERED_TO_PREVIOUS_SLOT\` state, and the blocker
\`LOCAL_LIFECYCLE_NOT_IDLE_OR_UNPROVEN\`.

## Scoped design

- New signed \`POST /v1/local-updates/reconcile-r125-lifecycle\` endpoint is
  exclusively authorized by the existing \`recovery-console\` HMAC identity,
  loopback-only. The Web-adapter HMAC identity MUST be rejected.
- The native Recovery Console remains read-only for its existing commands.
  The new command \`reconcile-r125-lifecycle\` requires an explicit
  \`--confirm-preserve-and-archive\` acknowledgement, an operator id,
  exact installed version, exact source proof SHA and a unique idempotency key.
- Hold \`LocalUpdateOperationGate.Sync\` and \`MachineMutationLease\`.
  Reject foreign in-progress or recovery-required operations.
- Require a settled, signed-id-commit, \`RECOVERED_TO_PREVIOUS_SLOT\` routine.
  \`DatabaseMutationPerformed\`, Public mutation, reboot, unproven active slot,
  unsound latest event or \`RecoveryRequired\` block reconciliation.
- The present live Apache slot, managed-file SHA, installed Local identity and
  *byte-exact* restored lifecycle state, managed inventory and installed identity
  must match the retained proof.
- Run \`RoutineLocalDatabase.RequireBaseline\` (SELECT-only) using the live
  PHP/MariaDB ledger; any partial migration, failed transaction or mismatch
  blocks the operation. A clean ledger **does not prove a usable backup** or
  authorize the next release update.
- Copy nothing to the application directories; move the exact proof by an
  atomic on-volume rename to a protected Maintenance-owned archive. Persist
  durable intent, archive receipt and journal. No SQL writes, Local/Public
  changes, stopping/restarting Apache or proof deletion.
- If the process crashes after the proof move, the durable operation remains
  blocking until the *same signed idempotency request* revalidates the
  archived proof, intent and live host.
- This is NOT a general stale-lock cleaner, not a migration rollback, not a
  completed DB restore, and not an ordinary update authorization.

## Limits and release gates

The current One Support report does **not** disclose
\`RoutineLocalSlotState.DatabaseMutationPerformed\`, the byte-exact match
result or an actual DB restoration proof. Consequently the café host may be
rejected by the new strict preflight. Never infer success from the presence of
a recovered marker.

No production package is published by this branch. The already-installed
Maintenance 0.3.22 does not contain this endpoint or updated Recovery Console.
A separate fully verified Windows installer/release and real host-specific
backup/restore preflight are needed before invoking recovery on café equipment.
Do not delete residual files, bypass the HMAC authorization or rerun Local
activation.
