# SOKNA Cafe V3 — سیاست Checkpoint / Handoff / انتقال نهایی

تاریخ: 2026-09-27
وضعیت: **CANONICAL EXECUTION POLICY**

## اصل اجرایی

از این نقطه تا پایان Product Gap Closure، اجرای روزمره روی **workspace محلی ایجنت** انجام می‌شود. GitHub مسیر اجرای روزمره نیست.

هر workstream یا sub-workstream فقط وقتی «تمام‌شده» تلقی می‌شود که:
1. scope آن روشن باشد؛
2. implementation کامل شده باشد؛
3. تست‌های مربوطه سبز باشند؛
4. status/evidence آن ثبت شده باشد؛
5. snapshot/checkpoint قابل بازیابی در Library ذخیره شده باشد.

پس از بسته‌شدن هر بخش، checkpoint همان بخش بلافاصله در Library ذخیره می‌شود. اگر چت قطع شد، ایجنت بعدی باید از Library ادامه دهد، نه از chat history.

## GitHub policy

- در طول اجرای G0..G6، GitHub فقط source/reference تاریخی است مگر کاربر صریحاً انتقال بخواهد.
- برای هر sub-task به GitHub push/commit نمی‌کنیم.
- commitهای کاری در workspace محلی انجام می‌شوند.
- فقط بعد از کامل‌شدن مجموعهٔ مورد توافق و سبزشدن همهٔ gateها، transfer نهایی به GitHub انجام می‌شود.
- انتقال نهایی باید از checkpointهای Library و workspace نهایی reproducible باشد.

## ساختار اجباری checkpoint هر بخش

هر checkpoint باید حداقل این‌ها را داشته باشد:
- `STATUS_FA.md` — چه چیزی انجام شد/نشد؛
- `EVIDENCE.json` — tests, hashes, source refs, local commit/head؛
- `NEXT_FA.md` — قدم بعدی دقیق و scope ممنوعه؛
- source snapshot یا patch/bundle کافی برای بازیابی؛
- در صورت artifact: SHA256 و manifest.

## نام‌گذاری پیشنهادی

`/SOKNA_V3_WORKSTREAM_CHECKPOINTS/<NN>_<WORKSTREAM>/<checkpoint-id>/`

مثال:
`/SOKNA_V3_WORKSTREAM_CHECKPOINTS/G0_GOVERNANCE_REPAIR/g0-final/`

## قانون ادامه در چت جدید

ایجنت جدید باید به‌ترتیب بخواند:
1. `/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/00_READ_ME_FIRST_FA.md`
2. `/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/09_CONTINUATION_STATE.json`
3. `/SOKNA_V3_CONTINUE_HERE__PRODUCT_GAP_CLOSURE/12_WORKSTREAM_LEDGER.json`
4. checkpoint فعال/آخرین checkpoint همان workstream
5. فقط owner source و dependencyهای اعلام‌شده در scope

ایجنت نباید از صفر audit کند و نباید از GitHub branch state حدس بزند که آخرین workstream چیست، مگر Library صریحاً GitHub transfer را به‌عنوان آخرین مرحله ثبت کرده باشد.

## Completion levels

- `IN_PROGRESS` = implementation هنوز باز است.
- `CHECKPOINTED` = بخش اجرا/تست شده و در Library durable است.
- `WORKSTREAM_COMPLETE` = همهٔ checkpointهای لازم آن workstream بسته شده‌اند.
- `TRANSFER_READY` = مجموعهٔ مورد توافق کامل است و آمادهٔ انتقال نهایی به GitHub است.
- `TRANSFERRED` = انتقال نهایی انجام و verify شده است.

## ممنوع

- اعلام completion بدون Library checkpoint.
- وابسته‌کردن continuation به chat history.
- push پراکنده به GitHub وسط workstreamها.
- حذف checkpoint قبلی هنگام ساخت checkpoint جدید؛ checkpoint جدید باید append-only باشد مگر فایل canonical state که versioned overwrite می‌شود.
