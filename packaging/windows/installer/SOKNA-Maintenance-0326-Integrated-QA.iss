#ifndef PayloadRoot
  #error PayloadRoot must be specified
#endif
#ifndef OutputDir
  #error OutputDir must be specified
#endif
[Setup]
AppId={{EDDA9110-521F-468D-BC09-0326EC25A171}
AppName=SOKNA Maintenance and Recovery (QA)
AppVersion=0.3.26
VersionInfoVersion=0.3.26.0
VersionInfoTextVersion=0.3.26.0
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
OutputBaseFilename=SOKNA-Maintenance-0326-INTEGRATED-QA-NOT-PRODUCTION
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
Source: "{#PayloadRoot}\Maintenance\App\SoknaMaintenanceSupervisor.exe"; DestDir: "{tmp}\SOKNA-0326\Maintenance\App"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Maintenance\App\SoknaMaintenanceUpdateEngine.exe"; DestDir: "{tmp}\SOKNA-0326\Maintenance\App"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe"; DestDir: "{tmp}\SOKNA-0326\Maintenance\Recovery"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\maintenance-component-manifest-v2.json"; DestDir: "{tmp}\SOKNA-0326"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\invoke-maintenance-components-v4.ps1"; DestDir: "{tmp}\SOKNA-0326"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\maintenance-signed-health-v1.ps1"; DestDir: "{tmp}\SOKNA-0326"; Flags: ignoreversion deleteafterinstall
[Code]
var ConfirmArchive: Boolean; WorkflowExitCode: Integer;
function GetCustomSetupExitCode: Integer;
begin
  Result := WorkflowExitCode;
end;
procedure InitializeWizard;
begin
  ConfirmArchive := ExpandConstant('{param:CONFIRM-R125-ARCHIVE|NO}') = 'YES';
  if (not WizardSilent) and (FileExists(ExpandConstant('{commonappdata}\SOKNA\Maintenance\local-slots\routine-local-lifecycle-before.json')) or
     DirExists(ExpandConstant('{commonappdata}\SOKNA\Maintenance\local-slots\reconciled-lifecycle-evidence'))) then
    ConfirmArchive := MsgBox('After the service upgrade, verify and safely archive the previous recovery evidence? No database migration or restore will run.', mbConfirmation, MB_YESNO) = IDYES;
end;
procedure CurStepChanged(CurStep: TSetupStep);
var PS, Params: String; ResultCode: Integer;
begin
  if CurStep <> ssPostInstall then Exit;
  WorkflowExitCode := 2;
  PS := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  Params := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
    ExpandConstant('{tmp}\SOKNA-0326\invoke-maintenance-components-v4.ps1') +
    '" -PayloadRoot "' + ExpandConstant('{tmp}\SOKNA-0326') + '"';
  if not Exec(PS, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    RaiseException('Maintenance upgrade could not start.');
  if ResultCode <> 0 then
    RaiseException('Maintenance upgrade was blocked or rolled back. No recovery change was requested.');
  Params := 'finish-r125-recovery';
  if ConfirmArchive then Params := Params + ' --confirm-preserve-and-archive';
  if not Exec(ExpandConstant('{tmp}\SOKNA-0326\Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe'), Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    RaiseException('Maintenance is upgraded; recovery verification could not start.');
  if ResultCode <> 0 then
    RaiseException('Maintenance is upgraded; recovery is blocked. A report is saved on the Desktop.');
  WorkflowExitCode := 0;
  WizardForm.FinishedLabel.Caption := 'Maintenance is verified. Recovery status is recorded in the Desktop report. This is not approval for an application or database upgrade.';
end;
