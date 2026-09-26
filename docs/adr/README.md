# Architecture Decision Records

Material V3 decisions are recorded here as ADRs.

Create/update an ADR when a change:
- introduces or moves a component owner;
- changes a cross-component contract;
- introduces a new long-running process/service;
- changes data ownership;
- changes packaging/update/repair/recovery semantics;
- intentionally changes established UI/behavior or Design-System ownership;
- deviates from `ARCHITECTURE.md` or `UI_DESIGN_SYSTEM.md`.

An ADR should include context, decision, alternatives considered, compatibility/migration impact, tests/UAT, rollback implications and historical lineage when relevant.

Do not use an ADR to legitimize an accidental local workaround after implementation; record the decision before or with the change that depends on it.
