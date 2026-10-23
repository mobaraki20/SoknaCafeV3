# Qualified U01 cumulative release — 2026-10-23

Source: `e8649ecbeb92603a4aae3c27f80b13d3f856848c`.
[Qualification run](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/39928737099).
[Immutable candidate release](https://github.com/mobaraki20/SoknaCafeV3/releases/tag/windows-services-v1.1.23-u01-rc.1).
[One-download Home bundle](https://github.com/mobaraki20/SoknaCafeV3/releases/download/windows-services-v1.1.23-u01-rc.1/SOKNA-HOME-FINAL-U01-20261023.zip).

## Scope and installation
Windows Services 1.1.23; Maintenance Supervisor/Engine 0.3.12. Local target 231bf has 522 managed files and 75 immutable-ledger migrations; head 0074_iran_time_notification_boundaries. Target tree: `80fd968a9e4f1642c3f1519f66d7ab6a2fb1bb64f1a8c0a26444d0d8da1712be`.

Extract the Home bundle. Run SOKNA-Windows-Services-Setup-1.1.23.exe, then SOKNA-Home-231au-to-231bf-UPDATE-UAT.exe. The Home bridge only accepts the exact 231au/71 baseline and moves directly to 231bf/75. The optional standalone Preflight is under tools. The panel ZIP accepts the exact 231be/74 baseline after Maintenance 0.3.12 is installed; it reaches the identical target. Do not install that panel ZIP again after the Home bridge reaches 231bf.

The target preserves authoritative T01 source except the version/provenance manifests and the reviewed DB-aware activation gate. It includes repaired M06, Theme Engine R2 and reviewed CSS, Iran date/number presentation, and the U01 updater. Public is separate and was not deployed. Dev26 Guest/Waiter findings that were audit-only remain outside completed scope.

## Retained proof
The gated release passed the normal five-component installer build, actual keyless ZIP Preflight/Stage, worker regression, Iran presentation checks and 24 real Windows Server 2022 SCM/Apache/PHP/MariaDB cycles. Four roots (C, D, spaces, Persian) each covered normal success and faults at APACHE_STOPPED, DATABASE_BACKUP_VERIFIED, DATABASE_FORWARD_INTENT, POINTER_SWITCHED and COMMITTED. The Go Home tests ran against PHP/MariaDB for 71-to-75, data preservation and replay rejection, and native NTFS identity.

The delivery workflow reuses frozen release binaries, verifies their original hash lists, requires the complete 24-case Cartesian matrix and actual Home tests, and recomputes all 522 managed-file hashes before assembling the bundle. QUALIFICATION-U01.json, original hashes, cycle evidence, Home test JSONL and Persian guide are included.

## Recovery and remaining UAT
Before the database forward boundary, recovery restores the prior code slot. After the durable boundary, recovery completes the exact target forward; never automatically start old code against the changed database. Preserve operation JSON on failure. LOCAL_RUNNING_PANEL_BLOCKED is not full success.

Installation on the user's actual Home computer has not been performed or verified. The release is a qualified UAT candidate, not evidence of a completed Home installation.
