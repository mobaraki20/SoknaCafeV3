# SOKNA Cafe V3 — Master Product Intent Reconciliation

Status: **CANONICAL PRODUCT GAP AUDIT / PRE-RC**
Date: 2026-09-27
Audited V3 HEAD: `911d9700755508d23e30ff94fa7464eba6cfaa43`

## Authority order

1. New explicitly approved decisions/ADRs (2026-09-27) win implementation-method conflicts.
2. Initial architecture handoff R2 (`SOKNA_ARCHITECTURE_HANDOFF_STANDALONE_FINAL_R2_2026-09-18`) remains Frozen Product Intent unless explicitly superseded.
3. Legacy post-UI baseline `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05` / `1.36.4-dev.39` is observable UI/UX/behavior evidence.
4. `1.36.4-dev.26` is business provenance/historical behavior evidence, including updater lineage.
5. Current V3 source proves what exists today; absence is not proof that an intended capability was intentionally removed.

## Main finding

V3 has strong, reusable backend/component foundations, but **Product Migration is not complete**. Earlier slice completion was later treated too broadly as product completion.

Preserve:
- Local Business Authority and M1-M5 domain/backend work.
- Public auth/relay/deferred backend foundations.
- Runtime core.
- Print Agent core.
- SCDS canonical shared foundation.
- package lifecycle primitives (verify/stage/activate/rollback/repair).
- Business Backup/Restore and migration safety work.

Reopen/complete:
- Local product UI/workflows.
- Local browser setup wizard.
- Local Update Center + application updater/recovery + unified diagnostics.
- Public deployable product surfaces.
- Public updater + independent Emergency Console.
- Theme/Media/Publishing.
- Notifications.
- Printing management/template lifecycle UI.
- Marketing/Campaign/Event and Reporting/Analytics parity.
- Infrastructure/Windows packaging composition.
- M9/M10 final gates after the above are complete.

Manual UAT is **not the next phase**. It resumes only after a new RC.

## Critical product gaps

1. Local domain services exist, but the complete user-facing surfaces from the protected legacy/final product are not migrated.
2. Local initial setup is not WordPress-like yet. Required final model: immutable ZIP/web package -> browser preflight -> DB/config -> migrations -> initial admin/config -> setup lock. No Local PS1/EXE installer.
3. Local Update Center does not yet provide component version/health/status/update orchestration.
4. dev.26 had a resilient updater lineage (stable loader + versioned engines + staging/recovery/rollback); V3 currently has lifecycle primitives, not the complete product-facing Local updater/recovery surface.
5. Initial handoff explicitly requires a limited Public Emergency/Recovery Console; current Public source lacks the complete independent update/rollback/recovery surface.
6. Public backend exists but still needs complete deployable guest/remote/emergency product entrypoints.
7. Theme/Media/atomic guest publishing contract is incomplete as a product workflow.
8. Notifications end-to-end workflow is incomplete.
9. Print Agent core is strong, but printing management and print-template package lifecycle remain product gaps.
10. Unified Health/Logs/Diagnostics/Support Bundle and Public status/log projection into Local are incomplete.
11. Marketing/Campaign/Event and Reporting/Analytics existed in the audited legacy product and have not been explicitly removed by product decision; parity must be resolved.
12. ADR-0002 packaging composition is superseded where it puts Local/managed Platform payload inside the Windows setup.

## Final deployment architecture

See ADR-0004.

- Infrastructure: external/independent dependencies.
- Windows Services installer: Runtime + Print Agent/service lifecycle only.
- Third-party prerequisite binaries are not bundled; detect compatible versions, optionally acquire/install from verified sources with progress, or offer manual fallback.
- Local Web: independent immutable ZIP/web package + Browser Setup Wizard.
- Public Edge: independent server deploy package.
- Local Update Center: central visibility/orchestration, without stealing lifecycle ownership.
- Public Emergency Console: independent limited health/update/rollback/recovery when Local control path is unavailable.

## UI authority

dev.26 is not the final UI owner. The initial handoff requires the final post-UI source to be the observable UI baseline; that baseline is dev.39. V3 SCDS (`SCDS-CANONICAL-2026-R1`) is the canonical design-system owner.

UI migration is not redesign permission. Preserve approved behavior/workflow/responsive/touch/RTL/IME contracts using canonical SCDS components; do not copy legacy CSS debt as a new owner.

## Workstream status

- Foundation/contracts: CLOSED.
- Local domain/backend: KEEP, defect-only reopen.
- Public transport/auth/relay backend: KEEP.
- Runtime core: CLOSED.
- Print Agent core: CLOSED.
- SCDS foundation: CANONICAL; consumer migration OPEN.
- Local product surface/setup/lifecycle: OPEN.
- Public product surface/lifecycle: OPEN.
- Infrastructure/Windows packaging: REVISE.
- M9/M10: historical evidence preserved; final gate superseded until new architecture/product gaps are qualified.
- Manual UAT: HOLD.

## Continuation rule

Use `COMPONENTS.json`. Work on one component at a time. Do not re-audit the whole project.

If new evidence contradicts this audit, reopen only the affected capability and dependencies. The detailed 50-capability parity matrix and raw source archives are preserved in the project Library at `/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/`; chat history is not source of truth.


## Detailed audit register

The canonical detailed matrix contains **50 capabilities**: **23 P0, 15 P1, 10 P2, 2 P3**. These are gap-closure priorities, not a quality score for preserved core work. The full matrix (CSV/JSON), final decisions, roadmap, continuation state, and initial handoff archive are durably stored in the project Library under `/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/` and `/SoknaCafeV3-Handoff/00-Canonical-History/`.
