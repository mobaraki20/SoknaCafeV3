# SOKNA Cafe V3 — UI Design System

This document is a mandatory V3 product contract for the user interface. It is inherited from the canonical Sokna UI/UX rules in the historical repository and adapted to V3 component boundaries.

## Source lineage

Historical canonical sources:

- `mobaraki20/SoknaCafe/docs/UI_DESIGN_SYSTEM_FA.md`
- `mobaraki20/SoknaCafe/docs/UI_BEHAVIOR_PRESERVATION_CONTRACT_FA.md`
- `mobaraki20/SoknaCafe/docs/DECISIONS_FA.md`

During V3 migration, architecture refactoring does not authorize visual or interaction redesign. A deliberate change requires an explicit product decision and corresponding tests.

## 1. Canonical Design-System Rule

SOKNA has one canonical Design System. Agents and contributors must not create page-local substitutes for an existing shared token, component, interaction pattern, visual hierarchy, state model or accessibility behavior.

Before implementing any user-facing change, the contributor must identify the current canonical owner. If no owner exists, the new pattern must first be proposed as a Design-System addition, reviewed, documented and tested before broad consumption.

A page is not allowed to become an accidental design authority merely because it implemented a pattern first.

## 2. Single-owner rule

A reusable UI concern has one canonical owner.

Examples include:
- buttons and action hierarchy;
- typography and spacing tokens;
- colors/state semantics;
- numeric/money input behavior;
- date/time controls;
- modal/sheet/drawer behavior;
- focus/keyboard behavior;
- empty/error/loading/disabled states;
- record/list shells;
- shared financial composition;
- shared navigation and utility actions.

Parallel implementations are migration debt and must be removed from the migrated scope. Legacy CSS/JS/markup may remain only while an explicit migration plan still references it; it must not silently coexist as an alternate owner.

## 3. Pattern lifecycle

A reusable new pattern should move through this lifecycle:

`Candidate -> Review -> Canonical Owner -> Contract/Regression Gate -> General Use`

The exact storage mechanism may evolve, but these semantics are mandatory:

1. prove that an existing pattern cannot satisfy the requirement;
2. define the new pattern at Design-System level rather than page level;
3. define tokens, states, accessibility and responsive behavior;
4. add tests/visual or device UAT requirements;
5. only then allow general page consumption.

No agent may bypass this lifecycle by hard-coding a local implementation and calling it an exception without an explicit recorded decision.

## 4. Token-first implementation

Visual values that represent product semantics must come from shared tokens or explicitly scoped component tokens.

Do not hard-code page-specific alternatives for:
- primary/accent/state colors;
- typography scales/weights;
- common spacing/radius/shadow;
- focus visuals;
- common surface/border semantics;
- repeated amount/status treatments.

Scoped local tokens are allowed only when the component/family is the explicit owner and the tokens derive from the current product theme rather than creating a private theme.

## 5. Core UI invariants

1. The primary operational UI is Persian and true RTL.
2. Minimum operational touch target is 44px.
3. In RTL, primary/confirm/final actions are placed on the right; secondary/cancel/back/reject actions on the left.
4. Color alone must never communicate state; use text/icon/state semantics as well.
5. UI is mobile-first and must be validated at 320, 360, 390 and 412px before tablet/desktop.
6. Stable high-risk surfaces such as Guest, Quick Order, Settlement, financial flows and printing are changed only for an explicit requirement or proven defect.
7. Refactor is not redesign. Observable workflow, action placement, permissions, state transitions and user-visible outcomes remain stable unless explicitly approved.
8. Persian/RTL behavior is designed natively; it is not a post-processing flip of an LTR-first implementation.

## 6. Surface ownership and spacing

- Each independent surface owns its own border/radius.
- The parent owns spacing/gap between sibling surfaces.
- Do not fix layout with page-specific margin hacks, specificity patches or stacked overrides.
- Repeated long lists should not become card-per-row without an operational reason.
- Empty, filled, error and form states must be covered at mobile breakpoints.
- A new card/surface is justified by a real context boundary, not merely visual decoration.

## 7. Action hierarchy

- Record/detail headers keep identity on the RTL right side and utility actions in a stable left-side utility slot.
- Utility actions such as edit, print, download and copy are limited and icon-accessible with `aria-label`/tooltip.
- Operational tasks use explicit primary/secondary buttons.
- Destructive actions live in exception/action menus with confirmation and are not placed next to ordinary actions by default.
- The same semantic action should not change hierarchy from page to page without a documented workflow reason.

## 8. Shared component state contract

Shared components must define and reuse consistent states where applicable:

- default;
- loading;
- empty;
- error;
- disabled;
- read-only;
- dirty/unsaved;
- success/committed;
- retry/recovery;
- unavailable/degraded.

Pages must not independently invent conflicting visual or behavioral meanings for these states.

## 9. Numeric, money and canonical values

- Human-readable numeric input is rendered in Persian digits where appropriate.
- Technical IDs, URLs, codes and canonical transport values stay technical/Latin.
- Money presentation may use Persian digits and grouping, but submit/API/database values remain canonical integers.
- Localized numeric controls use semantic `inputmode`; presentation must not be coupled to canonical storage.
- Invalid numeric input must fail validation rather than be force-cast.

## 10. Overlays, drawers and interaction classes

- Compact choice -> centered modal.
- Browse choice -> mobile bottom sheet; suitable desktop presentation.
- Date/time -> modal.
- Contextual menu -> desktop popover / mobile action sheet.
- Nested overlays are prohibited by default.
- Form drawers have fixed header/footer and one scrollable body.
- Dirty forms require safe-close confirmation.
- Protected financial/destructive modals do not gain gesture-dismiss behavior by default.
- Overlay focus/close behavior is shared behavior, not reimplemented per page.

## 11. Progressive disclosure

- Low-frequency metadata must not permanently inflate the primary workflow.
- Optional fields may be added/removed by the user before submit.
- Healthy states without an action should not dominate the main screen; surface exceptions first.
- Instruction/help and alerts are distinct UI semantics.

## 12. Error, state and safety

- Raw technical errors (`Failed to fetch`, stack traces, PDO errors, raw exception messages) never appear in ordinary UI.
- Sensitive actions must be retry-safe/idempotent where required.
- New management side effects must not be hidden behind GET requests.
- State changes use explicit desired-state semantics rather than blind toggles when correctness matters.
- Error, retry and degraded-state presentation must reuse shared semantics rather than page-local wording/colors alone.

## 13. Accessibility and focus

- Shared focus styling must be clearly visible without layered ring hacks.
- Closing an overlay restores focus to its trigger when applicable.
- Icon-only utility actions require accessible labels.
- State must not be communicated only by color.
- Keyboard/IME behavior belongs to shared control owners when the same interaction recurs across pages.

## 14. Date/time controls

- Jalali calendar layout remains structurally stable; month navigation must not cause modal height jumps.
- Month navigation arrows remain available; swipe is enhancement, not the only control.
- Custom date controls remain coherent composite controls rather than overlapping icon hacks.
- Time controls use explicit semantic minute steps and keep human presentation separate from canonical 24h values.

## 15. Responsive contract

Mobile and desktop are one product language, not two unrelated UIs.

- Prefer one data model and semantic markup across breakpoints.
- Desktop may increase density and workspace width but must retain vocabulary, hierarchy and state meaning.
- Do not create simultaneous "desktop table + mobile card" implementations for the same feature unless a documented operational requirement proves it necessary.
- Responsive acceptance must cover at least 320/360/390/412 and relevant tablet/desktop widths for the feature family.

## 16. Agent implementation protocol

Before an agent creates or changes a page, it must:

1. read this file;
2. identify existing shared owners/patterns relevant to the page;
3. reuse those owners rather than recreate them;
4. identify any missing pattern;
5. if a reusable pattern is missing, update/propose the Design System before implementing a local substitute;
6. preserve current approved behavior unless explicit change approval exists;
7. include appropriate contract/browser/visual/UAT coverage.

A change that introduces an undocumented parallel pattern should be treated as a regression even if the page appears visually acceptable in isolation.

## 17. Migration rule

For every migrated Local/Public UI scope:

1. capture the legacy observable baseline;
2. identify the canonical V3 UI owner;
3. move/refactor implementation without changing observable behavior;
4. remove duplicate/legacy UI owners in the migrated scope;
5. run contract/browser/visual regression gates;
6. mark device-sensitive behavior as `UAT_REQUIRED` until tested on real target devices.

Anything historically built or tested that conflicts with the canonical Design System must be corrected during migration rather than copied forward unchanged.

Any intentional redesign must be explicitly marked and documented as `REDESIGN_APPROVED` or `BEHAVIOR_CHANGE_APPROVED` before merge.

## 18. Change control

A UI standard change is incomplete unless the same release also updates:

- this Design-System contract or a referenced standards register;
- the corresponding contract/regression tests;
- UAT coverage when device/visual verification is required.

The Design System is therefore both documentation and an enforceable engineering contract.
