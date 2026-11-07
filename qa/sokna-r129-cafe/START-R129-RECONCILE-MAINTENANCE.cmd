@echo off
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
echo.
echo SOKNA R129 - reconcile exact cafe R125 emergency rollback to R112
echo This is NOT an application upgrade.
echo No print, sales, public or local application file is modified.
echo.
powershell -NoProfile -Command "([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)" | findstr /I "True" >nul
if errorlevel 1 (
 echo ERROR: Run as administrator.
 pause
 exit /b 5
)
set "PHP_BIN=" 
if not exist "D:\SOKNA\Infrastructure\" (
 echo ERROR: SOKNA infrastructure missing.
 pause
 exit /b 6
)
for /R "D:\SOKNA\Infrastructure" %%F in (php.exe) do (
 if not defined PHP_BIN if exist "%%~fF" set "PHP_BIN=%%~fF"
)
if not defined PHP_BIN (
 echo ERROR: PHP not found.
 pause
 exit /b 7
)
for %%P in ("%PHP_BIN%") do set "PHP_DIR=%%~dpP"
echo.
echo Do NOT run this unless R125 restore returned R112 successfully.
echo To authorize evidence reconciliation only, type RECONCILE-R112
set /p "ACK=Confirmation: "
if /I not "%ACK%"=="RECONCILE-R112" (
 echo Cancelled.
 pause
 exit /b 0
)
if exist "%PHP_DIR%php.ini" (
 "%PHP_BIN%" -c "%PHP_DIR%php.ini" -f "%~dp0SOKNA-RECONCILE.php" 2> "%~dp0SOKNA-R129-ERROR.txt"
) else (
 "%PHP_BIN%" -f "%~dp0SOKNA-RECONCILE.php" 2> "%~dp0SOKNA-R129-ERROR.txt"
)
set "RC=%ERRORLEVEL%"
echo.
echo Exit code: %RC%
echo Please send SOKNA-R129-RECONCILE-RESULT.txt to ChatGPT.
echo Do not start a new update before reviewing this report.
pause
exit /b %RC%