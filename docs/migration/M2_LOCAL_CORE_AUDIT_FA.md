# M2 — Local Core Audit / Bootstrap, Data Ownership, Auth, Observability

Status: COMPLETE at the M2 Local Core slice level
Historical source: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
V3 target: `apps/local-web`

این سند مرجع نهایی M2 و نقطه ادامه برای فاز بعد است. هدف M2 کپی‌کردن bootstrap قدیمی نبود؛ هدف استخراج یک Local Core کم‌وابستگی با حفظ semantics اثبات‌شده بود.

## 1. Historical owners confirmed

### Bootstrap / configuration / DB
Canonical historical entry is `bootstrap.php`.

Observed responsibilities:
- request-path + observability bootstrap;
- HTTP session lifecycle and 12h shift session;
- config existence/install redirect;
- app timezone/debug setup;
- eager loading of many business domains;
- maintenance guard;
- global `db()` PDO connection factory.

V3 decision:
- config/session/DB semantics required by business code are preserved;
- historical eager require-list is not migrated as the Local Core owner;
- core bootstrap loads only shared/core dependencies; business domains move in later M5 slices;
- Local Core initializes/tests without Public, Runtime or Print Agent being required;
- DB/auth/migrations remain lazy until explicitly consumed.

### Data ownership
Historical modular-monolith registry confirms platform ownership of:
- `audit_log`
- `schema_migrations`
- `settings`
- `users`
- `user_capabilities`

`user_preparation_areas` remains an Orders/Preparation-domain table, not a new generic auth store. Auth/capability evaluation may read it through the canonical authority, but M2 does not claim its schema ownership.

V3 decision:
- Local DB remains the primary business data authority;
- schema migrations use Expand -> Migrate -> Contract where compatibility matters;
- no component other than Local writes Local business tables directly;
- Runtime/Print/Public integration occurs through explicit contracts, never direct DB ownership.

### Authentication / authorization
Historical canonical auth owner is `includes/auth.php` plus capability helpers from the shared platform layer.

Preserved semantics:
- server-side PHP session with strict cookie mode;
- 12h shift lifetime/rolling activity refresh;
- authenticated user is refreshed from `users` and disabled accounts are rejected;
- `users` + `user_capabilities` remain the primary permission authority;
- preparation-area scope remains the existing authority; no second permission model;
- local-only identity/session behavior remains independent from Public/Runtime/Print;
- password hashes are not copied into session identity.

Compatibility-sensitive historical behavior retained and regression-tested: an already authenticated session may remain usable when the identity refresh query throws due to a transient DB failure. Any future hardening of that behavior requires an explicit security decision and new acceptance tests.

Preparation note: generic admin capability compatibility remains preserved, but Preparation operational mutation rules are still owned by the Preparation domain. Admin role alone must not become Preparation mutation authority when that later slice moves.

### Observability
Historical owner `includes/observability.php` is intentionally DB-independent.

Retained semantics:
- request correlation ID validation/generation;
- stable per-request correlation identity;
- structured JSONL logging;
- recursive sensitive-context redaction;
- bounded string/depth logging;
- atomic JSON state writes;
- observability remains usable when MariaDB is unavailable.

V3 ownership correction:
- Local consumes a configured data root (`SOKNA_DATA_DIR` / app config);
- Windows path discovery/provisioning/ACL ownership stays outside Local Core;
- historical direct `%PROGRAMDATA%` discovery is not a Local ownership requirement. Setup/Platform supplies the Local data root on Windows.

## 2. Historical coupling deliberately NOT copied into M2

1. The legacy `bootstrap.php` eager load of Menu, Printing, Inventory, Supply, Expenses, Deferred, Settlement, Center, Accommodation and other domains.
2. The broad `includes/functions.php` aggregator as a new V3 platform owner.
3. Registry, SCM, Winspool, driver lifecycle, elevated PowerShell or machine ACL provisioning inside Local Core.
4. Public transport or Print Agent implementation inside Local Core.
5. Immutable-package/server provisioning ownership inside request bootstrap.
6. A second permission database/schema alongside the proven Local authorities.

## 3. Implemented V3 Local Core owners

Under `apps/local-web`:

- `src/Core/Config.php` — validated app/local configuration access only.
- `src/Core/Database.php` — PDO construction/connection policy; no business queries.
- `src/Core/Observability.php` — DB-independent correlation/log/redaction/state primitives.
- `src/Core/Session.php` — Local web session policy and private Local session storage.
- `src/Core/IdentityRepository.php` — identity/capability/preparation-area read contract.
- `src/Core/PdoIdentityRepository.php` — canonical PDO adapter over existing Local authorities.
- `src/Core/Auth.php` — session identity refresh/login/logout preserving dev39 semantics.
- `src/Core/Capabilities.php` — capability evaluation over existing Local authorities; no new permission model.
- `src/Core/Migrations.php` — ordered/replay-safe Local migration runner with MySQL advisory locking and the historical `schema_migrations` ledger shape.
- `src/Core/Bootstrap.php` + `bootstrap.php` — composition root; DB/identity/auth/migrations remain lazy and no Windows/Print/Public implementation is loaded.
- `database/migrations/0001_m2_platform_core.sql` — M2-owned baseline tables only: `settings`, `users`, `user_capabilities`, `audit_log`; `schema_migrations` is bootstrapped by the migration owner.

## 4. Migration safety model

MySQL/MariaDB DDL may implicitly commit. M2 therefore does not pretend that a multi-statement schema migration is transactionally atomic.

Rules:
- migration files must be replay-safe/idempotent;
- advisory lock `sokna_v3_local_migrations` serializes execution;
- a ledger marker is written only after all statements in the migration file succeed;
- a second execution must be a no-op;
- `user_preparation_areas` is explicitly excluded from M2 schema ownership;
- future compatibility migrations should follow Expand -> Migrate -> Contract.

## 5. Executable evidence

CI wiring:
- `.github/workflows/v3-component-gates.yml` Local job runs `python3 tests/local-core-contract.py`;
- the Local job starts a real MariaDB 11.4 service and runs `php tests/local-mysql-migration-selftest.php`.

Tests:
- `tests/local-core-selftest.php` — config, explicit Local data root, stable correlation ID, redaction, atomic state and JSONL logging without opening a DB connection.
- `tests/local-auth-selftest.php` — active-user refresh, disabled-user rejection, 12h expiry, transient-DB compatibility, password login, unknown-capability rejection and kitchen/bar scope filtering using injected identity authority.
- `tests/local-migrations-selftest.php` — SQL parser behavior, quoted/comment semicolon handling, malformed SQL rejection and schema ownership boundaries.
- `tests/local-mysql-migration-selftest.php` — real MariaDB migration, second-run idempotency, ledger marker, owned table presence, `user_preparation_areas` absence, real PDO identity/capability reads and audit snapshot-column compatibility.
- `tests/local-core-contract.py` — PHP lint, all no-DB self-tests, required-file/schema checks and forbidden Windows ownership checks.

## 6. Verified checkpoints

Earlier implementation checkpoints:
- `00bd62a8706eca80b9aca73b2be51ab6b29bae12` / workflow `36211338368`: initial executable Local Core gate SUCCESS.
- `f9e73348da06546bc03156535f4811770161ff00` / workflow `36211638201`: Auth-expanded Local Core workflow SUCCESS.

**M2 close checkpoint:**
- head: `74575fb124e4a58836b59fb0a9c59285e85d9688`
- workflow: `36212016322`
- overall conclusion: SUCCESS
- Local Web gate job: `108320387116` — SUCCESS
- `Validate M2 Local Core ownership and executable bootstrap` — SUCCESS
- `Validate M2 migrations against real MariaDB` — SUCCESS
- Foundation, Contracts, Local, Public, Runtime, Print Agent, Platform, Packaging, Migration and SCDS gates on that workflow completed successfully.

## 7. M2 exit gate

SATISFIED for the Local Core slice:
- Local Core initializes/tests without Public/Runtime/Print;
- observability works without DB availability;
- DB/config behavior is explicit and tested against real MariaDB where DB is required;
- auth/capability regressions cover active/disabled users, expiry, login, transient DB compatibility and scope filtering;
- M2-owned schema/migration bootstrap is implemented and tested on MariaDB;
- no second permission schema exists;
- no Windows Registry/SCM/Winspool/PowerShell ownership exists in Local Core;
- evidence and continuation state are in-repo.

M2 completion does **not** mean Local business domains are migrated. Orders, Preparation, Inventory, Finance and other business owners remain later slices.

## 8. Continuation point

Do **not** restart M2.

Next ordered slice is **M3 — Public Edge Persistence, Auth Projection and Relay Transport**.

Before moving implementation into M3:
1. update migration inventory/matrix rows that now have M2 implementation evidence to at least `in_progress` where their total scope extends beyond M2;
2. audit the exact dev39 Public database/migration owners, binding/auth material, relay queue persistence and minimal auth projection at baseline SHA `a46435cca57df5bd5b9770efd0bb95390528aa05`;
3. preserve strict Realtime vs Deferred-safe persistence/state-machine separation;
4. bind migrated Public transport to the executable M1 contracts rather than re-inventing route/auth semantics;
5. keep Local as final Business Authority and never create a Public full-business DB clone.
