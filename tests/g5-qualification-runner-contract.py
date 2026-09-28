#!/usr/bin/env python3
from pathlib import Path
import sys
R=Path(__file__).resolve().parents[1]
p=R/'tests/run-g5-windows-packaging-qualification.sh'
s=p.read_text(encoding='utf-8')
def need(v,m):
    if not v: print('FAIL G5 runner:',m,file=sys.stderr); raise SystemExit(1)
for token in ['g5-windows-packaging-contract.py','g5-prerequisite-lock-selftest.py','m7-runtime-gate.py','m8-print-agent-gate.py','component-registry-gate.py','pwsh','dotnet build packaging/windows/setup-host','dotnet build packaging/windows/setup-ui','G5 Windows Packaging Qualification: PASS']:
    need(token in s,f'missing {token}')
need(s.index("command -v pwsh") < s.index('G5 Windows Packaging Qualification: PASS'),'PASS marker can precede Windows environment gate')
need(s.index("command -v dotnet") < s.index('G5 Windows Packaging Qualification: PASS'),'PASS marker can precede build gate')
print('PASS G5 qualification runner contract')
