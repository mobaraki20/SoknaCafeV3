# BK-2b/BK-3 — cross-layer handoff for Local/Public owners (26 Oct 2026)

Status: Windows SOURCE CANDIDATE ONLY; no production release, no live UAT. This document DOES NOT authorize edits to Local/Public by the Windows owner.

Windows PR: https://github.com/mobaraki20/SoknaCafeV3/pull/49
Branch: `work/bk2b-bk3-maintenance-v4-20261026`
Base: existing Maintenance 0.3.16 commit `84586d8b3d5dc51977eea8291979cfc43e7409d6`.
Windows owner changes existing `SoknaMaintenanceSupervisor` only; Runtime and Print Agent remain unchanged.

## 1. Local owner — required change (NOT owned by this PR)

The source snapshot `SOKNA_V3_BATCH12_CUMULATIVE_RESTORE_WAITER_SOURCE_ONLY_NOT_INSTALLABLE_20261026.zip` contains `working/local/tools/maintenance-backup-job.php` with **v1 single-step** CLI behavior. It is NOT compatible with Windows v2 two-phase durable protocol. Local owner must adapt that existing CLI, not add another daemon or service.

- Input: one bounded JSON object via stdin ONLY; never CLI argv, environment, URL or logs. Fields: `mode` = `prepare|reconcile|upload`; `backup_id,sha256,size` for reconcile/upload; `recovery_passphrase,vault_bearer,vault_endpoint,source_installation_id,source_release,protection_key_id`.
- `prepare`: produce consistent encrypted Local artifact with existing `RecoveryWorkspaceService`, locally verify manifest/digest/size/installation; return immutable identity but **no Public call**.
- Windows persists `PREPARED` to its durable `schedule-state.json` before any remote call.
- `reconcile`: ask Public about **same** `backup_id`, validate receipt, digest, size, installation, and tenant. Return `REMOTE_VERIFIED`, `REMOTE_UPLOADING`, or independently confirmed `REMOTE_NOT_FOUND`; ambiguity must be a failure/UNKNOWN, never proof of absence.
- `upload`: resume/commit **same artifact ID only** via existing `RemoteVaultUploadClient`, comparing Public receipt and immutable source metadata. No fresh snapshot, no new backup ID.
- Exact response JSON (stdout, max 8 KiB) format `sokna-maintenance-backup-job-result-v2`; fields: `format,state,code,backup_id,sha256,size,source_installation_id,remote_receipt_id` (receipt only after remote verification). On failure: symbolic error code, no path/secret/system exception. Exit nonzero.
- Carry a valid `vault.upload` bearer with bounded expiry. The requested `source_installation_id` must match installed source; never rely on supplied IDs alone.
- The existing Local admin backup tab must call HMAC loopback endpoints `GET /v1/backups`, `POST /v1/backups/configure`, `POST /v1/backups/renew-upload-credential`, `POST /v1/backups/disable`. Only authenticated administrator may configure/renew, with CSRF protection and explicit owner-held passphrase/copy acknowledgement. No token/secret in browser storage, logs or command line. UI shows actual status and pending UNKNOWN, never false success.
- Owner-held passphrase MUST work on a replacement machine. Windows DPAPI LocalMachine is only a cached operational copy; **not** the sole recovery copy.
- For BK-3 reuse existing `maintenance-recovery-fetch.php`, `RemoteVaultDownloadClient`, `RecoveryWorkspaceService`, setup/recovery flows. Decrypt and restore only after exact ciphertext hash+receipt check, explicit confirmation, empty isolated MariaDB and preflight.

## 2. Public owner — required change (NOT owned by this PR)

Source snapshot already has `maintenance.php`: `vault-upload-begin/chunk/status/finalize`, `vault-inventory`, `vault-download-begin/status/chunk`, and admin `vault-credential-provision/rotate/revoke/list`. In `VaultCredentialStore::ttlForScopes()`, upload max TTL=15,552,000 s (180 days), recovery_read max TTL=3,600 s (1 hour).

- Verify that upload-status and inventory can give authenticated **proof** of immutable backup ID, digest, size, installation/tenant and verified receipt, including after lost finalize response. Never treat network timeout as NOT_FOUND.
- Enforce idempotency and identity matching server-side: same `backup_id` cannot refer to different bytes/tenant. Reconciliation must not double-finalize, double-count retention, or delete last known good.
- Implement a bounded and auditable `vault.upload` renewal/rotation contract for long-term automatic backup. It must be limited to original installation and upload scope, NOT an indefinite admin session or general super-token. Coordinate exact API with Local owner; Windows already exposes HMAC-only `renew-upload-credential` for accepting the newly issued scoped bearer. If no secure lifecycle can be agreed, status stays BLOCKED at expiry and owner must be told it is not fully automatic.
- Recovery read token must only be created on demand after authenticated owner action; short-lived and only `vault.recovery_read`. Do not give upload/read crossover privileges.
- Run Public fault tests: already committed then timeout, incomplete chunk, verified 404, malicious same ID changed digest, cross-tenant, quota, retention, expiration/rotation, corrupt ciphertext.
- Vault R1 lives outside the cafe PC on the Public host; it is NOT independent-host R2.

## 3. Windows owner — existing controls and explicit remaining gates

PR #49 uses the same `SoknaMaintenanceSupervisor`, shared `MachineMutationLease`, durable PREPARED/UNKNOWN state and last verified receipt, DPAPI for operational cached secret, stdin PHP child protocol, no extra Windows service. `/v1/backups` reads actual state but intentionally returns `release_qualified=false` and not-ready while end-to-end tests remain open.

No installation on Home/Cafe/Public live. No Maintenance-only installer/prerelease until Windows qualified tests AND Local/Public interface tests AND empty MariaDB restore lab are all PASS. Runtime/Print do not change/restart for Maintenance-only.

Acceptance matrix: prepare crash before remote (recover after bounded delay); upload crash before/after finalize (same immutable artifact only); remote unavailable; duplicate upload; concurrent updater lock; DPAPI ACL and secret redaction; expired/rotated credentials; wrong installation/hash/tenant; recovery read expiry; verified download and empty MariaDB restore with schema+row comparison; recovery denial on non-empty DB. Support Bundle must include backup state and fault code, without secrets. Live UAT is one consolidated later step and remains NOT_RUN.
