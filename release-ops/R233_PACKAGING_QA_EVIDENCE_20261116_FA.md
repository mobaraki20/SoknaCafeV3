# R233 — Release factory QA evidence (2026-11-16)

Scope: GitHub release tooling and temporary QA only. No Local/Public application, Windows Service, Maintenance, PrintAgent, database, updater or host modification.

## Immutable source
- Local R233 full source: 593 files; original ZIP SHA256 783bdde1ecd7bad4e8043d8591b52a2e628b99bc6cd32b2247c4786c5eba7a2d.
- Public R233 full source: 233 files; original ZIP SHA256 8d89b156b3efcd380763d907796a9c6914f9ec2c27f40c1a61d04ffb6dc4ca7b.
- Reproduced byte-exact with GitHub evidence in run 43830191738.
- R233 is a HISTORICAL checkpoint, not automatically the latest approved product source.

## Windows Local package QA
- Exact historical R201 60-baseline Release Set is only a TEMPLATE for source-owned metadata and layout.
- QA-only Local Maintenance v2.1 ZIP contains exact R233 functional source bytes; only VERSION, release identity, build provenance and generated 593-item managed inventory may change.
- Source R233 inventory had 590 entries, while target QA inventory has 593/593.
- Existing check_local_release_set.py accepts static format. Its --require-installable check deliberately rejects the QA candidate.
- Historical 60 origins remain UNPROVEN for this new target; no native Windows Stage/Activate, MariaDB, backup/restore or host acceptance claimed.

## One Public ZIP, both channels
- QA-only Public target: 3.0.0-g3.6-workspace.35zy-r233-qa-do-not-install.
- EXACT single Public Update ZIP SHA256 bf95f25788cd80db3868165a8b7343a3a286ed8232823aa657d7492b2216dda0.
- Public Update has 228 managed files; same-target Public flat-root Clean has 233 files.
- Only VERSION.txt and src/Update/emergency-owner-v1.json target metadata differ from original source. Functional byte changes: ZERO.
- Same exact Public ZIP tested through real PHP Routine and independent Emergency with real code and disposable test roots.
- Four independent successes in GitHub Actions run 43832422762: R171/35HT Routine PASS, R171/35HT Emergency PASS, R153/35HK Routine PASS, R153/35HK Emergency PASS.
- Each route checked target version/tree, Stage/Activate/Commit, owner metadata, preserved emergency.php/config/recovery files and 8 migration files.
- Disposable PHP test does not qualify real HTTPS, MariaDB, Windows or actual cafe hosts.

## Durable source vault
- Workflow: .github/workflows/sokna-source-evidence-vault-r233.yml.
- On main only, SHA-verifies both original source ZIPs and stores them in an authenticated, DRAFT, nonpublic GitHub Release tagged sokna-source-evidence-r233-20261116.
- No public/software release is created, and readback SHA is checked. Vault execution result must be independently verified.
- Source ZIPs are SOURCE-ONLY / NOT INSTALLABLE, not update or clean-install packages. No temporary QA candidate ZIP is uploaded.

## Reusable assets
- release-ops/source_gate.py and release-ops/audit.py
- release-ops/qa_local_maintenance_rehearsal.py
- release-ops/qa_public_update_rehearsal.py, qa_public_root_clean_rehearsal.py, qa_public_two_route.php
- release-ops/qa_reconstruct_public_r153.py
- .github/workflows/sokna-r233-release-qa-rehearsal.yml

## Remaining no-GO items
- Latest *approved* full source lineage has to be pinned per new target; never silently fall back to historical R233.
- Local target needs native Windows Maintenance identity, preflight/stage/activate/readback/backup/recovery for TWO distinct actual origins on the SAME final SHA.
- Public needs other supported origins where claimed and real HTTPS/PHP host, legacy QR and DB-forward tests before production release.
- UI, finances, warehouse and real printing need acceptance appropriate to changes.
- Packaging/static QA is not installation approval; all candidate outputs from this work remain DO-NOT-INSTALL.

## Windows-specific read-only proof
- [Windows QA run 43832689667](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43832689667): Windows Server 2022 runner accepted the same QA-only Local Maintenance v2.1 ZIP shape with the historical verifier and independently checked its SHA256 using the actual Windows PowerShell 5.1 executable and native .NET SHA256.
- Windows services were NOT installed, started, stopped, updated or reconfigured. Native Windows Maintenance lifecycle Stage/Activate, business DB and backups remain NOT_RUN.
