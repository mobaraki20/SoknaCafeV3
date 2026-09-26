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

## 3. V3 Local Core boundaries

Implemented owners under `apps/local-web`:

- `src/Core/Config.php` — validated app/local configuration access only.
- `src/Core/Database.php` — PDO construction/connection policy; no business queries.
- `src/Core/Observability.php` — DB-independent correlation/log/redaction/state primitives.
- `src/Core/Session.php` — Local web session policy and private Local session storage.
- `src/Core/IdentityRepository.php` — identity/capability/preparation-area read contract.
- `src/Core/PdoIdentityRepository.php` — canonical PDO adapter over existing Local authorities.
- `src/Core/Auth.php` — session identity refresh/login/logout preserving dev39 semantics.
- `src/Core/Capabilities.php` — capability evaluation over existing Local authorities; no new permission model.
- `src/Core/Bootstrap.php` + `bootstrap.php` — composition root; DB/identity/auth remain lazy and no Windows/Print/Public implementation is loaded.

Ownership notes:
- `user_preparation_areas` is read through the identity adapter but remains an Orders/Preparation-owned table; M2 does not claim schema ownership for it.
- Admin capability compatibility is preserved at the generic capability layer; Preparation operational mutation restrictions remain a separate canonical Preparation rule and must be preserved when that domain moves.
- Windows `%PROGRAMDATA%` inference is intentionally absent from Local Core. Setup/Platform supplies `SOKNA_DATA_DIR` or `app.data_dir`.

## 4. M2 implementation order

1. [DONE] Create Local Core directory/composition root without business-domain eager loads.
2. [DONE] Port DB-independent observability first and test it without MariaDB.
3. [DONE] Add config + Database owner with lazy DB initialization.
4. [DONE] Add session/auth/capability owner preserving legacy identity semantics.
5. [NEXT] Add schema/migration bootstrap for M2-owned tables only (`schema_migrations`, `settings`, `users`, `user_capabilities`, platform audit owner as applicable).
6. [IN PROGRESS] Expand tests proving Local Core initialization does not require Public/Runtime/Print.
7. [DONE] Add forbidden-dependency gate for Windows-specific ownership inside Local Core.
8. [PENDING] Close M2 only after schema/migration and final Local CI evidence are complete.

## 5. Executable evidence now present

CI wiring:
- `.github/workflows/v3-component-gates.yml` Local job now runs `python3 tests/local-core-contract.py`.

Core tests:
- `tests/local-core-selftest.php` verifies configuration, explicit Local data root, stable correlation IDs, redaction, atomic state and structured logging without opening a DB connection.
- `tests/local-auth-selftest.php` verifies active-user refresh, disabled-user rejection, 12h expiry, preserved transient-DB compatibility, password login, unknown-capability rejection and kitchen/bar preparation-scope filtering using an injected identity repository.
- `tests/local-core-contract.py` PHP-lints all Local Core files, executes both PHP self-tests and rejects Windows ownership tokens such as `%PROGRAMDATA%`, Winspool, Registry/SCM and PowerShell inside Local Core.

Verified earlier M2 checkpoint before Auth expansion:
- head `00bd62a8706eca80b9aca73b2be51ab6b29bae12`;
- workflow run `36211338368` completed SUCCESS with the executable Local M2 gate.

Current Auth-expanded head is newer than that checkpoint; its workflow must be used as the next verification evidence before M2 advances to schema/migration completion.

## 6. M2 exit evidence required

M2 is not complete until all are true:
- Local Core starts under CLI/integration tests with Public/Runtime/Print absent;
- observability tests pass without DB availability;
- database/config tests prove deterministic injection/configuration;
- auth/capability regression tests cover active/disabled user and capability scope;
- M2-owned schema/migration bootstrap is implemented and tested;
- no second permission schema exists;
- no direct Windows Registry/SCM/Winspool/PowerShell ownership exists in Local Core;
- migration/status docs point to concrete test/CI evidence.

## 7. Current continuation point

Do **not** restart M2 from audit. Core implementation and Auth/Capability extraction are already present on `architecture/v3-foundation`.

Next action sequence:
1. verify the latest Auth-expanded Local CI run;
2. fix any regression if that run fails;
3. audit exact dev39 definitions/upgrade semantics for `schema_migrations`, `settings`, `users`, `user_capabilities` and platform audit storage;
4. implement the V3 Local migration runner/bootstrap without claiming `user_preparation_areas` ownership;
5. add migration tests and update this document with the final M2 checkpoint before marking the slice complete.
