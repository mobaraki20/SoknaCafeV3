#ifndef SupportScriptRoot
  #error SupportScriptRoot must refer to an audited source directory
#endif
#ifndef OutputDir
  #error OutputDir must be set explicitly by CI
#endif

[Setup]
AppId={{D4D96E8A-B2D2-4E40-86DB-3C50CFD20E84}
AppName=SOKNA One Support - R125 Read-only Diagnostics
AppVersion=1.0.0
VersionInfoVersion=1.0.0.0
AppVerName=SOKNA One Support R125 Diagnostic (QA, Read-only)
AppPublisher=SOKNA
AppCopyright=SOKNA
DefaultDirName={tmp}\SOKNA-One-Support-R125
CreateAppDir=no
Uninstallable=no
CreateUninstallRegKey=no
UsePreviousAppDir=no
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
MinVersion=10.0
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir={#OutputDir}
OutputBaseFilename=SOKNA-ONE-SUPPORT-R125-READONLY-DIAGNOSTICS-UAT
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
CloseApplications=no
RestartApplications=no
AlwaysRestart=no
DisableReadyPage=yes
ShowLanguageDialog=no
DisableFinishedPage=no

; Nothing is installed to Program Files, ProgramData, the Local Web tree,
; MariaDB, the Maintenance App directory, or the Public website.
; Only temporary copies of the existing support scripts are extracted.
[Files]
Source: "{#SupportScriptRoot}\collect-support-unified-v1.ps1"; DestDir: "{tmp}\SOKNA-One-Support-R125"; Flags: ignoreversion deleteafterinstall
Source: "{#SupportScriptRoot}\collect-support-v3.ps1"; DestDir: "{tmp}\SOKNA-One-Support-R125"; DestName: "collect-support-v2.ps1"; Flags: ignoreversion deleteafterinstall
Source: "{#SupportScriptRoot}\collect-support-v2.ps1"; DestDir: "{tmp}\SOKNA-One-Support-R125"; DestName: "collect-support-v2-core.ps1"; Flags: ignoreversion deleteafterinstall
Source: "{#SupportScriptRoot}\write-technician-report-v1.ps1"; DestDir: "{tmp}\SOKNA-One-Support-R125"; Flags: ignoreversion deleteafterinstall

[Code]
var
  DiagnosticExitCode: Integer;

function GetCustomSetupExitCode: Integer;
begin
  Result := DiagnosticExitCode;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  PS, Params, Source, OutputFolder: String;
  ExitCode: Integer;
begin
  if CurStep <> ssPostInstall then Exit;

  Source := ExpandConstant('{tmp}\SOKNA-One-Support-R125\collect-support-unified-v1.ps1');
  PS := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  OutputFolder := ExpandConstant('{userdesktop}');

  if not FileExists(Source) then
  begin
    DiagnosticExitCode := 51;
    Log('SOKNA_SUPPORT_DIAGNOSTIC_SCRIPT_NOT_EXTRACTED');
    if not WizardSilent then
      MsgBox('The diagnostic files could not be extracted. No SOKNA service or data was modified.', mbError, MB_OK);
    Exit;
  end;

  Params := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
    Source + '" -OutputRoot "' + OutputFolder + '"';

  Log('SOKNA_READ_ONLY_SUPPORT_COLLECTION_START');
  if not Exec(PS, Params, '', SW_HIDE, ewWaitUntilTerminated, ExitCode) then
  begin
    DiagnosticExitCode := 52;
    Log('SOKNA_READ_ONLY_SUPPORT_COLLECTOR_START_FAILED');
    if not WizardSilent then
      MsgBox('The read-only diagnostic collector could not start. No service or database was changed.', mbError, MB_OK);
    Exit;
  end;
  if ExitCode <> 0 then
  begin
    DiagnosticExitCode := 53;
    Log('SOKNA_READ_ONLY_SUPPORT_COLLECTOR_FAILED=' + IntToStr(ExitCode));
    if not WizardSilent then
      MsgBox('The support report was not completed. Do not treat it as a successful diagnosis.', mbError, MB_OK);
    Exit;
  end;
  Log('SOKNA_READ_ONLY_SUPPORT_COLLECTOR_SUCCEEDED');
  if not WizardSilent then
    MsgBox('The read-only SOKNA support report was created. Windows Explorer should show the SOKNA-ONE-SUPPORT ZIP. Upload that ZIP for diagnosis.', mbInformation, MB_OK);
end;
