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
