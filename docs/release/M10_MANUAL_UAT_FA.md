# M10 — UAT دستی قبل از انتشار Production

این فایل عمداً جای تست خودکار را نمی‌گیرد. سه مورد زیر فقط روی محیط واقعی قابل تأیید هستند و تا ثبت شاهد، وضعیتشان در `release/manual-uat-status.json` باید `pending` بماند.

1. **Windows clean install** روی یک Windows x64 پشتیبانی‌شده: New/Recover/Repair/Uninstall، سرویس Runtime و Print Worker، HTTPS محلی و حفظ DataRoot بعد از Uninstall.
2. **پرینتر حرارتی واقعی**: چاپ فارسی RTL، قطع/وصل پرینتر، restart سرویس و retry بدون چاپ تکراری.
3. **UAT دستگاه/ورودی فارسی**: موبایل/تبلت/تاچ، responsive layout، keyboard/IME فارسی، focus/contrast و مسیرهای اصلی اپراتور.

برای هر مورد، پس از اجرای واقعی، `status` به `passed` یا `failed` تغییر کند و در `evidence` تاریخ، دستگاه/نسخه و مرجع شاهد ثبت شود. CI فقط وجود و صحت این قرارداد را بررسی می‌کند و هرگز خودش این موارد را `passed` نمی‌کند.
