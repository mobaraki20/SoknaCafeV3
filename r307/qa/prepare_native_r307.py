#!/usr/bin/env python3
"""Strictly rebase historical native Maintenance Stage/Activate harness onto R306->R307 exact ZIPs."""
from pathlib import Path
import os,subprocess,sys
# R304 source preparer is the tested lineage of the R301 native Windows test.
subprocess.run([sys.executable,'r304/qa/prepare_native_windows.py'],check=True)
temp=Path(os.environ['RUNNER_TEMP']);base=temp/'r304-native.ps1';s=base.read_text(encoding='utf8')
items={
 '3.0.0-g6.2-workspace.231fs-r301-public-move-controlled-install':'3.0.0-g6.2-workspace.231fv-r306-local-public-ui-parity',
 '3.0.0-g6.2-workspace.231fu-r304-controlled-ui-finance':'3.0.0-g6.2-workspace.231fw-r307-dashboard-money-calendar-notify',
 '399356a587230c770cbf50f6c3bdd93f0ee4a339e35ec3c1c381d61473d8cb73':'1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209',
 '6dccf704f130610821e2727858bbcdd2531780ede5781c896153fb480201f1e1':'069a267f920b2d0614ca61443851b4f385994dca379d2bbbb3e1c42aba8047fa',
 '159b20c33d9b8d92a0a53f5ab1a91bcf8ca60e6a446b836636d66dbd4213193a':'63df287e865ac0503fd21dea275a4714c1138f0c11ee53834522b7cb3d060d7c',
 '9de2777162d05b0fa16e41d6c06380c8b039725f88a193bba23223574f9c2d51':'5455c9d2ea4762a050d826f59c1896f7e22827b1cdad9f6106bca356ba94b186',
 'SOKNA_R301_ORIGINAL_ZIP':'SOKNA_R306_ORIGINAL_ZIP',
 'SOKNA_R304_CANDIDATE_ZIP':'SOKNA_R307_CANDIDATE_ZIP',
 'Web.release.r301':'Web.release.r306',
 'R301_BASELINE_TREE_MISMATCH':'R306_BASELINE_TREE_MISMATCH',
 'R304_TARGET_TREE_MISMATCH':'R307_TARGET_TREE_MISMATCH',
 'R304_EXACT_PACKAGE_SHA_INVALID':'R307_EXACT_PACKAGE_SHA_INVALID',
 'R301_EXACT_PARENT_PACKAGE_SHA_INVALID':'R306_EXACT_PARENT_PACKAGE_SHA_INVALID',
 'R304_VERSION_WRONG':'R307_VERSION_WRONG',
 'R304_NATIVE_WINDOWS_REAL_MAINTENANCE_R301_TO_R304_STAGE_ACTIVATE_HTTP_DB83_PERSISTENT_PASS':'R307_NATIVE_WINDOWS_REAL_MAINTENANCE_R306_TO_R307_STAGE_ACTIVATE_HTTP_DB83_PERSISTENT_PASS',
}
for old,new in items.items():
 if old not in s:raise RuntimeError('R307_NATIVE_TRANSFORM_MISSING '+old)
 s=s.replace(old,new)
assert 'SOKNA_R306_ORIGINAL_ZIP' in s and 'SOKNA_R307_CANDIDATE_ZIP' in s
assert '3.0.0-g6.2-workspace.231fv-r306-local-public-ui-parity' in s
assert '3.0.0-g6.2-workspace.231fw-r307-dashboard-money-calendar-notify' in s
assert '127.0.0.1:39841' in s and 'SOKNA_R186_CI_ONLY' in s
out=temp/'r307-native.ps1';out.write_text(s,encoding='utf8');print('R307_NATIVE_EXACT_PARENT_PINNED',len(s))
