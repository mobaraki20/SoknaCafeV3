# Public

`apps/public` is the independently deployable Public Edge component.

It may own guest/public surfaces, remote staff gateway surfaces, durable relay state, safe projections/snapshots, Public-owned storage/migrations and limited emergency/recovery tooling for Public itself.

It is never the primary Business Authority and must not become a full clone of Local business data. Canonical business mutations must be revalidated/committed by Local through versioned contracts.

## M3 status

**M3 is complete at the Public Edge persistence/auth/transport boundary.**

Verified M3 checkpoint:

- head `07757f037ece5c71097cafb0f3dcc1d21ae4dcdf`;
- `V3 Component Gates` run `36241108219` — SUCCESS.

M3 Public ownership now includes:

- Public database connection and migration lifecycle;
- installation/binding state;
- minimal auth projection and Public sessions per ADR 0003;
- login throttling and security-audit metadata;
- durable installation-bound request nonce/replay protection;
- signed Local HMAC verification;
- heartbeat/connectivity metadata;
- durable Realtime relay queue and its enqueue/result/claim/ack owner;
- durable Deferred-safe pending/review queue and its enqueue/list/result/claim/ack/reconcile/period-status owner;
- Public health and safe-error boundary with correlation IDs.

Realtime and Deferred persistence/state machines remain separate. Public capability checks are edge filters only; Local remains the final canonical authorization and business-mutation authority.

The Public M3 CI gate executes migration, auth projection, login/session/throttling/audit, HMAC/replay, HTTP adapter, connectivity, Realtime, Deferred and health/safe-error tests against MariaDB.

## M4 boundary

Guest publish revisions, guest active-revision state, guest availability and remote read models remain **M4** scope and are intentionally excluded from the M3 core. Their migration must preserve immutable/atomic publish semantics, degraded read-only behavior and projected scope filtering without turning Public into a business authority or copying rejected legacy UI/design debt.

Canonical M3 evidence: `docs/migration/M3_PUBLIC_EDGE_AUDIT_FA.md`.
Canonical continuation: `docs/migration/CURRENT_CONTINUATION_FA.md`.

## G3 deployable document root

The hosting document root is **`apps/public/public/`**. The rest of the component, including `config.php`, migrations, storage and PHP source, stays outside the document root.

Deployment flow:

1. Upload/extract the complete Public Edge deploy ZIP into a directory outside the public document root, for example `~/sokna-public/`.
2. Copy `config.example.php` to `config.php` and set the MariaDB credentials, the paired `default_installation_id`, storage path and Local pairing secret. Keep `config.php` outside the public document root.
3. Point the domain/subdomain document root to the package's `public/` directory. The included `.htaccess` provides Apache front-controller rewriting.
4. From cPanel Terminal/SSH, run:
   ```bash
   php tools/deploy-bootstrap.php
   ```
   or pass an explicit config path with `--config=/absolute/path/config.php`. The command checks MariaDB, creates/verifies storage, runs all Public-owned migrations and performs a Public health check. It exits non-zero and prints JSON on failure.
5. Verify `GET /health` returns `ok: true`, then continue the Local/Public pairing flow. `GET /menu` becomes useful after the Local installation has published the first guest projection.

The deploy package deliberately does **not** include a generated `config.php`, database password or pairing secret. Those remain host-specific secrets.

`/menu` is Public-owned read projection UI. Guest order/waiter writes always cross the existing realtime relay and remain subject to Local freshness, capability and canonical business revalidation. Guest media is served only by immutable SHA-256 filenames verified again before read.

## G3.3 Emergency / update / takeover

`public/emergency.php` is the stable break-glass entrypoint and does not use the normal Public bootstrap. Normal Public updates use `sokna-component-package-v1` packages and preserve `public/emergency.php`, `src/Emergency/**`, `config.php`, storage, and update trust state. Local Update Center can orchestrate Public verify/stage/activate/repair/rollback through the signed Local/Public owner contract; when Local is unavailable the Emergency Console performs the same Public-owned lifecycle directly.

Provision `relay.secret_encryption_key_base64` with 32 random bytes encoded as base64 before using machine takeover. The Emergency Console can create a one-time A→B enrollment code. Completion atomically activates the fresh installation B, stores its pairing secret encrypted with sodium, and revokes installation A. Old A signatures are rejected after takeover.
