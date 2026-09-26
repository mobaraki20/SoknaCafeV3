# Packaging

M9 makes Packaging the composition owner for immutable component releases. Each component remains independently versioned and serviceable.

Canonical lifecycle:

`build full package -> verify hashes/contracts -> stage -> compatibility check -> activate`

A failed activation restores the previous active pointer. Same-version **Repair** re-verifies/restages the canonical full package without changing business data. **Recovery** is a distinct operation: it restores an explicit recovery set after machine/platform identity has been provisioned; it is not an alias for Repair or Update.

The normal Windows user sees one `SOKNA Setup.exe` flow. Internal PowerShell/.NET helpers are implementation details and are never required to be launched manually by the operator.

WiX is not a V3 owner. The retained Windows setup source under `packaging/windows` uses the accepted single-bootstrapper composition and component lifecycle contracts.
