#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
python3 tests/g5-windows-packaging-contract.py
python3 tests/g5-prerequisite-lock-selftest.py
python3 tests/m7-runtime-gate.py
python3 tests/m8-print-agent-gate.py
python3 tests/runtime-print-v1-contract.py
python3 tests/runtime-print-contract-audit.py
python3 tests/component-registry-gate.py
if ! command -v pwsh >/dev/null 2>&1; then echo 'G5 Windows qualification requires PowerShell/.NET/Inno on Windows.' >&2; exit 3; fi
pwsh -NoProfile -Command '$ErrorActionPreference="Stop"; $files=@("packaging/windows/scripts/setup-windows-services.ps1","packaging/windows/scripts/remove-windows-services.ps1","packaging/windows/scripts/prepare-shell-payload.ps1","packaging/windows/scripts/build-installer.ps1"); foreach($f in $files){[void][scriptblock]::Create((Get-Content -LiteralPath $f -Raw))}; "PowerShell parse: PASS"'
if ! command -v dotnet >/dev/null 2>&1; then echo 'G5 Windows qualification requires dotnet SDK.' >&2; exit 3; fi
dotnet build packaging/windows/setup-host/Sokna.SetupHost.csproj -c Release
dotnet build packaging/windows/setup-ui/Sokna.SetupUi.csproj -c Release
echo 'G5 Windows Packaging Qualification: PASS'
