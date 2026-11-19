#!/usr/bin/env python3
"""Strictly translate earlier native Windows integration test to R301 -> R304."""
from pathlib import Path
import os
s=Path('r301/tests/native_r299_to_r301.ps1').read_text(encoding='utf-8')
new_version='3.0.0-g6.2-workspace.231fu-r304-controlled-ui-finance'
old_version='3.0.0-g6.2-workspace.231fs-r301-public-move-controlled-install'
changes={
'$pack=$env:SOKNA_R301_CANDIDATE_ZIP':'$pack=$env:SOKNA_R304_CANDIDATE_ZIP',
'$parent=$env:SOKNA_R299_ORIGINAL_ZIP':'$parent=$env:SOKNA_R301_ORIGINAL_ZIP',
"-ceq '399356a587230c770cbf50f6c3bdd93f0ee4a339e35ec3c1c381d61473d8cb73') 'R301_EXACT_PACKAGE_SHA_INVALID'":"-ceq '6dccf704f130610821e2727858bbcdd2531780ede5781c896153fb480201f1e1') 'R304_EXACT_PACKAGE_SHA_INVALID'",
"-ceq '8a897f3ac9215e06d72f67be292b11cf17263a0ab687d9b59d8bc341ac818507') 'R299_EXACT_PARENT_PACKAGE_SHA_INVALID'":"-ceq '399356a587230c770cbf50f6c3bdd93f0ee4a339e35ec3c1c381d61473d8cb73') 'R301_EXACT_PARENT_PACKAGE_SHA_INVALID'",
"$old=Join-Path $root 'Web.release.r299'":"$old=Join-Path $root 'Web.release.r301'",
"-ceq '3.0.0-g6.2-workspace.231fq-r299-cumulative-controlled-install'})":"-ceq '"+old_version+"'})",
"-ceq '04e4bf1cc5c9c860835f4081a9f7f847f5fd9d9979f3d8558c5fcd3cee79517f') 'R299_BASELINE_TREE_MISMATCH'":"-ceq '159b20c33d9b8d92a0a53f5ab1a91bcf8ca60e6a446b836636d66dbd4213193a') 'R301_BASELINE_TREE_MISMATCH'",
"-ceq '3.0.0-g6.2-workspace.231fq-r299-cumulative-controlled-install') 'BASELINE_VERSION_WRONG'":"-ceq '"+old_version+"') 'BASELINE_VERSION_WRONG'",
"-ceq '"+old_version+"') 'R301_VERSION_WRONG'":"-ceq '"+new_version+"') 'R304_VERSION_WRONG'",
"-ceq '159b20c33d9b8d92a0a53f5ab1a91bcf8ca60e6a446b836636d66dbd4213193a') 'R301_TARGET_TREE_MISMATCH'":"-ceq '9de2777162d05b0fa16e41d6c06380c8b039725f88a193bba23223574f9c2d51') 'R304_TARGET_TREE_MISMATCH'",
"'R301_NATIVE_WINDOWS_REAL_MAINTENANCE_R299_231FQ_TO_R301_231FS_STAGE_ACTIVATE_HTTP_DB83_PERSISTENT_PASS'":"'R304_NATIVE_WINDOWS_REAL_MAINTENANCE_R301_TO_R304_STAGE_ACTIVATE_HTTP_DB83_PERSISTENT_PASS'"
}
for before,after in changes.items():
    count=s.count(before)
    if count != 1: raise ValueError(f'ASSERT_NATIVE_ADAPTATION_{count}_EXPECTED_ONE: {before}')
    s=s.replace(before,after)
assert 'GITHUB_ACTIONS' in s and 'SOKNA_R186_CI_ONLY' in s and '127.0.0.1:39841' in s
output=Path(os.environ['RUNNER_TEMP'])/'r304-native.ps1'
output.write_text(s,encoding='utf-8')
print('PASS_R304_NATIVE_STRICT_ADAPTATION',len(s),output)
