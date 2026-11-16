# R258 — ادامه از R257: ورودی سادهٔ انتقال وب عمومی (QA، بدون انتشار)

**تاریخ:** ۱۶ نوامبر ۲۰۲۶  
**شاخه:** `qa/sokna-r258-universal-public-move-ui-20261116`  
**مبنا:** `qa/sokna-r257-existing-public-consent-receipt-20261116`  
**وضعیت:** پیاده‌سازی گام اول UI/HTTP Local، آزمون CI در حال ارزیابی؛ **Production NO-GO**.

## هدف تغییرناپذیر
موتور انتقال Local/Public برای هر کافه و هر نصب مستقل، با دامنه و زیرپوشه و نسخهٔ متفاوت؛ بدون ثابت‌های مشتری، بدون SQL یا Emergency در مسیر معمول کاربر، و با حفاظت از داده و یک authority فعال. تصمیم‌های `MIG-001..MIG-017` و تاریخچهٔ `OLD-001..OLD-010` در `r251/decisions.json` معتبرند؛ این مرحله هیچ تصمیم محصولی را تغییر نمی‌دهد.

## آنچه در R258 واقعاً اضافه شد
1. در همان بخش «اتصال‌ها ← وب عمومی» بالای جزئیات فنی یک فرم تک‌فیلدی «آدرس جدید وب عمومی» و یک دکمهٔ «بررسی و انتقال» قرار گرفت؛ ابزار قدیمی rebind همان نصب در بخش پیشرفته قرار دارد. هیچ تب یا گردش‌کار جدیدی به اپراتور تحمیل نشد.
2. درخواست AJAX فقط `base_url` می‌فرستد، نه `verified`، نه `owner_approved`، نه کلید و نه receipt. API موجودِ `integrations/api.php` همچنان ورود مدیر واقعی Local و CSRF را با `WebAction::requireMutation()` الزامی دارد.
3. سرویس جدید `PublicMoveUiInspectionService` بر `PublicMoveDecisionEngine` بنا شده؛ آدرس نامعتبر، اتصال برابر، وضعیت بازیابی و نبود اتصال فعلی را بدون ارسال HTTP رد می‌کند. در صورت وجود اتصال فعال تنها به preflight امضاشدهٔ **همان نصب** که قبلاً در `PublicOriginRebindService` وجود دارد مراجعه می‌کند.
4. اگر پیش‌بررسی همان نصب واقعاً موفق و همسان با URL های معتبر باشد، فقط `ready_for_review` برمی‌گرداند. اگر امضا، هویت یا اتصال معتبر نباشد، نتیجه `awaiting_owner` یا blocked است. نتیجهٔ mismatch **دلیل قطعی مستقل بودن مقصد نیست**. هیچ تغییر آدرس، رمز، مالی، چاپ، migration، ledger، سفارش‌گیری یا cutover رخ نمی‌دهد.
5. PHP/JS lint، آزمون سناریوهای root/subfolder، URL آلوده، وضعیت recovery، شواهد جعلی/ناقص، نقص شبکه و ممنوعیت cutover در CI مخصوص R258 قرار گرفتند.

## مرز مهم: چه چیزی هنوز انجام نشده
- صفحهٔ مالک مقصد در Public موجود/تازه، انتقال امن challenge و receipt روی HTTP و اتصال واقعی تأیید جداگانهٔ مدیر Local به ledger رسمی هنوز پیاده‌سازی و آزمون E2E نشده‌اند.
- سرویس R257 `PublicMoveLocalApprovalService` هنوز به کوکی و نشست واقعی Local متصل نیست؛ صرف داشتن کلاس امنیتی کافی نیست.
- انتقال Public مستقل، backfill/readback، سفارش‌های معلق، fencing/authority epoch، commit، rollback، اصلاح login/PWA/push/QR، نسخه‌های متفاوت و قطع برق/شبکه هنوز **غیرمجاز**ند.
- اعتبارسنجی DNS مقصد در برابر DNS rebinding/SSRF، کلیدهای محیط واقعی، هاست مشترک چندنصبی و تطبیق بایتی سورس Local با بستهٔ منتشرشده همچنان گیت‌های پیش از انتشارند. preflight موجود نباید به ابزار انتقال مستقل تعمیم داده شود.
- انتقال/بازیابی Local و وضعیت Windows Runtime/Print Agent در فاز بعدی است.
- هیچ بستهٔ قابل نصب، فایل updater، deployment، تغییر `main` یا `CURRENT` ساخته/اعمال نشده است.

## دستور ادامهٔ دقیق
1. پیش از هر کد تازه `r251/DECISIONS-FA.md`، `r251/decisions.json`، `r257/START-HERE-FA.md`، `r257/STATUS.json` و این فایل و `r258/STATUS.json` را بخوان.
2. نتیجهٔ CI `.github/workflows/sokna-r258-public-move-ui.yml` را بررسی؛ شکست‌ها را رفع و STATUS را به‌روز کن. PASS آزمون واحد هرگز Production PASS نیست.
3. روی سورس واقعی R249/35IC Public با ۸ migration پایه و overlayهای 0009/0010 ادامه بده؛ owner consent screen را با cookie HttpOnly، session واقعی، Origin/CSRF و challenge server-bound بساز. برای Local با session واقعی و ledger مجزا؛ به مرورگر برای نتیجهٔ proof اعتماد نکن.
4. سپس orchestrator ادامه‌پذیر با preflight بدون تغییر، snapshot، media+finance+pending orders reconciliation، epoch fencing و readback بنویس؛ اول روی دو مشتری ساختگی با root/subfolder و نسخه‌های متفاوت، قطع شبکه/برق و recovery آزمون کن.
5. فقط پس از تأیید end-to-end، تطبیق با payloadهای واقعی و کسب موافقت کاربر، انتشار نهایی را بررسی کن.

## فایل‌های تغییرکرده
- `apps/local-web/src/Domain/PublicEdge/PublicMoveUiInspectionService.php`
- `apps/local-web/public/integrations/api.php`
- `apps/local-web/public/integrations/index.php`
- `apps/local-web/public/assets/connections-workspace.js`
- `r258/test-local-ui-inspection.php`
- `.github/workflows/sokna-r258-public-move-ui.yml`

**اصل ایمنی:** در این مرحله `safe_to_cutover=false` مستقل از پاسخ مقصد؛ receipt، preflight و مالکیت هرگز جای گیت commit نهایی نیستند.
