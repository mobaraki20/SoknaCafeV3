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
VersionInfoVersion=0.3.26.4
VersionInfoTextVersion=0.3.26.4
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
OutputBaseFilename=SOKNA-Maintenance-0326-INTEGRATED-QA-REV4-NOT-PRODUCTION
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
Source: "{#PayloadRoot}\invoke-maintenance-install-logged-v1.ps1"; DestDir: "{tmp}\SOKNA-0326"; Flags: ignoreversion deleteafterinstall
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
var PS, Params: String; ResultCode: Integer; FailureCode: AnsiString;
begin
  if CurStep <> ssPostInstall then Exit;
  WorkflowExitCode := 2;
  PS := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  Params := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
    ExpandConstant('{tmp}\SOKNA-0326\invoke-maintenance-install-logged-v1.ps1') +
    '" -PayloadRoot "' + ExpandConstant('{tmp}\SOKNA-0326') + '"';
  if not Exec(PS, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    RaiseException('Maintenance upgrade could not start.');
  if ResultCode <> 0 then begin
    if not LoadStringFromFile(ExpandConstant('{tmp}\SOKNA-0326\installer-failure-code.txt'), FailureCode) then FailureCode := 'MAINTENANCE_DIAGNOSTIC_UNAVAILABLE';
    Log('Maintenance component failure: ' + String(FailureCode));
    RaiseException('Maintenance upgrade failed: ' + String(FailureCode) + '. See the SOKNA report on the Desktop. Recovery was not requested.');
  end;
  { The Maintenance service upgrade has already committed, passed signed
    health, file SHA and installed-identity readback. R125 reconciliation is
    a separate optional Local recovery assessment; NEVER turn its BLOCKED
    result into a false statement that Maintenance installation failed. }
  WorkflowExitCode := 0;
  Params := 'finish-r125-recovery';
  if ConfirmArchive then Params := Params + ' --confirm-preserve-and-archive';
  if not Exec(ExpandConstant('{tmp}\SOKNA-0326\Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe'), Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then begin
    Log('Maintenance committed and verified; R125 recovery assessment could not start.');
    WizardForm.FinishedLabel.Caption := 'Maintenance updated. Previous Local recovery requires a separate check; no Local database update was attempted.';
    Exit;
  end;
  if ResultCode <> 0 then begin
    Log('Maintenance committed and verified; separate R125 recovery assessment blocked: ' + IntToStr(ResultCode));
    WizardForm.FinishedLabel.Caption := 'Maintenance updated. Previous Local recovery is still blocked; see the Desktop SOKNA workflow report. No Local database update was attempted.';
    Exit;
  end;
  WizardForm.FinishedLabel.Caption := 'Maintenance verified. Previous Local recovery status is recorded in the Desktop report. No application or database upgrade was performed.';
end;
