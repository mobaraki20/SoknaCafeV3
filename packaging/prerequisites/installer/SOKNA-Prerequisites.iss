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

function StableVersion(const Value: String): String;
var
  P: Integer;
begin
  Result := Trim(Value);
  P := Pos('-', Result);
  if P > 0 then
    Delete(Result, P, Length(Result));
end;

function VersionPart(const Value: String; Wanted: Integer): Integer;
var
  S, Part: String;
  P, I: Integer;
begin
  S := StableVersion(Value);
  Part := '';
  for I := 0 to Wanted do
  begin
    P := Pos('.', S);
    if P = 0 then
    begin
      Part := S;
      S := '';
    end
    else
    begin
      Part := Copy(S, 1, P - 1);
      Delete(S, 1, P);
    end;
  end;
  Result := StrToIntDef(Part, 0);
end;

function IsPrerelease(const Value: String): Boolean;
begin
  Result := Pos('-', Trim(Value)) > 0;
end;

function CompareSoknaVersion(const LeftVersion, RightVersion: String): Integer;
var
  I, L, R: Integer;
begin
  Result := 0;
  for I := 0 to 3 do
  begin
    L := VersionPart(LeftVersion, I);
    R := VersionPart(RightVersion, I);
    if L < R then
    begin
      Result := -1;
      Exit;
    end;
    if L > R then
    begin
      Result := 1;
      Exit;
    end;
  end;

  if IsPrerelease(LeftVersion) and (not IsPrerelease(RightVersion)) then
    Result := -1
  else if (not IsPrerelease(LeftVersion)) and IsPrerelease(RightVersion) then
    Result := 1
  else
    Result := CompareText(LeftVersion, RightVersion);
end;

function InitializeSetup(): Boolean;
var
  ExistingVersion: String;
  CurrentVersion: String;
  PromptText: String;
  CompareResult: Integer;
begin
  Result := True;
  CurrentVersion := '{#ProductVersion}';

  if GetInstalledVersion(ExistingVersion) then
  begin
    CompareResult := CompareSoknaVersion(ExistingVersion, CurrentVersion);

    if CompareResult > 0 then
    begin
      if not WizardSilent then
        MsgBox(
          'نسخه جدیدتری از SOKNA Prerequisites روی این سیستم نصب است.' + #13#10 + #13#10 +
          'نسخه نصب‌شده: ' + ExistingVersion + #13#10 +
          'نسخه این فایل: ' + CurrentVersion + #13#10 + #13#10 +
          'برای حفاظت از تنظیمات و سازگاری زیرساخت، Downgrade خودکار مجاز نیست.',
          mbError, MB_OK);
      Result := False;
      Exit;
    end;

    if WizardSilent then
    begin
      Result := True;
      Exit;
    end;

    if CompareResult = 0 then
      PromptText :=
        'نسخه ' + ExistingVersion + ' از SOKNA Prerequisites از قبل نصب است.' + #13#10 + #13#10 +
        'این اجرا فقط برنامه مدیریت Prerequisites را تازه‌سازی می‌کند و زیرساخت موجود حفظ می‌شود.' + #13#10 +
        'پس از پایان، وضعیت زیرساخت بررسی و در صورت نیاز حالت تعمیر پیشنهاد می‌شود.' + #13#10 + #13#10 +
        'ادامه می‌دهید؟'
    else
      PromptText :=
        'SOKNA Prerequisites نسخه ' + ExistingVersion + ' روی این سیستم نصب است.' + #13#10 + #13#10 +
        'برنامه مدیریت به نسخه ' + CurrentVersion + ' ارتقا داده می‌شود.' + #13#10 +
        'اگر برنامه قبلی باز باشد، Setup آن را برای جایگزینی امن فایل‌های مدیریت می‌بندد.' + #13#10 +
        'فایل‌های Web، Data و زیرساخت نصب‌شده حذف نمی‌شوند.' + #13#10 + #13#10 +
        'ارتقا انجام شود؟';

    Result := MsgBox(PromptText, mbConfirmation, MB_YESNO) = IDYES;
  end;
end;
