#ifndef WebZip
#error WebZip required
#endif
#ifndef StageScript
#error StageScript required
#endif
#ifndef OutDir
#error OutDir required
#endif
[Setup]
AppId=SOKNA-CP77-CLEAN-WEB-STAGE-DISPOSABLE-QA
AppName=SOKNA Local Web stage - QA ONLY
AppVersion=231gg-qa
DefaultDirName={sd}\Sokna-Infra-QA-NEVER-USE-OUTSIDE-CI
DisableDirPage=no
DisableProgramGroupPage=yes
UsePreviousAppDir=no
Uninstallable=no
PrivilegesRequired=admin
OutputDir={#OutDir}
OutputBaseFilename=SOKNA-231GG-CLEAN-WEB-STAGE-QA-ONLY
Compression=lzma2
SolidCompression=yes
SetupLogging=yes
[Files]
Source: "{#WebZip}"; DestName: "local-web.zip"; Flags: dontcopy
Source: "{#StageScript}"; DestName: "stage.ps1"; Flags: dontcopy
[Code]
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
  Args: String;
begin
  Result := 'Disposable Windows CI only; no release authorization.';
  if (GetEnv('GITHUB_ACTIONS') <> 'true') or (GetEnv('RUNNER_OS') <> 'Windows') then
    Exit;
  ExtractTemporaryFile('local-web.zip');
  ExtractTemporaryFile('stage.ps1');
  Args := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
    ExpandConstant('{tmp}\stage.ps1') + '" -Root "' +
    ExpandConstant('{app}') + '" -Package "' +
    ExpandConstant('{tmp}\local-web.zip') + '"';
  if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), Args, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    Result := 'Could not start QA stage.'
  else if ResultCode <> 0 then
    Result := 'QA stage failed or refused existing installation. Code: ' + IntToStr(ResultCode)
  else
    Result := '';
end;
