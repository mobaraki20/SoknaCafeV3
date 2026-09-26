# SOKNA Setup — M9

This directory is the source for the **single user-facing Windows setup**. It composes independently versioned Platform, Runtime, Print Agent and Local packages; it does not merge their ownership.

Supported product modes are New, Recover, Repair and Uninstall. Update is component-aware and compatibility-checked before activation. Repair uses the same canonical full package at the active version. Recovery consumes an explicit recovery set and reprovisions machine identity; it is not Repair.

PowerShell/.NET helpers may run behind the setup host, but the operator is not required to launch scripts manually. WiX is not a V3 lifecycle owner.
