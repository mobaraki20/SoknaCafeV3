# سوابق شکست‌های انتشار که باید به کنترل قابل‌تکرار تبدیل شوند
**مرجع:** QA-001 + REL-GATE-001 + GitHub Issues #48/#67 و اسناد R158/R233، آبان ۱۴۰۵. این سند ریشهٔ خطاهای *ثبت‌شده* را نگه می‌دارد؛ فرضیهٔ اثبات‌نشده را قطعی نمی‌نامد.

| شناسه/رویداد | شاهد تاریخی | کنترل پیش از تحویل |
|---|---|---|
| **R75 / 231cb / Maintenance 0.3.18** | Full و Delta با VERSION/tree یکسان ReleaseId متفاوت داشتند؛ سه هویت تاریخی Delta از manifest جا افتاده بود؛ \`PACKAGE_PREFLIGHT_REJECTED\` | دفتر مبناهای **اصل آرشیو** با ۶ فیلد و SHA آرشیو، تست جاافتادگی Full/Delta و native Preflight |
| **R99 / 35gg** | دو ZIP متفاوت با شناسهٔ کوتاه 35gg؛ بسته Hall-only فایل guest-contract.css و UX قبلی را برگرداند | Registry عدم استفاده مجدد شناسه؛ ممنوع‌کردن نامزد مبهم 35gg؛ مقایسه اصلاحات پذیرفته‌شده و محتوای واقعی |
| **R100–R104 / Public** | \`emergency-owner-v1.json\` به نسخه قدیمی اشاره داشت درحالی‌که VERSION تغییر کرده بود | version و owner metadata و SHA کلاس واقعی باید در **آخرین ZIP** برابر باشند |
| **R102 / Relay** | Heartbeat موفق، اما ارسال/Claim فرمان مهمان ناموفق؛ ریشه نخستین توقف Worker کامل اثبات نشده | تست فرمان واقعی Local↔Public با Claim/Commit/Retry؛ Heartbeat به‌تنهایی PASS نیست |
| **R104/R106/R107 / CSS** | فایل تغییرکرده فایل بارگذاری‌شدهٔ صفحه نبود؛ cascade قدیمی نمایش را خنثی می‌کرد | آزمون entrypoint/loaded assets و screenshot مرورگر واقعی، نه فقط lint |
| **R109 / Package Identity** | نسخه مقصد لوکال در Manifest اشتباه و مبنای Public ناسازگار شد | کنترل \`VERSION.txt\` در payload، target manifest و catalog مشترک |
| **R148 / Tea variants** | migration با شناسه‌های موجود کالای قدیمی مطابق نبود | ارتقا با fixture دیتابیس قدیمی و رکوردهای معتبر پیشین، نه نصب خالی تنها |
| **R158 / REG-003** | سازگاری ۴۸ مبدأ حفظ تمام ۲۳۳ سابقه/اصلاحات پذیرفته‌شده را اثبات نمی‌کند | ماتریس accepted fixes و تطبیق سورس کامل با payload همان ZIP؛ موارد نامعلوم را خودکار Done نکن |
| **R183 / Local** | archive wrapper، ZIP نامعتبر، hash غلط، status UAT و بکاپ ضعیف می‌توانند به اشتباه تحویل شوند | reuse static release gate و آزمون‌های منفی؛ نتیجه فقط static نه مجوز نصب |
| **R239→R240 / Windows Local** | ZIP از نظر CRC سالم اما \`local-managed-files\` فقط ۵۹۰ نام در برابر ۵۹۳ فایل داشت | شمارش و مجموعهٔ دقیق تمام فایل‌های inventory در inner ZIP |
| **R241 / Local** | یک ZIP با مبنای دقیق 231fa ساخته شد و native CI برای آن طراحی شد؛ خاص یک مبنا | ابزار عمومی نباید به نسخه/هش 231fa/R241 قفل شود و نباید status را به تنهایی قبول کند |
| **R247/R248 / Public** | owner ثابت Emergency جدا از payload managed بود؛ گیت R248 فقط با R171/R246 پین‌شده قابل اجراست | ثابت‌ها را از archive واقعی بخوان، dual-route با **همان ZIP** بررسی کن، منابع protected را به جای حدس از نقشه owner بشناس |
| **R233 / 2026-11-15** | ۵۹ مدخل دفتر QA؛ سورس/CI موفق ولی ZIPهای R233 \`source_only=true/installable=false\` و native install اثبات‌نشده | FAIL/NOT_RUN برای آزمون حیاتی؛ هیچ تغییر خودکار flag یا تبدیل source ZIP به فایل نصب |
| **Windows / PS5.1 / Services** | API جدید .NET، auto-loading \`Get-FileHash\`، encoding PS5.1، فایل قفل‌شده، هویت نسخهٔ مؤلفه و UAC قبلاً خطا دادند | از Windows Engineering Required Reading و CI حقیقی همان سازنده استفاده شود؛ KEEP بدون stop/copy/restart؛ این شاخه کد Windows را تغییر نمی‌دهد |
| **Home vs Café / Host** | تست سبز روی خانه یا fixture تضمین کافه نیست؛ Apache/ACL/SCM/PHP-FPM و restore دیتابیس ممکن است متفاوت باشند | وضعیت Host و نسخه نصب‌شده فقط با شاهد همان نصب؛ باقی \`NOT_RUN\` |

### از کجا بازیابی شود
- [Issue #48 — UUP-01](https://github.com/mobaraki20/SoknaCafeV3/issues/48): معماری فعال، Full/Delta، dual-route و source-only blockers.
- [Issue #67 — برخورد شناسه 35gg](https://github.com/mobaraki20/SoknaCafeV3/issues/67): مقایسه دو SHA/tree واقعی و درس رگرسیون.
- [PR #47 — historical only](https://github.com/mobaraki20/SoknaCafeV3/pull/47): اصل ابزار multibase قابل مطالعه؛ وابستگی signer منسوخ.
- [R183 local gate](https://github.com/mobaraki20/SoknaCafeV3/blob/qa/sokna-r183-release-gate-20261113/qa/sokna-r183/release_gate.py)، [R240 inventory](https://github.com/mobaraki20/SoknaCafeV3/blob/qa/sokna-r241-release-final-20261115/r240/upgrade_inventory.py)، [R248 Public](https://github.com/mobaraki20/SoknaCafeV3/blob/qa/sokna-r248-public-release-gate-20261115/r248/sokna_public_release_gate.py).
- Library: \`/Cafe/SOKNA-Release-Quality/...\`, \`/Cafe/SOKNA-Workspace/ISSUES/...\`, \`/SOKNA_V3_UPDATE_CANONICAL_20261025/\`, \`SOKNA-QA-001-RELEASE-ASSURANCE-BACKLOG.md\`, \`SOKNA-REL-GATE-001\`, \`SOKNA-RELEASE-PREFLIGHT-AGENT-RULES.md\`.
- [Windows required reading](../docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md).

### قواعد گزارش ریشه
- «بررسی نشده» و «FAIL» متفاوت هستند؛ «در سورس» و «در بسته/نصب» متفاوت‌اند.
- از خطای یک میزبان، حکم کلی برای همه نسخه‌ها نده.
- کنترل آفلاین جدید تضمین اجرای Browser/Windows/host یا درمان مشکل موتور نصب نیست؛ فقط پیش‌تحویل، مانع ارسال فایل آشکارا ناسالم می‌شود.
- افزودن شکست تاریخی جدید = افزودن یک **تست منفی قابل تکرار** و پیوند به شاهد، نه ساخت ابزار جایگزین و زنجیره جدید.