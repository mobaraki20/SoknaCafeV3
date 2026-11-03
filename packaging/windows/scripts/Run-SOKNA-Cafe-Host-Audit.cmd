@echo off
setlocal
title SOKNA Cafe - Safe Host Readiness Audit
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0diagnose-cafe-host-readonly-v1.ps1"
if errorlevel 1 (
  echo SOKNA host audit was not completed. No change was made to Local or Maintenance.
) else (
  echo SOKNA host audit finished. Check the new SOKNA-Host-Audit ZIP on Desktop.
)
echo.
pause
endlocal
