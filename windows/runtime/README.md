# Windows Runtime

`windows/runtime` owns Windows/OS integration, service supervision, scheduling/triggers, watchdog/health, diagnostics, approved OS adapters and maintenance integration.

It must not contain Local business rules, decide business validity, or write Local business tables directly.

The Runtime supervises the separate **Print Agent** Windows service, but does not own print-job business state or printer rendering logic. Print Agent remains an independently deployable process with its own loopback contract and durable state.

Expected flow:

`Runtime -> versioned Local worker/API -> business logic -> Local DB`

Runtime contracts are loopback/machine-bound by default. No Public/Internet endpoint may directly control Windows services.
