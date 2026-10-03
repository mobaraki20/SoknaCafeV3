# SOKNA Local — Internal Print Worker

این پوشه Source of Truth مؤلفه چاپ داخلی SOKNA Local است.

## مبنا
- کد عملیاتی از سورس واقعی Sokna Print Agent `6.2.5` که Owner پروژه ارائه کرده استخراج شده است.
- SHA-256 آرشیو مبنا در `PROVENANCE.json` ثبت شده است.
- state machine، SQLite durable queue، retry/reconciliation، renderer و Winspool به‌جای بازنویسی از همان مبنای بالغ حفظ شده‌اند.

## تفاوت معماری قطعی
- این مؤلفه **محصول نصب‌شونده جداگانه نیست**.
- `Setup` و `Control` مستقل upstream عمداً وارد این Source of Truth نشده‌اند.
- Build/Package/Install/Repair/Recovery این Worker فقط تحت SOKNA Local انجام می‌شود.
- UI مدیریتی کاربر همان Surface فارسی `چاپ و پرینترها` در SOKNA Local است.
- نام‌های فنی قدیمی `Sokna.PrintAgent.*` در namespace/API داخلی فعلاً برای حفظ compatibility و کاهش ریسک state-machine باقی مانده‌اند؛ این نام‌ها Design/Deployment authority نیستند.

## داده پایدار
مسیر canonical جدید Worker زیر Data Root خود SOKNA است (`<SOKNA_DATA_DIR>/print-worker`). Resolver برای مهاجرت امن، registry قدیمی PrintAgent را نیز به‌صورت fallback می‌خواند تا queue/config/secret موجود بدون تصمیم صریح دور ریخته نشوند.
