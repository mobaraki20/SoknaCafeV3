#ifndef PayloadRoot
  #error PayloadRoot must be specified
#endif
#ifndef OutputDir
  #error OutputDir must be specified
#endif
[Setup]
AppId={{9F9275BC-3270-47F8-AF77-202611110001}
AppName=SOKNA Maintenance Universal 0.3.27 (QA)
AppVersion=0.3.27
VersionInfoVersion=0.3.27.2
VersionInfoTextVersion=0.3.27.2
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
OutputBaseFilename=SOKNA-Maintenance-Universal-0327-QA-NOT-PRODUCTION
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartApplications=no
AlwaysRestart=no
ShowLanguageDialog=no
[Files]
Source: "{#PayloadRoot}\SOKNA-Maintenance-Only-0326-Independent-R1-QA-NOT-PRODUCTION.exe"; DestDir: "{tmp}\SOKNA-MAINT-UNIVERSAL"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\SOKNA-Maintenance-Only-0327-Independent-R1-QA-NOT-PRODUCTION.exe"; DestDir: "{tmp}\SOKNA-MAINT-UNIVERSAL"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Get-SoknaMaintenanceUpgradePlan.ps1"; DestDir: "{tmp}\SOKNA-MAINT-UNIVERSAL"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\invoke-maintenance-universal-0327.ps1"; DestDir: "{tmp}\SOKNA-MAINT-UNIVERSAL"; Flags: ignoreversion deleteafterinstall
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
   ExpandConstant('{tmp}\SOKNA-MAINT-UNIVERSAL\invoke-maintenance-universal-0327.ps1') +
   '" -PayloadRoot "' + ExpandConstant('{tmp}\SOKNA-MAINT-UNIVERSAL') + '"';
 if not Exec(PS, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
   RaiseException('SOKNA Maintenance universal verification could not start. No version chosen.');
 if ResultCode <> 0 then begin
   if not LoadStringFromFile(ExpandConstant('{tmp}\SOKNA-MAINT-UNIVERSAL\installer-failure-code.txt'), FailureCode) then
     FailureCode := 'MAINTENANCE_UNIVERSAL_DIAGNOSTIC_UNAVAILABLE';
   RaiseException('Maintenance was not fully updated: ' + String(FailureCode) + '. See Desktop SOKNA report.');
 end;
 WorkflowExitCode := 0;
 WizardForm.FinishedLabel.Caption := 'Maintenance 0.3.27 verified. Recovery, Local, Public and databases unchanged.';
end;
