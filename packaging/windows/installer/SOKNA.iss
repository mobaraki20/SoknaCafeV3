#ifndef SourceRoot
  #error SourceRoot is required
#endif
#ifndef ProductVersion
  #define ProductVersion "0.0.0-dev"
#endif

[Setup]
AppId={{7D6E9D44-9A2B-4D7E-8FB1-53C9375A84F1}
AppName=SOKNA Windows Services
UninstallDisplayName=SOKNA Windows Services
AppVersion={#ProductVersion}
AppVerName=SOKNA Windows Services {#ProductVersion}
VersionInfoVersion={#ProductVersion}
AppPublisher=SOKNA
DefaultDirName={autopf}\SOKNA Windows Services
DefaultGroupName=SOKNA
AllowNoIcons=yes
DisableProgramGroupPage=no
UsePreviousAppDir=yes
UninstallDisplayIcon={app}\Sokna.ico
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputBaseFilename=SOKNA-Windows-Services-Setup-{#ProductVersion}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
UninstallLogging=yes
CloseApplications=no
RestartApplications=no
SetupIconFile={#SourceRoot}\Sokna.ico

[Files]
Source: "{#SourceRoot}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Tasks]
Name: "startmenuicon"; Description: "ایجاد میانبر در منوی Start"; GroupDescription: "میانبرهای برنامه:"; Flags: checkedonce
Name: "desktopicon"; Description: "ایجاد میانبر روی دسکتاپ"; GroupDescription: "میانبرهای برنامه:"; Flags: unchecked

[Icons]
Name: "{group}\مدیریت سرویس‌های سکنا"; Filename: "{app}\SoknaSetupUi.exe"; IconFilename: "{app}\Sokna.ico"; Tasks: startmenuicon
Name: "{autodesktop}\مدیریت سرویس‌های سکنا"; Filename: "{app}\SoknaSetupUi.exe"; IconFilename: "{app}\Sokna.ico"; Tasks: desktopicon

[Run]
Filename: "{app}\SoknaSetupUi.exe"; Description: "مدیریت Windows Services سکنا"; Flags: postinstall nowait skipifsilent

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File ""{app}\remove-windows-services.ps1"" -InstallRoot ""{app}"" -DataRoot ""{commonappdata}\SOKNA"""; Flags: runhidden waituntilterminated; RunOnceId: "SoknaWindowsServicesCleanup"

[Code]
var
  PreviousInstalledVersion: String;
  InstallModeText: String;
  PreviousVersionComparison: Integer;

function CompareInstalledToCurrent(const InstalledVersion: String): Integer;
var
  InstalledPacked: Int64;
  CurrentPacked: Int64;
begin
  Result := 0;
  if not StrToVersion(InstalledVersion, InstalledPacked) then
    Exit;
  if not StrToVersion('{#ProductVersion}', CurrentPacked) then
    Exit;
  Result := ComparePackedVersion(InstalledPacked, CurrentPacked);
end;

function InitializeSetup(): Boolean;
var
  UninstallKey: String;
begin
  PreviousInstalledVersion := '';
  PreviousVersionComparison := 0;
  InstallModeText := 'نصب جدید';
  UninstallKey := 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{7D6E9D44-9A2B-4D7E-8FB1-53C9375A84F1}_is1';
  RegQueryStringValue(HKLM64, UninstallKey, 'DisplayVersion', PreviousInstalledVersion);

  if PreviousInstalledVersion <> '' then
  begin
    PreviousVersionComparison := CompareInstalledToCurrent(PreviousInstalledVersion);
    if PreviousVersionComparison > 0 then
    begin
      InstallModeText := 'BLOCK_DOWNGRADE ' + PreviousInstalledVersion + ' > {#ProductVersion}';
      Log('SOKNA_INSTALL_MODE=' + InstallModeText);
      SuppressibleMsgBox(
        'نسخه ' + PreviousInstalledVersion + ' از SOKNA Windows Services روی این سیستم نصب است و از این بسته ({#ProductVersion}) جدیدتر است.' + #13#10 + #13#10 +
        'برای جلوگیری از Downgrade، نصب متوقف شد.',
        mbCriticalError, MB_OK, IDOK);
      Result := False;
      Exit;
    end;

    if PreviousVersionComparison < 0 then
      InstallModeText := 'به‌روزرسانی از نسخه ' + PreviousInstalledVersion + ' به نسخه {#ProductVersion}'
    else
      InstallModeText := 'تعمیر / نصب مجدد نسخه {#ProductVersion}';
  end;

  Log('SOKNA_PREVIOUS_VERSION=' + PreviousInstalledVersion);
  Log('SOKNA_INSTALL_MODE=' + InstallModeText);
  Result := True;
end;

procedure InitializeWizard();
begin
  WizardForm.Caption := 'سکنا | ' + InstallModeText;
  if PreviousInstalledVersion = '' then
  begin
    WizardForm.WelcomeLabel1.Caption := 'نصب SOKNA Windows Services {#ProductVersion}';
    WizardForm.WelcomeLabel2.Caption :=
      'نسخه‌ای از Windows Services سکنا روی این سیستم تشخیص داده نشد.' + #13#10 + #13#10 +
      'این Setup فقط Runtime و Print Agent را آماده می‌کند. پس از پایان نصب، مدیریت سرویس‌های سکنا باز می‌شود.';
  end
  else if PreviousVersionComparison < 0 then
  begin
    WizardForm.WelcomeLabel1.Caption := 'به‌روزرسانی SOKNA Windows Services';
    WizardForm.WelcomeLabel2.Caption :=
      'نسخه ' + PreviousInstalledVersion + ' اکنون نصب است.' + #13#10 +
      'این Setup آن را به نسخه {#ProductVersion} به‌روزرسانی می‌کند.' + #13#10 + #13#10 +
      'داده‌ها و Pairing موجود حفظ می‌شوند. پس از نصب، Dashboard وضعیت نهایی را بررسی می‌کند.';
  end
  else
  begin
    WizardForm.WelcomeLabel1.Caption := 'SOKNA Windows Services {#ProductVersion} از قبل نصب است';
    WizardForm.WelcomeLabel2.Caption :=
      'همین نسخه ({#ProductVersion}) روی سیستم تشخیص داده شد.' + #13#10 + #13#10 +
      'ادامه دادن، فایل‌های برنامه مدیریت Windows Services را بازاعمال می‌کند. برای مشکلات خود سرویس‌ها می‌توانید بعد از Setup از «تعمیر نصب» یا «عیب‌یابی» در Dashboard استفاده کنید.';
  end;
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID = wpReady then
  begin
    WizardForm.ReadyLabel.Caption := InstallModeText + ':';
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  MarkerDir: String;
  MarkerPath: String;
begin
  if CurStep = ssPostInstall then
  begin
    MarkerDir := ExpandConstant('{commonappdata}\SOKNA\setup');
    MarkerPath := MarkerDir + '\previous-windows-services-version.txt';
    ForceDirectories(MarkerDir);
    if PreviousInstalledVersion <> '' then
      SaveStringToFile(MarkerPath, PreviousInstalledVersion, False)
    else if FileExists(MarkerPath) then
      DeleteFile(MarkerPath);
  end;
end;
