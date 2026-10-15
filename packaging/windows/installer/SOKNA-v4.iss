#ifndef SourceRoot
  #error SourceRoot is required
#endif
#ifndef ProductVersion
  #define ProductVersion "0.0.0"
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
OutputBaseFilename=SOKNA-Windows-Services-Core-{#ProductVersion}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
UninstallLogging=yes
CloseApplications=no
RestartApplications=no
SetupIconFile={#SourceRoot}\Sokna.ico

[InstallDelete]
; Remove target-payload locations used by package 1.0.x. Installed Runtime/PrintAgent/Maintenance roots are deliberately untouched.
Type: files; Name: "{app}\SoknaRuntimeService.exe"
Type: filesandordirs; Name: "{app}\print-worker"

[Files]
Source: "{#SourceRoot}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Tasks]
Name: "startmenuicon"; Description: "ایجاد میانبر در منوی Start"; GroupDescription: "میانبرهای برنامه:"; Flags: checkedonce
Name: "desktopicon"; Description: "ایجاد میانبر روی دسکتاپ"; GroupDescription: "میانبرهای برنامه:"; Flags: unchecked

[Icons]
Name: "{group}\مدیریت سرویس‌های سکنا"; Filename: "{app}\SoknaSetupUi.exe"; IconFilename: "{app}\Sokna.ico"; Tasks: startmenuicon
Name: "{autodesktop}\مدیریت سرویس‌های سکنا"; Filename: "{app}\SoknaSetupUi.exe"; IconFilename: "{app}\Sokna.ico"; Tasks: desktopicon

[Run]
Filename: "{app}\SoknaSetupUi.exe"; Description: "مدیریت سرویس‌های ویندوز سکنا"; Flags: postinstall nowait skipifsilent

[Code]
var
  PreviousInstalledVersion: String;
  InstallModeText: String;
  PreviousVersionComparison: Integer;
  BootstrapTransaction: String;

function CompareInstalledToCurrent(const InstalledVersion: String): Integer;
var InstalledPacked, CurrentPacked: Int64;
begin
  Result := 0;
  if not StrToVersion(InstalledVersion, InstalledPacked) then Exit;
  if not StrToVersion('{#ProductVersion}', CurrentPacked) then Exit;
  Result := ComparePackedVersion(InstalledPacked, CurrentPacked);
end;

function ReadNamedParam(const Name: String): String;
var I: Integer; Prefix, Value: String;
begin
  Result := '';
  Prefix := '/' + Name + '=';
  for I := 1 to ParamCount do begin
    Value := ParamStr(I);
    if Pos(Prefix, Value) = 1 then begin Result := Copy(Value, Length(Prefix) + 1, MaxInt); Exit; end;
  end;
end;

function InitializeSetup(): Boolean;
var UninstallKey: String;
begin
  BootstrapTransaction := ReadNamedParam('SOKNA_TX');
  if BootstrapTransaction <> '' then Log('SOKNA_BOOTSTRAP_TRANSACTION=' + BootstrapTransaction);
  PreviousInstalledVersion := ''; PreviousVersionComparison := 0; InstallModeText := 'نصب جدید';
  UninstallKey := 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{7D6E9D44-9A2B-4D7E-8FB1-53C9375A84F1}_is1';
  RegQueryStringValue(HKLM64, UninstallKey, 'DisplayVersion', PreviousInstalledVersion);
  if PreviousInstalledVersion <> '' then begin
    PreviousVersionComparison := CompareInstalledToCurrent(PreviousInstalledVersion);
    if PreviousVersionComparison > 0 then begin
      InstallModeText := 'BLOCK_DOWNGRADE ' + PreviousInstalledVersion + ' > {#ProductVersion}'; Log('SOKNA_INSTALL_MODE=' + InstallModeText);
      SuppressibleMsgBox('نسخه ' + PreviousInstalledVersion + ' از Windows Services سکنا روی این سیستم نصب است و از این بسته جدیدتر است.' + #13#10 + #13#10 + 'برای جلوگیری از Downgrade، نصب متوقف شد.', mbCriticalError, MB_OK, IDOK);
      Result := False; Exit;
    end;
    if PreviousVersionComparison < 0 then InstallModeText := 'به‌روزرسانی پوسته از ' + PreviousInstalledVersion + ' به {#ProductVersion}' else InstallModeText := 'بازاعمال پوسته نسخه {#ProductVersion}';
  end;
  Log('SOKNA_PREVIOUS_VERSION=' + PreviousInstalledVersion); Log('SOKNA_INSTALL_MODE=' + InstallModeText); Result := True;
end;

procedure InitializeWizard();
begin
  WizardForm.Caption := 'سکنا | ' + InstallModeText;
  if PreviousInstalledVersion = '' then begin
    WizardForm.WelcomeLabel1.Caption := 'نصب SOKNA Windows Services {#ProductVersion}';
    WizardForm.WelcomeLabel2.Caption := 'پوسته مدیریت یکپارچه Runtime، Print و Maintenance نصب می‌شود.' + #13#10 + #13#10 + 'پس از کپی امن فایل‌ها، Dashboard باز می‌شود و قبل از هر تغییر سرویس، وضعیت هر component را جداگانه بررسی می‌کند.';
  end else if PreviousVersionComparison < 0 then begin
    WizardForm.WelcomeLabel1.Caption := 'به‌روزرسانی SOKNA Windows Services';
    WizardForm.WelcomeLabel2.Caption := 'نسخه پوسته ' + PreviousInstalledVersion + ' اکنون نصب است و به {#ProductVersion} ارتقا می‌یابد.' + #13#10 + #13#10 + 'این مرحله به‌تنهایی همه سرویس‌ها را restart نمی‌کند؛ Dashboard برنامه دقیق componentها را مشخص می‌کند.';
  end else begin
    WizardForm.WelcomeLabel1.Caption := 'SOKNA Windows Services {#ProductVersion} از قبل نصب است';
    WizardForm.WelcomeLabel2.Caption := 'فایل‌های پوسته مدیریت بازاعمال می‌شوند. وضعیت Runtime، Print و Maintenance پس از بازشدن Dashboard به‌صورت مستقل بررسی خواهد شد.';
  end;
end;

procedure CurPageChanged(CurPageID: Integer);
begin if CurPageID = wpReady then WizardForm.ReadyLabel.Caption := InstallModeText + ':'; end;

procedure CurStepChanged(CurStep: TSetupStep);
var MarkerDir, MarkerPath, TxPath: String;
begin
  if CurStep = ssPostInstall then begin
    MarkerDir := ExpandConstant('{commonappdata}\SOKNA\setup'); ForceDirectories(MarkerDir);
    MarkerPath := MarkerDir + '\previous-windows-services-version.txt';
    if PreviousInstalledVersion <> '' then SaveStringToFile(MarkerPath, PreviousInstalledVersion, False) else if FileExists(MarkerPath) then DeleteFile(MarkerPath);
    if BootstrapTransaction <> '' then begin TxPath := MarkerDir + '\last-bootstrap-transaction.txt'; SaveStringToFile(TxPath, BootstrapTransaction, False); end;
  end;
end;
