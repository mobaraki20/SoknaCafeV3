#ifndef PayloadRoot
  #error PayloadRoot parameter must be passed to ISCC
#endif
#ifndef OutputDir
  #error OutputDir parameter must be passed to ISCC
#endif
[Setup]
AppId={{965FF692-4DAD-4C58-B76D-3F3A70A687CB}
AppName=SOKNA Maintenance Component Update (UAT)
AppVersion=0.3.16
AppVerName=SOKNA Maintenance Only 0.3.16 UAT
AppPublisher=SOKNA
AppCopyright=SOKNA
DefaultDirName={autopf}\SOKNA Windows Services
DisableDirPage=yes
DisableProgramGroupPage=yes
CreateAppDir=no
Uninstallable=no
CreateUninstallRegKey=no
PrivilegesRequired=admin
MinVersion=10.0
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir={#OutputDir}
OutputBaseFilename=SOKNA-Maintenance-Only-0.3.16-UAT-REV1
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartApplications=no
AlwaysRestart=no
DisableReadyPage=yes
DisableFinishedPage=no
ShowLanguageDialog=no

[Files]
Source: "{#PayloadRoot}\maintenance\app\*"; DestDir: "{tmp}\SOKNA-maintenance-only\maintenance\app"; Flags: ignoreversion recursesubdirs createallsubdirs deleteafterinstall
Source: "{#PayloadRoot}\component-install-manifest-v1.json"; DestDir: "{tmp}\SOKNA-maintenance-only"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\windows-services-component-manifest-v2.json"; DestDir: "{tmp}\SOKNA-maintenance-only"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\invoke-maintenance-only-v1.ps1"; DestDir: "{tmp}\SOKNA-maintenance-only"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\setup-windows-components-maintenance-v1.ps1"; DestDir: "{tmp}\SOKNA-maintenance-only"; Flags: ignoreversion deleteafterinstall

Source: "{#PayloadRoot}\\maintenance-signed-health-v1.ps1"; DestDir: "{tmp}\\SOKNA-maintenance-only"; Flags: ignoreversion deleteafterinstall

Source: "{#PayloadRoot}\maintenance-installed-versions-v1.ps1"; DestDir: "{tmp}\SOKNA-maintenance-only"; Flags: ignoreversion deleteafterinstall

[Code]
var
  MaintenanceFailureExitCode: Integer;

function GetCustomSetupExitCode: Integer;
begin
  Result := MaintenanceFailureExitCode;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ExitCode: Integer;
  PS: String;
  Params: String;
begin
  if CurStep <> ssPostInstall then Exit;
  PS := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  Params := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
    ExpandConstant('{tmp}\SOKNA-maintenance-only\invoke-maintenance-only-v1.ps1') +
    '" -PayloadRoot "' + ExpandConstant('{tmp}\SOKNA-maintenance-only') + '"';
  Log('SOKNA_MAINTENANCE_ONLY_SCOPED_TRANSACTION_START');
  if not Exec(PS, Params, '', SW_HIDE, ewWaitUntilTerminated, ExitCode) then
  begin
    MaintenanceFailureExitCode := 42;
    Log('SOKNA_MAINTENANCE_ONLY_TRANSACTION_START_FAILED');
    MsgBox('Maintenance-only installation could not start. No installed service was changed.', mbError, MB_OK);
    Exit;
  end;
  if ExitCode <> 0 then
  begin
    Log('SOKNA_MAINTENANCE_ONLY_TRANSACTION_FAILED=' + IntToStr(ExitCode));
    MaintenanceFailureExitCode := 43;
    MsgBox('Maintenance-only update failed. Inspect ProgramData\SOKNA\setup\maintenance-only-last-error.txt and transaction evidence. Do not treat this update as installed.', mbError, MB_OK);
    Exit;
  end;
  Log('SOKNA_MAINTENANCE_ONLY_TRANSACTION_VERIFIED');
end;

