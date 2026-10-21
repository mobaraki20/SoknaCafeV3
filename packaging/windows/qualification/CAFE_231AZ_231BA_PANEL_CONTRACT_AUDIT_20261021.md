# Maintenance V4 Local panel — 231az → 231ba contract check (2026-10-21)

Status: **NOT INSTALL-QUALIFIED / REAL CAFE UAT NOT RUN**. This is a pre-publisher regression gate only, not a Local installer, signer, Windows Services version bump or alternative updater.

## Source and measured results
- Cafe reported Local 231az / Public 35eo and signed Supervisor transport; \`install_ready=false\` with \`LOCAL_INSTALLED_IDENTITY_REBIND_REQUIRED\`. Exact installed Windows Services version **not evidenced**.
- Reference Windows Services tag \`windows-services-v1.1.21-rc.1\` at \`484cf8ba65156180c372998bbfc15499fe9ff141\` contains the trusted \`SupervisorSignedLocalIdentityRebind.AdoptForNextRelease\` invoked by canonical signed Preflight after exact baseline mismatch. It must not be replaced with manually written identity.
- Library 231ba engineering candidate has 510 files, target managed-tree SHA256 \`abe5e9fbdd6e147b7ec11274994682fe0f2b6c223f384ab444485d02a7ef6b6a\`, nested artifact SHA256 \`713c471fa08a2de281c0eebf69779c25cbf24b13ed122c2fb6f9a57e5d9d801e\`.
- Comparing Library 231az target ZIP with 231ba: **only** \`VERSION.txt\`, \`resources/release-identity-v1.json\`, \`resources/build-provenance.json\` differ; 70 migration SQL files byte-identical.
- Found an additional definite package defect: \`targets.local.managed_files\` is absent. Both \`SupervisorInactiveLocalCandidateCoordinator.Prepare\` and \`SupervisorLocalCodeOnlyPreExecution.RequireExactSignedTarget\` require the exact signed count, so the current 231ba preparation would fail before activation even if subsequently signed.
- Also unresolved: the trusted Cafe schema fingerprint, migration head and authority epoch policy; actual nonexportable issuer signature; true install qualification and host backup/recovery evidence. The preparation archive has \`for_install=false\`, \`production_pass=false\` intentionally.

## Pre-publisher contract gate
\`python packaging/windows/qualification/verify-local-release-contract-v21.py <release-set.zip>\`

For old engineering material only, pass \`--preparation\`; a passing **source-contract** result is never host-install qualification. The script verifies the nested Local artifact SHA/size, safe unique managed file paths, real file count, exact target version/tree mapping and no unbound identity placeholders. The existing Supervisor remains final authority for cryptographic signing, sequence/compatibility, machine backup/recovery and activation. The script **cannot grant** those.

## Gates to close before any install-capable ZIP is claimed
1. Obtain authoritative read-only live \`SOKNARESTAURANT\` component inventory including exact Windows Services/Supervisor/Engine installed versions, current Local tree and trusted identity, signed trust ID, DB schema/migration head, operation blockers, backup/recovery state; do not infer from GitHub prerelease.
2. Fill \`targets.local.managed_files=510\` and publisher-attested signed baseline fields **only from authoritative machine evidence**; no manual identity writing. Check that next target identity is self-consistent and signing trust is actually available.
3. Complete real Windows/MariaDB/recovery console/backup consistency and failure matrix qualification, preserving fail-closed claims; only then allow publisher issuance with valid nonexportable signing key, target hash, expiry and monotonic sequence.
4. Use Local panel ZIP selector to execute signed Preflight → Stage → inactive slot → backup → Activate → live readback; exercise safe rollback and next-cycle identity readback. Record real evidence separately from CI.
5. Do not update Windows Services or Public just for a version change; do not merge/ship this PR as an operational release or set \`for_install\`/\`production_pass\` true as a shortcut.

Source-only audit: 10/10 narrow structural positive/negative cases passed off-host. This says **nothing** about real Windows integration or publisher security.
