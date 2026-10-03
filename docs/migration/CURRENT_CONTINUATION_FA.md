# SOKNA V3 — Current Continuation

آخرین به‌روزرسانی: 2026-09-27
وضعیت: **PRODUCT GAP CLOSURE**
مرحله فعال بعدی: **G0 — Governance Repair**

## Canonical master audit

Audit کامل با این چهار مرجع انجام شده است:
- Initial Architecture Handoff R2 (2026-09-18), checksum-verified;
- real `1.36.4-dev.26` source for historical business behavior;
- post-UI source `mobaraki20/SoknaCafe @ a46435cca57df5bd5b9770efd0bb95390528aa05` for UI/Design System and post-UI behavior;
- current V3 source on this branch.

Full durable continuation artifacts are stored in the project Library at:

`/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/`

Required read order there:
1. `00_READ_ME_FIRST_FA.md`
2. `06_MASTER_PRODUCT_ARCHITECTURE_AUDIT_FA.md`
3. `08_FINAL_ARCHITECTURE_DECISIONS_FA.md`
4. `07_MASTER_CAPABILITY_MATRIX.csv` / JSON
5. `10_EXECUTION_ROADMAP_FA.md`
6. `09_CONTINUATION_STATE.json`

The original initial handoff is durably stored at:
`/SoknaCafeV3-Handoff/00-Canonical-History/SOKNA_ARCHITECTURE_HANDOFF_STANDALONE_FINAL_R2_2026-09-18.zip`

## Master capability register

- Canonical matrix: **50 capabilities** — 23 P0 / 15 P1 / 10 P2 / 2 P3.
- Full CSV/JSON + audit + roadmap + continuation state: `/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/` in the project Library.
- Initial handoff archive: `/SoknaCafeV3-Handoff/00-Canonical-History/SOKNA_ARCHITECTURE_HANDOFF_STANDALONE_FINAL_R2_2026-09-18.zip`.
- Verified source hashes: initial handoff ZIP `9962187b9ab0f2a246f276aa5cac4e7ab971bfe08c7cd9b006efbe765094d945`; dev.26 ZIP `1a1d0723edb5cf2ebdb6e7f37925b08ca729d82ceb283089183fbca61439e4dc`.

## Important correction

Automated M4–M10 evidence on `911d9700755508d23e30ff94fa7464eba6cfaa43` remains valid engineering evidence, but `3.0.0-rc.1` is **not** a Final Product RC. Manual UAT is not the next stage.

The main gap was introduced during the clean V3 extraction: many backend/contracts were moved, while required product UI/workflows, some adapters/domains, updater/recovery surfaces and final deployment composition were not fully moved.

## Preserve / do not restart

Keep closed unless defect evidence says otherwise:
- foundation ownership/contracts;
- existing Local domain/core work that is green;
- Public auth/realtime/deferred backend primitives;
- Windows Runtime core;
- Print Agent core;
- SCDS shared foundation;
- Business Backup/Restore core.

## Final deployment decisions

- Infrastructure is external/independent.
- Windows Services installer owns Runtime + Print Agent/service lifecycle only.
- Infrastructure binaries and Local Web are not bundled in that installer.
- Missing/incompatible prerequisites may be offered as verified online download with progress or manual fallback.
- Local Web is an independent ZIP/web package with a WordPress-like Browser Setup Wizard; no PS1/EXE installer.
- Public Edge is an independent server deploy package.
- Local Update Center is the normal central component health/version/status/update control surface.
- Public Emergency Console is the independent break-glass health/update/rollback/recovery path.
- Components may have independent versions/releases with versioned contracts and compatibility manifest.
- Monorepo remains one repo, but agents work one explicitly registered component scope at a time.

## No-reaudit rule

Do not restart source/architecture audit from zero unless:
1. the user provides a newer authoritative source/handoff;
2. the canonical capability matrix has an explicit UNKNOWN requiring source inspection; or
3. new implementation evidence contradicts the master audit.

Otherwise continue directly from the active workstream.

## Next — G0 Governance Repair

Deliverables:
- root machine-readable Component Registry;
- superseding ADRs for deployment separation/update control plane;
- completion vocabulary `CORE_COMPLETE / PRODUCT_COMPLETE / RELEASE_COMPLETE`;
- Product Parity Gate;
- correction of migration statuses so backend-only completion is never presented as complete product migration.

After G0, continue sequentially with Local product parity, Local setup/update/control plane, Public productization/emergency updater, remaining capability parity, Windows packaging revision, new M9/M10, and only then Manual UAT.
