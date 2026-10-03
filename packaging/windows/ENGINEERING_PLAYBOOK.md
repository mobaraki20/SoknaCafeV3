# Windows Services Engineering Playbook

منابع دائمی قبل از هر تغییر جدید روی Windows Services:

1. قواعد ساخت، تست، qualification و انتشار:
   `docs/engineering/WINDOWS_INSTALLER_ENGINEERING_PLAYBOOK_FA.md`
2. قواعد تجربه کاربر، تشخیص نصب، عیب‌یابی بدون AI و remediation:
   `docs/engineering/WINDOWS_SERVICES_SELF_SERVICE_STANDARD_FA.md`

هر دو سند باید قبل از شروع کار خوانده شوند.

Regression rule مهم برای GitHub Actions/Windows PowerShell 5.1:
- parser PASS به‌تنهایی کافی نیست؛ test harness باید با APIهای واقعاً موجود در Windows PowerShell 5.1 اجرا شود.
- overload دوپارامتری `String.Contains(value, StringComparison)` در harness ممنوع است؛ برای مقایسه‌ی ordinal از `IndexOf(value, [StringComparison]::Ordinal) -ge 0` استفاده شود.
- assertionهای log/installer تا حد ممکن بر markerهای ASCII پایدار تکیه کنند تا encoding متن فارسی باعث failure کاذب نشود.
- failure مربوط به harness نباید باعث تغییر بی‌دلیل کد محصول شود.

Regression rule مهم برای BiDi در WinForms/GDI:
- کاراکترهای Unicode isolate یعنی `U+2066 LRI` و `U+2069 PDI` نباید داخل `Control.Text` کاربرمحور باقی بمانند؛ در بعضی مسیرهای `TextRenderer`/فونت به‌صورت glyph قابل‌دیدن نمایش داده می‌شوند.
- برای tokenهای کوتاه LTR در متن فارسی از روش سازگار با WinForms استفاده شود؛ در 1.0.11 از `LRM (U+200E)` استفاده شده و رشته‌ی خام همچنان بدون mark قابل بازیابی است.
- Self-Service contract باید نشت `LRI/PDI` را fail کند و screenshot واقعی هر اندازه‌ی هدف قبل از Release بصری بازبینی شود.
- PASS شدن bounds/non-blank screenshot به‌تنهایی اثبات درستی BiDi نیست.
