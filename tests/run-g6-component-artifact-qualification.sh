#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)";cd "$ROOT"
fail_env(){ echo "$1" >&2; exit 3; }
command -v python3 >/dev/null 2>&1 || fail_env 'G6 component qualification requires Python 3.'
python3 release/generate-compatibility-v2.py >/dev/null
python3 tests/g6-component-release-contract.py
python3 tests/g6-release-preflight.py
python3 tests/m9-packaging-gate.py
python3 tests/m10-release-qualification.py
python3 tests/component-registry-gate.py
python3 tests/product-parity-gate.py --mode inventory
TMP="${SOKNA_G6_ARTIFACT_DIR:-$(mktemp -d)}"; export TMP
python3 release/build-portable-release.py --out "$TMP/portable" >/dev/null
python3 - <<'PY'
import json,os
from pathlib import Path
p=Path(os.environ['TMP'])/'portable/artifact-index.json';j=json.loads(p.read_text())
assert j['format']=='sokna-portable-release-index-v1' and len(j['artifacts'])==4
print('Portable independent artifacts: PASS')
PY
command -v pwsh >/dev/null 2>&1 || fail_env 'G6 component qualification requires Windows PowerShell/PowerShell for Windows artifact pipelines.'
command -v dotnet >/dev/null 2>&1 || fail_env 'G6 component qualification requires dotnet SDK.'
if [[ "${OS:-}" != "Windows_NT" ]] && ! pwsh -NoProfile -Command 'if($env:OS -ne "Windows_NT"){exit 1}' >/dev/null 2>&1; then fail_env 'G6 component qualification requires Windows for Runtime/Print/Installer artifacts.'; fi
pwsh -NoProfile -File release/build-windows-release.ps1 -RepoRoot "$ROOT" -OutputRoot "$TMP/windows"
python3 - <<'PY'
import json,os
from pathlib import Path
p=Path(os.environ['TMP'])/'windows/artifact-index.json';j=json.loads(p.read_text(encoding='utf-8-sig'))
assert j['format']=='sokna-windows-release-index-v1'
print('Windows independent artifacts: PASS')
PY
printf 'G6 Component Release Qualification: PASS\n'
