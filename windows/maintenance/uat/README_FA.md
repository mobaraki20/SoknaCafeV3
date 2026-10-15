# SOKNA Maintenance V4 — Windows Real-Host UAT Bundle

این بسته فقط برای **UAT واقعی روی سیستم پشتیبانی‌شده سکنا** است و Production/Final Release نیست.

## هویت منبع محصول

- repository: `mobaraki20/SoknaCafeV3`
- exact reconciled product commit: `61f881a0d68ab978de2e3435d37d759c4f07b4b4`
- exact product tree: `c59308634ddfc57ed114d316610cdfd2a1c62d7c`
- Supervisor: `0.2.1`
- Maintenance Core / Recovery Console: `0.1.1`
- Update Engine: `0.2.0`
- protocol: `maintenance_control_protocol 1.0`

UAT tooling روی child branch جداگانه اضافه شده و workflow باید قبل از ساخت bundle ثابت کند که هیچ product source نسبت به commit بالا تغییر نکرده است.

## محتوای بسته

- `app/` — self-contained Supervisor + Update Engine
- `recovery/` — independent self-contained Recovery Console
- `scripts/Install-MaintenanceV4-UAT.ps1`
- `scripts/Uninstall-MaintenanceV4-UAT.ps1`
- `scripts/Capture-MaintenanceV4-UAT.ps1`
- `BUNDLE_MANIFEST.json`
- `PROVENANCE.json`
- `SHA256SUMS.txt`

هیچ `web-adapter.key` یا `recovery-console.key` داخل bundle وجود ندارد. کلیدها فقط در runtime روی سیستم مقصد و زیر `%ProgramData%\SOKNA\Maintenance` ایجاد/نگهداری می‌شوند.

## قبل از نصب

1. PowerShell را **Run as Administrator** باز کنید.
2. ابتدا hash خود ZIP و سپس `SHA256SUMS.txt` / `BUNDLE_MANIFEST.json` را با checkpoint Library تطبیق دهید.
3. Local/Public را در این مرحله تغییر ندهید.
4. اگر `SoknaMaintenanceSupervisor` از قبل وجود دارد و ImagePath آن خارج از install root همین UAT است، installer عمداً fail-closed می‌شود و سرویس موجود را تغییر نمی‌دهد.

## نصب UAT

از ریشه Extract شده bundle:

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
.\scripts\Install-MaintenanceV4-UAT.ps1
```

رفتار مورد انتظار:

- سرویس `SoknaMaintenanceSupervisor` با startup خودکار ثبت می‌شود؛
- service account برای UAT استاندارد `LocalSystem` است؛
- ProgramData Maintenance خارج از Local Web tree باقی می‌ماند؛
- Supervisor خودش `SoknaApache` را بررسی و ACL policy نهایی را اعمال می‌کند؛
- `control-plane.json` و `web-adapter-acl.json` ایجاد می‌شوند؛
- هیچ Local/Public source تغییر نمی‌کند.

اگر `web_adapter_acl_state = acl_pending` شد، **مجوز را دستی broad نکنید**؛ evidence را capture کنید و علت identity/ImagePath را بررسی کنید.

## Capture اولیه

```powershell
.\scripts\Capture-MaintenanceV4-UAT.ps1
```

این اسکریپت موارد زیر را ثبت می‌کند:

- `SoknaMaintenanceSupervisor`, `SoknaApache`, `SoknaRuntime`, `SoknaPrintWorker` state/account/ImagePath؛
- descriptor و Web Adapter ACL evidence؛
- ACL text روی descriptor/secret paths؛
- hash باینری‌های نصب‌شده؛
- loopback listener؛
- اجرای واقعی یک process با temporary unrelated standard-user token برای اثبات عدم امکان خواندن `web-adapter.key`.

**مقدار هیچ کلیدی خوانده، hash، چاپ یا export نمی‌شود.** temporary probe user در پایان حذف می‌شود.

## گام Web بعد از Windows PASS اولیه

فقط اگر baseline واقعی Local دقیقاً gateهای candidate را پاس کرد، بسته زیر از Library stage/activate شود:

`SOKNA-Local-Web-Delta-Upgrade-230c-to-231m-Maintenance-V4-ReadOnly-Integration-UAT.zip`

سپس از Local واقعی:

- `GET system/api.php?section=maintenance`؛
- موفقیت هر هفت read route با HMAC server-side؛
- descriptor discovery بدون hard-code؛
- restart Supervisor در حالی که Maintenance tab باز است؛
- عدم نشت key/signature/nonce/auth header در browser/network/log/Support Bundle؛
- خاموش‌کردن Local Web و تأیید استقلال Recovery Console؛
- reboot و تکرار service/descriptor/HMAC checks.

## Uninstall / rollback ابزار UAT

```powershell
.\scripts\Uninstall-MaintenanceV4-UAT.ps1
```

این کار فقط سرویس و باینری‌های UAT را حذف می‌کند. `%ProgramData%\SOKNA\Maintenance` عمداً حذف نمی‌شود تا state/secrets/evidence برای recovery و تحلیل باقی بمانند.

هیچ Force یا full-snapshot overlay برای دورزدن baseline mismatch مجاز نیست.
