# M2 — Local Core Audit / Bootstrap, Data Ownership, Auth, Observability

Status: IN PROGRESS
Historical source: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
V3 target: `apps/local-web`

این سند نقطه ادامه M2 است و باید قبل از جابه‌جایی implementation هسته Local خوانده شود. هدف M2 کپی‌کردن bootstrap قدیمی نیست؛ هدف استخراج یک Local Core کم‌وابستگی با حفظ semantics اثبات‌شده است.

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

Decision for V3:
- preserve config/session/DB semantics required by business code;
- do NOT migrate the historical eager require-list as the Local Core owner;
- core bootstrap must load only shared/core dependencies; business domains move in later M5 slices;
- Local core must not require Public, Runtime or Print Agent to initialize ordinary tests.

### Data ownership
Historical modular-monolith registry confirms platform ownership of:
- `audit_log`
- `schema_migrations`
- `settings`
- `users`
- `user_capabilities`

`user_preparation_areas` remains an Orders/Preparation-domain table, not a new generic auth store. Auth/capability evaluation may read it through the canonical preparation permission owner; M2 must not invent a duplicate permission schema.

Decision for V3:
- Local DB remains the primary business data authority;
- schema migrations use Expand -> Migrate -> Contract where compatibility matters;
- no component other than Local writes Local business tables directly;
- Runtime/Print/Public integration occurs through explicit contracts, never direct DB ownership.

### Authentication / authorization
Historical canonical auth owner is `includes/auth.php` plus capability helpers from the shared platform layer.

Semantics to preserve unless a later explicit security ADR changes them:
- server-side PHP session with strict cookie mode;
- 12h shift lifetime/rolling activity refresh;
- authenticated user is refreshed from `users` and disabled accounts are rejected;
- `users` + `user_capabilities` remain the primary permission authority;
- preparation-area scope remains the existing authority; no second permission model;
- admin role alone must not silently bypass Preparation semantics established by the frozen architecture;
- local-only redirect validation and role/capability guards remain required;
- login throttling must not persist plaintext credentials/usernames.

Historical transient-DB behavior: an already authenticated session may remain usable when the user refresh query throws. This is compatibility-sensitive and MUST NOT be silently changed during structural migration; hardening, if desired, needs explicit acceptance/tests.

### Observability
Historical owner `includes/observability.php` is intentionally DB-independent.

Semantics to retain:
- request correlation ID validation/generation and `X-Sokna-Correlation-ID` response propagation;
- structured JSONL logging;
- recursive sensitive-context redaction;
- bounded string/depth logging;
- atomic JSON state writes;
- observability remains available when MariaDB is unavailable.

V3 ownership correction:
- Local may consume a configured data root (`SOKNA_DATA_DIR` / app config);
- Windows path discovery/provisioning/ACL ownership must stay outside Local Core;
- the historical direct `%PROGRAMDATA%` fallback is not a Local ownership requirement. Setup/Runtime/Platform supplies the Local data root on Windows.

## 2. Historical coupling that must NOT be copied into M2

1. `bootstrap.php` eagerly requires Menu, Printing, Inventory, Supply, Expenses, Deferred, Settlement, Center, Accommodation and other domains. M2 must not make these dependencies of core initialization.
2. `includes/functions.php` is a broad aggregator containing helpers plus domain-specific after-response hooks. Do not move it wholesale as the V3 platform owner.
3. Local Core must not own Registry, SCM, Winspool, driver lifecycle, elevated PowerShell or machine ACL provisioning.
4. Local Core must not embed Public transport or Print Agent implementation.
5. Request bootstrap should not become the owner of immutable-package/server provisioning. Runtime filesystem/server protection needed for production packaging is a Platform/Setup concern unless it is application-private runtime storage behavior.

## 3. Proposed V3 Local Core boundaries

The first implementation step should establish small owners under `apps/local-web`:

- `src/Core/Config.php` — validated app/local configuration access only.
- `src/Core/Database.php` — PDO construction/connection policy; no business queries.
- `src/Core/Observability.php` — DB-independent correlation/log/redaction primitives.
- `src/Core/Session.php` — Local web session policy.
- `src/Core/Auth.php` — session identity refresh/login/logout/guards using existing authorities.
- `src/Core/Capabilities.php` — capability evaluation adapter over canonical Local data/Preparation owner, not a new permission system.
- `bootstrap.php` — composition root for the above; no Windows/Print/Public ownership.

Exact file names may change only if ownership remains equivalent and this document is updated in the same change.

## 4. M2 implementation order

1. Create Local Core directory/composition root without business-domain eager loads.
2. Port DB-independent observability first and test it without MariaDB.
3. Add config + Database owner and a test bootstrap that can use injected/test configuration.
4. Add session/auth/capability owner preserving legacy behavior.
5. Add schema/migration bootstrap for M2-owned tables only.
6. Add tests proving Local Core initialization does not require Public/Runtime/Print.
7. Add forbidden-dependency gate for Windows-specific ownership inside Local Core.
8. Only then mark M2 migrated and proceed to M3/M5 consumers.

## 5. M2 exit evidence required

M2 is not complete until all are true:
- Local Core starts under CLI/integration tests with Public/Runtime/Print absent;
- observability tests pass without DB availability;
- database/config tests prove deterministic injection/configuration;
- auth/capability regression tests cover active/disabled user and capability scope;
- no second permission schema exists;
- no direct Windows Registry/SCM/Winspool/PowerShell ownership exists in Local Core;
- migration/status docs point to concrete test/CI evidence.

## 6. Current continuation point

Historical audit for bootstrap, platform table ownership, auth and observability is complete enough to begin implementation. Next action: create the minimal `apps/local-web` Core implementation + executable M2 regression tests, then wire the Local component CI gate to those tests.
