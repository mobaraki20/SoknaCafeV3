# ممیزی ZIP واقعی R262/R263 و پیشگیری از انتشار Clean ناقص

**تاریخ:** ۱۶ نوامبر ۲۰۲۶ | **Scope:** فقط QA و زیرساخت انتشار GitHub، بدون تغییر Local/Public/Windows یا سیستم عملیاتی

## یافته‌های قطعی از بایت‌های آرشیو

| خروجی | SHA256 | وضعیت |
|---|---|---|
| Local R262 Maintenance QA، ۶۰۴ فایل، ۸۲ Migration | `3d12ea375a307e58c8e36b2036a4fea9dec9ef05330f4a046234102c32164b8c` | Windows QA موفق؛ نصب مشتری تأیید نشده |
| Public R262 Update، ۲۴۴ فایل، ۱۱ Migration | `5aef4cd781fbd450d3e454f324e294da6f4180bfe1e5eb1871cb4818dc20c93a` | QA موجود و Migration 8→11 پاس؛ هاست واقعی تأیید نشده |
| Public R262 Clean، ۲۴۴ فایل | `156ddccf01e0755c2d94a6a88442523c02d1e6a63e815089be30a1e50f56616e` | **مردود و غیرقابل تحویل**: پنج کلاس `require_once` بوت‌استرپ در ZIP نیست |
| Public R263 Self-contained Clean، ۲۵۱ فایل | `37242dabf6893bfc4bf26e8233485802c197ea8b742ae264b8aed9e0971f0d21` | اصلاح Clean، همه ۲۴۴ فایل مشترک دقیقاً یکسان؛ نصب QA روی هاست خالی ریشه/زیرپوشه پاس، نه Production |

## قواعد قطعی جدید انتشار

- `release-ops/audit.py` از این پس وابستگی‌های صریح `require_once __DIR__` در `bootstrap.php` را از **خود ZIP Clean** بررسی می‌کند. هیچ آزمونی حق ندارد با کپی کلاس‌ها از سورس یا fixture، نقص ZIP تحویلی را پنهان کند.
- اگر قالب Trust در بسته Clean موجود است، باید از نوع `sokna-update-trust-v1` و دارای `ed25519_public_keys={}` باشد. فایل تنظیمات، `install.lock`، `emergency.php` و داده خصوصی از هاست در Clean قرار نمی‌گیرند.
- `release-ops/version-registry.json` نسخه‌های QA R262/R263 را فقط در `observed` ثبت می‌کند و SHA فایل Clean ناقص R262 را به لیست `rejected` می‌سپارد. هیچ یک به معنی `released` یا `accepted` نیست.
- پرچم‌های `for_install=true` و `production_pass=true` در **Manifest بسته Local R262** برای اجرای آزمون Native استفاده شده‌اند. وجود آنها همراه با `status=R262_NATIVE_WINDOWS_PENDING_HOST_ACCEPTANCE` و نام `UNQUALIFIED` هرگز مجوز تحویل/نصب عملیاتی نیست.
- موجودی SQLها با بایت دقیق مبنا کنترل شده است: Local R241 ۸۱→R262 ۸۲ (۸۱ قبلی بدون تغییر و فقط `0082_public_move_owner_approval.sql` اضافه)؛ Public R249 ۸→R262 ۱۱ (هشت قبلی بدون تغییر و فقط ۰۰۰۹، ۰۰۱۰ و ۰۰۱۱ اضافه).
- Public R262 Update شامل فایل‌های `src/Emergency/**` یا فایل machine-bound trust نیست، اما **R263 Clean** حاوی ۶ فایل PHP ثابت Emergency و قالب Trust خالی است؛ این تفاوت عمدی است و نباید با یکسان‌سازی سادهٔ ZIPها حذف شود.

## اجرا و مدرک

- Workflow: `.github/workflows/sokna-r262-r263-archive-crossgate.yml`. ورودی فقط آرشیوهای دقیق GitHub Actions R241، R249، R262 و R263 با SHA ثابت است.
- فرایند فقط گزارش JSON QA آپلود می‌کند، هیچ فایل ZIP قابل نصب تحویل یا منتشر نمی‌کند.
- شواهد مرجع: [Native Windows R262](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43834210936)، [Public existing 8→11](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43835146004)، [R263 empty-host clean](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43837950540).
- مرجع سورس فعلی `release-ops/active-source.json` همان **آخرین Workspace تأییدشده و ثبت‌شده در زمان ممیزی** یعنی R233 است. شاخه R262/R263 به‌خودی‌خود «سورس approved جدید» نیست؛ پیش از انتشار باید Workspace و تصمیم‌های کاربر دوباره بررسی شوند.

## موارد هنوز باز

پذیرش روی هاست واقعی مشتری، سازگاری با همه مبناهای ادعاشده در نصب‌های موجود، آزمون بازیابی پس از خرابی روی همان ZIP نهایی و موافقت صریح برای انتشار واقعی. تا آن زمان Production=BLOCKED.
