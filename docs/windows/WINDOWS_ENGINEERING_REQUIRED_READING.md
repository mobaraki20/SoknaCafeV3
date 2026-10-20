# SOKNA Windows Engineering — REQUIRED READING

**Status: MANDATORY**

This document is required reading before any human or agent changes Windows-related code, packaging, prerequisites, services, installers, diagnostics, recovery tooling, or Windows CI in this repository.

Applies to changes under or affecting:

- `windows/**`
- `platform/windows/**`
- `packaging/windows/**`
- Windows-specific contracts, CI workflows, installers, service registration, ACLs, logging, support bundles, recovery tooling, or prerequisites

If a newer ADR explicitly supersedes a rule here, the ADR wins and this file must be updated in the same change.

## Core rules

1. Windows Services is one lifecycle/package surface but contains independently versioned logical components. Runtime, Print and Maintenance must be planned per component; package version alone is never enough.
2. `KEEP` means no stop, copy, service re-registration or restart unless an explicit compatibility transaction group requires it.
3. Home Test Host and Cafe Test Host are separate real Windows test environments. Evidence, baselines and PASS claims must never be mixed.
4. Windows PowerShell 5.1 compatibility is a hard requirement until explicitly retired. Test the exact Windows PowerShell executable used by the lifecycle.
5. Do not assume newer .NET APIs exist in Windows PowerShell 5.1. `Path.IsPathFullyQualified` caused a real pre-mutation failure; use compatible path primitives.
6. Do not depend on PowerShell module auto-loading for security-critical work. `Get-FileHash` availability caused a real pre-mutation failure; use compatible base .NET SHA-256 primitives for lifecycle verification.
7. Packaged `.ps1` files executed by Windows PowerShell 5.1 must use a safe encoding. UTF-8 without BOM caused mojibake in evidence; current packaging re-emits scripts as UTF-8 BOM.
8. Do not assume cmdlet parameters from PowerShell 7 exist in Windows PowerShell 5.1 (for example `Invoke-WebRequest -SkipHttpErrorCheck`).
9. Installer diagnostics begin before UAC. Preserve user-side bootstrap evidence and elevated `%ProgramData%` transaction evidence under shared transaction/correlation IDs.
10. Never log HMAC keys, recovery keys, pairing codes, credentials, tokens, cookies, Authorization headers, private keys or raw business payloads.
11. Existing service ownership must be proven by parsing/canonicalizing ImagePath and confirming the executable belongs to the SOKNA-owned root. Same-name services outside the owned root are `BLOCKED`, not mutated.
12. SCM `Stopped` is not sufficient proof that files are safe to replace. For mutated components verify service PID exit and owned-process exit before replacement.
13. Plan before mutation, then revalidate package/plan after UAC/elevation. The UI is not a privileged source of truth.
14. Same version with binary/service/ACL/config drift is `REPAIR` or security `BLOCKED`, not `up_to_date`.
15. Do not restart unrelated services. Selective lifecycle tests must prove unchanged components remain unchanged.
16. Do not assume two independent builds are byte-identical unless reproducible builds are explicitly guaranteed and tested. Promote/freeze the qualified artifact and record its provenance/hash.
17. CI failure collectors must read the actual resolved transaction/DataRoot. A previous CI handler hid valid logs by looking in the wrong path.
18. UI screenshot tests must exercise the real executable entrypoint, render real painted UI, avoid environment-dependent auto-refresh/modal hangs, and be visually reviewed after automated geometry checks.
19. Maintenance loopback/ACL security remains fail-closed: no broad Users/Everyone grants, web adapter cannot read recovery key, browser never receives HMAC secrets/auth material.
20. Diagnostics should reconstruct a cross-component timeline and Support Bundle collection should remain usable when Local Web / Apache / PHP is down.
21. Lifecycle-visible executable version identity must remain semantic and comparable to the component manifest. Do not put engineering lineage such as `4.0.0-p2b-*` into `ProductVersion/InformationalVersion` when the lifecycle version is `0.2.1`; source/branch/commit provenance belongs in the package manifest and frozen qualification evidence.
22. GUI-started privileged helpers must not expose implementation consoles. Setup hosts should be `WinExe`; PowerShell diagnostics/pairing launched from the GUI must hide their console window while preserving logs and exit codes.
23. Any C#/PInvoke compiled dynamically by Windows PowerShell `Add-Type` must be qualified on the oldest supported Windows/PS5.1 host. Ambiguous framework type names (for example `FILETIME`) must be fully qualified.
24. `RUNNING` from SCM means the service process is running, not that the product function is healthy. UI must label process/service state separately from functional health unless an actual health contract was probed.
25. One mutation domain has one transaction owner. Windows Services installation, Local Web adoption/update, database migration and Public deployment are distinct mutation domains unless an explicit ADR defines a shared transaction.
26. A committed transaction MUST NOT synchronously execute a second mutation domain and then relabel the first transaction as failed. Post-commit cross-domain work must be durable, separately observable and owned by the target domain's state machine.
27. Persisted descriptor/file existence is not readiness. A stale `control-plane.json`, old port file, PID file or cached health result MUST NOT be accepted as proof that the current process generation is ready. Prefer eliminating synchronous readiness coupling; when readiness is necessary, probe a fresh generation-bound contract.
28. Installer/UI lifetime is not update lifetime. Mutation intent that must survive installer exit, browser closure, process restart or Local Web outage must be persisted outside the mutable application tree and resumed by the privileged lifecycle owner.
29. User-host canaries are validation, not deterministic updater qualification. Before offering a new updater architecture to Home or Cafe, run a repeated Windows fault matrix of at least 20 completed activation/failure/rollback/recovery cycles and retain machine-readable evidence. Source build PASS alone is insufficient.
30. Automatic retry is mutation-aware and bounded. Pre-mutation transient failures may retry; once activation occurred, unattended retries stop unless an explicit recovery state machine proves that repeating activation is safe.

**Runtime business-outcome lesson (2026-10-20):** A Local trigger HTTP 200 means delivery was accepted, not that its handler succeeded. Consume `worker_completed`, `worker_success`, and safe `worker_error_code` separately; preserve request-id idempotency on incomplete results and do not replay business mutations merely because a downstream WAN operation failed. Validate both Local and Runtime halves before host deployment.

## Host evidence classification

Every host-side evidence set should record:

- `host_role = home_test | cafe_test`
- safe machine identifier
- UTC capture time
- Windows version/build
- package/component versions
- source/artifact identity under test
- transaction/correlation IDs where applicable

A Home PASS never closes a Cafe gate. Both machines may be test environments while still remaining separate environments.

## Mandatory pre-host-test capture

Before Install/Update/Repair on either host, capture:

- Windows version/build and PowerShell version
- Windows Services package registry identity if present
- `SoknaRuntime`, `SoknaPrintWorker`, `SoknaMaintenanceSupervisor`, `SoknaApache` service state/account/ImagePath/start mode
- executable versions and SHA256 where readable
- `%ProgramData%\SOKNA\Maintenance` descriptor/evidence presence
- ACL summaries without reading secret contents
- relevant loopback listener/port state
- pending reboot indicators where practical

Do not use Force to bypass an unexplained baseline mismatch.

## Known failure lessons

| Failure/near-miss | Root cause | Permanent guardrail |
|---|---|---|
| Lifecycle failed before mutation | newer .NET path API under PS5.1 | use compatible APIs; test exact PS5.1 runtime |
| SHA verification failed before mutation | `Get-FileHash` module assumption | base .NET SHA-256 for critical lifecycle verification |
| Diagnostic text became `â€”` | unsafe script encoding for PS5.1 | package `.ps1` as UTF-8 BOM; scan evidence |
| Legacy preflight used PS7-only HTTP parameter | cmdlet-version assumption | never assume PowerShell 7 parameters on PS5.1 |
| CI failure hid transaction log | collector assumed wrong DataRoot | collect from resolved transaction path |
| Headless screenshot hung | production refresh/modal path ran during test | explicit screenshot mode; no blocking modal |
| Visual defect survived geometry PASS | automated layout checks incomplete | mandatory human/agent screenshot review |
| Potential locked-file regression | SCM Stopped treated as process-exit proof | verify PID + owned-process exit |
| Unrelated components could be touched | package-level update mindset | per-component plan; true KEEP |
| Rebuild hash differed from prior CI | rebuild assumed reproducible | freeze/promote qualified artifact |
| Same-name service mutation risk | weak ImagePath ownership check | exact parsed/canonical owned-root check |
| Maintenance installed successfully but UI marked it newer/incompatible | `ProductVersion` carried engineering lineage `4.0.0-*` while manifest lifecycle target was `0.2.1` | lifecycle-visible Product/InformationalVersion must be semantic; provenance stored separately |
| Setup/diagnostics exposed black/blue console windows | privileged helper built/launched as console process | WinExe for GUI helpers; hidden PowerShell console for GUI actions |
| Restart Manager diagnostics failed on real Windows 10 | `FILETIME` was ambiguous in PS5.1 `Add-Type` C# | fully qualify interop framework types; real-host/PS5.1 compile gate |
| Dashboard said service was healthy although Print protocol probes were failing | SCM RUNNING was presented as functional health | separate service state from application health contracts |
| Windows Services update committed but R6 installer later reported generic failure | installer synchronously invoked Local adoption as a second post-commit mutation domain | one transaction owner per domain; installer only persists durable Local adoption intent |
| R6 bootstrap could race a newly restarted Supervisor | stale descriptor existence + SCM RUNNING were treated as enough readiness | durable Supervisor-owned work; never use persisted descriptor existence as current-generation readiness |
| Real host became the primary updater debugger | deterministic fault/recovery matrix was incomplete before user-visible release | >=20-cycle Windows reliability matrix before a new updater architecture reaches Home/Cafe |

## Required review checklist

Before declaring a Windows change engineering-qualified, explicitly answer:

- Which logical component owns it?
- Which mutation domain owns the transaction and durable state?
- Does any committed transaction synchronously invoke another mutation domain?
- Can the operation survive installer/UI exit and owner-process restart without user intervention?
- Is every readiness claim current-generation evidence rather than persisted-file existence or SCM state?
- Are retries bounded and prohibited after activation unless recovery explicitly authorizes them?
- For updater architecture changes, did the >=20-cycle Windows fault/recovery matrix PASS with retained evidence before user-host UAT?
- Did package/component versions change correctly and independently?
- Does it run on the actual supported Windows PowerShell/.NET environment?
- Are path APIs, cmdlet parameters and encoding compatible with PS5.1 where required?
- Do lifecycle-visible `ProductVersion`/`FileVersion` values agree semantically with the component manifest?
- Does `KEEP` remain zero-mutation?
- Are service ownership and process-exit boundaries verified?
- Are update/repair/rollback paths fail-closed?
- Are pre-UAC/elevated logs preserved?
- Can failure be reconstructed from evidence without repeatedly interrogating the machine?
- Are secrets excluded?
- Does Support Bundle work without Local Web?
- Do GUI actions avoid visible helper consoles?
- If UI changed, were final-entrypoint screenshots generated and visually reviewed?
- Are Home and Cafe evidence kept separate?
- Is exact source commit/tree + artifact digest frozen?
- Is CI/source qualification kept distinct from host UAT?

If any answer is unknown, the change is not finished.

## Required reading order for Windows work

1. `/START_HERE.md`
2. `/ARCHITECTURE.md`
3. `/PROJECT_LINEAGE.md`
4. `/docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md`
5. target component docs/README
6. relevant ADRs/contracts
7. current immutable checkpoint/handoff

When a Windows defect reveals a reusable lesson, update this document in the same engineering block so another Windows component or future agent does not repeat it.