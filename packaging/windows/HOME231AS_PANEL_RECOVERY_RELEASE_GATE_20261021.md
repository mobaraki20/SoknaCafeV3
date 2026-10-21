# Home Local 231as — Maintenance panel-update gates (2026-10-21)

Scope: **exact Home Local 231as**, existing Windows Services 1.1.19 / Maintenance 0.3.9, Public Home 35ek. This branch is based on the isolated Maintenance 0.3.10 UAT installer PR #33. No Runtime, Print, Public, DB or Staff-order code is changed here.

## Issue 1: LOCAL_BASELINE_NOT_ALLOWLISTED

- Windows 1.1.19 bundled queue contract was pinned to 231ai. PR #32 introduced **exact** 231as source identity: `3.0.0-g6.2-workspace.231as-connectivity-relay-ack-home-uat`, 509 managed files, SHA256 managed tree `d67561b5cc72d0e8003c2497c2af26dcdb3f7c5a893aa4eccaa462924c49f77f`.
- This branch updates the independently shipped Maintenance **Home lifecycle exact tuples** (231ai kept, 231as added); it does not accept wildcard versions. It also corrects the queue script's invalid file-path regular expression (previously empty alternation could reject valid nested paths). The queue script remains a **full Windows Setup Shell** file; the Maintenance-only EXE does not overwrite or pretend to have upgraded the existing manager.
- New signed Local-only release **MUST** contain the exact live 231as `supported_baselines[].local` (managed tree, schema fingerprint, migration head, authority epoch). The pre-existing `SupervisorSignedLocalIdentityRebind` verifies the next release's genuine publisher P-256 signature and anti-rollback before binding a changed installed identity. No Local signing keys are created, and mismatched installs remain blocked.
- If the current installed tree differs from 509/`d675...`, do not repair identity to force a match; diagnose actual installed files and request a valid signed supported baseline from the Local release producer.

## Issue 2: stable_recovery

- `apps/local-web/public/local-recovery.php` is an unmanaged, **machine-owned overlay** deliberately excluded from normal Local managed-file ZIPs, and was absent in Home diagnostics.
- Maintenance 0.3.10 embeds the version-controlled PHP asset as a read-only assembly resource. Supervisor start tries a **strict 231as-only R8 repair**: inspect the Apache-owned active junction, confirm its real 509 managed files and expected tree hash, require authentic persistent/slot installation bindings, and then create missing `LocalWeb/persistent/public/local-recovery.php` and active `Web/public/local-recovery.php` from the embedded asset. Only missing files can be created; unknown/modified files or reparse destinations fail closed. Both copies are re-hashed after writing; partial creations are cleaned; the managed Local tree is reattested unchanged. Result or blocker is journalled.
- The PHP asset's config fallback supports sibling-release layout. Its legacy physical-tree POST recovery is explicitly refused on R8, which must use native Windows Recovery Console. A read-only GET remains independent of Local PHP bootstrap.
- Future inactive slot staging fails closed with `INACTIVE_SLOT_STABLE_RECOVERY_MISSING` if either recovery copy is absent. The normal Local diagnostics `stable_recovery` must be read back live; checking only its source file is insufficient.

## End-to-end acceptance — not yet complete

1. Complete PR #33 standalone **actual final EXE** Windows Server 2022 test including nonzero failed preflight, service stopped/started and exact installed version. It must ship the updated code in this branch (or a rebased successor), not stale PR #33 artifact. Keep Runtime/Print/Recovery Console Manager binary untouched.
2. Run the UAT EXE on Home Windows 10 with a previously installed 0.3.9, only after CI passed. Readback signed Health 0.3.10, exact component receipt, Local diagnostic `stable_recovery`, persistent and active overlay hashes, no identity change and no deleted prior machine files.
3. Obtain next publisher-signed install-qualified **Local-only** Release Set with exact Home 231as supported baseline (and legitimate newer release identity), including signed manifest and production qualification. Verify file-set hashes and live Home R8 state.
4. Upload signed Local-only archive through actual Home web panel, Stage, Activate; verify new version via HTTP `X-Sokna-Build`, live managed hash, installed identity, Recovery Console, rollback drill and support ZIP. A ready button or source CI alone does **not** satisfy this.
5. Verify Public / Staff P1 flow separately. Do not conflate Runtime trigger timeouts with panel update success. No Windows Services full release is needed unless real host evidence independently proves it.

**Deployment status:** source changes and automated tests only; do not declare a successful Home panel install until step 4 is done.
