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

function InitializeSetup(): Boolean;
var
  UninstallKey: String;
begin
  PreviousInstalledVersion := '';
  UninstallKey := 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{7D6E9D44-9A2B-4D7E-8FB1-53C9375A84F1}_is1';
  RegQueryStringValue(HKLM64, UninstallKey, 'DisplayVersion', PreviousInstalledVersion);
  Result := True;
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
