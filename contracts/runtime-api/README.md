# Contract: Local Web ↔ Windows Runtime

Version: `0.1.0-draft`

Windows Runtime exposes machine/OS integration to Local Web through a versioned local contract. It is not a Business Authority.

## Frozen boundary

- endpoint is loopback-only by default;
- authentication is machine-bound and secrets/config are ACL-protected;
- Runtime may supervise services/workers, schedule triggers, expose health/diagnostics and perform approved OS adapters;
- Runtime never validates business orders/settlements/inventory policy;
- Runtime never writes Local business tables directly;
- Public never calls this API directly over the Internet;
- Local Web requests intent through the contract and remains owner of business decisions.

## Historical source

Audit `runtime/windows/`, `SoknaRuntimeService.cs`, setup/runtime support and related health/service lifecycle code from `work/reconcile-dev39`.

## Draft work still required

Before stability define endpoint transport, auth handshake/token rotation, capability list, request/response envelope, timeouts, error taxonomy, health schema, supported service-supervision operations and compatibility range.
