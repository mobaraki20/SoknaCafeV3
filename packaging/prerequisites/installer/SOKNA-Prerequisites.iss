#ifndef SourceRoot
  #error SourceRoot is required
#endif
#ifndef ProductVersion
  #define ProductVersion "0.0.0-dev"
#endif

[Setup]
AppId={{BF4DB10B-9F11-4A7A-8B02-1B090FC57B31}
AppName=SOKNA Prerequisites
UninstallDisplayName=SOKNA Prerequisites Setup
AppVersion={#ProductVersion}
AppPublisher=SOKNA
DefaultDirName={autopf}\SOKNA Prerequisites
DefaultGroupName=SOKNA
AllowNoIcons=yes
DisableProgramGroupPage=no
UninstallDisplayIcon={app}\Sokna.ico
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputBaseFilename=SOKNA-Prerequisites-Setup-{#ProductVersion}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
UninstallLogging=yes
CloseApplications=yes
CloseApplicationsFilter=SoknaPrerequisitesSetup.exe
RestartApplications=no
SetupIconFile={#SourceRoot}\Sokna.ico

[Files]
Source: "{#SourceRoot}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Tasks]
Name: "startmenuicon"; Description: "ایجاد میانبر در منوی Start"; GroupDescription: "میانبرهای برنامه:"; Flags: checkedonce
Name: "desktopicon"; Description: "ایجاد میانبر روی دسکتاپ"; GroupDescription: "میانبرهای برنامه:"; Flags: unchecked

[Icons]
Name: "{group}\آماده‌سازی زیرساخت سکنا"; Filename: "{app}\SoknaPrerequisitesSetup.exe"; IconFilename: "{app}\Sokna.ico"; Tasks: startmenuicon
Name: "{autodesktop}\آماده‌سازی زیرساخت سکنا"; Filename: "{app}\SoknaPrerequisitesSetup.exe"; IconFilename: "{app}\Sokna.ico"; Tasks: desktopicon

[Run]
Filename: "{app}\SoknaPrerequisitesSetup.exe"; Description: "آماده‌سازی PHP، Apache و MariaDB"; Flags: postinstall nowait skipifsilent runascurrentuser


[Code]
const
  SOKNA_UNINSTALL_KEY = 'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{BF4DB10B-9F11-4A7A-8B02-1B090FC57B31}_is1';

function GetInstalledVersion(var Version: String): Boolean;
begin
  Result := RegQueryStringValue(HKLM64, SOKNA_UNINSTALL_KEY, 'DisplayVersion', Version);
  if not Result then
    Result := RegQueryStringValue(HKLM32, SOKNA_UNINSTALL_KEY, 'DisplayVersion', Version);
end;

function InitializeSetup(): Boolean;
var
  ExistingVersion: String;
  CurrentVersion: String;
  PromptText: String;
begin
  Result := True;
  CurrentVersion := '{#ProductVersion}';

  if GetInstalledVersion(ExistingVersion) then
  begin
    if WizardSilent then
    begin
      Result := True;
      exit;
    end;

    if CompareText(ExistingVersion, CurrentVersion) = 0 then
      PromptText :=
        'نسخه ' + ExistingVersion + ' از SOKNA Prerequisites از قبل نصب است.' + #13#10 + #13#10 +
        'این اجرا فایل‌های برنامه را تازه‌سازی می‌کند و نصب موجود حفظ می‌شود.' + #13#10 +
        'پس از پایان، برای بررسی و ترمیم زیرساخت حالت «تعمیر نصب موجود» باز می‌شود.' + #13#10 + #13#10 +
        'ادامه می‌دهید؟'
    else
      PromptText :=
        'SOKNA Prerequisites نسخه ' + ExistingVersion + ' روی این سیستم نصب است.' + #13#10 + #13#10 +
        'اکنون به نسخه ' + CurrentVersion + ' ارتقا داده می‌شود.' + #13#10 +
        'اگر برنامه قبلی باز باشد، Setup برای جایگزینی امن فایل‌ها آن را می‌بندد.' + #13#10 +
        'فایل‌های Web و Data زیرساخت حذف نمی‌شوند.' + #13#10 + #13#10 +
        'ارتقا انجام شود؟';

    Result := MsgBox(PromptText, mbConfirmation, MB_YESNO) = IDYES;
  end;
end;
