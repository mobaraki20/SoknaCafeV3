@echo off
setlocal
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Inspect-Sokna-PrintAgent-ReadOnly.ps1"
echo.
echo Read-only Print Agent identity report saved to Desktop. No installation has occurred.
pause
