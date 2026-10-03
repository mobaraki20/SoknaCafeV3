# SOKNA Windows Services 1.0.9 — Release Notes

تاریخ انتشار: 2026-10-03

## دامنه

این انتشار فقط به بسته Windows Services مربوط است: Runtime، Print Agent، Setup UI، Setup Host و ابزار عیب‌یابی همین بسته. هیچ تغییری در Local Web، Public Edge یا زیرساخت Apache/PHP/MariaDB اعمال نشده است.

## اصلاح اصلی نصب / Repair

نسخه 1.0.8 در نصب مجدد یا Repair می‌توانست پس از توقف `SoknaPrintWorker` بلافاصله پوشه `PrintAgent` را حذف کند، در حالی که پردازش سرویس یا child worker هنوز کاملاً خارج نشده و DLL بومی SQLite (`e_sqlite3.dll`) هنوز در Windows باز بود. نتیجه، خطای Access denied و نصب نیمه‌کاره بود.

در 1.0.9 lifecycle اصلاح شد:

- وضعیت `Stopped` در Service Control Manager دیگر به‌تنهایی پایان shutdown محسوب نمی‌شود.
- PID واقعی سرویس قبل از توقف ثبت و خروج واقعی همان process اثبات می‌شود.
- همه پردازش‌های SOKNA که executable آنها زیر ریشه همان payload قرار دارد قبل از جایگزینی فایل‌ها کنترل می‌شوند.
- فقط اگر shutdown در بازه محدود کامل نشود، process متعلق به همان payload SOKNA به‌صورت محدود Force Stop می‌شود؛ processهای خارج از ریشه محصول هدف قرار نمی‌گیرند.
- عملیات فایل با retry/backoff انجام می‌شود تا lockهای کوتاه‌مدت Windows باعث شکست فوری نشوند.
- Print Agent دیگر با `Remove-Item` مستقیم جایگزین نمی‌شود؛ payload جدید ابتدا در staging کپی و اعتبارسنجی می‌شود، نسخه قبلی به backup rename می‌شود و سپس staging به مسیر فعال منتقل می‌شود.
- اگر فعال‌سازی payload جدید شکست بخورد، تا حد امکان rollback به پوشه قبلی انجام می‌شود.
- Install/Repair state اکنون نوع shutdown proof و transactional replacement را ثبت می‌کند.

## Support Bundle v2

بسته عیب‌یابی برای خطاهای مشابه دقیق‌تر شده است و بدون جمع‌آوری secret/token/pairing شامل این شواهد می‌شود:

- snapshot وضعیت و PID سرویس‌های SOKNA؛
- processهای مرتبط با مسیر نصب؛
- `tasklist /m e_sqlite3.dll`؛
- metadata فایل‌های Runtime / Print Service / Print Worker / `e_sqlite3.dll`؛
- Windows Restart Manager report برای مشخص‌کردن process یا service نگهدارنده فایل؛
- Service Control Manager و Application events؛
- لاگ‌های غیرمحرمانه Setup، Runtime و Print Agent.

## رابط کاربری و آیکن

- Setup UI از ظاهر خام WinForms به presentation منظم‌تر با هدر برند، کارت‌ها، تب‌های اختصاصی، hierarchy واضح دکمه‌ها و spacing مناسب RTL تغییر کرده است.
- عنوان برنامه فقط نسخه محصول را نشان می‌دهد و SHA گیت دیگر وارد ProductVersion نمایشی نمی‌شود.
- `Sokna.ico` در executable، title bar، shortcut و taskbar یکسان شده است.
- AppUserModelID اختصاصی برای Setup UI تعریف شده است تا Windows taskbar identity پایدار باشد.

## Gate انتشار

نسخه نهایی فقط در صورتی منتشر می‌شود که Windows release workflow موارد زیر را PASS کند:

1. build کامل installer با نسخه دقیق `1.0.9`؛
2. ProductVersion Setup UI/Host بدون suffix مربوط به git SHA؛
3. نصب واقعی Windows Service و Running شدن Print Worker؛
4. Repair روی همان نصب در حالی که Print Worker قبل از Repair در حال اجراست؛
5. Repair دوم متوالی برای stress کردن shutdown/replacement؛
6. باقی‌ماندن `e_sqlite3.dll` در payload جدید و نبود staging residue؛
7. ساخت Support Bundle v2 و وجود lock diagnostics در ZIP؛
8. uninstall نهایی موفق؛
9. انتشار installer، artifact index و SHA256 فقط بعد از PASS همه gateها.

## فایل انتشار

`SOKNA-Windows-Services-Setup-1.0.9.exe`

Tag: `windows-services-v1.0.9`
