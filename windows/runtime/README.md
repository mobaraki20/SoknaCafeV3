# Windows Runtime

`windows/runtime` owns Windows/OS integration, service supervision, scheduling/triggers, watchdog/health, diagnostics, approved OS adapters and maintenance integration.

It must not contain Local business rules, decide business validity, or write Local business tables directly.

The Runtime supervises the separate **Print Agent** Windows service, but does not own print-job business state or printer rendering logic. Print Agent remains an independently deployable process with its own loopback contract and durable state.

Expected flow:

`Runtime -> versioned Local worker/API -> business logic -> Local DB`

Runtime contracts are loopback/machine-bound by default. No Public/Internet endpoint may directly control Windows services.

## Scheduler policy

- `public.relay_sync` is the only v1 trigger explicitly allowed to use a fast cadence below five seconds. Its supported minimum is one second.
- The legacy installed default `public.relay_sync=5` is treated by Runtime 1.0.3 as an effective one-second cadence, so existing installations gain the latency fix without rewriting their config file. Health exposes both configured and effective intervals.
- Other/custom triggers retain the historical five-second safety floor when configured below five seconds; normal 15/30/60-second intervals are not accelerated.
- Scheduler scan tick is 100 ms so a healthy one-second relay lane is normally dispatched close to its configured cadence.
- Every trigger has an independent lane. A lane never overlaps itself, while a slow/failing trigger does not block unrelated trigger lanes.
- Ambiguous transport failures retry the same scheduled identity after bounded exponential backoff. Definitive Local HTTP failures rotate the scheduled identity before retrying.
- Retry backoff is bounded to 30 seconds and there is no catch-up/runaway loop after a slow handler.
- Print Agent supervision runs on a separate Runtime loop so Windows service recovery cannot stall trigger scheduling.
- `/v1/health` and `runtime-state.json` expose configured/effective cadence, last start/completion, next due time, in-flight state, consecutive failures and last trigger error.

Runtime configuration is reloaded on the normal service restart performed by Windows Services Install/Repair. Unrelated trigger definitions and custom intervals are not rewritten by this release.
