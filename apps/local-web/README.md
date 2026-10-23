# Local Web

`apps/local-web` is the sole primary Business Authority for SOKNA V3.

Owns business rules, local business data/migrations, canonical local workflows and internal APIs/workers that execute business logic.

Must not own Windows Registry, Service Control, Winspool, machine ACLs, elevated OS operations or hardware-driver lifecycle.

Migration rule: bring legacy business capability here only after identifying its canonical owner, preserving approved UI/behavior, removing duplicate owners and defining any Runtime/Public contract explicitly.

## M2 Local Core — in progress

Canonical continuation document: `docs/migration/M2_LOCAL_CORE_AUDIT_FA.md`.

Implemented core owners:
- `src/Core/Config.php` — configuration access;
- `src/Core/Database.php` — PDO connection policy only;
- `src/Core/Observability.php` — DB-independent correlation/log/redaction/state primitives;
- `src/Core/Session.php` — Local session policy/storage;
- `src/Core/IdentityRepository.php` + `PdoIdentityRepository.php` — canonical identity/capability/preparation-area reads;
- `src/Core/Capabilities.php` — capability filtering over existing authorities;
- `src/Core/Auth.php` — active-user refresh, login/logout and session compatibility semantics;
- `src/Core/Bootstrap.php` + `bootstrap.php` — minimal composition root with lazy DB/auth dependencies.

The M2 bootstrap intentionally does **not** eager-load business domains and does not discover/provision Windows paths. `SOKNA_DATA_DIR` or Local app configuration supplies the data root; Windows Setup/Platform owns machine-specific provisioning.

Executable gate: `tests/local-core-contract.py` PHP-lints Local Core, runs `tests/local-core-selftest.php` and `tests/local-auth-selftest.php`, and rejects Windows Runtime/Print ownership tokens inside Local Core.

Still required before M2 exit: M2-owned schema/migration bootstrap and its regression coverage, followed by a final green Local CI checkpoint recorded in the migration handoff.

## G2.1 Browser Setup

Local Web is installed from its web package, not from a Windows/PowerShell installer. When `config.php` or a valid `install.lock` is missing, normal Local routes redirect to `/setup/`.

The browser wizard owns environment preflight, MariaDB 11.4.x connection testing/optional database creation, migrations, initial admin/cafe/table bootstrap, installation identity, Runtime token/config provisioning, final health validation and the final setup lock. `install.lock` is written only after the final health check. A config-without-valid-lock state is treated as partial and may be safely resumed after the database installation identity is verified.

An installed Web tree is also bound to the persistent installation identity by a machine-local `.sokna-installation.json` file outside the public document root. If a Clean package is extracted after the old `Web` directory was removed while `config.php`/`install.lock` still exist in the SOKNA root, the wizard reports **existing installation found** instead of silently reusing that state. The operator can either validate and continue the existing installation without changing business data, or explicitly archive the old setup state under `SetupArchive/` and start a fresh wizard. The fresh path never automatically deletes the previous data directory or database.

`tools/setup-machine.php` remains an internal/legacy automation helper while packaging is revised in G5; it is not the end-user Local Web installation surface.

## D2.3 Product Design System

The Local Web product UI now uses a shared page grammar derived from the approved prototype and the D2.1/D2.2 real workflows. Canonical shared patterns cover page headers, attached local tabs, section/panel hierarchy, tables, metrics, detail rails and compact attention inboxes. Tabbed routes declare the shared page pattern through `ProductShell::start(..., true)` instead of inventing route-specific header/tab styling.

Hall attention now lives in the contextual detail rail: items for the selected table are promoted while remaining hall actions stay in the same compact inbox. Existing backend-backed actions are preserved; prototype-only fake actions are not introduced.
