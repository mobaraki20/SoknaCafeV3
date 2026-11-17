# کارخانه مشترک انتشار سکنا — مسیر یکپارچهٔ ساخت و کنترل بسته (۱۷ نوامبر ۲۰۲۶)

## نتیجه اجرایی

ورودی اصلی همان [SOKNA Unified Release Factory](https://github.com/mobaraki20/SoknaCafeV3/actions/workflows/sokna-release-factory.yml) است. از این پس در یک Workflow، سه مسیر مجزا دارد:

| مسیر | کارکرد | وضعیت خروجی |
|---|---|---|
| `historical-r233` | نسخهٔ مرجع R233؛ Local + Public + QA Windows/MariaDB | QA موقت، نه قابل نصب عملیاتی |
| `legacy-public-35ic` | بسته‌های دقیق R265/35if و R266/35ig از سازنده R268؛ بررسی *همان ZIPهای اصلی* در گیت مشترک | دو مرحله فقط برای Emergency قدیمی؛ QA و پذیرش میدانی باز |
| `registered-candidate` | ورود بستهٔ جدید از هر بسته‌ساز موجود در GitHub با قرارداد نسخه‌بندی‌شده، Run ID و SHA | کنترل ساختاری بایت‌ها؛ سایر آزمون‌های تخصصی و پذیرش هنوز باید برای همان بسته ضمیمه شوند |

**نکتهٔ حیاتی:** «ساخته‌شده داخل کارخانه» و «کنترل بایت‌ها توسط کارخانه» دو موضوع مستقل‌اند. بسته‌های تاریخی R265/R266 *خارج* از کارخانه ساخته شده‌اند، اما اکنون امکان کنترل مجدد همان فایل‌ها از **ورودی مشترک** را دارند. وجود رسید به‌تنهایی تأیید نصب عملیاتی نیست.

## استفاده برای بسته جدید بدون ساخت کارخانه موازی

۱. با سازندهٔ اصلی همان مؤلفه، ZIP را بساز و در **همان اجرای سازنده** به‌عنوان Artifact ثبت کن. از دستورهای موقت یا کپی دستی روی سرور برای جایگزینی فایل خروجی استفاده نکن.

۲. در GitHub روی شاخهٔ همان سورس تأییدشده، یک قرارداد `sokna-candidate-contract-v1` در `release-ops/` ثبت کن. مثال:

```json
{
  "schema": "sokna-candidate-contract-v1",
  "component": "public",
  "producer": {
    "run_id": 123456789,
    "artifact": "PUBLIC-RXXX-EXACT-QA-PACKAGES"
  },
  "source_authority": {
    "approved_checkpoint": "APPROVED_WORKSPACE_CHECKPOINT",
    "source_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  },
  "packages": [
    {
      "file": "SOKNA-RXXX-PUBLIC-UPDATE.zip",
      "sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
      "component": "public",
      "kind": "public-update",
      "version": "3.0.0-g3.6-workspace.35xx-rxxx"
    }
  ],
  "production_authorized": false
}
```

اعداد و SHA مثال هستند و نباید به‌عنوان مقادیر واقعی استفاده شوند. Package Kind برای Local می‌تواند `local-update` / `local-legacy-update` / `local-clean` و برای Public `public-update` / `public-clean` باشد. از هر نام فایل و SHA یکسان در همه مراحل QA استفاده می‌شود.

۳. مسیر GitHub Actions → Unified Factory را اجرا کن و گزینهٔ `registered-candidate` را انتخاب کن؛ سه مقدار `candidate_contract_path`، `candidate_run_id` و `candidate_artifact` را دقیقاً مطابق قرارداد بده. یا سازندهٔ آینده خودش `.github/workflows/sokna-release-factory.yml` را با `workflow_call` و همین ورودی‌ها فراخوانی کند. خروجی `SOKNA-UNIFIED-FACTORY-CANDIDATE-DECISION-ONLY` یک گزارش JSON قابل رهگیری است.

۴. گیت مشترک `.github/workflows/sokna-release-candidate-gate.yml` پیش از دریافت Artifact قرارداد را با Run ID مقایسه می‌کند و پس از دریافت، **خود بایت‌های ZIP** و Manifest/Tree را می‌خواند. در یک اجرا که سازنده خودش کارخانه را صدا بزند، `built_inside_factory=true` تنها وقتی قابل ثبت است که Producer Run ID همان Run ID گیت باشد؛ برای بازآزمایی بسته‌ای قدیمی مقدار آن false است. اگر فایل مفقود یا SHA غلط باشد، FAIL و نه PASS.

۵. **تفاوت مهم:** مسیر `registered-candidate` فعلاً گیت ساختاری است، نه جایگزین QA اختصاصی نصب. تا وقتی تأیید نسخه مبدأ، Source/REG-003، Database Migration و Recovery، Windows یا Public HTTP، مالی/چاپ/ارتباطات و پذیرش واقعی به همان SHA متصل نشده‌اند، `source_approved=false` و `production_authorized=false` باقی می‌مانند. هیچ دکمه «رد کردن هشدار» یا انتشار خودکار نداریم.

## مسیر خاص R265/R266

در GitHub Actions → Unified Factory گزینهٔ `legacy-public-35ic` را انتخاب کن. این مسیر از قرارداد `release-ops/external-public-r268-provenance.json` و Artifact تاریخی Run **44025587468** استفاده می‌کند و حتماً بایت‌های واقعی R265 و R266 را دوباره بررسی می‌کند؛ دستکاری بایت‌ها یا ساخت ZIP جدید وجود ندارد.

- مبدأ خاص: R249 / `35ic` با ۸ Migration و Emergency محدود به تغییر کد.
- بسته اول: R265 / `35if`، بدون SQL جدید؛ بعد از Commit باید ۸ Migration بماند.
- بسته دوم: R266 / `35ig`، سه Migration جدید؛ بعد از Commit باید ۱۱ Migration باشد.
- **این دو مرحله راه‌حل یک‌باره برای نسل قدیمی است.** برای هر نسخه جدید Public، قاعده عمومی «یک ZIP یکسان برای Routine و Emergency همان نسخه» پابرجاست.
- فیش پذیرش نهایی هاست فقط پس از تأیید Backup و Restore مستقل، Health پس از هر مرحله، عدم سفارش مهمان فعال و بررسی مالک Local/Public صادر می‌شود.

**محدودیت نگهداری:** Artifact اصلی R268 با نگهداری هفت‌روزه ایجاد شده بود. اگر منقضی شود، مسیر مرتبط عمداً FAIL می‌شود؛ باید همان بایت‌های پین‌شده از آرشیو قابل اعتماد دوباره تحت یک Run قابل رهگیری وارد شوند. نتیجه QA قبلی به یک Artifact بازسازی‌شده نسبت داده نشود.

## گزارش اجباری کنار خروجی

- `Factory gate used: YES/NO`
- `Built inside factory: YES/NO`
- `Producer workflow + run ID` و `Factory run ID`
- `Source checkpoint + exact source SHA`، نوع بسته و مبناهای پشتیبانی‌شده
- `ZIP SHA256` **همان فایل تحویلی**
- نتیجه بررسی‌های ساختاری و دیتابیس / Windows / Public Routine+Emergency
- `field backup/restore`، `installed host activation`، `guest sync`، `print physical` و سایر کنترل‌های مرتبط PASS/FAIL/NOT_RUN
- `operator release status`: تا وقتی شواهد لازم کامل نباشند، **BLOCKED**

## اصول ایمنی

Workflowها فقط مجوز **خواندن** GitHub و **آپلود گزارش QA** دارند؛ هیچ کد محصول، Maintenance/PrintAgent، دیتابیس، هاست یا بسته‌ای نصب/منتشر نمی‌شود. سورس `main` به‌تنهایی به‌عنوان نسخه جدید نهایی پذیرفته نیست؛ آخرین Workspace و تصمیم‌های REG-003/۲۷ HOLD پیش‌نیاز پذیرش سورس است. کنترل ساختاری موفق به‌معنای اثبات رفتار نسخه جدید نیست.

**معیار اتمام یکپارچه‌سازی در این مرحله:** یک ورودی GitHub برای R233 و R265/R266 و بسته‌های بعدی، یک گیت عمومی قابل فراخوانی، و رسید تفکیک‌شدهٔ منشأ ساخت و تأیید SHA؛ برای ادعای «انتشار خودکار نصب‌پذیر کامل» هنوز اتصال سازنده‌های اصلی هر مؤلفه و آزمون میدانی همان بسته لازم است.
