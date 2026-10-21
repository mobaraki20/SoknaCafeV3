# Maintenance V4 — publisher-side automation, no cafe operator key handling

Status: **ENGINEERING WIP / NOT A PUBLISHABLE RELEASE / CAFE INSTALL NOT RUN**.

The operator must continue to select only a finished ZIP from the existing Local panel. No PowerShell, key, certificate, EXE, identity file, or extra updater is part of the operator workflow.

This folder adds a **publisher-side** Release Set v2.1 sealer using the **existing** Maintenance Core canonicalizer and P256 signature format. It does not change Supervisor, Update Engine, installed trust, Local/Public or Windows Services binaries. It **never** generates a signing key, enrolls new trust, sets `for_install` or `production_pass`, or fabricates UAT results. It requires an already-qualified manifest, bound artifact, proof receipts, and a key supplied automatically from a **protected publisher environment** as `SOKNA_V4_PUBLISHER_P256_PEM`. The private key must not be placed in source, artifacts, CI logs, Local panel, GitHub repository files or Cafe Support Bundles.

Security boundaries:

* An authenticated, protected release workflow must independently verify real Windows host, MariaDB, backup/restore, negative matrix receipts and manifest provenance **before** invoking the signer. The receipt fields inside the signer only bind the request; their presence alone is not cryptographic UAT proof.
* The publisher key ID is pinned to the SHA256 of its P256 SPKI public key. The target host must already trust the corresponding issuer public key; do **not** use trust-on-first-use from the selected ZIP.
* `SupervisorSignedLocalIdentityRebind` still makes the machine-local installed identity transition only during signed Preflight against exact real Local version/tree/schema; the publisher never edits that file.
* Stage and Activate still require existing machine proof, operation lease, backup, recovery, anti-rollback and live readback; source/CI tests do not satisfy those requirements.
* `ci-v4-local-publisher-source.yml` uses disposable ephemeral keys/CI-only fake qualification fixtures and **never publishes an artifact**. A production signing workflow must not be enabled until publisher custody, trust distribution and independently verifiable UAT evidence are operational.

This component is deliberately **not enabled for real releases** yet. The existing non-exportable **Cafe UAT** key must not be copied, exported or treated as a production publisher credential. A trustworthy publisher credential and its public trust binding are infrastructure responsibilities, never a recurring Cafe operator step.

Next: on authorized `SOKNARESTAURANT` access, read real installed Windows version, exact signed trusted baseline and DB/schema proofs; perform Windows recovery/rollback UAT and protected issuer provisioning without operator commands; then issue the first 231az→231ba install-qualified ZIP and test **from the panel**. Until then NOT DONE.
