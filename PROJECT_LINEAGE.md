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

1. V3 architecture/ADRs and explicit V3 product decisions.
2. Current canonical behavior and Design System in the historical SOKNA source, where V3 has not intentionally changed it.
3. Frozen architecture/product decisions in historical handoffs/decision documents.
4. Historical implementation details and older experimental branches.

If a legacy implementation conflicts with a frozen product invariant or V3 architecture, the invariant is preserved and the implementation is refactored during migration.

## Migration evidence rule

Every migrated capability should record:

- legacy source path/owner;
- relevant historical commit/PR/handoff when material;
- V3 target owner;
- intentional behavior changes, if any;
- removed duplicate/legacy owners;
- compatibility/contract impact;
- test and rollback evidence.

This prevents future maintainers or agents from having to infer why code moved or which historical implementation was authoritative.

## Historical Design System lineage

Canonical UI/UX references inherited into V3 include:

- `docs/UI_DESIGN_SYSTEM_FA.md`
- `docs/UI_BEHAVIOR_PRESERVATION_CONTRACT_FA.md`
- `docs/DECISIONS_FA.md`

V3's `UI_DESIGN_SYSTEM.md` is the active product contract derived from those canonical rules. The historical ZIP/reference artifacts discussed during exploration are not V3 design authorities unless a future explicit decision promotes them.

## Repository policy

The historical repository must not be deleted or repurposed while it remains the evidence archive for V3 lineage. V3 documentation should link back to exact historical refs when a decision depends on them.
