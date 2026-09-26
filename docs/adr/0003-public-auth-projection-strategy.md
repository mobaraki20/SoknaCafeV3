# ADR 0003 — Public Auth Projection Strategy

Status: **ACCEPTED**

Date: 2026-09-26

Applies to: M3 — Public Edge Persistence, Auth Projection and Relay Transport

## Context

The historical dev.39 baseline (`mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`) supports remote/Public login without requiring a synchronous Local round-trip for every login.

Verified historical behavior:

- Local projects active user identity data to Public.
- The projection contains `projection_id`, `username`, display/role metadata, `password_hash`, projected capabilities and preparation-area scopes.
- `public_edge/api/v1/local/projection_sync.php` accepts this projection only through the signed Local request boundary.
- `public_edge/api/v1/auth/login.php` verifies the supplied password against the projected `password_hash` with `password_verify`.
- Public creates an opaque random session token and stores only its SHA-256 hash.
- Public session reads join back to the active auth projection and active installation state, so disabling/deactivating a projection invalidates subsequent session use.

The V3 architecture requires:

- `apps/local-web` remains the sole primary Business Authority and canonical identity/permission authority.
- `apps/public` owns only Public Edge persistence and safe projections; it must never become a full Local business database clone.
- successful Public login behavior should not be broken merely because component ownership is being corrected.

External observer finding EOR-07 correctly identified that the credential strategy had not yet been made explicit for V3.

## Decision

### 1. Local remains identity and credential authority

The canonical user record, password lifecycle and permission assignment remain Local-owned.

Public MUST NOT:

- create or change a user's canonical password;
- author canonical capabilities or preparation-area grants;
- become the source of truth for user enable/disable state;
- write Local business or identity tables directly.

### 2. V3 M3 preserves the proven remote-login projection behavior

For the M3 compatibility generation, Public MAY persist the current Local `password_hash` as part of the **minimal auth projection** required to preserve remote login semantics.

This is a compatibility projection, not credential ownership.

The projected record is limited to the fields needed for Public authentication/session shaping and permission filtering:

- installation binding;
- projection/user identifier;
- username;
- display name and role snapshot;
- current password verifier (`password_hash` for this contract generation);
- projected Public capabilities;
- projected preparation-area scopes;
- projection version;
- active state.

No unrelated Local user/business columns are copied.

### 3. Projection sync is Local-signed, installation-bound and replay-protected

Auth projection mutation on Public is accepted only through the Local/Public signed request contract:

- installation-bound HMAC verification;
- durable nonce replay protection;
- reconciled dev.39 error taxonomy;
- no browser/Public-session endpoint may submit or mutate auth projections.

A sync marks previously projected users inactive before/while applying the authoritative Local projection set, preserving the historical deactivation model.

### 4. Public login verifies locally against the projection

Public login verifies the supplied password using the projected verifier and, on success, creates a Public-owned session.

For this contract generation:

- successful-login semantics remain compatible with dev.39;
- invalid credentials do not reveal whether installation, username or password was the failing field beyond the documented API taxonomy;
- plaintext passwords and password hashes MUST NOT be written to logs, diagnostics, audit payloads or relay envelopes.

### 5. Public sessions are Public-owned but authority-sensitive

Public session tokens are random opaque values. Only a one-way token hash is persisted.

Every authenticated Public request must continue to require:

- an unexpired Public session;
- an active auth projection;
- an active installation;
- remote access enabled for that installation.

Disabling/removing a projection therefore revokes effective access without making Public the user authority.

Password changes update the verifier on the next authoritative Local projection sync. Existing already-issued sessions retain the existing session-lifetime semantics unless the user/projection is disabled or another explicit session-revocation decision is introduced.

### 6. Capability projection is an edge filter, not final mutation authority

Public may reject requests that the projected session clearly lacks permission to attempt.

Local MUST still revalidate the canonical active user/capability/scope before committing any business mutation. Public capability checks are an edge filter and user-experience/security optimization, never the final business authorization owner.

### 7. Abuse controls belong to Public without changing normal successful-login semantics

M3 must add bounded Public-owned failed-login throttling and security audit metadata around the login boundary.

Requirements:

- throttle repeated failed attempts by installation/account and available network-origin metadata;
- do not store attempted plaintext passwords or password hashes in throttle/audit records;
- successful ordinary login behavior remains unchanged;
- throttling responses and expiry policy must be executable/tested before M3 exit;
- authentication audit records contain only non-secret operational/security metadata and correlation identity.

These controls are Public Edge security responsibilities and do not transfer identity authority from Local.

## Alternatives considered

### A. Remove `password_hash` from Public immediately and require synchronous Local verification

Rejected for M3 because it would change proven remote-login availability/behavior and couple Public login to current Local connectivity.

### B. Make Public a full independent user/permission authority

Rejected because it violates V3 ownership: Local is the canonical Business and identity/permission authority.

### C. Keep the entire legacy Public user/business model unchanged

Rejected because V3 permits only the minimum projection required by the Public Edge contract; unrelated Local data must not be cloned.

## Compatibility and migration impact

- Existing dev.39 password hashes can be projected without plaintext-password migration.
- Current successful remote login semantics are preserved.
- M3 Public schema must treat the projected verifier as sensitive data and keep it isolated to the auth projection owner.
- Future replacement of `password_hash` projection with another verifier/credential mechanism requires a new contract generation/ADR and compatibility path; it must not silently break remote login.

## Required tests before M3 exit

1. signed Local projection sync accepts the reconciled HMAC contract and rejects unsigned/replayed requests;
2. active projected user can login with the correct password;
3. wrong password returns the documented invalid-credential response without secret leakage;
4. disabled/removed projection cannot login and invalidates subsequent session access;
5. password verifier update via projection sync changes future login verification;
6. Public session token is persisted only as a hash;
7. Public capability/scope filtering never substitutes for Local canonical revalidation of a business mutation;
8. failed-login throttling is bounded, expires predictably and records no password/verifier material;
9. logs/audits contain correlation/security metadata but no plaintext password, verifier or bearer token.

## Rollback

Because Local remains the canonical authority, rollback of an M3 Public deployment may restore the previous compatible projection/login implementation without changing Local user records. Public schema changes for this ADR must therefore be additive/replay-safe until the M3 compatibility window is explicitly closed.

## Observer disposition

EOR-07: **PRESERVED** for user-visible remote-login semantics, with explicit V3 ownership boundaries and Public-owned abuse-control hardening defined by this ADR.
