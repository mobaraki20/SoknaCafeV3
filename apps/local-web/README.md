# Local Web

`apps/local-web` is the sole primary Business Authority for SOKNA V3.

Owns business rules, local business data/migrations, canonical local workflows and internal APIs/workers that execute business logic.

Must not own Windows Registry, Service Control, Winspool, machine ACLs, elevated OS operations or hardware-driver lifecycle.

Migration rule: bring legacy business capability here only after identifying its canonical owner, preserving approved UI/behavior, removing duplicate owners and defining any Runtime/Public contract explicitly.
