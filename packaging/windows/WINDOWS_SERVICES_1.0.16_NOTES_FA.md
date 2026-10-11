# SOKNA Windows Services 1.0.16

این نسخه فقط مسیر Windows Runtime / Windows Services را تغییر می‌دهد و برای کاهش latency همگام‌سازی `Public → Local` منتشر می‌شود.

- Windows Runtime: `1.0.3`.
- Windows Services package: `1.0.16`.
- Print Agent بدون تغییر: `6.2.9`.
- Local Web، Public Edge، Hall، Guest UI و business logic در این نسخه تغییر نکرده‌اند.
- cadence مجاز `public.relay_sync` از floor قدیمی پنج‌ثانیه‌ای آزاد شده و حداقل امن آن ۱ ثانیه است.
- config قدیمی `public.relay_sync=5` بدون rewrite فایل، به‌صورت `effective=1s` reconcile می‌شود.
- scheduler tick از حدود ۲ ثانیه به ۱۰۰ میلی‌ثانیه کاهش یافته است.
- هر trigger lane مستقل و دارای no-overlap است؛ کندی یا failure یک trigger، triggerهای دیگر را متوقف نمی‌کند.
- ambiguous transport retry همان scheduled identity/request identity را حفظ می‌کند.
- definitive Local HTTP failure هویت scheduled request را rotate می‌کند.
- failure backoff محدود و capped تا ۳۰ ثانیه است؛ catch-up/runaway loop وجود ندارد.
- Print Agent supervision از trigger scheduler جدا شده تا recovery سرویس چاپ cadence relay را block نکند.
- Runtime Health و `runtime-state.json` cadence configured/effective، last start/completion، next run، in-flight، failure count و last error هر trigger را گزارش می‌کنند.
- intervalهای 15/30/60 ثانیه‌ای و custom triggerهای غیرمجاز برای fast cadence سریع‌تر نمی‌شوند.

## ارتقا

برای سیستم دارای Windows Services 1.0.15 یا قدیمی‌تر، فقط Windows Services 1.0.16 را Install/Repair کنید. سرویس `SoknaRuntime` با Runtime 1.0.3 جایگزین و restart می‌شود. نیازی به تغییر دستی Local/Public یا `runtime-config.json` نیست.

پس از ارتقا، در Runtime Health برای `public.relay_sync` باید `effective_interval_seconds=1` دیده شود. اگر config قدیمی هنوز مقدار ۵ را دارد، `configured_interval_seconds=5` طبیعی است و نشان می‌دهد reconciliation سازگار با نصب قدیمی فعال شده است.
