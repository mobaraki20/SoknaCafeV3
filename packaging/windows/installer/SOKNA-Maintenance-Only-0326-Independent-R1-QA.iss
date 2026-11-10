#ifndef PayloadRoot
  #error PayloadRoot must be specified
#endif
#ifndef OutputDir
  #error OutputDir must be specified
#endif
[Setup]
AppId={{EDDA9110-521F-468D-BC09-0326EC25A171}
AppName=SOKNA Maintenance Only (QA)
AppVersion=0.3.26
VersionInfoVersion=0.3.26.5
VersionInfoTextVersion=0.3.26.5
DefaultDirName={autopf}\SOKNA Windows Services
CreateAppDir=no
Uninstallable=no
CreateUninstallRegKey=no
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputDir={#OutputDir}
OutputBaseFilename=SOKNA-Maintenance-Only-0326-Independent-R1-QA-NOT-PRODUCTION
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartApplications=no
AlwaysRestart=no
DisableReadyPage=no
ShowLanguageDialog=no
[Files]
Source: "{#PayloadRoot}\Maintenance\App\SoknaMaintenanceSupervisor.exe"; DestDir: "{tmp}\SOKNA-M-PAIR\Maintenance\App"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Maintenance\App\SoknaMaintenanceUpdateEngine.exe"; DestDir: "{tmp}\SOKNA-M-PAIR\Maintenance\App"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\maintenance-independent-manifest-v1.json"; DestDir: "{tmp}\SOKNA-M-PAIR"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\invoke-maintenance-independent-v1.ps1"; DestDir: "{tmp}\SOKNA-M-PAIR"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\invoke-maintenance-independent-logged-v1.ps1"; DestDir: "{tmp}\SOKNA-M-PAIR"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\maintenance-signed-health-v1.ps1"; DestDir: "{tmp}\SOKNA-M-PAIR"; Flags: ignoreversion deleteafterinstall
[Code]
var WorkflowExitCode: Integer;
function GetCustomSetupExitCode: Integer;
begin
  Result := WorkflowExitCode;
end;
procedure CurStepChanged(CurStep: TSetupStep);
var PS, Params: String; ResultCode: Integer; FailureCode: AnsiString;
begin
  if CurStep <> ssPostInstall then Exit;
  WorkflowExitCode := 2;
  PS := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  Params := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
    ExpandConstant('{tmp}\SOKNA-M-PAIR\invoke-maintenance-independent-logged-v1.ps1') +
    '" -PayloadRoot "' + ExpandConstant('{tmp}\SOKNA-M-PAIR') + '"';
  if not Exec(PS, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    RaiseException('Maintenance-only update could not start.');
  if ResultCode <> 0 then begin
    if not LoadStringFromFile(ExpandConstant('{tmp}\SOKNA-M-PAIR\installer-failure-code.txt'), FailureCode) then
      FailureCode := 'MAINTENANCE_DIAGNOSTIC_UNAVAILABLE';
    Log('Independent Maintenance update failed: ' + String(FailureCode));
    RaiseException('Maintenance-only update was not completed: ' + String(FailureCode) + '. See Desktop SOKNA report.');
  end;
  WorkflowExitCode := 0;
  WizardForm.FinishedLabel.Caption := 'Maintenance updated. Recovery, Local, Public, databases and print services were not modified. R125 Local recovery remains separate.';
end;
