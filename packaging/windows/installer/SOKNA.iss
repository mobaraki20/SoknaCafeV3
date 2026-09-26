#ifndef SourceRoot
  #error SourceRoot is required
#endif
#ifndef ProductVersion
  #define ProductVersion "0.0.0-dev"
#endif
[Setup]
AppId={{8E19AF58-57C6-45D0-A39E-2D6C3D61D003}
AppName=SOKNA
UninstallDisplayName=SOKNA
AppVersion={#ProductVersion}
AppPublisher=SOKNA
DefaultDirName={autopf}\SOKNA
DefaultGroupName=SOKNA
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputBaseFilename=SOKNA-Setup-{#ProductVersion}
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
Source: "{srcexe}"; DestDir: "{app}\maintenance"; DestName: "Setup.exe"; Flags: external

[Icons]
Name: "{group}\راه‌اندازی و تعمیر سکنا"; Filename: "{app}\SoknaSetupUi.exe"; IconFilename: "{app}\Sokna.ico"
Name: "{commondesktop}\SOKNA"; Filename: "https://sokna.local/"; IconFilename: "{app}\Sokna.ico"

[Run]
Filename: "{app}\SoknaSetupUi.exe"; Description: "راه‌اندازی سکنا"; Flags: postinstall nowait skipifsilent

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\remove-owned-services.ps1"""; Flags: runhidden waituntilterminated; RunOnceId: "SoknaOwnedServicesCleanup"
