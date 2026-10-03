# SOKNA Prerequisites 1.0.12 — Product/UI Standard

هدف رابط Prerequisites این است که کاربر بدون دسترسی به AI یا توسعه‌دهنده بتواند وضعیت را بفهمد، عملیات مناسب را انتخاب کند و خطاهای رایج را رفع یا برای پشتیبانی مستند کند.

## UI
- Main window یک Dashboard فشرده است: header ثابت، محتوای tabbed و footer عملیات ثابت.
- صفحه اصلی horizontal/vertical AutoScroll ندارد.
- وضعیت/اجرا، فایل‌های پیش‌نیاز، credential نصب جدید MariaDB و جزئیات فنی در بخش‌های جدا نمایش داده می‌شوند.
- فارسی/RTL در سطح محصول؛ path/URL/version/port LTR.
- نسخه نمایشی semantic است و Git SHA در title/version badge نمایش داده نمی‌شود.

## Self-service
- findingها کد پایدار `PRQ-*` دارند و متن «مشکل چیست / کاربر چه کند» ارائه می‌کنند.
- remediation خودکار فقط برای اقدام‌های امن و برگشت‌پذیر است.
- Support Bundle باید secret-redacted باشد و evidence سرویس، process، port، config و event را جمع کند.
- Data موجود MariaDB هیچ‌گاه remediation خودکار نیست.

## Qualification
- 960×650، 1100×660 و 1280×720 باید هم gate خودکار و هم review دستی داشته باشند.
- clipping، collapsed technical fields، BiDi خراب، hidden action و scrollbar غیرضروری failure هستند.
- Release فقط از exact qualified artifact انجام می‌شود.
