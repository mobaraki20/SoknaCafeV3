# سکنا R274 — چک‌پوینت دقیق اصلاح اتصال Public موجود به Local کافه
تاریخ: ۱۷ نوامبر ۲۰۲۶
شاخهٔ QA: `qa/sokna-r274-cafe-cutover-resume-20261117`
وضعیت: **CI FULL-CORE-PASS / FIELD-NOT-VERIFIED / PRODUCTION NO-GO**

## علت قطع ارتباط واقعی کافه
Local نصب‌شده `231fg/R262` با `public_authority_resync_required` و URL قبلی `https://www.soknaspace.ir` است؛ Public مقصد `35ig/R266` در `https://www.soknagroup.ir/public` ازقبل‌جفت‌شده است. Local کد جفت‌کردن اولیه می‌خواهد ولی Public متصل آن کد را صادر نمی‌کند. GET-only خارجی [44083974235](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44083974235) نسخه و فایل مالک قدیمی را مستقیماً در مقصد اثبات کرد. Health و QR [44081529196](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44081529196) سالم هستند؛ این سلامت به معنی ارتباط نیست.

## R274 در ادامهٔ R273 چه چیزی را برطرف کرد؟
1. `pairing_uncertain` ناشی از گم‌شدن پاسخ تعویض authority، اگر مقصد Public موجود باشد، در UI به ادامهٔ ایمن و signed authority probe هدایت می‌شود؛ برای pairing تازه همچنان مسدود می‌ماند.
2. تأیید مستقل مدیر Local و رضایت مالک Public با همان operation/nonce و پس از refresh/popup-reopen از ledger قابل بازیابی است. مرورگر دوباره consent_sign نمی‌فرستد، بلکه با نشست مالک، offer_existing را فقط بعد از کنترل مجدد تأیید Local درخواست می‌کند.
3. خطای مرحلهٔ اجرای offer در صفحه باعث شروع کورکورانهٔ sync نمی‌شود؛ وضعیت واقعی journal دوباره خوانده می‌شود.
4. نسخه‌های بازسازی‌شدهٔ تجمیعی از بایت‌های دقیق R262 و R266؛ بدون تغییر مهاجرت‌ها، بدون تغییر main و CURRENT و بدون نصب روی کافه.

## نسخه‌ها و هش‌های کاندید (QA ONLY / DO NOT INSTALL)
- Local `231fk`: `SOKNA-R274-LOCAL-231FK-CAFE-CUTOVER-RESUME-QA-DO-NOT-INSTALL.zip`; SHA256 `9038abd4757ed1ed3dc3ab92e54f9266096fe73d5919d7667a1f195540991ec4`; ۹٬۱۹۹٬۷۷۵ بایت.
- Public `35ii`: `SOKNA-R274-PUBLIC-35II-OWNER-RESUME-CODE-ONLY-QA-DO-NOT-INSTALL.zip`; SHA256 `68725567e7336e0b43691ff4adae8b494b4a9c793a50a30d4564a2dab11daa92`; ۱٬۳۳۴٬۱۰۵ بایت.
- مبدأ مجاز بسته‌های فعلی **دقیقاً** Local 231fg و Public 35ig است؛ محصول یونیورسال برای تمام مبناها نشده است. فایل‌های `QA-DO-NOT-INSTALL` برچسب اطلاع‌رسانی صرف نیستند: manifest رسماً گیت field release را رد می‌کند.

## شواهد سبز
- HTTP/TLS + MariaDB با Public ریشه و زیرپوشه، ownerless legacy staff upgrade، owner consent، Local approval، zero pending guest work، full media sync، یک سفارش مهمان واقعی، duplicate exactly once، و lost reply state: [44087836405](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44087836405) PASS.
- Chromium واقعی با کد JS بایتی ZIPهای R274 در دو origin، normal popup and post-refresh same consent resume بدون consent تکراری: [44088598201](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44088598201) PASS (HTTP API test fixture؛ PHP/MariaDB در گیت مستقل بالا).
- Windows native Maintenance direct 231fg→231fk Stage→Activate→HTTP/DB readback، ۸۲ migration: [44087621464](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44087621464) PASS.
- Protected Public Emergency 35ig→35ii با HTTP واقعی login/CSRF/upload/stage/activate/commit روی fixture حفاظت‌شده و MariaDB ۱۱ migration: [44089170047](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44089170047) PASS. فایل `emergency.php` واقعاً نصب‌شده روی هاست **byte-pinned نبوده**.
- Fail-closed operator delivery guard: `operator_install_allowed=false` PASS؛ Local release-set هنوز `R274_E2E_QA_PENDING_HOST_NO_GO` و `signature=null` و qualification.real_public_host=false، Public offline_qualified=false دارد.

## تکلیف سیستم کافه
Local فعلی برای سفارش‌گیری داخلی پابرجا بماند؛ پذیرش سفارش از QR تا احراز Local receipt عملیاتی تلقی نشود. هیچ Clean Install، SQL دستی، purge ۴۴۴۲ رویداد projection، تغییر secret/epoch/URL، یا نصب ZIPهای QA انجام نشود. QR قدیمی به مقصد جدید سالم ریدایرکت می‌شود.

برای صدور release عملیاتی بدون گمانه‌زنی باید با نشست مجاز مدیر و شواهد زنده، installation_id Public را با Local تطبیق داد، realtime/deferred و سفارش‌های فعال مقصد را بررسی کرد، backup/restore قابل بازیابی و فایل محافظت‌شدهٔ Emergency روی هاست را احراز کرد و release factory مهر تأیید دهد. هیچ‌کدام از این شواهدِ live از GET عمومی Health یا تست GitHub به دست نمی‌آیند. پس **سیستم کافه هنوز وصل نشده و نصب واقعی انجام نشده است**.

بعد از تأیید field gate، ترتیب ایمن: ۱) snapshot/backup و preflight ۲) Public code-only 35ig→35ii ۳) Windows Maintenance Local 231fg→231fk ۴) اتصال از UI با رضایت مالک/مدیر و full readback ۵) یک سفارش تست با رسید Local و QR. این دستور نصب در وضعیت NO-GO فعلی نیست.

## دفتر تصمیمات
MIG-019/020 در `r251/decisions.json` و `r251/DECISIONS-FA.md` ثابت‌اند؛ R274 شواهد و patchهای جدید این تعهد را اجرا می‌کند، تصمیمِ لغو گیت انتشار صادر نمی‌کند.
