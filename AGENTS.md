# SOKNA — mandatory release discovery for humans and agents

Before **any** request to create, rebuild, verify, share, publish or install a Local/Public Update or Clean Install package, **read [release-ops/READ_FIRST_FA.md](release-ops/READ_FIRST_FA.md) first**, even when the user does not mention GitHub or a workflow.

This applies to natural-language requests such as «بسته به‌روزرسانی بساز»، «کلین اینستال منتشر کن»، «لینک آخرین نسخه»، and requests about Routine or Emergency. It is not authorization to deploy or modify an installed host.

1. Consult the canonical Updater v1.0.3 decision, UUP-01 / Issue #48, REL-GATE-001, QA-001, REG-003, and the release-ops incident/asset map. Newer owner-approved decisions supersede historical experiments; historical PR #47 signer requirements are NOT active policy.
2. **Never assume `main`, a branch named final, `VERSION.txt`, or the latest GitHub commit contains the current accepted production source.** Exact source/release artifact identity and accepted-fix preservation must be proven; otherwise STOP.
3. Before packaging, verify the immutable source ZIP with `release-ops/source_gate.py --checkpoint <pinned-checkpoint> --source <ZIP> --component <local|public>` and `release-ops/source-register.json`. Its ORIGINAL `VERSION.txt` is not necessarily the target release version. Use existing builders and validators where applicable. Do not generate a new engine, per-machine updater, signing scheme or route-specific Public ZIP.
4. Local has **two distinct updater contracts**: legacy Local component-package and Windows Maintenance release-set v2.1. Choose from verified installed owner, not file naming. Public clean root ZIP may intentionally have no manifest, unlike historical deploy-v1; use the correct explicit kind. Before any claim of installable release, inspect the **exact final ZIP** with `python3 release-ops/audit.py`, historical-baseline provenance and the actual component-specific gates; distinguish static audit from Windows, MariaDB, real-host and business UAT.
5. Publication automation work is confined to `release-ops/**`, `.github/workflows/sokna-release-assurance.yml`, and this instruction. **Do not edit** `apps/**`, `windows/**`, `platform/**`, `packaging/**`, `contracts/**`, `CURRENT`, existing installers, Maintenance, Runtime, Print Agent or databases under this scope.
6. Every release request reports `PASS / FAIL / NOT_RUN`, artifact SHA256, source provenance and blocking reasons. No green source-only run can mean successful field installation.

This policy is intentionally discoverable at repository root and portable for use with or without GitHub Actions.

**Historical source replay (currently R233 only):** If the approved source checkpoint is `R233-20261115`, use `.github/workflows/sokna-release-source-replay-r233.yml` and verify its two byte-exact source ZIPs and noninstallable Public root-clean QA report. This is *not* a general Latest resolver or an installable package builder. The pinned R204 parent originates from an expiring GitHub Actions archive; if it expires, STOP and restore immutable source provenance through an approved durable archive rather than guessing a replacement.
