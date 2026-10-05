# SOKNA Prerequisites 1.0.13

Hotfix scope: MariaDB executable detection / repeated Upgrade Wizard popup.

## Root cause

Prerequisites 1.0.12 used a broad `maria*d.exe` wildcard while enriching installed-component version metadata. The MariaDB distribution also contains GUI/helper executables that can match this pattern, including the Upgrade Wizard. The selected executable was invoked with `--version`. Because enrichment was scheduled every five seconds, a matched GUI helper could reopen repeatedly and make the manager appear hung.

## Changes

- New hardened entry point: `ProgramV3`.
- MariaDB server discovery is exact-name only:
  - `mariadbd.exe`
  - fallback: `mysqld.exe`
- MariaDB Upgrade Wizard and other helper binaries are never considered server executables.
- Recurring five-second dependency-version polling is removed from the production UI path.
- State/provenance enrichment runs only at bounded lifecycle points and uses the exact server locator.
- Self-service diagnostics adds an explicit error when the MariaDB directory exists but no valid server executable exists.
- Safe diagnostics uses the safe state enricher for provenance repair.
- Added a regression self-test where `mariadb-upgrade-wizard.exe` is deliberately present as a decoy and must never be selected.

## Safety

No MariaDB Data deletion, initialization or migration behavior changed. Apache/PHP configuration and Local Web/Public Edge/Windows Services are outside this hotfix scope.
