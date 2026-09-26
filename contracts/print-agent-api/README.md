# Contract: Local/Runtime ↔ Print Agent

Version: `1.0.0`

The V3 Print Agent is a separate Windows deployable that owns durable print submission/device/spooler execution. Runtime may supervise its service lifecycle; Local Web owns business print intent and document content.

## Frozen boundary

- Local Web decides what business document/ticket should be printed and exposes the versioned server contract consumed by Print Agent.
- Print Agent owns durable local receipt/submission fence, local execution state, driver/spooler interaction and device diagnostics.
- Windows Runtime may supervise the Print Agent service lifecycle but must not duplicate its spooler/attempt state machine.
- Print Agent does not decide whether an order/settlement is business-valid.
- Printing failure does not corrupt or roll back unrelated committed business state.
- Business receipt and preparation ticket are different document semantics; tax belongs to business receipt, not preparation ticket.
- Browser/device-local wake and preview are a separate paired loopback subprotocol, never a second durable submission path.

## M1 executable contracts

- Historical audit: `historical-audit-v1.json`
- Retained Local server/Agent protocol: `server-wire-v4.json`
- Paired loopback wake/preview protocol: `loopback-v1.json`
- Compatibility vectors: `compatibility-vectors-v4.json`
- CI gates: `tests/runtime-print-contract-audit.py` and `tests/runtime-print-v1-contract.py`

### Retained Print API v4 actions

`probe`, `heartbeat`, `claim`, `claim_reconcile`, `attempt_status`, `renew`, `accept`, `start`, `report`.

The retained safety sequence is:

`claim/reserve -> durable local persistence -> accept(receipt + content hash) -> start -> physical spooler execution -> report`

Critical semantics preserved:
- mutation `request_id` is body-fingerprinted/idempotent;
- destination snapshot cannot silently change after claim;
- durable `local_receipt_id` + `content_sha256` fence is established before physical execution;
- ambiguous physical outcomes become durable `unknown` / `recovery_hold` rather than blind automatic reprints;
- report delivery can retry independently from physical printing;
- server identity/scope binding prevents a backlog from silently moving to an unrelated server.

### Retained loopback v1

- `/v1/wake` is a bounded, paired-origin nudge only; durable jobs are still obtained through Print API v4.
- `/v1/preview` is bounded device-local rendering and does not create a print attempt or mutate business state.

## Historical source and ownership correction

The mature historical state machine comes from `runtime/print-worker/source/`, `print-agent/v4/`, shared print contracts and the Pagent lineage. V3 intentionally rejects the dev39 packaging statement that Print Worker is an internal Local component. The execution owner is `windows/print-agent`; Runtime supervision does not change that ownership.

## M8 implementation status

M8 migrates the independently deployable Print Agent implementation and Local durable print owner. Remaining release/UAT gates include:
- actual separate-deployable service implementation and upgrade/repair lifecycle;
- secret/token provisioning and rotation;
- durable SQLite/state migration and recovery tests;
- Winspool/driver restart/failure tests;
- physical-printer UAT;
- Local/Agent compatible-version negotiation during staged update/rollback.
