# SOKNA Prerequisites Engineering Playbook

This file is the durable engineering handoff for the Windows Prerequisites manager. It applies to `packaging/prerequisites/**` and its prerequisite-specific policy/tests. It does not transfer ownership of Local Web, Public Edge, or Windows Runtime pairing to this component.

## Ownership boundary

Prerequisites owns installation/repair/recovery of the external Windows infrastructure required by Local Web: PHP, Apache, MariaDB server binaries/services, the MariaDB infrastructure data directory, loopback endpoint selection, dependency acquisition/cache, and infrastructure diagnostics.

Prerequisites does **not** install the Local Web payload, provision the SOKNA application database/user/migrations, configure Public Edge, or pair the Windows Runtime/Print Agent.

## Required operator model

There are three infrastructure operations:

- **Install**: create a genuinely new infrastructure and initialize MariaDB Data once.
- **Repair**: restore managed binaries/config/service registration while preserving Web and Data.
- **Recover**: re-register/rebuild managed infrastructure after Windows reinstall while preserving existing Data.

The UI may recommend a mode based on detected state, but destructive mode changes must never be inferred silently.

## Data safety invariants

1. Existing `Data\MariaDB` is never deleted by Prerequisites.
2. Existing initialized MariaDB Data is never initialized again.
3. Cross-root installation is fail-closed; no automatic migration or deletion is permitted.
4. Safe remediation may start SOKNA-owned services, enrich state provenance, or select a free loopback port. It may not reset application data.
5. Support bundles must not intentionally contain passwords, tokens, authorization values, or application credentials.

## Version and installer lifecycle

`packaging/prerequisites/VERSION.txt` is the manager version source. The outer Inno installer uses one stable AppId.

- no installed manager -> fresh manager install
- same manager version -> repair/refresh manager files
- older installed version -> upgrade
- newer installed version -> **block downgrade**, including silent execution

The infrastructure version is separate from the manager version. PHP/Apache/MariaDB expected versions come from `platform/windows/release-lock.json`.

## UI contract

The hardened entry point from 1.0.13 onward is `ProgramV3`. The existing infrastructure engine/UI baseline remains unchanged; the hardening layer owns RTL layout enforcement, diagnostics integration, provenance enrichment, support tooling and safety guards.

Required rules:

- `RightToLeftLayout=true` on top-level forms.
- prefer Vazirmatn when present; fall back to Tahoma then Segoe UI. Do not depend on a font being installed for correctness.
- paths, URLs, IPs, ports, versions, passwords, and other technical fields remain LTR islands.
- do not inject LRI/RLI/FSI/PDI Unicode controls into WinForms text.
- diagnostic findings use stable `PRQ-*` codes and always include a user action.

## State contract

The core state remains `Infrastructure\infrastructure-state.json`. The hardening layer enriches it with:

- `setup_version`
- `release_lock_sha256`
- `infrastructure_policy_sha256`
- `components` with expected/detected versions
- `state_writer`
- `state_write_mode=atomic-replace`

State enrichment is best-effort and must never make infrastructure operations fail. Writes use same-directory temporary-file replacement and never include credentials.

### MariaDB executable-selection invariant

Only the actual server executable may be used for version probing or server-health classification:

1. Prefer exactly `MariaDB\bin\mariadbd.exe`.
2. If absent, allow exactly `MariaDB\bin\mysqld.exe` as the legacy/server fallback.
3. Never discover the server with wildcard patterns such as `maria*d.exe`.
4. Never execute helper/GUI binaries such as `mariadb-upgrade-wizard.exe` for metadata, diagnostics or version probing.
5. Provenance/version enrichment must not poll dependency executables on a recurring UI timer. It runs only at bounded lifecycle points such as form shown/closed or explicit user diagnostics.

Regression origin: Prerequisites 1.0.12 used `maria*d.exe`; on the real MariaDB package that pattern could select MariaDB Upgrade Wizard. Because state enrichment ran every five seconds, the wizard repeatedly opened and made the manager appear hung. This failure mode must have an explicit locator self-test in every subsequent Prerequisites candidate.

## Diagnostics and support

Stable categories: ROOT, STATE, PHP, APACHE, MARIA, DATA, PORT, UAC.

Support bundle v2 includes stable findings, `sc queryex`, `sc qc`, relevant listening ports/processes, recent logs, state, Apache/PHP config, and recent Service Control Manager events. Known secret-like assignments and URI credentials are redacted. Do not add `my.ini`, Local Web credentials, pairing secrets, or arbitrary environment dumps.

## Development workflow

Do not use GitHub Actions as the iterative development loop.

1. Make coherent changes on a dedicated `work/prerequisites-*` or focused `hotfix/prerequisites-*` branch.
2. Run/inspect static contracts locally or by source review.
3. Only when the candidate is coherent, run one focused Windows qualification.
4. Fix the candidate as a batch if qualification finds issues.
5. Qualification must produce one installer, artifact index/report and any required evidence tied to one source commit.
6. A release must publish the exact qualified artifact; do not rebuild the installer for release.

## Candidate qualification

Required gates include:

- legacy G7 static contract where relevant
- self-service contract
- .NET publish and ProductVersion match
- runtime self-tests
- **MariaDB exact server-locator self-test with Upgrade Wizard present as a decoy**
- endpoint and ownership self-tests
- UI audit/screenshots when presentation changes
- real Apache/PHP regression when infrastructure code changes
- Apache Windows service sodium regression when PHP/Apache binding changes
- Inno build with artifact provenance
- upgrade from the previous released manager
- same-version repair refresh
- silent downgrade block

## Lessons carried forward from Windows Services and Prerequisites

- A Windows service reporting `Stopped` is not by itself proof that every owned process/file mapping is released; replacement paths must be lifecycle-aware.
- UI screenshots must be rendered from the real executable, not inferred from source.
- Persian WinForms needs explicit RTL/layout rules; mixed technical values need LTR islands.
- A passing document or manifest is not evidence that runtime behavior passed; qualification must exercise the behavior.
- Record source commit and policy fingerprints in built artifacts.
- Support bundles should make the next failure diagnosable without repeated trial-and-error.
- Never infer an executable role from a broad filename wildcard when a dependency ships multiple GUI/helper tools beside its server binary.
- Background metadata refresh must never launch arbitrary dependency executables repeatedly.
