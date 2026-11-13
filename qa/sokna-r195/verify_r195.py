#!/usr/bin/env python3
"""Fail closed: prove this is the exact R194 source plus one authorized printing PHP change."""
from pathlib import Path
import sys,hashlib,json,zipfile,io
root=Path(sys.argv[1]);archive=Path(sys.argv[2]);patch=Path(sys.argv[3]);
EXPECTED_ARCHIVE='3444b9febcde490cc3f2a5a9d0f3bf1c7d270e3844e7bec6d60fb5b0d560ea0b'
EXPECTED_PARENT_FILE='b50ff277285d1278706c61aac1e4f3e8364744132b445bac4b34ef3144dd13b7'
EXPECTED_CHANGED_FILE='2207ce49179978731a842d9409b3ff9d0d6d6c9fd2e22520937dbb8887e53025'
TARGET='src/Domain/Printing/PrintService.php'
assert hashlib.sha256(archive.read_bytes()).hexdigest()==EXPECTED_ARCHIVE,'R194_PARENT_ZIP_DRIFT'
with zipfile.ZipFile(archive) as outer:
 assert outer.testzip() is None,'R194_OUTER_CRC'
 manifest=json.loads(outer.read('release-set.json'))
 assert '231ey-r194' in manifest['targets']['local']['version'],'R194_VERSION_WRONG'
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as origin:
  all_names=origin.namelist()
  assert len(all_names)==len(set(all_names))==590,'R194_SOURCE_NOT_590_FILES'
  assert hashlib.sha256(origin.read(TARGET)).hexdigest()==EXPECTED_PARENT_FILE,'BASE_PRINT_SERVICE_CHANGED'
  inventory=json.loads(origin.read('resources/local-managed-files-v1.json'))['files']
  assert sorted(inventory)==sorted(all_names),'R194_INVENTORY_INCOMPLETE'
  wrong=[]
  for name in all_names:
   want=EXPECTED_CHANGED_FILE if name==TARGET else hashlib.sha256(origin.read(name)).hexdigest()
   p=root/name
   if not p.is_file() or hashlib.sha256(p.read_bytes()).hexdigest()!=want:wrong.append(name)
  extras=sorted(str(p.relative_to(root).as_posix()) for p in root.rglob('*') if p.is_file() and p.relative_to(root).as_posix() not in all_names)
  assert not wrong,('R195_FILE_DRIFT',wrong)
  assert not extras,('R195_UNEXPECTED_FILE',extras)
assert patch.is_file() and patch.stat().st_size<5000,'R195_PATCH_MISSING'
print('PASS_R195_EXACT_R194_PARENT_590_FILES_SINGLE_PRINT_LABEL_CHANGE_NO_MIGRATION_NO_AGENT')
