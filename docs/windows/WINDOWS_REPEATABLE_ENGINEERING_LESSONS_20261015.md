# SOKNA Windows Engineering — Repeatable Lessons (2026-10-15)

**Scope:** reusable lessons from Windows Services 1.1.1 / Maintenance V4 installer, diagnostics, component planner, UI qualification, Home-UAT corrective work, and pre-release promotion.

This file is documentation-only. It does not change the qualified product candidate. The exact qualified binary candidate remains commit `44aad1481a9f9123c89261c1175851395e9237d3` unless a later checkpoint explicitly supersedes it.

## 1. Promote qualified artifacts; do not rebuild them for release

When an installer/binary has already passed qualification and has an exact artifact SHA:

- publish/promote that exact CI artifact to GitHub Release/Pre-release;
- do not rebuild merely to create a release asset;
- release automation must pin the exact qualification run/artifact and verify the expected SHA256 before upload;
- the release tag must target the exact qualified source commit, even if the release-workflow commit is newer and documentation-only;
- a repeated release workflow may re-upload only the same SHA-verified artifact; it must never silently replace it with a newly rebuilt binary.

Why: independent builds are not guaranteed byte-identical. Rebuilding the same semantic component version can legitimately change its fingerprint and cause a strict installed-host planner to classify it as `REPAIR`. Promotion preserves the identity that was actually qualified.

## 2. Release revisions are immutable evidence, not aliases

If an earlier UAT/pre-release exists, do not repoint or overwrite its meaning.

- create a new revision tag (`r2`, `r3`, ...);
- keep old release notes/artifact hashes as historical evidence;
- state exact source commit, qualification run, installer SHA256 and test tier in every release note;
- never make an older Home-UAT release appear to be the current qualified artifact by replacing only its asset.

## 3. Semantic component version comparison must normalize trailing zeros

Windows file/product metadata frequently exposes versions such as `1.0.5.0` while the lifecycle manifest uses `1.0.5`.

Required behavior:

- `1.0.5 == 1.0.5.0`;
- `6.2.9 == 6.2.9.0`;
- `0.2.1 == 0.2.1.0`;
- true higher/lower versions must still compare correctly;
- build/prerelease lineage must not be smuggled into lifecycle-visible semantic ProductVersion/InformationalVersion fields.

A false `installed_newer` decision is a lifecycle safety defect because it can block a valid repair/update before mutation.

## 4. Version identity and binary identity are independent signals

The planner must evaluate at least:

- semantic installed version vs target version;
- executable SHA256/fingerprint;
- provenance/build identity;
- service registration/ImagePath/start/account/recovery contract;
- ACL/config/contract drift where applicable.

Therefore:

- same version + same verified fingerprint/contracts = `KEEP`;
- same version + changed legitimate target fingerprint = `REPAIR`;
- higher unsupported version = `BLOCKED` by anti-rollback policy;
- lower version = `UPDATE` when compatible;
- version equality alone must never imply `up_to_date`.

## 5. `KEEP` is a zero-mutation contract and must be proved

For a `KEEP` component, evidence must prove:

- no service stop;
- no file copy/replacement;
- no service re-registration;
- no restart;
- unchanged executable hash/timestamp and SCM registration where the qualification scenario supports those checks.

Do not infer KEEP from a successful installer exit code.

## 6. Privileged lifecycle must revalidate after elevation

A pre-UAC UI plan is explanatory, not authoritative.

After elevation, Setup Host/lifecycle must independently re-read target package/component identity and installed state. If component action or target fingerprint changed between planning and privileged execution, fail closed before mutation.

## 7. Qualify the actual oldest supported PowerShell/runtime path

Windows PowerShell 5.1 remains a separate compatibility target.

Reusable rules:

- avoid .NET APIs unavailable to its framework runtime;
- avoid reliance on module auto-loading for security/lifecycle primitives;
- avoid PowerShell 7-only cmdlet parameters;
- dynamically compiled C#/PInvoke must compile under the supported PS5.1 host;
- packaged `.ps1` entrypoints must use PS5.1-safe encoding (current policy: UTF-8 BOM);
- evidence text should be scanned for mojibake as a qualification gate, not just visually sampled.

## 8. Service stop is not process-exit proof

Before replacing files belonging to a mutated service:

- wait for SCM `Stopped`;
- prove the service PID exited;
- prove owned child/processes under the component root exited;
- only then replace/move files.

This is especially important for native libraries such as print dependencies that can remain locked after SCM state changes.

## 9. Installer diagnostics must span pre-UAC through rollback

Generate transaction/correlation identity before elevation and propagate it through UI -> Setup Host -> PowerShell/component actions.

Evidence must survive failures in:

- elevation/UAC;
- ProgramData creation/ACL;
- extraction/copy/hash;
- SCM registration/start;
- health verification;
- rollback.

A failed install without a durable Diagnostic ID and reconstructable phase/component timeline is not sufficiently diagnosable.

## 10. UI qualification requires both geometry gates and visual review

Automated layout checks are necessary but insufficient.

For WinForms installer UI:

- render the actual production entrypoint;
- capture painted client-area screenshots, not synthetic prototypes;
- disable environment-dependent auto-refresh/modal blocking only in explicit screenshot mode;
- test compact and normal sizes (current baseline includes 960x600, 1100x660, 1280x720);
- visually inspect dashboard, component plan and failure-result states after automated checks pass;
- if a visual finding is fixed, repeat screenshot generation and review until no actionable visual defect remains.

## 11. Health state and update state must remain independent

Examples that must be representable:

- service process running + update available;
- service process running + functional health degraded;
- same semantic version + repair required due to fingerprint drift;
- target version current + pending restart.

SCM `Running` is process/service state, not proof of functional application health.

## 12. Separate Engineering qualification, Home UAT and Cafe UAT

Every evidence/result must identify its tier/host role.

- Windows Server CI PASS != real-host PASS;
- Home Test Host PASS != Cafe Test Host PASS;
- a Home finding can create a corrective candidate, but that candidate still needs its own real-host retest;
- never infer installed Cafe versions from GitHub/Library candidates.

## 13. Pre-release publication checklist

Before publishing a Windows installer pre-release:

1. freeze exact source commit/tree;
2. verify qualification CI PASS on that exact candidate;
3. identify the exact CI artifact to promote;
4. independently verify installer SHA256 and expected size;
5. create a new revision tag rather than rewriting historical UAT meaning;
6. target the exact qualified source commit;
7. upload the already-qualified artifact, not a rebuild;
8. mark the release `prerelease=true` and clearly state `NOT PRODUCTION`;
9. verify the published release asset metadata/digest after upload;
10. record release URL/tag/asset SHA in the active checkpoint/Library handoff.

## Continuation rule

If a future Windows defect reveals a lesson that applies beyond that one defect, add it to the repository's Windows required-reading/lesson set in the same engineering block. Avoid recording one-off implementation trivia as permanent policy.
