# Contract: Local/Runtime ↔ Print Agent

Version: `0.1.0-draft`

The V3 Print Agent is a separate Windows deployable that owns durable print submission/device/spooler execution. Runtime may supervise its service lifecycle; Local Web owns business print intent and document content.

## Frozen boundary

- Local Web decides what business document/ticket should be printed and submits through the versioned contract.
- Print Agent owns durable submission fence/state, local execution state, driver/spooler interaction and device diagnostics.
- Windows Runtime may start/stop/health-check/supervise the Print Agent but must not duplicate its spooler state machine.
- Print Agent does not decide whether an order/settlement is business-valid.
- Printing failure does not corrupt or roll back unrelated committed business state.
- Business receipt and preparation ticket are different document semantics; tax belongs to business receipt, not preparation ticket.

## Historical source

Audit the mature historical print state machine across `runtime/print-worker/source/`, `print-agent/v4/`, shared print contracts and the Pagent 6.2.5 lineage. V3 intentionally changes deployment ownership from the dev.39 internal Print Worker composition to a separate deployable Print Agent while preserving proven execution semantics.

## Draft work still required

Before stability define request envelope, document payload/version, idempotency/submission key, result/health schema, queue/retry semantics, spooler state mapping, authentication, service-supervision surface and backward-compatibility tests. Physical-printer UAT remains a distinct release gate.
