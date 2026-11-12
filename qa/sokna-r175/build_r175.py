#!/usr/bin/env python3
"""R174 exact parent -> R175 Local only UAT (NOT installable).
No business DB, updater, Public, maintenance or live host changes.
"""
import argparse,hashlib,io,json,zipfile
from pathlib import Path

BASE_SHA='b8e15174670240c082a78bf7c87b43d6d72ba33590d1e645f601e7bc45336088'
VERSION='3.0.0-g6.2-workspace.231eq-r175-resumable-golive-qa'
RELEASE='sokna-r175-resumable-golive-qa-20261112'
CHANGED=['src/Domain/System/GoLiveResetEngine.php','src/Domain/System/GoLiveResetCoordinator.php','public/system/go-live.php']
H=lambda b:hashlib.sha256(b).hexdigest()
def jb(o):return (json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def putzip(d):
  buffer=io.BytesIO()
  with zipfile.ZipFile(buffer,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for n,v in sorted(d.items()):
      entry=zipfile.ZipInfo(n,(1980,1,1,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED;entry.external_attr=0o100644<<16
      z.writestr(entry,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
  return buffer.getvalue()
def target_tree(d):return H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
def canonical(d):
  omit={'resources/build-provenance.json','resources/release-identity-v1.json'}
  return H(''.join(n+'\0'+str(len(d[n]))+'\0'+H(d[n])+'\n' for n in sorted(d) if n not in omit).encode())

def main():
  ap=argparse.ArgumentParser();ap.add_argument('--parent',required=True);ap.add_argument('--patched-tree',required=True);ap.add_argument('--out',required=True);ap.add_argument('--canary',action='store_true')
  a=ap.parse_args();source=Path(a.parent);patched=Path(a.patched_tree);out=Path(a.out);out.mkdir(parents=True,exist_ok=True)
  version=VERSION if not a.canary else '3.0.0-g6.2-workspace.231eq-r175-resumable-golive-canary'
  release=RELEASE if not a.canary else 'sokna-r175-resumable-golive-canary-20261112'
  if H(source.read_bytes())!=BASE_SHA:raise ValueError('R174_PARENT_PACKAGE_SHA_MISMATCH')
  with zipfile.ZipFile(source) as outer:
    if set(outer.namelist())!={'release-set.json','artifacts/local/web.zip'}:raise ValueError('PARENT_STRUCTURE_UNEXPECTED')
    manifest=json.loads(outer.read('release-set.json'))
    with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as nested:
      original={p:nested.read(p) for p in nested.namelist()}
  if len(original)!=589:raise ValueError('R174_FILE_COUNT_UNEXPECTED')
  parentIdentity=dict(manifest['targets']['local'])
  if parentIdentity['version']!='3.0.0-g6.2-workspace.231ep-r174-inventory-candidate-repair-canary':raise ValueError('PARENT_VERSION_UNEXPECTED')
  if len(manifest['supported_baselines'])!=52:raise ValueError('PARENT_BASELINES_UNEXPECTED')
  files=original.copy()
  for n in CHANGED:
    path=patched/n
    if not path.is_file():raise ValueError('PATCHED_FILE_MISSING:'+n)
    data=path.read_bytes()
    if data==files[n]:raise ValueError('EXPECTED_SCOPED_CHANGE_MISSING:'+n)
    files[n]=data
  files['VERSION.txt']=(version+'\n').encode()
  provenance=json.loads(files['resources/build-provenance.json']);provenance.update(version=version,release_set_id=release,classification=('R175_BOUNDED_GOLIVE_RECOVERY_CANARY' if a.canary else 'R175_BOUNDED_GOLIVE_RECOVERY_SOURCE_QA'),production_host_install_verified=False,full_business_acceptance=False)
  files['resources/build-provenance.json']=jb(provenance)
  identity=json.loads(files['resources/release-identity-v1.json']);identity['version']=version;identity['release_set_id']=release
  identity['source']['canonical_file_count']=len(files)-2
  identity['source']['managed_file_count']=len(files)
  identity['source']['canonical_tree_sha256']=canonical(files)
  identity['source']['source_ref']='R174 exact 589-file source + three scoped go-live changes; no migration, Public or Maintenance changes'
  identity['qualification']['tier']='R175_WINDOWS_STAGE_CANARY' if a.canary else 'R175_QA_NOT_INSTALLABLE_UNTIL_MARIADB_AND_WINDOWS'
  files['resources/release-identity-v1.json']=jb(identity)
  inventory=json.loads(files['resources/local-managed-files-v1.json'])
  if len(inventory['files'])!=len(files) or set(inventory['files'])!=set(files):raise ValueError('MANAGED_INVENTORY_NOT_EXACT')
  originalDiff={n for n in files if files[n]!=original[n]}
  expected=set(CHANGED)|{'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'}
  if originalDiff!=expected:raise ValueError('UNBOUNDED_SOURCE_MUTATION:'+str(originalDiff^expected))
  web=putzip(files);tree=target_tree(files)
  manifest['supported_baselines'].append({'local':parentIdentity,'public':None})
  if len(manifest['supported_baselines'])!=53:raise ValueError('BASELINE_COUNT_MISMATCH')
  manifest.update(release_set_id=release,sequence=20261112715,status=('R175_CUMULATIVE_INSTALLABLE_CANARY_PRE_RELEASE' if a.canary else 'R175_QA_NOT_FOR_INSTALL'),for_install=bool(a.canary),production_pass=bool(a.canary))
  manifest['targets']['local'].update(version=version,release_id=release,managed_files=len(files),managed_tree_sha256=tree)
  manifest['artifacts']['local-web'].update(size_bytes=len(web),sha256=H(web))
  manifest.setdefault('qa_notes',{}).update(r175_provenance='R174 exact identity plus 3 scoped PHP/UX files',r175_field_tested=False,r175_cleanup_previous_failure_replay_proven=False,r175_schema_migration_changed=False)
  name='SOKNA-R175-LOCAL-231EQ-53BASE-GOLIVE-'+('CANARY-PANEL-UPDATE' if a.canary else 'QA-DO-NOT-INSTALL')+'.zip'
  blob=putzip({'release-set.json':jb(manifest),'artifacts/local/web.zip':web})
  (out/name).write_bytes(blob)
  report={'artifact':name,'artifact_sha256':H(blob),'target_version':version,'target_tree_sha256':tree,'source_parent_sha256':BASE_SHA,'supported_baselines':53,'source_file_count':len(files),'changed_files':sorted(originalDiff),'migration_head_unchanged':True,'for_install':bool(a.canary),'field_verified':False}
  (out/'R175-QA-PACKAGE-IDENTITY.json').write_bytes(jb(report))
  print(json.dumps(report,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
