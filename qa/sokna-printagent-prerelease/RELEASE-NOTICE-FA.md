# پیش‌انتشار Print Agent 6.2.12 — فقط دریافت و ارزیابی، نه نصب کافه

**وضعیت: QA PRE-RELEASE / INSTALLATION BLOCKED.**

هدف: فراهم‌کردن باینری کامل Print Agent به‌صورت مستقل از Local، Public و Maintenance، همراه با ابزار شناسایی فقط‌خواندنی رایانه مقصد؛ نه فعال‌سازی بی‌مجوز.

## مدارک انجام‌شده
- آخرین گزارش تاریخی مخصوص **رایانه کافه** در ۱۳ نوامبر ۲۰۲۶: Print Agent 6.2.11؛ هویت بایتی نصب‌شده مشخص نشده؛ فایل Home مبنا نیست.
- باینری Service+Worker نسخه 6.2.12 با SHA-256 شناخته‌شده و مانفیست فایل‌به‌فایل از QA قبلی، بدون بازساخت حدسی.
- آزمون Windows برای موتور چاپ 58/80mm و یادداشت هر قلم: run 43581433946.
- آزمون Heartbeat و ایمنی جلوگیری از چاپ تکراری: run 43484720274؛ باینری QA نسخه 6.2.12: run 43489066238.
- QA این release باید فایل باینری دقیق، مانفیست، اسکن read-only، تست‌های Worker و Heartbeat را از نو کنترل کند.

## وضعیت ارتقا
**ارتقای عملیاتی 6.2.11 → 6.2.12 هنوز مجاز نیست.** تغییرات و SHA-256 باینری واقعی 6.2.11 روی کافه در دست نیست و آزمون واقعی preservation/rollback از همان نسخه انجام نشده است. نسخه را نمی‌توان صرفاً از روی عدد پذیرفت.

## مسیر آماده‌سازی در کافه (بعداً)
۱. ZIP ابزار فقط‌خواندنی را از صفحه Pre-release دانلود و Extract کنید.
۲. فایل `Run-ReadOnly-PrintAgent-Audit.cmd` را اجرا کنید؛ خروجی `SOKNA-PrintAgent-ReadOnly-*.json` روی Desktop ساخته می‌شود.
۳. خروجی را برای تطبیق SHA، نسخه، سرویس و مانفیست ارسال کنید. ابزار Service، Printer، Registry، ProgramData و صف SQLite را تغییر نمی‌دهد و محتوای secret/config/queue.db را نمی‌خواند.
۴. فقط پس از تأیید منشأ 6.2.11 و آزمون نصب و Rollback با حفظ ProgramData، از بسته رسمی Update مستقل Print Agent استفاده شود.

### توضیح فایل‌ها
- `SoknaPrintAgent-6.2.12-win-x64.zip`: باینری واقعی Service+Worker، **به‌تنهایی نصب‌کننده نیست**.
- `SOKNA-PrintAgent-ReadOnly-Audit.zip`: ابزار فقط‌خواندنی نسخه‌برداری هویت.
- `R198-QA-ONLY-DO-NOT-INSTALL.json`: وضعیت gate و lineage.
- `SHA256SUMS.txt`: اثرانگشت‌ها.
- `RELEASE-NOTICE-FA.md`: همین محدودیت‌ها.

**هیچ فایل Production یا Release رسمی، Main/CURRENT، Local/Public، Maintenance یا سرویس Windows در ساخت این Pre-release تغییر نمی‌کند.** این فایل‌ها نباید اکنون به‌عنوان installer اجرا یا روی کافه اعمال شوند.
