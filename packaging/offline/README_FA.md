# SOKNA Offline Kit

این پوشه برای آماده‌سازی یک فلش/هارد آفلاین تعریف شده است. فایل‌های third-party داخل Git repository نگهداری نمی‌شوند؛ آن‌ها را از منابع رسمی دریافت کنید و با نام اصلی کنار این راهنما قرار دهید.

## فایل‌های پیش‌نیاز قفل‌شده

نسخه و SHA-256 مرجع همیشه از `platform/windows/release-lock.json` خوانده می‌شود.

برای Local Web infrastructure:

- `php-8.2.34-Win32-vs16-x64.zip`
- `httpd-2.4.68-260920-Win64-VS18.zip`
- `mariadb-11.4.12-winx64.msi`

برای Windows Services، اگر Visual C++ Runtime روی سیستم نصب نیست:

- `VC_redist.x64.exe`

## استفاده بدون اینترنت

1. `SOKNA Prerequisites Setup` را اجرا کنید.
2. «انتخاب پوشه آفلاین» را بزنید تا PHP/Apache/MariaDB از همین پوشه پیدا و با Size + SHA-256 بررسی شوند. می‌توانید هر فایل را نیز جداگانه با «انتخاب فایل» تحویل دهید.
3. نصب زیرساخت را ادامه دهید. Setup از فایل‌های Cache تأییدشده استفاده می‌کند و نیازی به اینترنت ندارد.
4. در `SOKNA Windows Services Setup` اگر VC++ موجود نیست، ردیف Visual C++ را انتخاب و «انتخاب فایل از کامپیوتر» را بزنید. فایل با Size + SHA-256 + Authenticode بررسی می‌شود.
5. بسته مستقل Local Web را در Web Root قرار دهید و Browser Setup را روی `http://localhost/` اجرا کنید.
6. Public Edge برای کارکرد Local الزامی نیست.

## نکته امنیتی

نام فایل به‌تنهایی ملاک نیست. Setup فقط فایلی را قبول می‌کند که با Release Lock همان نسخه از نظر حجم و SHA-256 (و برای VC++ امضای Microsoft) تطبیق داشته باشد.
