# Local Web

`apps/local-web` is the sole primary Business Authority for SOKNA V3.

Owns business rules, local business data/migrations, canonical local workflows and internal APIs/workers that execute business logic.

Must not own Windows Registry, Service Control, Winspool, machine ACLs, elevated OS operations or hardware-driver lifecycle.

Migration rule: bring legacy business capability here only after identifying its canonical owner, preserving approved UI/behavior, removing duplicate owners and defining any Runtime/Public contract explicitly.

## M2 Local Core — in progress

Canonical continuation document: `docs/migration/M2_LOCAL_CORE_AUDIT_FA.md`.

First migrated core owners:
- `src/Core/Config.php` — configuration access;
- `src/Core/Database.php` — PDO connection policy only;
- `src/Core/Observability.php` — DB-independent correlation/log/redaction/state primitives;
- `src/Core/Bootstrap.php` + `bootstrap.php` — minimal Local composition root with lazy DB connection.

The M2 bootstrap intentionally does **not** eager-load business domains and does not discover/provision Windows paths. `SOKNA_DATA_DIR` or Local app configuration supplies the data root; Windows Setup/Platform owns machine-specific provisioning.

Executable gate: `tests/local-core-contract.py` runs PHP syntax checks and `tests/local-core-selftest.php`, and rejects Windows Runtime/Print ownership tokens inside Local Core.

Still required before M2 exit: session/auth/capability ownership, M2-owned schema/migration bootstrap and regression coverage for active/disabled users and capability/preparation scopes.
