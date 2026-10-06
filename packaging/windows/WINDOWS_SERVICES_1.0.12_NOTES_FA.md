# Windows Services 1.0.12 — PDF Preview + Capability Negotiation

## دامنه

این نسخه فقط مسیر Preview در Print Agent و بسته‌بندی Windows Services را به‌روزرسانی می‌کند.

- Windows Services package: `1.0.12`
- Print Agent: `6.2.8`
- Runtime: `1.0.2` — بدون تغییر سورس/نسخه
- Prerequisites: بدون تغییر

## تغییر اصلی

Bridge محلی Print Agent حالا capability واقعی خودش را از مسیر `POST /v1/capabilities` اعلام می‌کند. Local Web نباید قابلیت PDF را از روی شماره نسخه Agent حدس بزند.

قابلیت‌های Preview این نسخه:

- `preview_formats = ["png", "pdf"]`
- PDF و PNG هر دو از همان raster تولیدشده توسط `ReceiptRenderer` استفاده می‌کنند.
- PDF با `ReceiptPdfWriter` موجود ساخته می‌شود.
- Preview هیچ durable print attempt ایجاد نمی‌کند و به printer فیزیکی وابسته نیست.
- `output_format` در `/v1/preview` افزایشی و backward-compatible است؛ default قرارداد Bridge همچنان PNG است.
- Agentهای قدیمی که `/v1/capabilities` ندارند باید توسط Local به‌صورت PNG-only در نظر گرفته شوند.

## مرز معماری

Print Agent و Runtime دو component/service مستقل هستند، اما در یک Windows Services installer نصب و مدیریت می‌شوند. این تغییر Runtime را دست نمی‌زند؛ فقط چون محتوای Windows Services تغییر کرده، package version از 1.0.11 به 1.0.12 افزایش می‌یابد.

مسیر durable چاپ (`claim/accept/start/report`)، pairing، credential، server-scope و منطق spooler اصلی تغییر نکرده‌اند.

## Qualification مورد نیاز

- build کامل Print Agent روی Windows/.NET 10؛
- regression تست‌های Print Agent و Preview؛
- اثبات عدم تغییر `windows/runtime/**` نسبت به release 1.0.11؛
- build کامل Windows Services installer 1.0.12؛
- بررسی artifact index: Runtime 1.0.2 و Print Agent 6.2.8؛
- UAT نصب‌شده برای PDF Preview و parity با چاپ حرارتی واقعی پیش از release نهایی.
