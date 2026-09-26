# Print Agent

`windows/print-agent` preserves the mature printing lifecycle as an independently releasable Windows deployable when needed.

It owns print-driver/spooler integration, print state and print-specific runtime behavior. Windows Runtime may supervise its lifecycle, but Local Web must not duplicate Winspool/driver responsibilities and Runtime must not absorb printing business/UI concerns merely for convenience.
