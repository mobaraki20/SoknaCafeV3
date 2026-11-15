#ifndef PayloadRoot
 #error PayloadRoot must be supplied
#endif
#ifndef OutputDir
 #error OutputDir must be supplied
#endif
[Setup]
AppId={{CE7D9078-1685-471C-B749-6A282BEBA2E7}
AppName=SOKNA Print Agent 6.2.12 Universal
AppVersion=6.2.12
VersionInfoVersion=6.2.12.0
VersionInfoTextVersion=6.2.12.0
AppPublisher=SOKNA
CreateAppDir=no
Uninstallable=no
CreateUninstallRegKey=no
DisableProgramGroupPage=yes
DisableDirPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
CloseApplications=no
RestartApplications=no
OutputDir={#OutputDir}
OutputBaseFilename=SOKNA-PrintAgent-6.2.12-Independent-Universal-RC
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes

[Files]
Source: "{#PayloadRoot}\SoknaPrintAgent-6.2.12-win-x64.zip"; DestDir: "{tmp}\SOKNA-PRINT-UNIVERSAL"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Invoke-PrintAgentIndependentUniversal.ps1"; DestDir: "{tmp}\SOKNA-PRINT-UNIVERSAL"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Sokna.PrintAgent.UpgradeGuard.exe"; DestDir: "{tmp}\SOKNA-PRINT-UNIVERSAL"; Flags: ignoreversion deleteafterinstall

[Code]
var
  LabRoot: String;
  InstallExit: Integer;
function ArgValue(const Key: String): String;
var
  I: Integer; S: String;
begin
  Result := '';
  for I := 1 to ParamCount do
  begin
    S := ParamStr(I);
    if Pos(Uppercase(Key) + '=', Uppercase(S)) = 1 then
    begin
      Result := Copy(S, Length(Key)+2, MaxInt);
      Exit;
    end;
  end;
end;
function InitializeSetup(): Boolean;
var
  RunnerPath: String;
begin
  Result := False;
  InstallExit := 0;
  LabRoot := ArgValue('/LABROOT');
  if LabRoot <> '' then
  begin
    RunnerPath := GetEnv('RUNNER_TEMP');
    if (GetEnv('GITHUB_ACTIONS') <> 'true') or (RunnerPath = '') or
       (Pos(Uppercase(AddBackslash(RunnerPath)), Uppercase(AddBackslash(LabRoot))) <> 1) then
    begin
      InstallExit := 79;
      Log('SOKNA_QA_ROOT_NOT_OWNED_BY_RUNNER');
      Exit;
    end;
    Result := True;
    Exit;
  end;
  if WizardSilent then
  begin
    InstallExit := 78;
    Log('SOKNA_LIVE_SILENT_INSTALL_REQUIRES_MANUAL_APPROVAL');
    Exit;
  end;
  if MsgBox('SOKNA Print Agent 6.2.12' + #13#10 +
            'Only install when no printing is in progress.' + #13#10 +
            'To prevent the existing supervisor from restarting the print worker during replacement, SOKNA Runtime may briefly stop and be restored.' + #13#10 +
            'All database, printer and pairing data will be preserved.' + #13#10 +
            'Continue?', mbConfirmation, MB_YESNO) <> IDYES then
  begin
    InstallExit := 77;
    Exit;
  end;
  Result := True;
end;
procedure CurStepChanged(CurStep: TSetupStep);
var
  Exe, Args, ScriptPath, ZipPath, GuardPath: String;
  Code: Integer;
begin
  if CurStep <> ssPostInstall then Exit;
  Exe := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  ScriptPath := ExpandConstant('{tmp}\SOKNA-PRINT-UNIVERSAL\Invoke-PrintAgentIndependentUniversal.ps1');
  ZipPath := ExpandConstant('{tmp}\SOKNA-PRINT-UNIVERSAL\SoknaPrintAgent-6.2.12-win-x64.zip');
  GuardPath := ExpandConstant('{tmp}\SOKNA-PRINT-UNIVERSAL\Sokna.PrintAgent.UpgradeGuard.exe');
  Args := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' + AddQuotes(ScriptPath) +
    ' -PackageZip ' + AddQuotes(ZipPath) + ' -QueueGuardExe ' + AddQuotes(GuardPath) +
    ' -Mode Apply -AllowMutation';
  if LabRoot <> '' then
    Args := Args + ' -LabRoot ' + AddQuotes(LabRoot);
  if not Exec(Exe, Args, '', SW_HIDE, ewWaitUntilTerminated, Code) then
  begin
    InstallExit := 74;
    Log('SOKNA_PRINT_AGENT_POWERSHELL_COULD_NOT_START');
    Exit;
  end;
  if Code <> 0 then
  begin
    InstallExit := 75;
    Log('SOKNA_PRINT_AGENT_TRANSACTION_FAILED CODE=' + IntToStr(Code));
    Exit;
  end;
  Log('SOKNA_PRINT_AGENT_TRANSACTION_VERIFIED_BY_SCRIPT');
end;
function GetCustomSetupExitCode: Integer;
begin
  Result := InstallExit;
end;
