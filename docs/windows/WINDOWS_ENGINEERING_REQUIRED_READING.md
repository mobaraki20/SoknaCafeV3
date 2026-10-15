# SOKNA Windows Engineering — REQUIRED READING

**Status: MANDATORY**

This document is required reading before any human or agent changes Windows-related code, packaging, prerequisites, services, installers, diagnostics, recovery tooling, or Windows CI in this repository.

Applies to changes under or affecting:

- `windows/**`
- `platform/windows/**`
- `packaging/windows/**`
- Windows-specific contracts, CI workflows, installers, service registration, ACLs, logging, support bundles, recovery tooling, or prerequisites

If a newer ADR explicitly supersedes a rule here, the ADR wins and this file must be updated in the same change.

---

## 1. First principle: Windows is a set of independently versioned components

Do not treat “Windows Services” as one opaque binary/version.

The package/lifecycle owner is the unified **SOKNA Windows Services Setup**, but logical components keep independent identities and versions.

Current primary components:

- Runtime — persistent service `SoknaRuntime`
- Print Agent / Worker — persistent service `SoknaPrintWorker`
- Maintenance Supervisor — persistent service `SoknaMaintenanceSupervisor`

Maintenance subordinate components:

- Update Engine — versioned child executable, not a permanent service by default
- Recovery Console — versioned independent executable/UI; must remain usable if Local Web / Apache / PHP is unavailable

Package version and component versions are different concepts. Never decide update state only from the package version.

Allowed per-component lifecycle states/actions include:

- `KEEP`
- `INSTALL`
- `UPDATE`
- `REPAIR`
- `BLOCKED`
- `PENDING_RESTART`
- `NO_ACTION_OPTIONAL`

`KEEP` is a behavioral contract: no service stop, no file copy, no service re-registration, and no restart unless a declared compatibility/transaction-group dependency explicitly requires coordinated mutation.

---

## 2. Mandatory host classification: Home and Cafe are different test environments

There are two separate real Windows test environments and their evidence must never be mixed:

### Home Test Host

Used for early field/UAT validation of:

- installer behavior
- prerequisites
- service installation/update/repair/uninstall
- UI
- logging/diagnostics
- support bundle creation
- restart/reboot behavior
- controlled failure scenarios

A PASS here may be called **Home Test Host PASS** or **Home UAT PASS** only.

### Cafe Test Host

Used for the cafe-specific environment and integration validation, including its actual Apache/PHP/Local/Public/network/printer/service identities and ACLs.

A Home PASS never closes a Cafe Real-Host gate.

Both machines are test environments at present, but they are different environments with separate baselines, evidence, versions, service state, logs, and conclusions.

Every host-side evidence file must include at minimum:

- `host_role = home_test | cafe_test`
- hostname or safe machine identifier
- capture UTC time
- Windows version/build
- package/component versions
- source/artifact identity being tested
- test transaction/correlation ID where applicable

Never infer the installed state of one host from the other.

---

## 3. Windows PowerShell 5.1 compatibility is a hard requirement unless explicitly retired

The deployed lifecycle path can execute through Windows PowerShell 5.1. Code that works in `pwsh` / newer .NET is not automatically safe in Windows PowerShell 5.1.

### Do not assume newer .NET APIs exist

A real defect was caused by use of:

- `System.IO.Path.IsPathFullyQualified`

This API was not safe for the Windows PowerShell 5.1 runtime used by the lifecycle script.

Guardrail:

- use APIs available to the actual runtime
- validate absolute paths using compatible primitives such as `GetPathRoot` + `GetFullPath`
- test the exact executable path used in production: `%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe`

### Do not assume PowerShell module auto-loading

A real defect was caused by assuming `Get-FileHash` would always be available/auto-loaded in the lifecycle process.

Guardrail:

- security-critical hashing must not depend on optional module auto-loading
- prefer base .NET SHA-256 primitives available in the supported runtime
- hash calculation failures must fail closed before mutation

### Encoding: UTF-8 without BOM is not enough for Windows PowerShell 5.1 scripts containing non-ASCII text

A real defect produced mojibake (`â€”`) in diagnostic evidence because a `.ps1` script containing non-ASCII text was consumed by Windows PowerShell 5.1 without a safe encoding marker.

Guardrail:

- packaged `.ps1` files executed by Windows PowerShell 5.1 must be emitted in a Windows-PowerShell-safe encoding; current packaging uses UTF-8 with BOM
- CI/evidence should scan for obvious mojibake regressions
- never silently accept corrupted diagnostic text merely because lifecycle logic succeeded

---

## 4. Prerequisites and runtime assumptions must be explicit

Never rely on a developer workstation assumption.

Before depending on a Windows capability, identify whether it is:

- built into the supported Windows baseline
- shipped self-contained with SOKNA
- installed/verified by the SOKNA prerequisite layer
- optional and safely degradable

For every external prerequisite or OS capability, define:

- minimum supported Windows version/build
- architecture (`x64` unless explicitly expanded)
- whether admin/UAC is required
- executable/API actually used
- detection method
- failure UX
- diagnostic evidence
- rollback/cleanup behavior

Do not make service correctness depend on an SDK, developer tool, shell module, compiler, PATH entry, or interactive profile that is not guaranteed on the target host.

Self-contained service binaries must remain self-contained unless an approved architecture decision changes this.

---

## 5. Installer logging starts before UAC and before mutation

A setup failure can happen before ProgramData, service creation, or elevation succeeds. Therefore one ProgramData log is insufficient.

Every install/update/repair/uninstall attempt must have durable transaction identity from process start:

- install transaction ID
- correlation ID
- setup session ID
- UTC timestamps

Evidence is two-tiered:

### Tier A — pre-elevation/user side

Use a persistent user-safe location such as:

`%LOCALAPPDATA%\SOKNA\Installer\logs\<transaction_id>\`

This is authoritative if elevation or ProgramData creation fails.

### Tier B — elevated/system side

After elevation succeeds:

`%ProgramData%\SOKNA\Installer\logs\<transaction_id>\`

Safe bootstrap evidence should be copied/mirrored into the system transaction folder.

Installer failure must never end as a generic “installation failed” with no evidence. Record, where applicable:

- component
- phase
- current/target versions
- current/target hashes
- service identity/config
- Win32/HRESULT/SCM/process exit code
- retry/backoff
- pending restart
- rollback steps/results
- Diagnostic ID

Failed transaction logs survive rollback/uninstall under the diagnostics retention policy.

---

## 6. Secrets never belong in routine logs or support bundles

Never emit values of:

- HMAC keys
- Recovery keys
- pairing codes
- bearer/auth tokens
- credentials/passwords
- DB passwords
- private keys
- cookies/Authorization headers
- raw business payloads
- raw receipt/print content unless a specifically approved redacted diagnostic contract requires a safe derivative

Presence, path, ACL result, safe fingerprint, and access-test result may be logged where needed; secret content may not.

Every new diagnostics path must have redaction tests. A secret leak is a release blocker.

---

## 7. Service ownership and ImagePath checks must be exact

Never decide service ownership with a loose substring test.

Before mutating an existing service:

1. read the real service registration
2. parse the executable path safely
3. canonicalize the path
4. prove it belongs under the SOKNA-owned install root
5. verify expected service identity/contract

If a same-name service points outside the owned root, fail closed as `BLOCKED`; do not stop, replace, or delete it.

Service identity/account and image path are security-relevant configuration, not cosmetic metadata.

---

## 8. Stopping a service is not proof that its files are safe to replace

A previous class of failures involved file locking / lingering processes (including native DLL use such as SQLite/native dependencies).

For a component that will actually be mutated:

1. request service stop
2. wait until SCM reports Stopped
3. capture and verify service PID exit
4. verify owned child/processes under the component root have exited
5. only then replace files

If processes do not exit within the contract timeout, fail or perform the explicitly approved forced-termination path and record it.

For `KEEP`, none of the above may happen by default.

---

## 9. Plan first, mutate second, revalidate after elevation

The unprivileged/native UI is not a security authority.

Before mutation:

- build installed component inventory
- verify target manifest/package identity
- compute per-component plan
- display the plan in UI

After UAC/elevation, the privileged host must re-read/revalidate the plan and package identity. If target bytes, versions, hashes, service ownership, or compatibility state changed after plan creation, fail closed.

Do not trust stale UI state across the privilege boundary.

---

## 10. Version equality does not mean healthy/up-to-date

If target and installed version numbers match but any of the following drift, the component is not simply “up to date”:

- binary hash/provenance
- service ImagePath
- account/start mode/recovery policy
- ACL contract
- required configuration/contract version

Use `REPAIR` or security `BLOCKED` as appropriate.

Health and Update State are separate dimensions. A component can be healthy but have an update available; it can also be latest-version but require repair.

---

## 11. Do not restart unrelated services

If only Maintenance changes, Runtime and Print must remain untouched unless the signed/versioned compatibility manifest explicitly declares a coordinated transaction group.

This reduces:

- downtime
- failure blast radius
- file-lock races
- rollback ambiguity
- diagnostic noise

CI must include selective-lifecycle tests proving unchanged components preserve at least:

- binary hash
- file timestamp where useful
- SCM configuration
- explicit `KEEP` evidence

---

## 12. Rebuild hashes are not automatically immutable release identity

A strict gate once failed because freshly rebuilt binaries were assumed to be byte-for-byte identical to binaries from an earlier CI run.

Lesson:

- source identity and build inputs must be frozen
- release artifacts must have explicit provenance and their own hashes
- do not assume two independent builds are byte-identical unless reproducible-build guarantees have actually been established and tested
- if exact artifact identity matters, promote/retain the qualified artifact rather than silently rebuilding and calling it identical

A hash mismatch is evidence to investigate, not something to waive casually.

---

## 13. CI failure evidence must use the same paths as the product

A previous CI path tried to read failure logs from `%ProgramData%\SOKNA\...` while the qualification lifecycle had intentionally redirected `DataRoot` elsewhere. The product had logged the failure, but the CI failure handler looked in the wrong place and hid the evidence.

Guardrail:

- diagnostic readers must consume the actual transaction/DataRoot resolved by the test or product
- on failure, upload/print structured logs from the real transaction path
- failure evidence collection itself must be tested
- never let a CI assertion destroy or hide the evidence needed to diagnose the assertion

---

## 14. Windows UI tests must test the final entrypoint and real painted UI

Do not qualify a prototype form that the executable does not actually launch.

Required principles:

- screenshot/layout tests use the actual production entrypoint
- render real painted WinForms UI
- test representative compact/normal/large dimensions
- detect clipping, collapsed controls, unexpected scroll, and action hierarchy issues
- inspect screenshots visually; geometry tests alone are not sufficient

A previous headless screenshot test hung because normal `Shown` behavior triggered a real refresh and MessageBox in the test environment.

Guardrail:

- screenshot mode must disable/non-trigger environment-dependent auto-refresh safely
- headless tests must fail with structured evidence rather than blocking on modal MessageBox
- screenshot capture should capture the intended client area, not accidentally include non-client/titlebar pixels in layout calculations

UI qualification must not be declared complete from CI green status alone; review the produced screenshots.

---

## 15. Maintenance ACL and loopback security invariants

Maintenance control traffic is local-machine control-plane traffic.

Keep these invariants unless a newer security ADR changes them:

- loopback endpoint only
- exact supported web-service identity, not broad user/group access
- `web-adapter.key` readable only by the intended Windows/Apache identity contract
- `recovery-console.key` remains unavailable to the web adapter
- no `Users`, `Everyone`, `Authenticated Users`, or similarly broad secret grants
- descriptor/key ACLs must survive atomic replacement/restart reconciliation
- browser must never receive raw HMAC key/signature/nonce/auth material

ACL drift is diagnosable state and may require Repair/Blocked behavior.

---

## 16. Diagnostics must reconstruct the timeline across components

Runtime, Print, Maintenance, and Installer logs must converge on a shared structured model where technically possible.

Useful common fields include:

- UTC timestamp
- component
- host role
- version/build identity
- transaction ID
- correlation ID
- operation ID
- request ID
- attempt/retry/backoff
- phase/state transition
- duration
- safe result/error code

The target operational property is: in ordinary failures, one Support Bundle should contain enough evidence to diagnose the incident without repeatedly interrogating the machine by hand.

Support Bundle collection should be Windows-owned and remain usable when Local Web / Apache / PHP is down. Partial collection should still produce a bundle with `collection_errors` rather than fail the whole bundle.

---

## 17. Before every Windows host test

Capture before-state before installing/updating:

- host role: Home Test or Cafe Test
- Windows version/build
- installed Windows Services package identity if available
- Runtime / Print / Maintenance service inventory
- service ImagePath/account/start mode/status
- relevant executable versions + SHA256
- ProgramData Maintenance presence/descriptor state
- relevant ACL summaries without secret content
- pending reboot state where technically practical
- ports/listeners relevant to the control plane

Never use `Force` to bypass an unexplained baseline mismatch. Stop, capture, reconcile.

After the test, capture corresponding after-state and transaction logs.

---

## 18. Required review checklist for any Windows change

Before declaring a Windows change engineering-qualified, explicitly answer:

- Which component owns the change?
- Did package/component versions change correctly and independently?
- Does it run on the actual supported Windows PowerShell/.NET environment?
- Are paths and encodings safe for Windows PowerShell 5.1 where applicable?
- Does `KEEP` remain zero-mutation?
- Are service ownership and process-exit boundaries verified?
- Are update/repair/rollback rules fail-closed?
- Are pre-UAC and elevated logs preserved?
- Can the failure be reconstructed from structured evidence?
- Are secrets excluded?
- Does Support Bundle still work if Local Web is unavailable?
- Were UI screenshots generated from the real entrypoint and visually reviewed if UI changed?
- Was Home evidence kept separate from Cafe evidence?
- Was the exact source commit/tree + artifact digest frozen?
- Was source/CI qualification kept distinct from real-host UAT?

If any answer is unknown, the change is not finished.

---

## 19. Known failure lessons — never repeat silently

| Failure/near-miss | Root cause | Permanent guardrail |
|---|---|---|
| Lifecycle failed before mutation on Windows PowerShell 5.1 | newer .NET path API used | test exact PowerShell 5.1 runtime; use compatible path primitives |
| SHA step failed despite valid file | reliance on `Get-FileHash` auto-loading | use base .NET SHA-256 for critical lifecycle verification |
| Diagnostic JSON showed `â€”` | UTF-8 script without PS5.1-safe encoding marker | package `.ps1` as UTF-8 BOM; scan evidence for mojibake |
| CI failure hid real transaction log | failure collector assumed wrong DataRoot | collect from resolved transaction path; test failure-evidence path |
| Headless UI test hung | production `Shown` refresh opened modal path | explicit screenshot/test mode; never allow blocking modal in headless test |
| Screenshot evidence looked cropped | captured non-client/titlebar area incorrectly | capture intended client surface and audit actual pixels |
| UI geometry passed but visual defects remained | automated geometry is incomplete | mandatory visual screenshot review after automated PASS |
| Potential locked-file regression | service Stopped was treated as process-exit proof | verify SCM stop + PID exit + owned-process exit before replacement |
| Unrelated services could be needlessly touched | package-level update mindset | per-component planner; `KEEP` means no mutation/restart |
| Strict binary re-build hash comparison failed | independent rebuild assumed byte-identical | freeze/promote qualified artifacts; do not assume reproducibility |
| Service with same name could be unsafe to mutate | weak ownership assumptions | parse/canonicalize ImagePath and prove SOKNA-owned root |

Add new lessons here whenever a Windows defect reveals a reusable engineering guardrail.

---

## 20. Required reading order for Windows work

Before changing Windows code, read in this order:

1. `/START_HERE.md`
2. `/ARCHITECTURE.md`
3. `/PROJECT_LINEAGE.md`
4. **this file**: `/docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md`
5. target component docs/README
6. relevant Windows/Maintenance ADRs and compatibility contracts
7. current immutable checkpoint/handoff for the workstream

Do not begin implementation from memory alone when the current checkpoint or release artifact identity is available.
