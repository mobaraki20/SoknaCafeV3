# SOKNA Prerequisites 1.0.12 — Windows Installer Engineering Playbook

این سند برای جلوگیری از تکرار خطاها در چت‌ها و نسخه‌های بعدی نصب‌کننده پیش‌نیازها نگهداری می‌شود.

## قواعد اجباری

- GitHub Actions محیط توسعه و آزمون‌وخطای ریز نیست. تغییرات باید ابتدا در یک بلوک معنادار جمع و سپس candidate ویندوزی qualification شود.
- قرارداد استاتیک UI باید invariant رفتاری/ساختاری را بررسی کند، نه literal پیکسلی یا نام implementation موقت.
- candidate نهایی باید screenshot واقعی در 960×650، 1100×660 و 1280×720 تولید کند و قبل از Release دستی بازبینی شود؛ PNG غیرخالی یا CI سبز به‌تنهایی کافی نیست.
- Main UI باید Dashboard فشرده با header/footer ثابت و بدون AutoScroll افقی/عمودی باشد. جزئیات طولانی در tab/dialog مستقل قرار می‌گیرند.
- UI اصلی فارسی/RTL است و path، URL، version و port به‌صورت LTR island نگهداری می‌شوند. LRI/PDI داخل Control.Text در WinForms ممنوع است.
- Root/Port/credential controls نباید در mirrored RTL collapse شوند. event handler و business logic اصلی حفظ می‌شود و presentation می‌تواند re-parent شود.
- visual review ناموفق، candidate را رد می‌کند حتی اگر همه gateهای خودکار سبز باشند.
- دانلودهای بیرونی ممکن است موقتاً HTML/error به‌جای artifact بدهند. evidence یک regression واقعی فقط وقتی قابل inheritance است که baseline کاملاً PASS باشد و diff gate ثابت کند هیچ core/runtime/dependency-policy file از baseline تغییر نکرده است. در غیر این صورت regression واقعی باید دوباره اجرا شود.
- Release نباید rebuild کند؛ فقط exact qualified artifact قابل انتشار است.
- Installer باید Fresh / Same / Upgrade / Newer را تشخیص دهد و downgrade را در interactive و silent mode مسدود کند.
- Repair/Recover هیچ‌وقت MariaDB Data موجود یا داده کاربر را initialize/delete نمی‌کند. migration خودکار cross-root ممنوع است.
