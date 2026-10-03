# یادداشت تغییرات Windows Services — 1.0.9-rc.1

تاریخ: 2026-10-03

دامنه این تغییر فقط بسته و رابط **SOKNA Windows Services** است. هیچ تغییری در Local Web، Public Edge، Apache/PHP/MariaDB یا داده‌های کسب‌وکار انجام نشده است.

## علت خطای نصب 1.0.8 که قبل از این تغییر تشخیص داده شد

در نصب/Repair روی یک نصب موجود، اسکریپت `setup-windows-services.ps1` بعد از `Stop-Service` و `WaitForStatus('Stopped')` بلافاصله پوشه `PrintAgent` را حذف می‌کند. در گزارش واقعی 2026-10-03 حذف روی فایل `PrintAgent\Service\e_sqlite3.dll` با Access Denied متوقف شد. این رفتار با یک lock/race در shutdown سرویس چاپ سازگار است.

در این RC منطق lifecycle و حذف فایل تغییر داده نشده است؛ هدف این تغییر این است که دفعه بعد صاحب lock و وضعیت دقیق processها در Support Bundle قابل مشاهده باشد و رابط مدیریتی نیز از حالت خام مهندسی خارج شود.

## Support Bundle v2

`collect-support.ps1` از format `sokna-windows-support-v2` استفاده می‌کند و علاوه بر اطلاعات قبلی موارد زیر را جمع می‌کند:

- Install Root واقعی از install-state یا Service ImagePath.
- snapshot پردازش‌های SOKNA بدون command line و بدون secret/token.
- `tasklist /svc` برای ارتباط PID و سرویس‌ها.
- `tasklist /m e_sqlite3.dll` برای تشخیص سریع پردازش‌هایی که SQLite native module را load کرده‌اند.
- Restart Manager inspection برای فایل‌های حساس نصب:
  - `e_sqlite3.dll`
  - `SoknaRuntimeService.exe`
  - `Sokna.PrintAgent.Service.exe`
  - `Sokna.PrintAgent.Worker.exe`
- metadata فایل‌های هدف شامل مسیر، حجم، زمان آخرین تغییر و Attributes.

خروجی‌های جدید داخل ZIP در پوشه `file-locks/` قرار می‌گیرند:

- `related-processes.txt`
- `tasklist-services.txt`
- `tasklist-e_sqlite3.txt`
- `target-files.txt`
- `restart-manager-locks.txt`

Policy محرمانگی حفظ شده است: pairing file، token، credential، private file و process command line وارد بسته نمی‌شوند.

## اصلاح هویت و آیکن برنامه

در نسخه 1.0.8 فرم، آیکن `Sokna.ico` را در runtime می‌گرفت ولی taskbar می‌توانست بر اساس icon/identity خود executable نمایش متفاوتی داشته باشد. برای یکسان‌سازی:

- `ApplicationIcon` در پروژه با مسیر صریح MSBuild به `packaging/windows/Sokna.ico` بسته می‌شود.
- AppUserModelID صریح `SOKNA.WindowsServices.SetupUi` تنظیم می‌شود.
- بعد از ساخته شدن Window Handle، `WM_SETICON` برای آیکن کوچک و بزرگ از همان `Sokna.ico` اعمال می‌شود.

هدف این است که title bar، taskbar و shortcut همگی هویت تصویری یکسان داشته باشند.

## اصلاح نمایش نسخه

در 1.0.8، `Application.ProductVersion` شامل Source Revision مانند `1.0.8+11565d8e...` بود و در رابط RTL رشته بسیار بد نمایش داده می‌شد.

- `IncludeSourceRevisionInInformationalVersion=false` شده است.
- رابط جدید نسخه قابل نمایش را از FileVersion نرمال می‌کند و فقط بخش نسخه محصول را نشان می‌دهد.

## بازطراحی رابط

فایل `ModernProgram.cs` بدون تغییر منطق عملیاتی `SetupForm`، یک presentation shell جدید روی همان قابلیت‌ها اعمال می‌کند:

- هدر کوتاه و خوانا با برند سکنا و نسخه واضح.
- زمینه گرم و پنل‌های سفید به جای ظاهر خام WinForms.
- تب‌های owner-drawn با وضعیت انتخاب واضح.
- دکمه اصلی نصب با hierarchy بصری مشخص؛ حذف سرویس‌ها با حالت هشدار؛ Support Bundle با حالت تشخیصی.
- spacing و padding منظم‌تر برای RTL.
- حذف GridLines سنگین از ListViewها.
- فونت `Segoe UI` با fallback به `Tahoma`، بدون وابستگی به فایل فونت خارجی.
- status area مجزا و خواناتر.

منطق نصب، Repair، Uninstall، Pairing، تشخیص پیش‌نیازها و ساخت Support Bundle از `SetupForm` قبلی استفاده می‌کند و در این مرحله بازنویسی نشده است.

## نسخه و شاخه

- Base: `work/q3-final-uat-package-freeze-20261002` @ `8d15e989bf28f58e3b8bf30910909c2c7cd9bfed`
- Working branch: `fix/windows-services-ui-diagnostics-20261003`
- Candidate version: `1.0.9-rc.1`

## تست لازم روی Windows

قبل از merge/release باید روی Windows واقعی این موارد تأیید شوند:

1. Build و publish `SoknaSetupUi.exe` بدون خطای compile.
2. یکسان بودن icon در shortcut، title bar و taskbar.
3. نمایش نسخه بدون Git SHA.
4. بررسی DPI در 100%، 125% و 150%.
5. بررسی RTL هر سه تب.
6. ساخت Support Bundle و وجود تمام فایل‌های `file-locks/`.
7. در صورت بازتولید lock روی `e_sqlite3.dll`، بررسی اینکه `restart-manager-locks.txt` PID/process صاحب lock را گزارش می‌کند.

این RC هنوز رفع lifecycle race مربوط به حذف `e_sqlite3.dll` را اعمال نکرده است؛ آن اصلاح باید جداگانه و با تست reinstall/repair انجام شود.
