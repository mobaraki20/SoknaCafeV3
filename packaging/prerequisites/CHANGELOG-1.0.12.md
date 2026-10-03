# SOKNA Prerequisites 1.0.12 — Work Candidate

Baseline: `diag/prerequisites-1.0.11-final`
Working branch: `work/prerequisites-self-service-1.0.12-20261003`

## Scope

Only the Prerequisites manager, its installer/build path, prerequisite-specific policy, tests, qualification workflow, and documentation are changed. Local Web, Public Edge, and Windows Runtime/Print Agent behavior are not modified.

## Implemented

- Manager version moved to 1.0.12.
- New `ProgramV2` startup layer preserves existing CLI contracts while hardening UI/diagnostics.
- Top-level RTL hardening and Persian-font preference/fallback added; technical input fields remain LTR.
- Main window presentation was recomposed into a compact dashboard with a fixed branded header, fixed progress/action footer, and separate tabs for overview/run, prerequisite files, new-install MariaDB credentials, and technical details. The dashboard itself does not use horizontal or vertical AutoScroll.
- Legacy Root/Port/MariaDB controls are re-parented into responsive layouts so existing event handlers and installer logic are preserved while mirrored RTL no longer collapses LTR technical fields.
- Stable self-service diagnostic codes (`PRQ-*`) added for root/state/PHP/Apache/MariaDB/Data/port/UAC.
- Safe remediation is restricted to starting SOKNA-owned services, enriching state provenance, and selecting a free loopback port in the existing UI.
- Advanced Support Bundle v2 added with service query/config, relevant process/port evidence, SCM events, state/log/config collection, and secret-pattern redaction.
- Existing infrastructure state is enriched atomically with manager version, policy/release-lock fingerprints, and expected/detected component versions.
- Outer Inno installer now distinguishes same-version repair, upgrade, and newer-installed downgrade; downgrade is blocked before Silent Mode bypass.
- Build artifact index upgraded to v2 with source commit, release-lock hash, infrastructure-policy hash, UI hash, size and installer SHA-256.
- Self-service policy contract added at `platform/windows/prerequisites-self-service-v1.json`.
- Static v2 gate, runtime self-test, UI audit/screenshot hooks and installer lifecycle regression were added.
- Candidate GitHub Actions workflow uses strict evidence inheritance only when a diff proves core/runtime files are unchanged from a fully qualified baseline; this prevents a transient external download mirror from invalidating presentation-only changes.
- Durable engineering playbook and Persian product standard added.

## Explicit non-goals / invariants preserved

- No Local Web payload installation was moved into Prerequisites.
- No SOKNA application database/user/migration provisioning was moved into Prerequisites.
- No Public Edge behavior was changed.
- No Windows Services pairing behavior was changed.
- Existing MariaDB Data must never be deleted or reinitialized by repair/recovery.
- Automatic cross-root migration remains forbidden.

## Qualification lessons retained

- A green automated screenshot gate is not sufficient: screenshots must be manually reviewed for real clipping, RTL collapse and visible scrollbars.
- Static UI contracts assert behavior/structure markers, not exact pixel literals or obsolete implementation details.
- Network-dependent runtime regressions may be inherited only from an already-passing baseline when an explicit diff gate proves all core/runtime/dependency files are byte-history unchanged; otherwise those runtime regressions must run again.
- Release publication must use the exact qualified artifact and must not rebuild it.

## Qualification status

This is still a work candidate until the compact dashboard screenshot at 960x650, 1100x660 and 1280x720 is manually accepted and the final installer lifecycle passes. No release should be created before both conditions are true.
