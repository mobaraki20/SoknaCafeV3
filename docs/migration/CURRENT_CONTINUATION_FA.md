# SOKNA V3 — Current Continuation

آخرین به‌روزرسانی: 2026-09-27
وضعیت: **PRODUCT GAP CLOSURE**
مرحله فعال بعدی: **G0 — Governance Repair**

## مرجع قطعی

Audit مادر با چهار منبع انجام و ثبت شده است:
- Initial Architecture Handoff R2 (2026-09-18) — checksum-verified؛
- real `1.36.4-dev.26` source — historical business behavior/provenance؛
- post-UI source `mobaraki20/SoknaCafe @ a46435cca57df5bd5b9770efd0bb95390528aa05` — UI/Design System + post-UI behavior authority؛
- current V3 source — implementation evidence.

تصمیم‌های صریح نهایی 2026-09-27 در روش پیاده‌سازی بر تصمیم‌های قدیمی مقدم‌اند.

## ترتیب مطالعه برای هر ایجنت جدید

1. `START_HERE.md`
2. `docs/migration/CURRENT_CONTINUATION_FA.md`
3. `docs/product/MASTER_PRODUCT_INTENT_AUDIT_FA.md`
4. `docs/product/FINAL_ARCHITECTURE_DECISIONS_FA.md`
5. `docs/product/MASTER_CAPABILITY_MATRIX.csv` یا JSON
6. `docs/product/IMPLEMENTATION_ROADMAP_FA.md`
7. `docs/product/CONTINUATION_STATE.json`
8. `COMPONENTS.json`
9. فقط اسناد/contractهای component فعال

نسخه‌های کامل همین continuation در Library نیز در مسیر زیر نگه‌داری می‌شوند:
`/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/`

## تصحیح وضعیت

Automated M4–M10 روی `911d9700755508d23e30ff94fa7464eba6cfaa43` evidence مهندسی معتبر است، اما `3.0.0-rc.1` **Final Product RC نیست**. Manual UAT مرحلهٔ بعدی نیست.

Matrix مادر 50 capability دارد؛ وضعیت فعلی audit: 23 مورد P0، 15 مورد P1، 10 مورد P2 و 2 مورد P3. این priorityها برای gap closure هستند، نه میزان کیفیت core موجود.

## Preserve / do not restart

بدون evidence defect دوباره audit/rewrite نشوند:
- foundation ownership/contracts؛
- Local domain/coreهای سبز موجود؛
- Public auth/realtime/deferred backend primitives؛
- Windows Runtime core؛
- Print Agent core؛
- SCDS shared foundation؛
- Business Backup/Restore core.

## Deployment نهایی تأییدشده

- Infrastructure مستقل/external است.
- Windows Services installer فقط Runtime + Print Agent/service lifecycle را مالک است.
- Infrastructure binary و Local Web داخل installer نیستند.
- prerequisite missing/incompatible می‌تواند verified online acquisition با progress یا manual fallback داشته باشد.
- Local Web = immutable ZIP/web package + WordPress-like Browser Setup Wizard؛ بدون PS1/EXE installer.
- Public Edge = independent server deploy package.
- Local Update Center = routine central version/health/status/update orchestration.
- Public Emergency Console = break-glass health/update/rollback/recovery.
- componentها release/version مستقل با compatibility manifest و contract versioning دارند.

## No-reaudit rule

کل پروژه از صفر دوباره audit نشود مگر:
1. منبع authoritative جدیدی از کاربر برسد؛
2. یک ردیف Matrix صریحاً UNKNOWN باشد؛ یا
3. evidence جدید با audit مادر تناقض واقعی داشته باشد.

در غیر این صورت فقط همان capability/component درگیر reopen شود.

## Next — G0 Governance Repair

هدف G0 این است که continuation ambiguity دیگر تکرار نشود:
- تکمیل root `COMPONENTS.json` با tests/gates/package/version/updater/health برای هر component؛
- تثبیت ADR-0004 و supersessionهای deployment/update control plane؛
- enforce کردن vocabulary: `CORE_COMPLETE / PRODUCT_COMPLETE / RELEASE_COMPLETE`؛
- Product Parity Gate بر اساس Matrix مادر؛
- اصلاح statusهای migration که backend-only را `migrated` product نشان می‌دهند.

بعد از G0 به‌ترتیب G1..G7 در roadmap ادامه بده.
