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
CloseApplications=no
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
