#!/usr/bin/env python3
"""Strict adaptation of already-qualified R301->R304 actual native Windows test to R301->R306."""
from pathlib import Path
import os
s=Path('r304/qa/prepare_native_windows.py').read_text(encoding='utf-8')
replacements={
"new_version='3.0.0-g6.2-workspace.231fu-r304-controlled-ui-finance'":"new_version='3.0.0-g6.2-workspace.231fv-r306-local-public-ui-parity'",
"$pack=$env:SOKNA_R304_CANDIDATE_ZIP":"$pack=$env:SOKNA_R306_CANDIDATE_ZIP",
"6dccf704f130610821e2727858bbcdd2531780ede5781c896153fb480201f1e1":"1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209",
"9de2777162d05b0fa16e41d6c06380c8b039725f88a193bba23223574f9c2d51":"63df287e865ac0503fd21dea275a4714c1138f0c11ee53834522b7cb3d060d7c",
"r304-native.ps1":"r306-native.ps1",
"R304_":"R306_",
}
for old,new in replacements.items():
 if old not in s:raise ValueError('R306_NATIVE_PREP_EXPECTED '+old)
 s=s.replace(old,new)
output=Path(os.environ['RUNNER_TEMP'])/'r306-prepare-from-r301.py'
output.write_text(s,encoding='utf-8')
print('R306_NATIVE_PREPARER_WRITTEN',str(output))
