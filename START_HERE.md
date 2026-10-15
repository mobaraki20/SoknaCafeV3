# START HERE — SOKNA Cafe V3

This is the canonical operational entrypoint for every human or agent.

## Current truth

SOKNA V3 has strong validated backend/component foundations, but product migration is **not complete**. Current phase is `PRODUCT GAP CLOSURE`; `G0 — Governance Repair` is complete and the active workstream is `G1 — Local Web Product Parity`. Do not start from the historical `3.0.0-rc.1 / Manual UAT next` assumption.

Read in this exact order:

1. `docs/migration/CURRENT_CONTINUATION_FA.md`
2. `docs/product/MASTER_PRODUCT_INTENT_AUDIT_FA.md`
3. `docs/product/FINAL_ARCHITECTURE_DECISIONS_FA.md`
4. `docs/product/MASTER_CAPABILITY_MATRIX.csv` or `.json`
5. `docs/product/IMPLEMENTATION_ROADMAP_FA.md`
6. `docs/product/CONTINUATION_STATE.json`
7. `docs/product/WORKSPACE_CHECKPOINT_POLICY_FA.md`
8. `COMPONENTS.json`
9. `ARCHITECTURE.md` / relevant ADRs and contracts only as needed for the active component
10. UI/Design System authority for any user-facing work

Historical M9/M10/Final Integration documents remain engineering evidence, not product-completion authority.

## Source hierarchy

1. Latest explicitly approved final V3 decisions/ADRs win implementation-method conflicts.
2. Initial Architecture Handoff R2 remains Frozen Product Intent unless explicitly superseded.
3. Post-UI baseline `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05` owns observable final UI/UX and post-UI behavior evidence.
4. `1.36.4-dev.26` is business provenance/historical behavior evidence.
5. Current V3 source proves what exists today; missing code does not silently delete intended product scope.

## Component rule

One monorepo, independently releasable components, explicit contracts. Work on one registered component/workstream at a time. Do not re-audit closed cores without evidence defect or explicit contract impact.

## Final deployment decision

`docs/adr/0004-deployment-and-component-lifecycle-v2.md` supersedes the old unified Local-Web/Platform composition where relevant:
- Local Web: ZIP + Browser Setup Wizard; no PS1/EXE installer.
- Infrastructure prerequisites: detect / verified online acquire / manual fallback; not bundled.
- Public Edge: independent deploy package.
- Local Update Center + Public Emergency Console are required product surfaces.

For SOKNA-owned Windows components, the newer accepted decision is:

`docs/adr/0009-maintenance-v4-windows-services-composition.md`

ADR-0009 supersedes ADR-0004 **only** where ADR-0004 listed the Windows Services installer as Runtime + Print Agent only. The unified Windows Services lifecycle now owns these primary logical components:

- Runtime (`SoknaRuntime`)
- Print Agent (`SoknaPrintWorker`)
- Maintenance Supervisor (`SoknaMaintenanceSupervisor`)

Maintenance Update Engine and Recovery Console are versioned subordinate Maintenance components; they are not additional permanent services by default. Local Web and third-party infrastructure remain outside the Windows Services payload as defined by ADR-0004.

### Mandatory Windows engineering reading

Before changing anything under or affecting `windows/**`, `platform/windows/**`, `packaging/windows/**`, Windows prerequisites, Windows services, installer/lifecycle code, ACLs, diagnostics, recovery tooling or Windows CI, every human/agent **must** read:

`docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md`

This file records reusable failure lessons and hard compatibility/lifecycle guardrails. When a new Windows defect reveals a reusable lesson, update it in the same engineering block.

## Completion language

`CORE_COMPLETE` is not `PRODUCT_COMPLETE`, and neither is `RELEASE_COMPLETE`. Never use plain `migrated/closed` as a product-completion claim without the level.

## Durable continuation

The same master continuation set and the original initial handoff are also preserved in the project Library. If a new chat starts, use these repo documents first; do not reconstruct the project from chat history.

Manual UAT resumes only after G0–G6 gap closure, new release qualification, and a new RC.
