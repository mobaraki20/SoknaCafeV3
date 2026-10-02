# SOKNA Windows — Print Agent

این پوشه Source of Truth سرویس مستقل Print Agent در معماری V3 است.

## مبنا
- state machine عملیاتی از lineage بالغ Sokna Print Agent `6.2.5` حفظ و تا نسخه فعلی `6.2.7` سخت‌سازی شده است.
- provenance مبنای مهاجرت در `PROVENANCE.json` ثبت شده است.
- SQLite durable queue، retry/reconciliation، renderer، submission fence و Winspool به‌جای بازنویسی از همان مبنا حفظ شده‌اند.

## مالکیت معماری قطعی
- مالک اجرای فیزیکی چاپ، durable receipt/submission state و تعامل با spooler مؤلفه `windows/print-agent` است.
- Print Agent یک Windows service مستقل از Local Web است؛ Local Web فقط business print intent، document content، مقصدها و Print API v4 را مالک است.
- Windows Runtime می‌تواند lifecycle سرویس را supervise کند، اما state machine چاپ را تکرار نمی‌کند.
- نصب/repair این مؤلفه در بسته Windows Services محصول هماهنگ می‌شود؛ این موضوع استقلال runtime/service آن از Local Web را تغییر نمی‌دهد.
- namespaceهای فنی `Sokna.PrintAgent.*` قرارداد داخلی همین مؤلفه‌اند و authority استقرار آن `windows/print-agent` است.

## داده پایدار
مسیر canonical داده زیر Data Root سکنا است (`<SOKNA_DATA_DIR>/print-worker`). Resolver برای مهاجرت امن registry قدیمی PrintAgent را نیز به‌صورت fallback می‌خواند تا queue/config/secret موجود بدون تصمیم صریح دور ریخته نشوند.

## P5 سخت‌سازی
- تغییر `secret.dat` به‌صورت hot-reload تشخیص داده می‌شود و credential جدید فقط پس از probe احراز هویت‌شده فعال می‌شود.
- Local Web rotation را با pairing ticket کوتاه‌عمر stage می‌کند و hash جدید همان `agent_id` فقط در confirm نهایی commit می‌شود.
- Agent حداقل نسخه اعلام‌شده توسط Local Web را enforce می‌کند تا rollback ناسازگار متوقف شود.
- M8 CI علاوه بر build/unit/contract، سناریوهای credential repair، restart reconciliation و Windows submission-fence crash recovery را اجرا می‌کند.
