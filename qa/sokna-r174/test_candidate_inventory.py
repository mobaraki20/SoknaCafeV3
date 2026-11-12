#!/usr/bin/env python3
"""Replicate Windows Candidate's exact inventory paths gate (read-only, fails safely)."""
from __future__ import annotations
import sys,zipfile,io,json,hashlib,pathlib

def check(path):
  with zipfile.ZipFile(path) as outer:
    manifest=json.loads(outer.read('release-set.json'))
    with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
      paths=inner.namelist(); count=manifest['targets']['local']['managed_files']
      listed=json.loads(inner.read('resources/local-managed-files-v1.json'))['files']
      tree=hashlib.sha256(''.join(n+'\0'+hashlib.sha256(inner.read(n)).hexdigest()+'\n' for n in sorted(paths)).encode()).hexdigest()
      return {'version':manifest['targets']['local']['version'],'archive_count':len(paths),'managed_declared':count,'inventory_count':len(listed),'missing_in_inventory':sorted(set(paths)-set(listed)),'extra_in_inventory':sorted(set(listed)-set(paths)),'inventory_unique_casefold':len(set(p.lower() for p in listed))==len(listed),'tree_match':tree==manifest['targets']['local']['managed_tree_sha256'],'package_sha256':hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()}
if __name__=='__main__':
 for p in sys.argv[1:]:
  d=check(p)
  print(json.dumps({'package':pathlib.Path(p).name,**d},ensure_ascii=False,indent=2))
  print('PASS_CANDIDATE_INVENTORY' if not d['missing_in_inventory'] and not d['extra_in_inventory'] and d['archive_count']==d['managed_declared']==d['inventory_count'] and d['tree_match'] and d['inventory_unique_casefold'] else 'FAIL_LOCAL_WEB_INVENTORY_FILES_MISMATCH')
