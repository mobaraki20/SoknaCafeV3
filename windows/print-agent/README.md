# Print Agent

`windows/print-agent` is the canonical independently deployable Windows printing process for V3.

It owns durable local receipt/submission fencing, worker isolation, Windows printer discovery, Winspool/driver execution, local SQLite recovery, preview rendering and device diagnostics. Windows Runtime may supervise the service lifecycle, but does not absorb this state machine.

Local Web owns business print intent and the durable server-side queue. The retained protocol is Print API v4:

`claim/reserve -> local durable receipt -> accept(content fence) -> start -> physical submission -> report`

Ambiguous outcomes become `unknown` / `recovery_hold`; they are never blind automatic reprints. Business transactions remain committed even when printing is unavailable.

Historical implementation source was migrated from `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05` and is retained under `windows/print-agent/source`.
