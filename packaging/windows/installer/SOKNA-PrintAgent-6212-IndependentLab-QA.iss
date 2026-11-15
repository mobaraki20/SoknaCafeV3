#ifndef PayloadRoot
 #error PayloadRoot must be supplied
#endif
#ifndef OutputDir
 #error OutputDir must be supplied
#endif
[Setup]
AppId={{8E4EA04B-DA2C-4631-9450-7BD731BC9070}
AppName=SOKNA Print Agent 6.2.12 Independent Windows QA (NO CAFE INSTALL)
AppVersion=6.2.12
VersionInfoVersion=6.2.12.0
VersionInfoTextVersion=6.2.12.0
AppPublisher=SOKNA
CreateAppDir=no
Uninstallable=no
CreateUninstallRegKey=no
DisableProgramGroupPage=yes
DisableDirPage=yes
AllowNoIcons=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
CloseApplications=no
RestartApplications=no
OutputDir={#OutputDir}
OutputBaseFilename=SOKNA-PrintAgent-6.2.12-Universal-Lab-QA-NOT-FOR-CAFE
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes

[Files]
Source: "{#PayloadRoot}\SoknaPrintAgent-6.2.12-win-x64.zip"; DestDir: "{tmp}\SOKNA-PRINT-QA"; Flags: ignoreversion deleteafterinstall
Source: "{#PayloadRoot}\Invoke-PrintAgentIndependentLab.ps1"; DestDir: "{tmp}\SOKNA-PRINT-QA"; Flags: ignoreversion deleteafterinstall

[Code]
var
  QaLabRoot: String;
function ReadLabArg(): String;
var I: Integer; V: String;
begin
  Result := '';
  for I := 1 to ParamCount do
  begin
    V := ParamStr(I);
    if Pos('/LABROOT=', Uppercase(V)) = 1 then
    begin
      Result := Copy(V, 10, MaxInt);
      Exit;
    end;
  end;
end;

function InitializeSetup(): Boolean;
var RunnerDir, ExpandedRoot: String;
begin
  Result := False;
  QaLabRoot := ReadLabArg();
  RunnerDir := ExpandConstant('{%RUNNER_TEMP}');
  if (GetEnv('GITHUB_ACTIONS') <> 'true') or (QaLabRoot = '') or (RunnerDir = '') then
  begin
    MsgBox('این فایل فقط برای آزمایش خودکار Windows در GitHub Actions است و روی رایانه کافه یا خانه نصب نمی‌شود.', mbCriticalError, MB_OK);
    Exit;
  end;
  ExpandedRoot := Uppercase(AddBackslash(QaLabRoot));
  if Pos(Uppercase(AddBackslash(RunnerDir)), ExpandedRoot) <> 1 then
  begin
    MsgBox('QA_LAB_ROOT_OUTSIDE_RUNNER_TEMP', mbCriticalError, MB_OK);
    Exit;
  end;
  Result := True;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var PowerShell, Args, ScriptPath, ZipPath: String; ExitCode: Integer;
begin
  if CurStep <> ssPostInstall then Exit;
  PowerShell := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
  ScriptPath := ExpandConstant('{tmp}\SOKNA-PRINT-QA\Invoke-PrintAgentIndependentLab.ps1');
  ZipPath := ExpandConstant('{tmp}\SOKNA-PRINT-QA\SoknaPrintAgent-6.2.12-win-x64.zip');
  Args := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' + AddQuotes(ScriptPath) +
    ' -PackageZip ' + AddQuotes(ZipPath) + ' -LabRoot ' + AddQuotes(QaLabRoot) + ' -Mode Apply -AllowMutation';
  if not Exec(PowerShell, Args, '', SW_HIDE, ewWaitUntilTerminated, ExitCode) then
    RaiseException('R238_QA_POWERSHELL_START_FAILED');
  if ExitCode <> 0 then
    RaiseException('R238_QA_TRANSACTION_FAILED_CODE_' + IntToStr(ExitCode));
end;