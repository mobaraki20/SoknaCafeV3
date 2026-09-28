# SOKNA Prerequisites Setup

این ابزار **Local Web را نصب نمی‌کند**. فقط زیرساخت لازم برای اجرای Local Web را آماده می‌کند.

## سه حالت

- **نصب جدید**: PHP + Apache + MariaDB را از نسخه‌های release-locked دریافت، SHA-256 را بررسی و زیرساخت را آماده می‌کند.
- **تعمیر**: فایل‌ها/تنظیمات مدیریت‌شده و سرویس‌ها را ترمیم می‌کند و `Web` و `Data` را نگه می‌دارد.
- **بازیابی بعد از نصب مجدد ویندوز**: از زیرساخت و Data باقی‌مانده روی درایو دیگر استفاده و سرویس‌های Windows را دوباره ثبت می‌کند. Data موجود initialize نمی‌شود.

## مسیر پیشنهادی

مثلاً:

```text
D:\SOKNA\Infrastructure\PHP
D:\SOKNA\Infrastructure\Apache
D:\SOKNA\Infrastructure\MariaDB
D:\SOKNA\Data\MariaDB
D:\SOKNA\Web
D:\SOKNA\Backups
```

درایو و ریشه قابل انتخاب است. استفاده از درایوی غیر از درایو Windows پشتیبانی می‌شود.

## مرز دیتابیس

Prerequisites Setup فقط MariaDB Server و Data Directory زیرساخت را آماده می‌کند.  
**Database مربوط به SOKNA، user محدود برنامه و migrationها در Browser Setup خود Local Web ساخته می‌شوند.**

بعد از آماده‌شدن زیرساخت، بسته Local Web جداگانه در `Web` Extract می‌شود و `http://localhost/` باز می‌شود.

## خطا و پشتیبانی

Logها در `<root>\Infrastructure\Logs` نگه‌داری می‌شوند. نصب MariaDB یک MSI verbose log جدا دارد. دکمه «ساخت بسته پشتیبانی» state، diagnostics و logهای اخیر را ZIP می‌کند و عمداً password/credentialهای Local Web را وارد بسته نمی‌کند.

اگر Data قبلی MariaDB تشخیص داده شود، حالت نصب جدید متوقف می‌شود و کاربر باید Repair یا Recover را انتخاب کند.
