# SOKNA Cafe V3 — Project Lineage

This file preserves the engineering lineage of V3 without importing legacy coupling by default.

## Historical source

Repository: `mobaraki20/SoknaCafe`

Historical engineering baseline used to start V3:

- branch: `work/reconcile-dev39`
- commit: `a46435cca57df5bd5b9770efd0bb95390528aa05`
- commit message: `fix: load sellable dependency during default seed setup`

The historical repository remains the archive for old commits, pull requests, reviews, CI runs, releases, handoffs, phase evidence and implementation history.

## V3 continuation

Repository: `mobaraki20/SoknaCafeV3`

V3 is a direct architectural continuation of SOKNA Cafe, not an unrelated rewrite. Historical evidence is intentionally preserved by reference rather than by treating every legacy path as a V3 owner.

## Source-of-truth order during migration

1. Explicit V3 architecture, ADRs and product decisions.
2. The latest continuation authority from historical baseline `work/reconcile-dev39`, not stale `main` documentation when the two conflict.
3. Current canonical business behavior and frozen R2 product/architecture invariants that have not been intentionally superseded by V3.
4. Historical implementation details, older handoffs and experimental branches as provenance only.

If a legacy implementation conflicts with a frozen product invariant or V3 architecture, the invariant is preserved and the implementation is refactored during migration.

## Continuation authority note

At the chosen V3 baseline, `docs/handoffs/CURRENT_STATUS_FA.md` explicitly points continuation to `docs/handoffs/DEV39_RECONCILIATION_2026-09-25_FA.md`. Older sections inside handoffs are provenance when marked superseded.

This matters because some decisions on the dev.39 continuation branch are newer than the default-branch documents. V3 migration must always verify the selected baseline branch before promoting an older document to authority.

## Migration evidence rule

Every migrated capability should record:

- legacy source path/owner;
- relevant historical commit/PR/handoff when material;
- V3 target owner;
- treatment (`migrate`, `refactor_then_migrate`, `split`, `replace`, `retire`, `reference_only`, etc.);
- data owner;
- contract boundary;
- intentional behavior/UI changes, if any;
- removed duplicate/legacy owners;
- tests/gates and rollback evidence.

This prevents future maintainers or agents from having to infer why code moved or which historical implementation was authoritative.

## Canonical Design System lineage

The final Design Authority at the selected historical baseline is:

`SCDS-CANONICAL-2026-R1`

Authoritative source set:

- `docs/ui-design-system/CANONICAL_DESIGN_SYSTEM_CONTRACT_FA.md`
- `docs/ui-design-system/COMPONENT_REGISTRY.json`
- `docs/ui-design-system/PRODUCT_LANGUAGE_FA.md`
- `docs/ui-design-system/UI_DEBT_BASELINE.json`

The dev.39 status explicitly marks older `docs/UI_DESIGN_SYSTEM_FA.md` as non-authoritative compatibility material. Previous SOKNA Design System versions/freezes/R packages are rejected as Design Authority.

`1.36.4-dev.26` is UI/business provenance and useful visual DNA only after audit/correction; it is not the final Design System.

V3's `UI_DESIGN_SYSTEM.md` is the active continuation of `SCDS-CANONICAL-2026-R1` adapted to V3 boundaries. Historical ZIP/reference artifacts discussed during exploration are not V3 design authorities unless a future explicit V3 decision promotes them.

## Repository policy

The historical repository must not be deleted or repurposed while it remains the evidence archive for V3 lineage. V3 documentation should link back to exact historical refs when a decision depends on them.

The long-term goal is for V3 to own a complete self-contained authority set so future agents do not depend on mutable historical branches for day-to-day work, while exact provenance remains recoverable.
