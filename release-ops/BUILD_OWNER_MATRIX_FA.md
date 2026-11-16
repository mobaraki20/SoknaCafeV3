# نقشه ابزارهای موجود و مالک مسیر ساخت — ممیزی ۱۶ نوامبر ۲۰۲۶

**کاربری:** به‌جای ساخت موتور یا builder موازی، از همین ابزارهای واقعی استفاده می‌شود. وضعیت «موجود» به‌معنی عمومی/نصب‌پذیر بودن نیست. هیچ کد محصول یا Windows در این کار اصلاح نشده است.

| مؤلفه و قالب | ابزار موجود/مسیر مستند | کاربرد قابل استفاده مجدد | مانع یا محدودیت واقعی |
|---|---|---|---|
| Local / Legacy `sokna-component-package-v1` | `apps/local-web/tools/build-update-package.py` در شاخه R241؛ `src/Domain/Update/LocalUpdateService.php` در سورس R233 | ساخت و تست Manifest/Payload قدیمی | `LegacySlotUpdateGuard` ممکن است روی نصب مدیریت‌شده با slot این راه را ببندد؛ **جایگزین Maintenance Release Set نیست** |
| Local / Maintenance `sokna-maintenance-release-set-v2.1` | `packaging/windows/release-gates/check_local_release_set.py` و `qa/sokna-r184/release_gate.py` | کنترل inner/outer ZIP، ۶ فیلد identity، backup، tree و qualification | builder عمومی برای آخرین سورس WORKING اثبات نشده؛ R241 از SHA پین‌شدهٔ 231fa ساخته و native CI خاص خودش دارد |
| Local / Windows native qualification | `.github/workflows/sokna-r241-exact-231fa-operator-release.yml` | الگویی از Freeze همان ZIP، Windows Maintenance Preflight→Stage→Activate→Readback، MariaDB 81 | به R201/231fa و نسخه‌ها/هش‌های ثابت قفل است؛ برای نسخه جدید نباید دستکاری یا کپی کورکورانه شود |
| Local / full and delta historical identities | `SOKNA-REL-GATE-001` در Library، `sokna_update_package_gate.py` | الگوریتم کشف ReleaseId واقعیِ Full/Delta از آرشیو | registry فعلی کامل نیست؛ VERSION یکسان می‌تواند ReleaseId متفاوت داشته باشد؛ سندهای Synthetic = مدرک نصب نیست |
| Public / Routine Update | `apps/public/tools/build-update-package.py` R248 و `r249/build_r249.py` | ساخت `sokna-component-package-v1` و نگهداری managed subtree | سازنده‌های قدیمی ممکن است `from_*` تک‌مبنا داشته باشند؛ R249 روی 35HK/35HT پین شده است |
| Public / independent Emergency | `r248/sokna_public_release_gate.py` و کد `src/Emergency/`, `src/Update/` در R233 | الگوی کنترل owner ثابت، hash/target و دو مسیر | ابزار R248 برای R171/R246 مشخص طراحی شده؛ بدون UAT هر دو مسیر با **همان SHA ZIP** نصب‌پذیر تلقی نمی‌شود |
| Public / Clean root | `build_r233_pre_release.py` در Library | نمونه خروجی **۲۳۳ فایل مسطح** با `index.php` و `setup/` در روت؛ نسخه‌بندی مقصد | QA-only، بدون host acceptance؛ فایل `emergency-owner-v1.json` سورس هنوز نسخه 35HK دارد، باید هویت مقصد آزموده شود |
| Public / historical deploy-v1 | `apps/public/tools/build-deploy-package.py` R248 | قالب دارای `manifest.json` و `public/index.php` | با فایل R233 clean root یکی نیست؛ ممکن است برای root host اشتباه باشد |
| Windows / Runtime, Print Agent, Maintenance | `docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md` + workflowهای component release | استقلال نسخه‌ها، KEEP بدون mutation، PowerShell 5.1، SCM/UAC/UTF-8-BOM، pin hash | کار حاضر **فقط مطالعه و کنترل انتشار** است؛ هیچ EXE/Windows service/package rebuild انجام نشد |
| Windows / experimental publisher | PR #47، `work/v4-universal-update-release-pipeline-20261025` | برخی آزمون‌های portability و baseline قابل استخراج‌اند | بخش signer/publisher key **مطابق canonical v1.0.3 منسوخ**؛ استفاده مجدد آن به‌عنوان الزام ممنوع |
| REG-003 / accepted fixes | Library `check_source_preservation-source-first.py` و `R158-55-SEMANTIC-CODE-MATRIX.json` | تطبیق اصلاحات پذیرفته‌شده از منبع تا بسته | لیست همهٔ ۲۳۳ سابقه یا ۲۷ HOLD را خودکار «انجام‌شده» نکن؛ ماتریس باید از تصمیم‌های مجاز ساخته شود |
| QA-001 / Common gates | `release-ops/source_gate.py`, `release-ops/audit.py`, GitHub Actions این PR | شروع با source SHA، آزمون همان ZIP، بازتولید R75/R99/R105/R240، جلوگیری از تکرار شناسه و انتشار بی‌مدرک | فقط Static/Source; نصب Windows/host و رفتار مالی/چاپ واقعی نیازمند شواهد جدا هستند |

## ترتیب درست استفاده، بدون ساخت موازی
1. خواندن `AGENTS.md` و `release-ops/READ_FIRST_FA.md`، بررسی آخرین source pointer معتبر Library؛ روی `main` یا R257 به‌عنوان سورس خودکار حساب نکن.
2. `source_gate.py` روی **همان آرشیو کامل** با sha/tree و شمارش؛ تجدید registry فقط با شاهد واقعی، نه نسخه انتخابی.
3. انتخاب مسیر **واقعی**: Local Legacy vs Maintenance (نسخه نصب و capability)، Public Update vs Public root Clean؛ builder موجود منطبق با آن مسیر.
4. اجرای builder موجود فقط روی pinned source و baseline واقعی؛ Metadata target را جداگانه محاسبه و کنترل کن، نه ویرایش PHP/JS/SQL برای انتشار.
5. `source_gate.py --candidate` برای مقایسه کد بایتی، سپس `audit.py` برای هش/manifest/owner/inventory/registry؛ آزمون‌های native و host برای همان SHA جداگانه ثبت شوند.
6. تحویل فقط پس از قبولی همه آزمون‌های لازم؛ در غیر این صورت خروجی SOURCE_ONLY/QA و علت BLOCKED شفاف.

## گپ‌های باز که نباید با پرچم سبز پنهان شوند
- آخرین سورس پذیرفته‌شده در Library است، نه GitHub؛ هیچ Artifact قابل اتکای R233 در GitHub run 43649705367 منتشر نشده است. برای خط تولید کامل، **بایتِ همان source ZIP** با SHA باید به Artifact امن GitHub منتقل شود.
- سازندهٔ عمومیِ Local Maintenance Release Set برای R233 از سورس کامل هنوز شناسایی/تأیید نشده و R233 `local-managed-files-v1.json` ناقص ۵۹۰/۵۹۳ است.
- فایل آزمایشی Public R233 نیازمند تجدید metadata owner به نسخه مقصد و آزمون Routeهای مستقل است؛ صرف `VERSION.txt` کافی نیست.
- Runtime/PrintAgent/Windows source را نباید برای QA یک تغییر Public بی‌دلیل rebuild کرد؛ real hardware checks در انتشار مرتبط الزام جدا دارند.

**ادعای خروجی این PR:** ابزار و مرجع انتشار GitHub آمادهٔ کنترل read-only و آزمون‌های منفی است؛ موتور/سرویس/اپلیکیشن و میزبان دست‌نخورده. هنوز سازندهٔ عمومی بسته نصب‌پذیر و مدرک عملیاتی end-to-end ایجاد نشده است.

## آزمون بازپخش و ساخت واقعی بدون تغییر محصول (R233)

- دو ZIP سورس با بایت‌های دقیق Library در GitHub بازسازی و منبع‌شناسی شد: [Actions run 43829020152](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43829020152).
- QA بستهٔ Public Root Clean با همان سورس، فقط نسخه/owner در کپی موقت و بدون تغییر فایل‌های عملکردی انجام شد: [Actions run 43829800080](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43829800080). **Static PASS، functional byte drift=0، Production NO-GO**. ZIP قابل نصب در artifact خروجی منتشر نشده است.
- بازپخش به آرشیو R204 در Actions run 43513196061 وابسته است که ممکن است expire شود؛ برای پایداری نسخه‌های بعد باید منبع immutable تحت retention پایدار قرار گیرد، وگرنه fail-closed.
