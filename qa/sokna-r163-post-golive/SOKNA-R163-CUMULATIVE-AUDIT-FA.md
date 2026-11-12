# سکنا — ممیزی تجمیعی بعد از R162، بدون اقدام روی داده‌های کافه

**مبنا:** Local 231em (R162)، Public 35hr. تاریخ: ۱۲ نوامبر ۲۰۲۶.

## قاعدهٔ پذیرش
از ۱۳۰ عنوان گفتگویی، هیچ عنوانی بدون شواهد نصب واقعی «کاملاً رفع‌شده» اعلام نمی‌شود. ۲۷ تصمیم تاریخی HOLD بدون تغییرند.

## کد واقعاً بازبینی‌شده / تغییر محدود

| شناسه | وضعیت | شاهد |
|---|---|---|
| CHAT14-021 | SOURCE_ADJUSTED_FIELD_PENDING | R159 CSS dialog radius; Firefox actual café not verified |
| CHAT14-023 | SOURCE_LAB_VERIFIED_HOST_PENDING | R162 go-live one-time Local/Public reset; not run on café |
| CHAT14-036 | CODE_COVERED_HOST_PENDING | Both guest.js use visible catalog card IDs for search count; no live device verification |
| CHAT14-037 | CODE_COVERED_HOST_PENDING | Both guest.js register popstate, Escape, search-back to close search |
| CHAT14-038 | CODE_COVERED_HOST_PENDING | Both guest.css hide .sg-recent-orders when .sg-search-active |
| CHAT14-040 | CODE_CANDIDATE_HOST_PENDING | Both guest.js implement guest modal scroll locking; no café acceptance |
| CHAT14-041 | CODE_COVERED_HOST_PENDING | Both guest.js handle Enter on detail note and click add when enabled; no field test |
| CHAT14-043 | R163_FIX_CANDIDATE | Local only table badge and about-link lacked faDigits; R163 targeted renderer patch; Public already correct |
| CHAT14-045 | PARTIAL_R163_FIX_CANDIDATE | Local return-to-menu top link target #sg-menu-search missing; added id; entire footer redesign not accepted |
| CHAT14-081 | R160_BROWSER_FIX_FIELD_PENDING | R159 field failure; R160 10/10 actual DOM fixture, no café re-test |
| CHAT14-082 | R160_BROWSER_FIX_FIELD_PENDING | R160 catalog viewport preservation test, no café acceptance |
| CHAT14-087 | MIGRATION_SOURCE_PRESENT_FIELD_PENDING | R162 source 0080 tea/herbal migration; installed DB variant prices not checked |
| CHAT14-088 | MIGRATION_SOURCE_PRESENT_FIELD_PENDING | R162 source 0080; exact live relations and ordering not checked |
| CHAT14-095 | SOURCE_PRESENT_FIELD_PENDING | Incremental count code present; real operational acceptance not yet recorded |
| CHAT14-101 | SOURCE_CANDIDATE_FIELD_PENDING | Invoice search related to R158 INV; no user-accepted live test |
| CHAT14-102 | SOURCE_CANDIDATE_FIELD_PENDING | R158 INV invoice archive source candidate, not production accepted |
| CHAT14-124 | HOLD_USER_REQUEST | Nov 11 mobile screenshots registered for later; no UI alteration authorized from screenshots |
| CHAT14-127 | HOLD_USER_POLICY | 27 legacy uncertain decisions remain HOLD; no merge without explicit decision |
| CHAT14-128 | R160_VISUAL_CANDIDATE_FIELD_PENDING | R160 guest card image radius CSS source updated; Firefox/café pending |
| CHAT14-129 | R160_VISUAL_CANDIDATE_FIELD_PENDING | R160 featured image contain CSS source updated; café pending |
| CHAT14-130 | R160_VISUAL_CANDIDATE_FIELD_PENDING | R160 suggested badge design changed, user visual acceptance pending |

## اصلاح محدود R163 (تنها کد Local منوی مهمان)

۱. نشان شمارهٔ میز و لینک صفحه دربارهٔ سکنا: تبدیل ارقام به فارسی، همسو با Public.
۲. لینک بازگشت به بالای منوی Local: افزودن شناسهٔ ثابت `sg-menu-search` که در Public وجود داشت.

**پوشش:** فقط `src/Domain/GuestLan/GuestLanPageRenderer.php` و تغییر فرادادهٔ نسخه هنگام ساخت بستهٔ آینده. بدون لمس دیتابیس، Public، جریان فروش، sync، یا reset R162.

## عناوین با کد موجود که عمداً دوباره اصلاح نشدند

- شمارندهٔ نتایج، جست‌وجوی بازگشت، مخفی کردن سفارش پیشین در جست‌وجو، Enter یادداشت در هر دو سورس حضور دارند؛ نیاز به آزمون کاربر دارند.
- اسکرول کاتالوگ در R160 آزمایشگاه ۱۰/۱۰ موفق بوده؛ R159 در نصب واقعی موفق نبوده، پس دوباره «رفع‌شدهٔ قطعی» نیست.
- ۳ ایراد تصویری منوی مهمان تغییر سورس داشته‌اند ولی تأیید میدانی ندارند.

## ادامهٔ اولویت‌بندی

۱. پس از پاکسازی و نصب R162، تأیید رفتار سرور واقعی (بدون ایجاد/حذف مجدد داده).
۲. بررسی موارد با خطر از دست‌رفتن سفارش و همگام‌سازی (CHAT14-025/026/028/050) با آزمون تزریق خرابی روی دیتابیس آزمایشی و source واقعی.
۳. بررسی انبار و مالی (CHAT14-093..115)؛ هرگونه تغییر migration در مسیر مجزا با آزمایش دیتابیس واقعی و بدون حدس.
۴. قبل از انتشار اصلاح تجمیعی بعدی، exact-source equality و Windows install-compat CI و UI browser fixture باید PASS شوند.

## تفکیک از پاکسازی
پاکسازی نصب واقعی کافه **انجام نشده**. این ممیزی/وصله، اجرای خودکار حذف ندارد و مرحلهٔ R162 باید قبل از نصب اصلاحات بعدی با موفقیت انجام شود.
