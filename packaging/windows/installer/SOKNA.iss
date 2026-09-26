#ifndef SourceRoot
  #error SourceRoot is required
#endif
#ifndef ProductVersion
  #define ProductVersion "0.0.0-dev"
#endif
[Setup]
SetupArchitecture=x64
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
Source: "{#SourceRoot}\setup-ui\publish\SoknaSetupUi.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#SourceRoot}\setup-host\publish\SoknaSetupHost.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#SourceRoot}\..\manifests\*"; DestDir: "{app}\manifests"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#SourceRoot}\scripts\*"; DestDir: "{app}\scripts"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#SourceRoot}\Sokna.ico"; DestDir: "{app}"; Flags: ignoreversion
Source: "{srcexe}"; DestDir: "{app}\maintenance"; DestName: "Setup.exe"; Flags: external

[Icons]
Name: "{group}\راه‌اندازی و تعمیر سکنا"; Filename: "{app}\SoknaSetupUi.exe"; IconFilename: "{app}\Sokna.ico"
Name: "{commondesktop}\SOKNA"; Filename: "https://sokna.local/"; IconFilename: "{app}\Sokna.ico"

[Run]
Filename: "{app}\SoknaSetupUi.exe"; Description: "راه‌اندازی سکنا"; Flags: postinstall nowait skipifsilent
