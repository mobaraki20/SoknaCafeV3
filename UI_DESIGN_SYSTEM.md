# SOKNA Cafe V3 — UI Design System

This document is a mandatory V3 product contract for the user interface. It is inherited from the canonical Sokna UI/UX rules in the historical repository and adapted to V3 component boundaries.

## Source lineage

Historical canonical sources:

- `mobaraki20/SoknaCafe/docs/UI_DESIGN_SYSTEM_FA.md`
- `mobaraki20/SoknaCafe/docs/UI_BEHAVIOR_PRESERVATION_CONTRACT_FA.md`
- `mobaraki20/SoknaCafe/docs/DECISIONS_FA.md`

During V3 migration, architecture refactoring does not authorize visual or interaction redesign. A deliberate change requires an explicit product decision and corresponding tests.

## Core UI invariants

1. The primary operational UI is Persian and true RTL.
2. Minimum operational touch target is 44px.
3. In RTL, primary/confirm/final actions are placed on the right; secondary/cancel/back/reject actions on the left.
4. Color alone must never communicate state; use text/icon/state semantics as well.
5. UI is mobile-first and must be validated at 320, 360, 390 and 412px before tablet/desktop.
6. Stable high-risk surfaces such as Guest, Quick Order, Settlement, financial flows and printing are changed only for an explicit requirement or proven defect.
7. Refactor is not redesign. Observable workflow, action placement, permissions, state transitions and user-visible outcomes remain stable unless explicitly approved.

## Surface ownership and spacing

- Each independent surface owns its own border/radius.
- The parent owns spacing/gap between sibling surfaces.
- Do not fix layout with page-specific margin hacks, specificity patches or stacked overrides.
- Repeated long lists should not become card-per-row without an operational reason.
- Empty, filled, error and form states must be covered at mobile breakpoints.

## Action hierarchy

- Record/detail headers keep identity on the RTL right side and utility actions in a stable left-side utility slot.
- Utility actions such as edit, print, download and copy are limited and icon-accessible with `aria-label`/tooltip.
- Operational tasks use explicit primary/secondary buttons.
- Destructive actions live in exception/action menus with confirmation and are not placed next to ordinary actions by default.

## Numeric, money and canonical values

- Human-readable numeric input is rendered in Persian digits where appropriate.
- Technical IDs, URLs, codes and canonical transport values stay technical/Latin.
- Money presentation may use Persian digits and grouping, but submit/API/database values remain canonical integers.
- Localized numeric controls use semantic `inputmode`; presentation must not be coupled to canonical storage.
- Invalid numeric input must fail validation rather than be force-cast.

## Overlays, drawers and interaction classes

- Compact choice → centered modal.
- Browse choice → mobile bottom sheet; suitable desktop presentation.
- Date/time → modal.
- Contextual menu → desktop popover / mobile action sheet.
- Nested overlays are prohibited by default.
- Form drawers have fixed header/footer and one scrollable body.
- Dirty forms require safe-close confirmation.
- Protected financial/destructive modals do not gain gesture-dismiss behavior by default.

## Progressive disclosure

- Low-frequency metadata must not permanently inflate the primary workflow.
- Optional fields may be added/removed by the user before submit.
- Healthy states without an action should not dominate the main screen; surface exceptions first.
- Instruction/help and alerts are distinct UI semantics.

## Error, state and safety

- Raw technical errors (`Failed to fetch`, stack traces, PDO errors, raw exception messages) never appear in ordinary UI.
- Sensitive actions must be retry-safe/idempotent where required.
- New management side effects must not be hidden behind GET requests.
- State changes use explicit desired-state semantics rather than blind toggles when correctness matters.

## Accessibility and focus

- Shared focus styling must be clearly visible without layered ring hacks.
- Closing an overlay restores focus to its trigger when applicable.
- Icon-only utility actions require accessible labels.
- State must not be communicated only by color.

## Date/time controls

- Jalali calendar layout remains structurally stable; month navigation must not cause modal height jumps.
- Month navigation arrows remain available; swipe is enhancement, not the only control.
- Custom date controls remain coherent composite controls rather than overlapping icon hacks.
- Time controls use explicit semantic minute steps and keep human presentation separate from canonical 24h values.

## Migration rule

For every migrated Local/Public UI scope:

1. capture the legacy observable baseline;
2. identify the canonical V3 UI owner;
3. move/refactor implementation without changing observable behavior;
4. remove duplicate/legacy UI owners in the migrated scope;
5. run contract/browser/visual regression gates;
6. mark device-sensitive behavior as `UAT_REQUIRED` until tested on real target devices.

Any intentional redesign must be explicitly marked and documented as `REDESIGN_APPROVED` or `BEHAVIOR_CHANGE_APPROVED` before merge.

## Change control

A UI standard change is incomplete unless the same release also updates:

- this design-system contract or a referenced standards register;
- the corresponding contract/regression tests;
- UAT coverage when device/visual verification is required.
