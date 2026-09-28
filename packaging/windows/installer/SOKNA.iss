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
AppPublisher=SOKNA
DefaultDirName={autopf}\SOKNA Windows Services
DefaultGroupName=SOKNA
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

[Icons]
Name: "{group}\مدیریت سرویس‌های سکنا"; Filename: "{app}\SoknaSetupUi.exe"; IconFilename: "{app}\Sokna.ico"

[Run]
Filename: "{app}\SoknaSetupUi.exe"; Description: "نصب یا تنظیم Runtime و Print Agent"; Flags: postinstall nowait skipifsilent

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File ""{app}\remove-windows-services.ps1"" -InstallRoot ""{app}"" -DataRoot ""{commonappdata}\SOKNA"""; Flags: runhidden waituntilterminated; RunOnceId: "SoknaWindowsServicesCleanup"
