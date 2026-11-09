#ifndef PayloadRoot
  #error PayloadRoot is required
#endif
#ifndef OutputDir
  #error OutputDir is required
#endif
[Setup]
AppId={{5F963242-29D7-4EBD-9EB5-9B514D75E251}
AppName=SOKNA R125 Read-Only Live Database Preflight (QA)
AppVersion=0.1.1
VersionInfoVersion=0.1.1.0
AppVerName=SOKNA R125 Read-only Evidence / Database Migration Ledger QA
AppPublisher=SOKNA
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
OutputBaseFilename=SOKNA-R129-RECOVERY-READONLY-011-QA-NOT-PRODUCTION
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartApplications=no
AlwaysRestart=no
DisableReadyPage=yes
ShowLanguageDialog=no
[Files]
Source: "{#PayloadRoot}\SOKNA-R125-READONLY-LIVE-DB-PREFLIGHT-UAT.exe"; DestDir: "{tmp}\SOKNA-R125-READONLY"; Flags: ignoreversion deleteafterinstall
[Code]
var
  StatusCode: Integer;

function GetCustomSetupExitCode: Integer;
begin
  Result := StatusCode;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ExecutablePath: String;
  ResultCode: Integer;
begin
  if CurStep <> ssPostInstall then Exit;
  ExecutablePath := ExpandConstant('{tmp}\SOKNA-R125-READONLY\SOKNA-R125-READONLY-LIVE-DB-PREFLIGHT-UAT.exe');
  Log('SOKNA_R125_READONLY_PREFLIGHT_START');
  if not Exec(ExecutablePath, '', '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
  begin
    StatusCode := 51;
    Log('SOKNA_R125_READONLY_PREFLIGHT_UNAVAILABLE');
    RaiseException('Read-only check was blocked; see the generated report.');
  end;
  if ResultCode <> 0 then
  begin
    StatusCode := 52;
    Log('SOKNA_R125_READONLY_PREFLIGHT_INCOMPLETE_CHECK_DESKTOP_REPORT');
    RaiseException('Read-only check was blocked; see the generated report.');
  end;
  Log('SOKNA_R125_READONLY_CHECKS_PASS_BUT_BACKUP_RESTORE_UNVERIFIED');
end;

