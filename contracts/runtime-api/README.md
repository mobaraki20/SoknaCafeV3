# Contract: Local Web ↔ Windows Runtime

Version: `0.1.0-draft`

Windows Runtime exposes machine/OS integration to Local Web through a versioned local contract and may issue allowlisted scheduling intents back to Local-owned worker/API handlers. Runtime is not a Business Authority.

## Frozen boundary

- the Runtime-facing endpoint is loopback-only by default;
- authentication is machine-bound and secrets/config are OS ACL-protected;
- Runtime may supervise services/workers, schedule triggers, expose health/diagnostics and perform approved OS adapters;
- Runtime never validates business orders/settlements/inventory policy;
- Runtime never writes Local business tables directly;
- Public never calls Runtime directly over the Internet;
- Runtime-to-Local trigger requests contain scheduling/trace identity only and resolve through a Local-owned allowlist;
- there is no generic shell/PowerShell/command/SQL execution surface;
- historical `printing` worker ownership is not carried forward: durable print/device/spooler execution belongs to `windows/print-agent`.

## M1 executable contract

- Historical audit: `historical-audit-v1.json`
- V3 boundary contract: `contract-v1.json`
- Compatibility vectors: `compatibility-vectors-v1.json`
- Architecture decision: `docs/adr/0001-runtime-local-contract-direction.md`
- CI gates: `tests/runtime-print-contract-audit.py` and `tests/runtime-print-v1-contract.py`

The V3 v1 boundary currently defines:
1. Local -> Runtime observational health/supervision state at a loopback machine-authenticated boundary.
2. Runtime -> Local idempotent allowlisted trigger intent; acceptance is not proof that business work committed.

## Historical source and compatibility position

The selected dev39 baseline did **not** expose a stable Runtime HTTP API. Proven historical surfaces were CLI, `sokna-local-runtime-v1` state JSON and the Windows SCM service host. Those semantics inform the V3 health/supervision contract, but V3 does not falsely claim HTTP route compatibility with a route that never existed.

## Still draft / later gates

The contract remains explicitly draft until implementation and Windows integration prove:
- actual loopback transport and machine-secret bootstrap/rotation;
- Local trigger receipt persistence/idempotency window;
- service lifecycle/restart/recovery and ACL behavior;
- diagnostics redaction and support bundle behavior;
- compatibility/version negotiation during component update/rollback.

No M1 contract evidence by itself claims Windows service/UAT or release readiness.
