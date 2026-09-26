# SOKNA Cafe V3 — UI Design System

**Canonical Design Authority:** `SCDS-CANONICAL-2026-R1`

This document is the mandatory V3 UI engineering contract. It carries the latest approved SOKNA design authority forward into V3 component boundaries.

## Source authority and lineage

The authoritative historical source is:

- `mobaraki20/SoknaCafe@work/reconcile-dev39/docs/ui-design-system/CANONICAL_DESIGN_SYSTEM_CONTRACT_FA.md`
- Design System ID: `SCDS-CANONICAL-2026-R1`
- supporting registry: `docs/ui-design-system/COMPONENT_REGISTRY.json`
- product language: `docs/ui-design-system/PRODUCT_LANGUAGE_FA.md`
- migration debt evidence: `docs/ui-design-system/UI_DEBT_BASELINE.json`

Earlier SOKNA Design System versions, freezes and R-series packages are **not Design Authority**. `docs/UI_DESIGN_SYSTEM_FA.md`, older UI freezes and historical ZIP/reference artifacts remain compatibility/provenance evidence only.

`1.36.4-dev.26` is not the final Design System. It may be used only as business/UI provenance and useful visual DNA after audit and correction.

The V3 UI migration formula is:

`Audit -> Correct -> Standardize -> Migrate -> Enforce`

Architecture refactoring is not permission to copy old UI defects or to invent a new visual language per page.

## 1. Canonical Design-System Rule

SOKNA has one canonical Design System. Agents and contributors must not create page-local substitutes for an existing shared token, component, interaction pattern, visual hierarchy, state model or accessibility behavior.

Before implementing any user-facing change, the contributor must identify the current canonical owner. If no owner exists, the new reusable pattern enters the Design-System lifecycle before broad consumption.

A page is never an accidental design authority merely because it implemented a pattern first.

## 2. Single-owner rule

Every reusable UI concern has exactly one canonical owner.

A page/domain style cannot create a second generic component owner. Parallel implementations are migration debt and must be removed from the migrated scope.

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
- navigation and utility actions;
- shared state presentation.

Legacy CSS/JS/markup may remain only while an explicit migration inventory still references it. It must not silently coexist as an alternate owner after its consumers have migrated.

## 3. Pattern lifecycle

Reusable new patterns follow:

`Candidate -> QA/Review -> Registry -> Canonical Owner -> General Use`

Required steps:
1. prove an existing pattern cannot satisfy the requirement;
2. define the pattern at Design-System level, not page level;
3. define tokens, states, accessibility and responsive behavior;
4. register its owner and migration/consumer state;
5. add contract/browser/visual/device gates appropriate to risk;
6. only then allow broad reuse.

No agent may bypass this lifecycle by hard-coding a local implementation and calling it an exception without an explicit recorded decision.

## 4. Token-first foundation

Semantic tokens own product-level visual meaning. Direct values in components/domains require a reviewed exception.

Required token families include:
- semantic colors: canvas/surface/text/border/primary/accent/success/warning/danger/info/disabled/focus;
- typography: family/size/weight/line-height/number presentation;
- spacing;
- radius;
- elevation;
- motion;
- z-index;
- control/touch/density;
- content widths;
- responsive/container contracts.

`!important`, page-specific specificity patches and breakpoint-per-bug fixes are not normal solutions. Migration must ratchet these debts downward.

## 5. Core component registry

At minimum the canonical registry covers:

Button, IconButton, Input, Textarea, Select/Choice, Search, MoneyInput, JalaliDate, QuantityStepper, Checkbox/Switch, Badge/Status, Alert, Card/Surface, ListRow, DataTable/ResponsiveTable, Tabs, Toolbar/FilterBar, Disclosure, Dialog, Sheet/Drawer, Toast/InlineMessage, EmptyState, Loading/Skeleton, ErrorState, Pagination, PageHeader, AppShell and BottomNav.

Domain patterns may compose these primitives but must not redefine them.

Domain families include:
- Operational / Table / Order / Preparation;
- Inventory / Supply / Purchase;
- Finance / Settlement / Expenses / Tax;
- Printing / Infrastructure;
- Guest / Public.

## 6. Persian-first / RTL-first contract

1. Human UI is Persian and true RTL from the source, not an LTR UI flipped later.
2. `<html lang="fa" dir="rtl">` or an equivalent server-owned guarantee is required.
3. Canonical UI font is pinned Vazirmatn unless an explicit requirement changes it.
4. Layout uses logical inline/block properties; physical left/right is an exception requiring justification.
5. Technical URL/hash/code/ID content uses local bidi isolation/LTR treatment rather than changing the whole component direction.
6. Human-readable digits are Persian where appropriate; protocol/API/database values remain canonical ASCII.
7. Money presentation uses Persian digits/grouping while submit/calculation values stay canonical.
8. Human dates use one Jalali owner; backend date values remain canonical ISO/Gregorian.
9. Product vocabulary is canonical Persian and is shared by UI, search placeholders, help, user-facing errors, receipts and print labels.
10. Persian IME/search/clear/Enter/mobile-keyboard behavior is part of control ownership.

## 7. Product language

The current canonical vocabulary rules are versioned with the Design System. At migration start, the historical authority includes these decisions:

- `subscriber / cafe credit customer` -> `مشتری / مشتریان / حساب مشتری`;
- `مشترک / مشترکین / حساب مشترک` is prohibited for that customer concept;
- `direct settlement` -> `تسویه`;
- `shared` may still translate to `مشترک` where it genuinely means shared/common, such as a shared draft or cryptographic secret.

Backend/schema/API compatibility names may remain legacy English; UI vocabulary changes do not imply schema renames.

## 8. Shared state semantics

Architecture/product states use one presentation language across consumers. This includes where applicable:

- pending;
- deferred;
- needs_review;
- conflict;
- stale;
- offline;
- reconnecting;
- saving;
- publishing;
- loading;
- empty;
- error;
- disabled/read-only;
- dirty/unsaved;
- success/committed;
- retry/recovery;
- unavailable/degraded.

Pages must not independently invent conflicting colors, vocabulary or interaction behavior for the same semantic state.

## 9. Accessibility contract

- keyboard-complete interaction;
- visible shared `:focus-visible` treatment;
- focus trap/return for modal/sheet;
- Escape behavior according to critical-action policy;
- semantic HTML and valid labels/names;
- accessible name for icon-only actions;
- color is never the sole carrier of meaning;
- `prefers-reduced-motion` support for motion;
- operational touch target at least 44x44px;
- contrast plus disabled/read-only differentiation.

Accessibility is Definition of Done, not later polish.

## 10. Responsive contract

Validation covers at least:

`320, 360, 390, 412, 768, 1024, 1366/1440px`

Rules:
- no horizontal overflow at 320px unless the data surface has a documented canonical pattern;
- no new breakpoint without registry/design-system ownership;
- prefer fluid/container-aware layout over breakpoint-per-bug patches;
- mobile and desktop are one product language, not two unrelated implementations;
- desktop may increase density/width while preserving vocabulary, hierarchy and state meaning.

## 11. Surface ownership

- an independent surface owns its own border/radius;
- parent owns gap between sibling surfaces;
- card/surface exists for a real context boundary, not decorative separation alone;
- long repeated lists must not become card-per-row without operational reason;
- layout defects are fixed at the owning primitive/root cause rather than with page-local margin/specificity islands.

## 12. Action hierarchy

- record/detail identity follows the RTL reading start; utility actions use a stable utility slot;
- edit/print/download/copy utilities are limited and accessible;
- operational tasks use explicit primary/secondary controls;
- destructive actions use exception/action patterns and confirmation;
- equivalent actions should not change hierarchy from page to page without a documented workflow reason;
- quantity stepper order/behavior follows the canonical RTL contract with 44px touch targets.

## 13. Numeric, money and canonical values

- human numeric presentation may use Persian digits;
- technical IDs/URLs/codes/canonical transport values stay technical/Latin;
- localized controls use semantic `inputmode`;
- presentation is decoupled from storage/transport values;
- invalid numeric input fails validation rather than being force-cast.

## 14. Overlays, drawers and interaction classes

- compact choice -> centered modal;
- browse choice -> mobile bottom sheet / suitable desktop presentation;
- date/time -> modal;
- contextual menu -> desktop popover / mobile action sheet;
- nested overlays are prohibited by default;
- form drawers use fixed header/footer and one scrollable body;
- dirty forms require safe-close confirmation;
- protected financial/destructive actions do not gain gesture-dismiss by default;
- overlay focus/close behavior is owned centrally rather than per page.

## 15. Progressive disclosure

- low-frequency metadata does not permanently inflate primary workflows;
- optional fields can be added/removed before submit where appropriate;
- healthy no-action states do not dominate the main screen; exceptions are surfaced first;
- instruction/help and alerts are different semantics.

## 16. Error and safety

- raw stack/PDO/HTTP/exception messages never appear in ordinary UI;
- sensitive actions are retry-safe/idempotent when required;
- management side effects are not hidden behind new GET requests;
- correctness-sensitive state changes use desired-state semantics, not blind toggles;
- degraded/retry/error presentation reuses shared semantics.

## 17. Migration rule

Every touched legacy UI scope follows:

1. inventory the current surface, owner, workflow and observable behavior;
2. audit against `SCDS-CANONICAL-2026-R1`;
3. correct legacy defects instead of treating them as requirements;
4. standardize the reusable primitive/pattern and register its single owner;
5. migrate consumers;
6. remove old parallel owners/overrides from the migrated scope;
7. run Persian/RTL, 320px, keyboard/focus, state/error/loading, browser/visual and risk-appropriate UAT gates;
8. ratchet relevant legacy debt ceilings downward.

This is deliberately **not** a blind visual-preservation rule. Business workflows and valid product behavior are preserved, but defects and rejected Design-System patterns are corrected during migration.

## 18. Agent implementation protocol

Before an agent creates or changes user-facing code it must:

1. read this contract and the component registry;
2. identify the canonical owner(s);
3. identify legacy debt/consumer mappings in the migration inventory;
4. reuse or migrate canonical owners rather than create a parallel implementation;
5. if a reusable pattern is missing, register/review it before general use;
6. preserve valid business behavior while correcting rejected UI debt;
7. add the appropriate contract/browser/visual/UAT evidence;
8. remove obsolete owner(s) when the migration slice is complete.

A visually acceptable page that creates a duplicate owner, new style island or unregistered shared pattern is a regression.

## 19. Change control and Definition of Done

A UI feature/migration is Complete only when:
- business behavior regression is absent;
- canonical component/token ownership is respected;
- Persian/RTL + 320px + keyboard/focus + state/error/loading gates pass;
- no duplicate selector owner/style island is introduced;
- visual/browser/device evidence matches risk;
- registry and migration inventory reflect owner/consumer status;
- relevant UI debt does not increase without a reviewed exception.

A Design-System standard change must update in the same change set:
- this contract or a referenced canonical standard;
- component/standard registry where ownership changes;
- corresponding contract/regression tests;
- UAT/visual/device coverage when required.

## 20. Historical authority policy

The historical source files under `mobaraki20/SoknaCafe@work/reconcile-dev39/docs/ui-design-system/` are the evidence for the V3 starting authority. V3 must progressively own its own canonical registry and standards; it must not depend forever on a mutable historical branch.

Earlier Design System documents can be consulted for compatibility evidence, but cannot overrule `SCDS-CANONICAL-2026-R1` unless an explicit newer V3 ADR/product decision supersedes it.
