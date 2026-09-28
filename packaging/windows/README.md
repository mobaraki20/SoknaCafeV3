# SOKNA Windows Services Packaging — G5

این پوشه فقط مالک بسته‌ی بومی ویندوز برای **SOKNA Runtime + Print Agent** است.

## مرز مالکیت
- Installer مالک Runtime و Print Agent، ثبت/حذف دو Windows Service و فایل‌های pairing خصوصی همین دو جزء است.
- Local Web به‌صورت ZIP و Browser Setup مستقل نصب می‌شود و هرگز payload این installer نیست.
- Public Edge مستقل deploy می‌شود و هرگز payload این installer نیست.
- PHP، Apache، MariaDB و سایر زیرساخت‌های مشترک **external** هستند. Setup فقط compatibility را detect می‌کند و در صورت انتخاب کاربر فایل hash/signature-locked را با progress/resume دریافت می‌کند؛ فایل را خودکار اجرا/نصب نمی‌کند.
- Uninstall/Repair داده‌ی تجاری یا زیرساخت خارجی را حذف/تغییر نمی‌دهد.

## lifecycle
UI بومی `SoknaSetupUi.exe` سه عمل دارد: Install/Update، Repair و Uninstall. اجرای privileged از طریق `SoknaSetupHost.exe` و سپس `setup-windows-services.ps1` انجام می‌شود. Pairing file اختیاری و versioned است.

## نسخه‌ها
نسخه installer از `WINDOWS_SERVICES_VERSION.txt` می‌آید. نسخه Runtime از csproj خود Runtime و نسخه Print Agent از `Directory.Build.props` خود Agent خوانده می‌شود. `windows-services-compatibility-v1.json` این نسخه‌ها و contractها را به هم متصل می‌کند؛ root `VERSION.txt` منبع نسخه‌ی Windows Services نیست.

## prerequisite acquisition
`platform/windows/prerequisites.json` policy detection را تعریف می‌کند و `platform/windows/release-lock.json` فقط URL/size/SHA-256/AuthentiCode frozen را مجاز می‌کند. دانلود قابل resume است و تا قبل از hash/signature verification از `.partial` به فایل نهایی promote نمی‌شود.

## qualification
G5 source/static gate در Workspace اجرا می‌شود. Build واقعی .NET/PowerShell/Inno و Windows SCM lifecycle در consolidated real-environment qualification ثبت می‌شود و blocker ادامه‌ی توسعه نیست.
