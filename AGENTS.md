# قواعد الزامی ایجنت‌ها — مسیر فعال Local/Public پس از R278

**این شاخه از شاخه‌های QA قدیمی توسعه یافته و فایل AGENTS.md مرکزی در آن وجود نداشت.** هدف این فایل حفظ اصول تحویل نسخه است، نه تغییر کد سامانه یا صدور مجوز نصب.

## فقط یک درگاه تأیید نسخه

برای هر نسخه جدید Local یا Public که از این شاخه مشتق می‌شود، اصل سورس و تاریخچه REG-003/ACCEPT/HOLD را بررسی کن؛ سپس سازنده واقعی همان مؤلفه را حفظ کن و بعد از Build، فایل ZIP را **بدون تغییر** در Artifact همان GitHub Run قرار بده. `candidate-contract.json` باید SHA256 همان فایل، component/kind/version، source-tree evidence و محدودیت‌های Database/Host را دقیق ذکر کند.

باید Workflow مرکزی را به‌عنوان **Job وابسته و الزامی** فراخوانی کنی:

```yaml
  factory-exact-candidate:
    needs: [build-and-owner-qa]
    uses: mobaraki20/SoknaCafeV3/.github/workflows/sokna-release-factory.yml@main
    with:
      track: registered-candidate
      candidate_contract_path: artifact:candidate-contract.json
      candidate_run_id: ${{ github.run_id }}
      candidate_artifact: EXACT-ORIGINAL-PRODUCER-ARTIFACT
    permissions:
      contents: read
      actions: read
```

`build-and-owner-qa` نام Job موجودِ سازنده است؛ ابتدا همین Job آزمایش واقعی MariaDB/Windows/Public و اعتبار فایل را طبق مؤلفه انجام دهد. نام دقیق Artifact باید جایگزین شود. **هیچ ZIP خارج از گیت مرکزی را به‌عنوان Release رسمی یا قابل نصبِ تأییدشده به کاربر نده**. نبودِ رسید همان SHA = `NO_FACTORY_RECEIPT_VERIFIED`. شکست یا Skip شدن گیت = BLOCKED، نه هشدار قابل چشم‌پوشی.

پیش از تحویل هر فایل باید به‌طور صریح گزارش بدهی: ۱) Producer Run، ۲) Factory Run و Factory Used، ۳) ZIP SHA، ۴) Source Authority، ۵) Baselines و DB migrations/forward fence، ۶) Windows/Maintenance/Print Agent یا Public Routine/Emergency متناسب با مؤلفه، ۷) Backup/Restore و پذیرش کافه/خانه/هاست واقعی، ۸) تصمیم Production. CI موفق به‌تنهایی به معنی پذیرش میدانی نیست. در هیچ فرایندی حق تغییر مستقیم پایگاه داده یا سرویس‌های عملیاتی کافه، Public یا نسخهٔ نصب‌شده را نداری.

## مبنای واقعی R278

- SHA نصب‌کننده بازتولیدشده Local 231fn: `3a1070e18b14307fb2571634c961b20ca3e5d38e6c9a9dc1afad68be89fe6824`.
- **Factory gate:** در اجرای [44298272934](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44298272934) پس از ساخت با سازنده R278 و QA MariaDB، گیت اصلی و گزارش نهایی موفق بودند. این موفقیت مجوز نصب عملیاتی نیست.
- روند اصلی همان `.github/workflows/sokna-r278-full-currency-sync.yml` است که در PR #99 به کارخانه متصل شده؛ **سازنده موازی جدید نساز**.
- R277 و R276 و نسخه‌های قدیمی‌تر طبق دفتر [Factory Coverage](https://github.com/mobaraki20/SoknaCafeV3/blob/main/release-ops/FACTORY_ADOPTION_STATUS_20261118_FA.md) بدون رسید قطعی کارخانه باقی می‌مانند مگر اینکه SHA و Run واقعی خود آنها مستقلاً تأیید شود.
- خواندن آخرین [راهنمای مرکزی کارخانه](https://github.com/mobaraki20/SoknaCafeV3/blob/main/release-ops/UNIFIED_CANDIDATE_INTAKE_RUNBOOK_FA.md) قبل از هر Build الزامی است، زیرا شاخه‌های قدیمی QA لزوماً ابزار Release Ops در `main` را ندارند.

**محدودیت:** این قواعد و Workflow جدید بدون Repository Ruleset/Required Checks در GitHub نمی‌توانند به‌صورت سیستمی اجرای تمامی مسیرهای دلخواه یا آپلودهای Library را متوقف کنند. هر ایجنت موظف است تحویل بدون رسید را مردود اعلام کند. برای PrintAgent و Maintenance، کنترل فرمت و نصب اختصاصی لازم است؛ هرگز آن را با گیت فرمت Local/Pubic اشتباه نگیر.
