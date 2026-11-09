#ifndef PayloadRoot
  #error PayloadRoot must be specified
#endif
#ifndef OutputDir
  #error OutputDir must be specified
#endif

[Setup]
AppId={{EDDA9110-521F-468D-BC09-0324EC25A171}
AppName=SOKNA Maintenance R125 Recovery Component Upgrade (QA)
AppVersion=0.3.24
VersionInfoVersion=0.3.24.0
VersionInfoTextVersion=0.3.24.0
AppVerName=SOKNA Maintenance 0.3.24 and Recovery Console 0.1.5 QA
AppPublisher=SOKNA
AppCopyright=SOKNA
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
OutputBaseFilename=SOKNA-Maintenance-0324-MultiBaseline-QA-NOT-PRODUCTION
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartApplications=no
AlwaysRestart=no
DisableReadyPage=yes
ShowLanguageDialog=no

; EXACTLY three signed-version candidate executables, all extracted to TEMP;
; no direct writes to Program Files, ProgramData, Local, Public or DB by Inno.
; Only the transactional entrypoint below may change the 3 owned components.
[Files]
Source: "{#PayloadRoot}\Maintenance\App\SoknaMaintenanceSupervisor.exe"; DestDir: "{tmp}\SOKNA-R125-0324\Maintenance\App"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Maintenance\App\SoknaMaintenanceUpdateEngine.exe"; DestDir: "{tmp}\SOKNA-R125-0324\Maintenance\App"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe"; DestDir: "{tmp}\SOKNA-R125-0324\Maintenance\Recovery"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\maintenance-component-manifest-v2.json"; DestDir: "{tmp}\SOKNA-R125-0324"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\invoke-maintenance-components-v2.ps1"; DestDir: "{tmp}\SOKNA-R125-0324"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\maintenance-signed-health-v1.ps1"; DestDir: "{tmp}\SOKNA-R125-0324"; Flags: ignoreversion deleteafterinstall

[Code]
var
  InstallFailedCode: Integer;

function GetCustomSetupExitCode: Integer;
begin
  Result := InstallFailedCode;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  PS, Params: String;
  ResultCode: Integer;
begin
  if CurStep <> ssPostInstall then Exit;
  PS := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  Params := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
    ExpandConstant('{tmp}\SOKNA-R125-0324\invoke-maintenance-components-v2.ps1') +
    '" -PayloadRoot "' + ExpandConstant('{tmp}\SOKNA-R125-0324') + '"';
  Log('SOKNA_R125_0324_THREE_COMPONENT_TRANSACTION_BEGIN');
  if not Exec(PS, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
  begin
    InstallFailedCode := 51;
    Log('SOKNA_R125_0324_POWERSHELL_COULD_NOT_START');
    if not WizardSilent then
      MsgBox('Recovery component upgrade could not start. Do not repeat installation until support evidence is reviewed.', mbError, MB_OK);
    RaiseException('Maintenance upgrade could not start.');
  end;
  if ResultCode <> 0 then
  begin
    InstallFailedCode := 52;
    Log('SOKNA_R125_0324_TRANSACTION_UNQUALIFIED_OR_FAILED');
    if not WizardSilent then
      MsgBox('Maintenance recovery upgrade was blocked or rolled back. No success was recorded. Use One Support for evidence.', mbError, MB_OK);
    RaiseException('Maintenance upgrade was blocked or rolled back; no success recorded.');
  end;
  Log('SOKNA_R125_0324_THREE_COMPONENT_TRANSACTION_VERIFIED');
end;

