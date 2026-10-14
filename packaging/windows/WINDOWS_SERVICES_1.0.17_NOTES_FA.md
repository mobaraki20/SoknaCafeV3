# SOKNA Windows Services 1.0.17

این نسخه مسیر Windows Runtime / Windows Services را برای پایداری کامل‌تر ارتباط Local↔Public ارتقا می‌دهد.

- Windows Runtime: `1.0.5`.
- Windows Services package: `1.0.17`.
- Print Agent بدون تغییر: `6.2.9`.
- Local Web، Public Edge، Hall، Guest UI و business-data توسط این بسته Windows تغییر نمی‌کنند.
- تمام بهبودهای latency نسخه 1.0.16 حفظ شده‌اند: `public.relay_sync` با cadence مؤثر ۱ ثانیه، scheduler tick صد میلی‌ثانیه و trigger laneهای مستقل/no-overlap.
- برای هر trigger بودجه زمانی صریح تعریف شده است؛ `public.relay_sync` حداکثر ۴.۵ ثانیه و `public.projection_sync` حداکثر ۱۵ ثانیه بودجه دارد.
- Runtime از timeout سراسری HttpClient استفاده نمی‌کند؛ deadline هر trigger با CancellationToken مستقل enforce می‌شود.
- payload Runtime شامل `budget_ms` و `deadline_at` است تا Local بتواند deadline سرتاسری را حفظ کند.
- HTTPهای `429/502/503/504/408` و سایر `5xx` به‌عنوان overload/transient طبقه‌بندی می‌شوند؛ `401/403` authentication و `426` compatibility هستند؛ سایر `4xx` domain/definitive محسوب می‌شوند.
- `Retry-After` رعایت می‌شود و backoff نمایی محدود همراه jitter پایدار اعمال می‌شود.
- retry budget برابر حداکثر ۶ تلاش در پنجره ۶۰ ثانیه است تا failure storm ایجاد نشود.
- retryهای transient همان scheduled/request identity را حفظ می‌کنند؛ failureهای definitive هویت بعدی را rotate می‌کنند.
- خطای authentication حداقل ۱۲۰ ثانیه و compatibility حداقل ۶۰ ثانیه policy-block می‌شود تا Runtime endpoint معیوب را پشت سر هم نکوبد.
- Runtime Health همچنان configured/effective cadence، next run، in-flight، failure count و last error را گزارش می‌کند.

## ارتقا

روی سیستم تست، Windows Services 1.0.17 را با Install/Repair عادی نصب کنید. سرویس `SoknaRuntime` با Runtime 1.0.5 جایگزین و restart می‌شود و Print Agent 6.2.9 بدون تغییر باقی می‌ماند. نیازی به Force، تغییر دستی `runtime-config.json` یا نصب مرحله‌ای 1.0.16→patch نیست.

این release از patch-chain مهندسی ARCH-05/06 روی source ثابت `release/windows-services-1.0.16@fbc2d63910ecb95b790b15b97448feecb113bacd` ساخته و در GitHub Actions ویندوز compile/qualify می‌شود.

## مرز پذیرش

انتشار و CI موفق یعنی **release-qualified**؛ تأیید نهایی مسیر واقعی mobile-data/Public و SLA همچنان باید در ARCH-22 روی سیستم تست واقعی انجام شود و release موفق به‌تنهایی production-pass محسوب نمی‌شود.
