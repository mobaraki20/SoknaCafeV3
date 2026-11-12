#!/usr/bin/env python3
"""Repair R171's manifest/inventory mismatch without mutating the installed system.
The immutable R171 canary is read-only. Output is a distinct R174 exact-target candidate.
"""
import argparse,hashlib,io,json,zipfile
from pathlib import Path

OLD='3.0.0-g6.2-workspace.231eo-r171-cumulative-staff-pin-expenses-uat'
NEW='3.0.0-g6.2-workspace.231ep-r174-inventory-candidate-repair-canary'
RELEASE_ID='sokna-r174-231ep-managed-inventory-repair-20261112'

def sha(b):return hashlib.sha256(b).hexdigest()
def jbytes(obj):return (json.dumps(obj,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode('utf-8')
def web_tree(files):return sha(''.join(p+'\0'+sha(data)+'\n' for p,data in sorted(files.items())).encode())
def canonical(files):
 ex={'resources/build-provenance.json','resources/release-identity-v1.json'}
 return sha(''.join(p+'\0'+str(len(files[p]))+'\0'+sha(files[p])+'\n' for p in sorted(files) if p not in ex).encode())
def zbytes(entries,prototype=None):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9,allowZip64=True) as zipout:
  for name,data in sorted(entries.items()):
   info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED
   info.external_attr=(0o100644 << 16)
   zipout.writestr(info,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return out.getvalue()
def check(condition,reason):
 if not condition:raise RuntimeError(reason)
def main():
 a=argparse.ArgumentParser();a.add_argument('immutable_r171');a.add_argument('outdir');args=a.parse_args()
 src=Path(args.immutable_r171);out=Path(args.outdir);out.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(src) as outer:
  check(set(outer.namelist())=={'release-set.json','artifacts/local/web.zip'},'UNEXPECTED_OUTER_STRUCTURE')
  m=json.loads(outer.read('release-set.json'))
  web=outer.read('artifacts/local/web.zip')
 with zipfile.ZipFile(io.BytesIO(web)) as inner:
  names=inner.namelist();check(len(names)==len(set(names))==589,'R171_BASE_NOT_EXACT_589')
  files={p:inner.read(p) for p in names}
 check(files['VERSION.txt'].decode().strip()==OLD,'WRONG_R171_SOURCE')
 inventory=json.loads(files['resources/local-managed-files-v1.json'])
 missing=sorted(set(names)-set(inventory['files']))
 check(len(inventory['files'])==583 and len(missing)==6, 'EXPECTED_R171_INVENTORY_GAP_NOT_FOUND')
 inventory['files']=sorted(names)
 files['resources/local-managed-files-v1.json']=jbytes(inventory)
 files['VERSION.txt']=(NEW+'\n').encode()
 provenance=json.loads(files['resources/build-provenance.json'])
 provenance.update(version=NEW,release_set_id=RELEASE_ID,classification='R174_CANDIDATE_INVENTORY_CORRECTION',source='Immutable R171 code + repaired 589/589 managed inventory + new exact release identity; no product behavior changes',production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False)
 files['resources/build-provenance.json']=jbytes(provenance)
 identity=json.loads(files['resources/release-identity-v1.json'])
 identity['version']=NEW;identity['release_set_id']=RELEASE_ID
 identity['source']['canonical_file_count']=len(files)-2
 identity['source']['managed_file_count']=len(files)
 identity['source']['canonical_tree_sha256']=canonical(files)
 identity['source']['source_ref']='R171 589 files; corrected manifest 589/589; immutable source code preserved except three identity/meta files and inventory'
 identity['qualification']['tier']='R174_INVENTORY_CANDIDATE_REPAIR_NEEDS_EXACT_WINDOWS_CANDIDATE_STAGE_AND_ACTIVATION_LAB'
 files['resources/release-identity-v1.json']=jbytes(identity)
 check(set(files)==set(names),'MANAGED_SET_CHANGED')
 # Double check embedded identity canonical tree before outer packaging.
 check(identity['source']['canonical_tree_sha256']==canonical(files),'CANONICAL_SOURCE_MISMATCH')
 web_new=zbytes(files);tree=web_tree(files)
 m.update(release_set_id=RELEASE_ID,sequence=20261112714,created_at='2026-11-12T14:15:00Z',status='R174_CUMULATIVE_INSTALLABLE_CANARY_PRE_RELEASE')
 m['targets']['local'].update(version=NEW,release_id=RELEASE_ID,managed_files=len(files),managed_tree_sha256=tree)
 m['artifacts']['local-web'].update(size_bytes=len(web_new),sha256=sha(web_new))
 q=m.setdefault('qa_notes',{});q['r171_inventory_mismatch_cause']='583 inventory entries vs 589 ZIP entries; six missing, including migration 0081';q['r174_corrected_inventory_entries']=589;q['r174_native_candidate_preparation_qa']='PENDING';q['real_cafe_or_home_install_verified']=False
 filename='SOKNA-R174-CANARY-LOCAL-231EP-52BASE-PANEL-UPDATE.zip'
 outer_new=zbytes({'release-set.json':jbytes(m),'artifacts/local/web.zip':web_new})
 (out/filename).write_bytes(outer_new)
 report={'original_sha256':sha(src.read_bytes()),'new_sha256':sha(outer_new),'filename':filename,'old_inventory_count':583,'new_inventory_count':len(inventory['files']),'old_files_unlisted':missing,'managed_file_count':len(files),'target_tree_sha256':tree,'source_changed_paths':[p for p in files if files[p]!=get_old(p,web)],'public_unchanged':True,'field_approved':False,'stage_verified':False,'candidate_prepare_verified':False}
 (out/'R174-REPAIR-IDENTITY.json').write_bytes(jbytes(report))
 print(json.dumps(report,ensure_ascii=False,indent=2))
def get_old(p,web):
 with zipfile.ZipFile(io.BytesIO(web)) as inner:return inner.read(p)
if __name__=='__main__':main()
