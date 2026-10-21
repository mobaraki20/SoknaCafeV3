# Maintenance V4 — Operator-authorized Local ZIP, no publisher signing keys
Date: 2026-10-22. Source branch: `fix/v4-panel-operator-unsigned-local-20261022`. PR #41.

## Definitive small-cafe decision
For routine **Local-only** update through the logged-in administrator's Local panel, the user's explicit upload and installation request replace the **publisher signature** requirement. Do not ask for certificates, signing keys, publisher key registration, issuer enrollment, a Cafe-specific key, a HOME key, a separate executable or PowerShell. The same procedure is independent of host name, installation ID, IP, domain or directory.

The existing authenticated local Web-adapter/Supervisor request channel (internal secret + RBAC/CSRF) remains so that a random local process or guest cannot pretend to be the administrator. These are internal transport controls, **not a release-signing ceremony** and not exposed to the operator.

## Existing Windows-owned process (no new update engine)
1. Admin selects a valid SOKNA `local_only` Release Set v2.1 ZIP. Upload via existing authenticated/authorised panel, streamed to Supervisor's content-addressed `InboxRoot/<sha256>.zip`.
2. Supervisor independently verifies that the real file is located immediately within its private inbox, is not reparse-linked and its SHA-256 bytes match its filename. Only this explicit operator upload may be unsigned; external files and other release types retain their prior behavior.
3. VerifyOnly validates Release Set schema, embedded artifact hashes, exact compatible Local baseline, min Maintenance version and anti-rollback sequence. No signature/key metadata, `for_install`, `production_pass` or publisher failure-matrix claims are necessary **for that operator-authorized Local-only upload**. It never mutates DB or activates on inspection.
4. Preflight receipt records `operator_authorized=true`, `signature_verified=false`, `artifact_integrity_verified=true`, never a fake cryptographic endorsement.
5. Stage independently rechecks upload hash and installed identity, verifies each artifact again, persists operation/sequence/receipts with operator provenance, and permits idempotent readback.
6. Only the already existing Local candidate/stage/backup/activation/recovery routes may execute an actual update. File traversal, duplicate/missing/changed artifact, wrong target managed-file count, incompatible current managed tree, failed backup, conflicts and unverified rollback remain blocking conditions.

The package must be a recognized **SOKNA Local Release Set ZIP**, not arbitrary software or arbitrary ZIP content. This preserves accidental-corruption/rollback protection without requiring a publishing key. The developer's old engineering 231ba preparation file is **not** a production package: it contains unresolved installed schema/migration/authority identity placeholders and lacks the final `targets.local.managed_files` field. Missing key is no longer its obstacle but these objective schema/contract mismatches remain; no identity may be fabricated.

## Verified tests, boundaries
Windows CI `Maintenance V4 Operator ZIP - No Publisher Key`: tested real Core `VerifyOnly` and `VerifyAndStage`, unsigned missing signature, no key directory, negative wrong content address, wrong installed baseline, corrupted artifact, path outside Inbox, Stage idempotency. Existing M9/G8/V3 gates also passed on the first PR #41 commit. The source-only validation script was changed not to demand signing metadata.

**Not yet completed**: no signed/unsigned production Windows Services installer release has been installed to the actual host, and no live panel-to-Supervisor Activate/rollback was performed. Do not call source-level CI an operational roll-out. The new Supervisor code must be distributed in a normal universal Windows Services update, once, to each installation for the new behavior. Do not reactivate closed publisher/key PRs #38/#39/#40.

## Exact scope
This change addresses the current normal **Local-only** panel workflow. It does not rewrite unrelated Public host deployments, Windows Services installer integrity, authentication secrets for the Web-adapter, or paid third-party code signing. It avoids replacing nonpackage/invalid archives with privileged install commands.
