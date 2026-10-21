# Sokna Maintenance V4 — universal Local update contract

Status: **source design + isolated-host regression, NOT installed-host UAT**. This contract applies equally to HOME, CAFE and any future single-site installation. The words Home/Cafe name *test fixtures*, never release variants.

## One publisher, many installations

- The **publisher**, not any Cafe or Home computer, holds the release signing key in a protected signing environment. One signed `local_only` Release Set ZIP is distributed unchanged to every qualifying installation. It must never be signed with a per-machine nonexportable Cafe UAT key.
- Each independently installed Windows Services / Maintenance has **the same trusted public publisher key(s)** supplied and pinned by its authenticated first-party Windows Services installation or properly authenticated foundation upgrade. **Never** import a public key from the ZIP the operator selects, never use automatic trust-on-first-use, and never ask the user to copy/install a key. A real publisher public trust bundle is not yet available in this branch; therefore production enablement is **blocked**, not silently substituted with an ephemeral fixture.
- Each machine generates/retains its own `installation_id`, machine binding, secret stores, local path, domain, port, LAN IP, database state and installation sequence. They stay strictly **local**, are discovered at runtime, and are never fields used to produce a per-device ZIP.
- The signed Release Set declares a **finite list of exact compatible source baselines** (`release_id`, `version`, managed-tree SHA256, schema fingerprint, migration head, authority epoch policy). The same package can contain several **independently qualified** baselines where necessary; no wildcard or guessed baseline is allowed.
- On each machine, signed Preflight checks the **actual machine state**. If an external code-only install caused installed identity drift, the existing signed `SupervisorSignedLocalIdentityRebind` reconciles it **only** against the machine's real filesystem/tree and an exact publisher-approved baseline. That does not copy another machine's `installation_id` or trust/key.
- Existing Maintenance Stage/Activate/backup/rollback/readback remains the only update engine. No manual EXE/PowerShell/key/identity ceremony for routine Local updates. Windows Services independently updated only when a foundation capability change actually requires it.

## Host matrix and fail-closed behavior

| Case | Expected |
|---|---|
| Two independent hosts with same qualified build/tree/schema/trust | The **same bytes** of one ZIP verify and can proceed to per-host Stage |
| Two hosts with different release IDs but both on the signed allowlist | The **same ZIP** verifies on both |
| Unsupported/modified managed tree, schema, migration head or authority policy | Reject before Stage; no guessed/rebound identity |
| Untrusted/missing publisher public key | Reject; cannot adopt trust from ZIP |
| Duplicate/ambiguous baseline in publisher input | Publisher rejects |
| Per-host failed backup, operations conflict, offline HTTP, unsafe slot or DB inconsistency | That host's transaction fails closed; other machines are unaffected |
| Different Windows version/platform missing a mandatory Maintenance capability | Foundation upgrade must be handled via its separately signed, universal Windows Services lifecycle, not a custom Cafe ZIP |

## Actual acceptance gate

A successful Windows CI synthetic harness is **not** an install on a customer host. To claim DONE:
1. Authenticated Windows Services installer lays down the genuine publisher public trust (or enforces its approved fingerprint) **universally** with no per-machine operator steps. Publisher private key is only in controlled release infrastructure.
2. Publisher CI independently validates release qualification provenance, produces an exact signed ZIP once, and audits the signer/trust identity. No fabricated Windows/MariaDB or backup/rollback result.
3. Host A and Host B using the **same** ZIP can select → Preflight → Stage → Activate → readback and demonstrate recovery/rollback; no local identity-writing script or per-host signing. At least one completed installation must be independently recorded, then compatibility across additional hosts tested.
4. Version/host-specific differences are handled by dynamic per-host preflight, never machine-named branches or hardcoded IP/paths.

### What this branch implements now

The publisher now accepts **multiple distinct exact source baselines** (max 64), still refuses incomplete/duplicate ones. The Windows harness reuses real `ReleaseSetVerifierStager.VerifyOnly` against independent disposable data roots to prove package bytes are portable after **simulated preprovisioned public trust**. It tests wrong baseline and missing public trust failure. The harness does **not** install, create production trust, perform Windows Services UAT, modify business DB, sign a publishable release, or replace publisher qualification gates.
