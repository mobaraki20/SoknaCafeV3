# استاندارد Self-Service نصب‌کننده پیش‌نیازهای SOKNA

این سند استاندارد محصولی/رفتاری Prerequisites است. هدف این است که کاربر برای نصب، تعمیر، بازیابی و تشخیص خطا تا حد ممکن به آزمون‌وخطا، PowerShell یا ارسال چندباره لاگ وابسته نباشد، بدون آن‌که مرزهای امنیتی Data شکسته شوند.

## مرز مسئولیت

Prerequisites فقط PHP، Apache، MariaDB Server، سرویس‌های زیرساخت، Data زیرساخت MariaDB، Cache فایل‌های پیش‌نیاز و endpoint محلی را مدیریت می‌کند. نصب Local Web، ساخت دیتابیس/کاربر کاربردی SOKNA، Public Edge و Pairing سرویس‌های Windows خارج از این جزء هستند.

## وضعیت‌های قابل فهم برای کاربر

رابط باید وضعیت را به یکی از حالت‌های قابل فهم تبدیل کند: نصب نشده، آماده، نیازمند تعمیر، قابل بازیابی، یا دارای تداخل نصب قبلی. کاربر نباید از روی وجود چند فایل یا چند RadioButton حدس بزند کدام عملیات مناسب است.

حالت پیشنهادی می‌تواند خودکار انتخاب شود، ولی عملیات مخرب یا تغییر Root نباید خودکار انجام شود.

## قواعد ایمنی

- Data موجود MariaDB هرگز حذف یا initialize مجدد نمی‌شود.
- Cross-root به‌صورت fail-closed متوقف می‌شود.
- Downgrade برنامه مدیریت، حتی در Silent Mode، مسدود است.
- Repair باید Web و Data را حفظ کند.
- Support Bundle نباید عمداً password، token، authorization یا credential برنامه را وارد کند.
- اقدام خودکار فقط برای موارد کم‌خطر مثل Start سرویس متعلق به SOKNA، تکمیل provenance State یا انتخاب پورت loopback آزاد مجاز است.

## رابط فارسی

- فرم‌های اصلی RTL واقعی دارند (`RightToLeftLayout=true`).
- Vazirmatn در صورت نصب بودن ترجیح داده می‌شود و Tahoma/Segoe UI fallback هستند؛ صحت برنامه نباید به نصب فونت وابسته باشد.
- Path، URL، IP، port، version و password به‌صورت LTR نمایش داده می‌شوند.
- از Unicode bidi-controlهای LRI/RLI/FSI/PDI در متن WinForms استفاده نمی‌شود.
- صفحه اصلی باید روی رزولوشن‌های qualification بدون clipping دکمه‌ها و بدون خرابی چیدمان کار کند.

## مرکز عیب‌یابی

هر Finding باید چهار جزء داشته باشد: کد پایدار، سطح، شرح مشکل، اقدام پیشنهادی. کدها با `PRQ-` شروع می‌شوند و دسته‌های اصلی عبارت‌اند از ROOT، STATE، PHP، APACHE، MARIA، DATA، PORT و UAC.

اقدام امن فقط وقتی در UI فعال می‌شود که remediation آن از قبل whitelist شده باشد. Data reset، reinitialize، cross-root migration و حذف registration نصب دیگر remediation امن محسوب نمی‌شوند.

## State و provenance

`Infrastructure\infrastructure-state.json` علاوه بر مسیرها و endpoint باید نسخه manager، SHA-256 release lock، SHA-256 سیاست زیرساخت و expected/detected version اجزا را ثبت کند. نوشتن enrichment به‌صورت atomic replacement انجام می‌شود و نباید credential وارد State کند.

## Support Bundle v2

بسته پشتیبانی جدید باید علاوه بر State و logها، وضعیت سرویس، PID/ImagePath، پورت‌های مرتبط، پردازش‌های مرتبط، Apache/PHP config و رخدادهای اخیر Service Control Manager را جمع کند. متن‌ها قبل از بسته‌بندی از الگوهای شناخته‌شده password/token/secret/authorization پاک‌سازی می‌شوند.

## انتشار و qualification

GitHub Actions محیط توسعه روزمره نیست. candidate کامل به‌صورت دستی qualification می‌شود. هر خروجی qualified باید به یک source commit مشخص و fingerprint سیاست‌ها متصل باشد. Release بعدی باید همان artifact qualified را منتشر کند و نباید دوباره build شود.

Screenshot واقعی از executable در ابعاد 960×650، 1100×660 و 1280×720 جزو evidence qualification است؛ وجود کد UI به‌تنهایی PASS محسوب نمی‌شود.
