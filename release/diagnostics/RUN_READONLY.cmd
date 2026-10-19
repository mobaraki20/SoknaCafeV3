@echo off
setlocal
powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0SOKNA-Windows-ReadOnly-Inventory.ps1"
if errorlevel 1 echo Evidence collection failed. No installer was run.
pause
