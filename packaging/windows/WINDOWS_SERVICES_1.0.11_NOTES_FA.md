# Windows Services 1.0.11 — Self-Service / Installer Awareness

## هدف

نسخه 1.0.11 برای بستن دو شکاف نسخه 1.0.10 ایجاد شده است:

1. خود Setup بیرونی باید نصب قبلی و نسخه آن را برای کاربر تشخیص و توضیح دهد؛
2. کاربر باید بدون AI/برنامه‌نویس بتواند خطاهای رایج Windows Services را تشخیص دهد و برای موارد امن اقدام کند.

## Installer Awareness

- AppId ثابت قبلی حفظ شده است.
- Setup از Uninstall registration نسخه نصب‌شده را می‌خواند.
- Fresh Install صریحاً به کاربر اعلام می‌شود.
- Upgrade نسخه مبدا و مقصد را نشان می‌دهد.
- اجرای همان نسخه به‌عنوان Repair/Reapply توضیح داده می‌شود.
- Downgrade روی نسخه جدیدتر مسدود می‌شود.
- Install mode در Setup log ثبت می‌شود تا qualification بتواند رفتار واقعی را اثبات کند.

## عیب‌یابی Self-Service

دکمه قبلی «بسته عیب‌یابی» در صفحه اصلی با action واضح «عیب‌یابی» جایگزین می‌شود. Support Bundle حذف نشده و از داخل مرکز عیب‌یابی قابل ساخت است.

مرکز عیب‌یابی این حوزه‌ها را بررسی می‌کند:

- Visual C++ x64 Runtime؛
- ثبت و Running بودن Runtime / Print Agent؛
- وجود executableهای Service ImagePath؛
- خوانایی state و package version؛
- وجود و قابل نوشتن بودن DataRoot؛
- وجود Pairing؛
- معتبر بودن Local Web URL؛
- پاسخ TCP روی host/port محلی ثبت‌شده.

هر finding دارای code، عنوان قابل فهم و راه‌حل کاربرمحور است.

## Remediation امن

- Start کردن Runtime متوقف در سیستم Pair شده؛
- Start کردن Print Agent متوقف؛
- دسترسی مستقیم به Repair موجود در Dashboard؛
- ساخت Support Bundle؛
- باز کردن log folder؛
- کپی گزارش کددار برای پشتیبانی.

Pairing خودکار، حذف token/state و پاک‌کردن DataRoot انجام نمی‌شود.

## UI

- عبارت‌های فنی انگلیسی داخل متن فارسی با Unicode BiDi isolation نمایش داده می‌شوند.
- دکمه اصلی footer برای کاربر «عیب‌یابی» است.
- failure hint کاربر را مستقیماً به عیب‌یابی هدایت می‌کند.
- Support Bundle مرحله دوم است، نه تنها روش تشخیص مشکل.

## Gateهای 1.0.11

قبل از Release باید PASS شوند:

1. PowerShell 5.1 parser؛
2. build کامل Windows Services + Inno؛
3. ProductVersion 1.0.11؛
4. Dashboard layout / screenshot واقعی؛
5. Self-Service diagnostic contract scenarios؛
6. Installer Awareness: Fresh / Same / Upgrade / Block Downgrade؛
7. Fresh install + repeated Repair؛
8. state package_version=1.0.11؛
9. independent Pairing + preservation؛
10. Support Bundle و uninstall؛
11. artifact index/checksum و انتشار دقیق همان artifact qualified.

## دامنه

این نسخه فقط `packaging/windows` و مستندات مهندسی مرتبط را تغییر می‌دهد. Local Web، Public Edge و Prerequisites تغییر نمی‌کنند.
