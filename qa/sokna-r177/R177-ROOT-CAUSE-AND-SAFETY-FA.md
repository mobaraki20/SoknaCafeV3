# سکنا R177 — بازیابی کاتالوگ همگام‌سازی پس از پاکسازی یک‌باره

- ورودی میدانی: گزارش health_public مورخ ۱۴۰۵/۰۸/۲۱ ساعت ۲۰:۲۶؛ Public 35ht در resync_required epoch=2، heartbeat و انتشار منو HTTP 200، اما در Local جدول public_projection_model_delivery خالی و صف pending_events=0 دیده شد.
- شاهد پیشین: بسته پشتیبانی پیش از پاکسازی ۱۱ مدل را در public_projection_model_delivery ثبت کرده بود.
- علت کدی: سیاست go-live جدول public_projection_model_catalog را CLEAR می‌کند. تریگر `tr_h322_projection_outbox_model_targets_ai` در migration شماره 0062 فقط بر اساس همین کاتالوگ برای صف جدید watermark می‌سازد. وقتی کاتالوگ خالی شود، syncProjectionModelsV2 هیچ هدفی پیدا نمی‌کند و رکوردهای صف را تحویل‌شده علامت می‌زند، بدون اینکه snapshot جدیدی به Public برسد. شرط فعال‌سازی Public وجود حداقل یک remote_read_models و heartbeat تازه است.
- اصلاح محدود: فقط هنگام resync_required کاتالوگ ۱۱ کلید مورد انتظار از سورس، به‌صورت idempotent و fail-closed بازسازی می‌شود، سپس همان منطق کامل همگام‌سازی مدل‌ها اجرا می‌گردد. هیچ داده خرید، کاربران، منو یا ثبت مالی حذف/ویرایش نمی‌شود. migration و سیاست پاکسازی حین عمل تغییر نمی‌کنند؛ کاتالوگ بازسازی‌شده داده عملیاتی جدید است.
- تشخیص تکمیلی: در صورت ناتمام‌ماندن، GoLiveResetCoordinator آخرین failed_channels و خطاهای مدل و authority_activate را بدون payload حساس در فایل نشانگر ثبت می‌کند؛ درخواست GET صفحه مدیریت فقط آن را می‌خواند.
- ایمنی: R176 و Public و Maintenance و main دست‌نخورده. بازیابی میدان واقعی هنوز باید پس از آزمون MariaDB و Windows انجام و با گرفتن receipt اتمام تأیید شود.
- در وضعیت resync_pending نصب به‌تنهایی عملیات پاکسازی را آغاز نمی‌کند و تا پذیرش میدانی کسب‌وکار مسدود می‌ماند.
