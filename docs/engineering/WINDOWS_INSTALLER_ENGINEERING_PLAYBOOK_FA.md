# راهنمای دائمی مهندسی Installerهای ویندوز سکنا

این سند **منبع شروع کار** برای هر چت/جلسه‌ی بعدی روی دو Installer زیر است:

- `packaging/windows/` — SOKNA Windows Services (Runtime + Print Agent)
- `packaging/prerequisites/` — SOKNA Prerequisites Setup (PHP + Apache + MariaDB)

هدف این سند جلوگیری از تکرار آزمون‌وخطا، اجرای بی‌دلیل GitHub Actions و بازگشت خطاهای قبلاً حل‌شده است.

## 1) اصل اجرایی: GitHub Actions محیط توسعه نیست

تغییرات ریز نباید یکی‌یکی push شوند تا هر بار Windows runner از صفر .NET، Print Agent، Runtime و Inno Setup را بسازد.

روال اجباری:

1. کار روی branch از نوع `work/...` انجام شود.
2. بررسی‌های ممکن در workspace و با inspection/static checks انجام شوند.
3. چند اصلاح مرتبط در **یک candidate commit** جمع شوند.
4. فقط candidate آماده به branch qualification منتقل شود.
5. GitHub Actions فقط برای چیزهایی استفاده شود که واقعاً Windows لازم دارند: WinForms، SCM، service lifecycle، Inno، MSI/EXE و تست واقعی install/repair/uninstall.
6. هر خطایی که CI پیدا می‌کند باید با **root-cause fix + regression gate** بسته شود؛ نه sleep یا workaround بی‌سند.

برای Windows Services، branch کاری نباید workflow سنگین را خودکار اجرا کند. Workflow نهایی روی `qualify/windows-services-<version>` اجرا می‌شود. برای Prerequisites نیز `work/prerequisites-*` برای توسعه ترجیح دارد؛ workflow G7 در PR/branchهای qualification یا integration اجرا شود، نه برای هر ویرایش ریز.

## 2) قانون candidate و انتشار

هر نسخه چهار مرحله دارد:

`work -> candidate -> qualification -> release`

- `work`: تغییر و بررسی اولیه.
- `candidate`: یک commit مشخص و immutable برای تست.
- `qualification`: همه‌ی gateهای Windows روی **همان SHA**.
- `release`: فقط همان artifactی که qualification شده؛ rebuild تازه برای Release ممنوع مگر اینکه دوباره qualification شود.

در گزارش نهایی همیشه این‌ها ثبت شوند:

- version
- source commit SHA
- workflow run id
- artifact name
- installer SHA-256
- نتیجه‌ی هر gate

## 3) خطاهای قبلی که نباید برگردند

### Windows Services

- PowerShell 5.1 روی بعضی سیستم‌ها `[System.IO.Path]::IsPathFullyQualified` ندارد. برای scriptهای سازگار با Windows PowerShell 5.1 از helper سازگار استفاده شود و parser gate اجباری است.
- `SoknaPrintWorker` می‌تواند `e_sqlite3.dll` را هنگام Repair/Upgrade lock کند. صرفاً `Service.Status == Stopped` proof کافی نیست. باید process exit ثابت شود و payload replacement retry/transactional باشد.
- حذف recursive مستقیم `PrintAgent` بلافاصله بعد از `Stop-Service` ممنوع است.
- Support Bundle باید اطلاعات process/service ownership و Restart Manager/file-lock diagnostics داشته باشد؛ secret/token/pairing code نباید داخل bundle بیاید.
- `ProductVersion` نباید SHA گیت یا suffix فنی ناخواسته را در UI نشان دهد.
- icon فایل EXE، title bar، shortcut و taskbar باید یک identity داشته باشند.
- `DataRoot` ممکن است روی درایوی مثل `D:\SOKNA\Data` باشد؛ UI حق ندارد `%ProgramData%` را کورکورانه فرض کند. مسیر باید از state/registry/service config کشف شود.
- state نصب باید `package_version` داشته باشد.
- Upgrade detection باید Fresh Install / Upgrade / Current / Repair / Newer را تفکیک کند و downgrade خودکار مسدود باشد.
- نسخه‌های قدیمی که `package_version` ندارند باید با fallback کنترل‌شده از marker/executable/install metadata تشخیص داده شوند.
- Pairing یک lifecycle مستقل است. Pair کردن Local Web **نباید payload را replace کند و نباید Windows Service را delete/create کند**. فقط config/secret provision و در صورت نیاز restart سرویس مجاز است.
- Repair/Upgrade نباید pairing معتبر قبلی را از بین ببرد. اگر state قدیمی `local_base_url` نداشت، از `runtime-config.json` بازیابی شود.
- Inno `ignoreversion` به‌تنهایی Upgrade Manager نیست؛ تصمیم Upgrade در UI/state باید صریح باشد.

### Prerequisites

- Prerequisites Setup مالک Local Web نیست؛ فقط PHP/Apache/MariaDB و زیرساخت وابسته را آماده می‌کند.
- نصب جدید، Repair و Recover باید از هم جدا باشند.
- Data موجود MariaDB نباید در Repair/Recover initialize یا overwrite شود.
- dependencyها باید از `release-lock.json` و policy manifest بیایند؛ Size/SHA-256 و در موارد لازم Authenticode قبل از promote/اجرا بررسی شوند.
- Offline Kit مسیر درجه‌اول است؛ failure دانلود آنلاین نباید installer را در حالت نامشخص رها کند.
- انتخاب مسیر روی درایو غیرسیستمی پشتیبانی است و نباید به C: hard-code شود.
- پورت Local Web باید قبل از ثبت Apache بررسی شود و endpoint loopback باقی بماند.
- Support Bundle باید log/state/diagnostics را بدون credential جمع کند.

## 4) قواعد UI فارسی برای Installerهای سکنا

UI installer ابزار مهندسی خام نیست؛ باید محصول نهایی قابل استفاده باشد.

قواعد اجباری:

- فرم اصلی `RightToLeft = Yes` و mirroring واقعی RTL داشته باشد.
- URL، IP، version، PID، SHA و file path باید LTR island باشند؛ در متن فارسی معکوس نمایش داده نشوند.
- فونت فارسی کنترل‌شده استفاده شود. Windows Services از Vazirmatn موجود در bundle استفاده می‌کند و fallback فقط برای خرابی غیرعادی است.
- `AutoScroll` روی فرم اصلی و panelهای اصلی ممنوع است؛ اطلاعات ثانویه در dialog جزئیات یا log باز شود.
- در اندازه‌های هدف حداقل `960x600`, `1100x660`, `1280x720` نباید overflow یا scrollbar افقی/عمودی ایجاد شود.
- layout self-test باید screenshot و report تولید کند.
- action اصلی باید بر اساس state باشد: نصب، به‌روزرسانی، تعمیر یا اتصال؛ کاربر نباید از روی حدس mode را انتخاب کند.
- Pairing در UI action جداگانه است و متن باید صریحاً بگوید reinstall انجام نمی‌شود.

## 5) نکته‌ی مهم WinExe در GitHub Actions

`SoknaSetupUi.exe` یک WinExe است. در Windows PowerShell اجرای مستقیم با `&` الزاماً به این معنی نیست که shell تا پایان process منتظر می‌ماند؛ در نتیجه `$LASTEXITCODE` ممکن است خالی/قدیمی باشد و workflow در حالی fail شود که self-test چند لحظه بعد PASS شده است.

برای اجرای gateهای WinExe از این الگو استفاده شود:

```powershell
$process = Start-Process -FilePath $exe -ArgumentList $args -Wait -PassThru
if ($process.ExitCode -ne 0) { throw "UI self-test failed: $($process.ExitCode)" }
```

این مورد یک regression rule است و نباید به اجرای مستقیم WinExe + `$LASTEXITCODE` برگردد.

## 6) Gateهای اجباری Windows Services قبل از Release

یک candidate نهایی باید همه‌ی موارد زیر را روی Windows runner پاس کند:

1. PowerShell 5.1 parser برای scriptهای بسته.
2. Build Runtime + Print Agent + Setup Host + Setup UI + Inno installer.
3. ProductVersion/FileVersion درست و بدون SHA suffix.
4. Persian UI layout gate + screenshot؛ بدون AutoScroll/overflow و با RTL/font صحیح.
5. Fresh install واقعی.
6. Print Worker در حال اجرا بعد از install.
7. حداقل دو Repair متوالی در حالی که Print Worker قبل از Repair زنده است؛ `e_sqlite3.dll` سالم و بدون staging residue.
8. version state stamping.
9. independent pairing با mock Local Web؛ hash باینری‌ها و service image path قبل/بعد Pairing یکسان بماند.
10. حفظ Pairing در Repair/Upgrade.
11. Support Bundle v2 و file-lock diagnostics.
12. Uninstall و نبود residue سرویس‌ها.
13. artifact index + SHA-256.

اگر هر gate شکست خورد، artifact نهایی منتشر نشود.

## 7) Gateهای اجباری Prerequisites قبل از Release

- contract/static gate G7.
- build واقعی WinForms روی Windows.
- endpoint/port self-test.
- Inno installer build.
- detection نسخه/حالت موجود.
- install/repair/recover بدون آسیب به Data.
- download/resume/offline paths و hash verification.
- Apache/MariaDB service lifecycle و port conflict diagnostics.
- Support Bundle بدون secret.
- artifact index و checksum.

## 8) شیوه برخورد با CI failure

وقتی CI fail شد:

1. اول job/step/log دقیق خوانده شود.
2. مشخص شود failure محصول است یا خود test harness/workflow.
3. اگر harness اشتباه است، محصول بی‌دلیل تغییر نکند.
4. اصلاح root cause انجام شود.
5. regression check همان خطا اضافه/تقویت شود.
6. چند اصلاح مرتبط در work branch جمع شود.
7. فقط یک candidate جدید به qualification فرستاده شود.

مثال واقعی: layout self-test در 1.0.10 PASS می‌شد اما workflow به‌دلیل اجرای WinExe با `&` و اتکا به `$LASTEXITCODE` زودتر fail می‌کرد. این failure مربوط به harness بود، نه layout؛ راه درست `Start-Process -Wait -PassThru` است.

## 9) مرز تغییرات

هنگام کار روی Windows Services، بدون دلیل مرتبط فایل‌های Local Web/Public Edge/Infrastructure تغییر نکنند. هنگام کار روی Prerequisites نیز payload Local Web وارد installer زیرساخت نشود.

هر diff نهایی باید scope audit شود. اگر فایل خارج از دامنه تغییر کرده است، قبل از qualification دلیل آن باید روشن و مستند باشد.

## 10) نقطه شروع در چت بعدی

در شروع هر کار جدید روی این installerها ابتدا این سند خوانده شود، سپس:

- version فعلی
- release/tag فعلی
- branch کاری/qualification
- آخرین workflow run و artifact
- یادداشت نسخه‌ی جاری

بررسی شود. از بازسازی تصمیم‌های بالا از صفر خودداری شود.
