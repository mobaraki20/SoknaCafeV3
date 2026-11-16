# ممیزی read-only سورس واقعی R233 — ۱۶ نوامبر ۲۰۲۶

**منبع واقعی**: دو ZIP آرشیو WORKING در Library، نه شاخه `main` یا `R257`. اینها source-only هستند و نصب آنها ممنوع است. در این ممیزی محتوا تغییر نکرده است.

| فیلد | Local R233 | Public R233 |
|---|---|---|
| SHA256 ZIP اصلی | `783bdde1ecd7bad4e8043d8591b52a2e628b99bc6cd32b2247c4786c5eba7a2d` | `8d89b156b3efcd380763d907796a9c6914f9ec2c27f40c1a61d04ffb6dc4ca7b` |
| فایل سورس | ۵۹۳ | ۲۳۳ |
| tree مبنا با الگوریتم `path:sha256\n` | `f8d6bce01b2cee08b290a361c6759c24af6122ac99c39c1dc65e2bc0d5b60d0f` | `ecb2915c599ba57ed2695fb89b8907046294f797c303f3bb19d6b27b5c84f7d0` |
| `VERSION.txt` **داخل سورس** | `3.0.0-g6.2-workspace.231fb-r204-ux-zero-empty-canary` | `3.0.0-g3.6-workspace.35hy-r204-farsi-description-canary` |
| migration | ۸۱ | ۸ |
| مجوز نصب | خیر | خیر |

## دو یافتۀ جدید که باید قبل از تولید خروجی بررسی شوند

۱. **Local managed inventory**: در `resources/local-managed-files-v1.json` دقیقاً ۵۹۰ مسیر ذکر شده در حالی که آرشیو ۵۹۳ فایل دارد. موارد جاافتاده:
- `public/assets/settlement-display-currency.js`
- `src/Domain/Sellables/BundlePricePolicy.php`
- `src/Relay/AccountPasswordRealtimeAdapter.php`

این همان کلاس خطای R239→R240 است. در بسته Release Set مخصوص Maintenance باید inventory با **تمام فایل‌های slot managed مقصد** یکسان باشد. اصلاح خودکار سورس یا ادعای قبولی نصب ممنوع؛ در مرحله ساخت، تولید inventory درست از payload واقعی و آزمون آن نیاز است.

۲. **Public owner version**: در سورس، `src/Update/emergency-owner-v1.json` هنوز `release_version=3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa` دارد، در حالی که `VERSION.txt` داخل سورس 35hy قدیمی است. هنگامی که نسخه هدف جدید انتخاب شود، **metadata متعلق به بسته مقصد** باید با همان نسخه و هش owner منطبق باشد؛ تولیدکنندۀ R233 آزمایشی فقط VERSION را عوض کرده بود، بنابراین به‌روزرسانی از منظر هویت Emergency بدون آزمون مجدد قابل پذیرش نیست.

## تفاوت سه فرمت نصب که نباید اشتباه شوند

- **Local component update**: `sokna-component-package-v1` / component=`local` و `payload/`؛ مالک `src/Domain/Update/LocalUpdateService.php` و `LegacySlotUpdateGuard` در سورس R233. **برای هر نصب دارای slot-managed Maintenance، جانشین Release Set نیست**.
- **Local Windows/Maintenance**: `sokna-maintenance-release-set-v2.1`، `release-set.json` و `artifacts/local/web.zip`؛ باید توسط Maintenance Core نصب‌شده بررسی شود. بدون شواهد هویت نصب و native Windows قبول نمی‌شود.
- **Public**: Update با `manifest.json` + `payload/`؛ در مقابل، Clean Install روت R233 یک ZIP **مسطح، فاقد manifest اضافی** با `index.php` و `setup/` در root است. این با `sokna-public-deploy-v1` دارای manifest از آزمایش‌های تاریخی متفاوت است و نباید به‌عنوان archive خراب تشخیص داده شود.

## مرجع ابزار قبلی
`build_r233_pre_release.py` و `R233-PRE-RELEASE-CHECKSUMS-AND-GATES.json` در Library قبلاً سه کاندید R233 را با نسخه‌های `231fc-r233-qa` / `35hz-r233-qa` ساخته‌اند؛ صریحاً `NO-GO / QA-DO-NOT-INSTALL`. در این شاخه **هیچ بسته جدیدی نساختیم** و همان ابزار تاریخی را برای شناخت قراردادها استفاده کردیم.

**جمع‌بندی:** معیار انتخاب «آخرین سورس» با انتخاب «مسیر نصب» و نسخهٔ مقصد یکی نیست. GitHub باید ابتدا دو سورس را با SHA ثابت قبول کند، سپس نوع بسته و مالک واقعی نصب را تعیین کند، و بعد روی بسته نهایی آزمون انجام دهد.