# آزمون روی آرشیوهای واقعی — ۲۰۲۶-۱۱-۱۶ (فقط خواندنی)

**مسیر آزمون:** [GitHub Actions run 43819369750](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43819369750) — job `historical-real-archive-lab`.

**شرایط:** دو ZIP کاملِ واقعی از Artifactهای CI گذشته خوانده شدند. هیچ Stage/Activate/Commit، میزبان واقعی، دیتابیس، سرویس ویندوز یا بسته‌ای تغییر نکرد. هر دو با همان `release-ops/audit.py` نهایی بررسی شدند.

| منبع واقعی | SHA256 دقیق ZIP | خروجی استاتیک |
|---|---|---|
| R201 Local: `SOKNA-R201-LOCAL-231FA-60BASE-EXACT-WINDOWS-TECH-CANDIDATE.zip` از run `43491934082` | `911ea673dc1863bee3ce372dbc692602aa3a32439d2fc9ccba69dc2a6c243897` | PASS؛ ۶۰ هویت مبدأ، هش، target، inventory و format سالم |
| R171 Public: `SOKNA-R171-PUBLIC-35HT-FROM-35HK-PANEL-UPDATE.zip` از run `43205002445` | `ea1d2f7d90242d5b45fe232da7ff5510f03ee55c835e2dabe5f61980430be157` | FAIL: `PUBLIC_EMERGENCY_OWNER_VERSION_OR_HASH_MISMATCH` و `PUBLIC_ONE_SOURCE_ONLY_COMPATIBILITY` |

**تفسیر:** R171 قبلاً در پرونده‌های R247/R248 به‌عنوان دارای owner ثابت/managed ناسازگار ثبت شده بود؛ این آزمون آن را در فایل واقعی بازتولید کرد. وجود `compatibility.from_*` در این ZIP تاریخی نیز برای «بسته مقصد عمومی چندمبنایی» به‌درستی رد شد. از این مشاهده نمی‌توان نتیجه گرفت Public نصب‌شده روی هاست خاصی هنوز همین وضعیت را دارد.

**حدود شواهد:** Local R201 تنها PASS ایستای فایل است، نه اثبات نصب Windows یا حفظ اصلاحات تاریخی. در هر دو بسته، source snapshot کامل، ماتریس accepted fixes، تطبیق Update/Clean و UAT میزبان در این اجرای تازه `NOT_PROVEN` ماند.

**پایداری:** Artifactهای اصلی GitHub retention محدود دارند؛ این نتیجه و SHAها پایدار ثبت شدند و آزمون‌های منفی بدون وابستگی به انقضای Artifact در `release-ops/tests/test_audit.py` باقی می‌مانند. Job موقت دریافت آرشیو پس از ثبت شواهد از Workflow دائمی حذف می‌شود تا CI آینده به artifactهای منقضی وابسته نباشد.
