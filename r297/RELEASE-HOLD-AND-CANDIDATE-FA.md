# SOKNA R297 — Local candidate; QA, no installation

Date: 2026-11-19. Source: exact Local R278 231fn installable archive (SHA256 `3a1070e18b14307fb2571634c961b20ca3e5d38e6c9a9dc1afad68be89fe6824`) + cumulative R296 QA source (607-file tree `9ecbbc9850d3f077d57f0cff4e808975157710781682cfa8be0e0b3bca458246`). GitHub source QA run: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44501927499 (success). That run does **not** qualify the R297 final ZIP.

R296 source release metadata was stale R278: VERSION, provenance, release identity and managed inventory (604 names for 607 files). Three newly added files were absent from inventory: 0083_remaining_account_discount.sql, dashboard-open-accounts.js, iran-calendar-1405.json.

Offline R297 candidate for controlled QA only:
- Version: `3.0.0-g6.2-workspace.231fo-r297-r296-cumulative-qa`
- ZIP filename: `SOKNA-R297-LOCAL-231FO-CUMULATIVE-CANDIDATE-NOT-INSTALL.zip`
- Exact ZIP SHA256: `dc91ddc34d97e0ae500d9f61bd8151c8be531fec11d9f6fcdf3b938ca6f2ceaf`
- Exact managed tree SHA256: `44f62caccfc87d18283556d75db05e29a4aa3cbc7077bbe37f50e716a59d3c1d`
- 607 managed files, 83 migrations; 82 historical SQL migration files preserved exactly; 344 PHP/JS syntax checks pass.
- Release envelope structurally valid v2.1, four exact known historical baselines including R278.
- The package has **for_install=false, production_pass=false** and must not be installed.

The exact candidate and proof are archived in Library at `/Cafe/SOKNA-Workspace/WORKING/20261119-R297-LOCAL-PACKAGING-QA/`.

This is **not** a Unified Release Factory approved artifact. No factory run or native Windows Maintenance prepare/stage/activate/readback/backup/restore test on the final candidate has occurred. Physical 58/80mm printing, Print Agent build, live Local/Public+QR+ACK integration, business E2E, and field acceptance remain open. No production machine or host changed. Follow the standard factory intake; do not bypass its fail-closed requirement.
