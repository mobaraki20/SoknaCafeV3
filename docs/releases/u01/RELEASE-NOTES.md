SOKNA U01: Windows Services 1.1.23 / Maintenance Supervisor + Update Engine 0.3.12 / Local 231bf.

First install/repair Windows Services 1.1.23 using the normal universal installer. Then upload SOKNA-Local-231bf-U01-Panel-Update.zip in the authenticated Local Update Center. No operator key, certificate, fixed drive, manual extraction or PowerShell is required for the Local update.

This Local ZIP accepts the EXACT published M06 Home closure baseline 231be (520 managed files, 74 migrations), including its installed release identity. An older/different baseline must be upgraded or explicitly qualified separately; a version label alone is never accepted. The payload combines M06, subsequent Theme R2/CSS corrections, T01 Iran time and U01 panel compatibility. Target: 522 managed files, 75 migrations.

Changes:
- Append-only migration proof: all prior migration bytes remain immutable; the target schema fingerprint/head are bound to the verified package.
- Apache stopped before a fresh private MariaDB dump; dump exit/footer/length/SHA256 checked before the durable database forward boundary.
- Pre-boundary recovery restores the previous slot. Post-boundary recovery resumes the exact target migration journal and commits forward. No in-place old-database rollback.
- Truthful database-mutation status and installed schema identity; committed results can be reconciled after an interrupted acknowledgement.
- Same signed panel routes, durable worker, exact baseline checks, one-use delegated Engine permit and idempotency retained.
- Runtime 1.0.7, Print Agent 6.2.10 and Setup UI incorporate the earlier Iran presentation changes. Recovery Console 0.1.3 includes the updated Core dependency.

Release publication is gated on a real disposable Windows SCM/Apache/PHP/MariaDB matrix: 24 completed cycles over C:, D:, paths with spaces and a Persian path, with process termination before/after the database boundary and after commit. The matrix and artifact hashes are attached.

This is a release candidate, not a claim of installation on the user's Cafe or Home computer. Public is a separate deployment owner and is not modified by this Local ZIP. Backups stay private on the Local computer; a manifest requiring remote-verified backup is rejected by this routine path.
