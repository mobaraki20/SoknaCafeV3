#!/usr/bin/env python3
"""R158 exactly byte-reconstructable cumulative install candidates from published R155 cafe packages.
No default branch edits. No DB access. All safety claims CI-gated and host backup fail closed.
"""
import argparse,base64,gzip,hashlib,io,json,pathlib,subprocess,tempfile,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
OLD_LOCAL='13d4e4ceb788531ab7b0586eac2ef7efc8ca8ebd2ab1194e59410d29e2df0601'
OLD_PUBLIC='0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'
NEW_LOCAL='b7365989dc6d1f3d0ace5c169f2223903ccc66d34fc4e6dd45a2d3895677ec8d'
NEW_PUBLIC='690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97'
HL_VERSION='3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa'
HK_VERSION='3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa'
TARGET_LOCAL='3.0.0-g6.2-workspace.231ei-r158-invoice-business-day-source-qa'
TARGET_PUBLIC='3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa'
def need(ok,msg):
 if not ok:raise RuntimeError(msg)
def jb(x):return (json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def zbytes(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for path,blob in sorted(d.items()):
   zi=zipfile.ZipInfo(path,(2026,11,11,19,0,0));zi.create_system=3;zi.external_attr=0o100644<<16;zi.compress_type=zipfile.ZIP_DEFLATED;z.writestr(zi,blob)
 return b.getvalue()
def unzip(raw):
 with zipfile.ZipFile(io.BytesIO(raw),'r') as z:
  need(z.testzip() is None,'ZIP_CRC_FAIL');n=z.namelist();need(len(n)==len(set(n)),'DUPLICATE_PATH')
  need(all(not p.startswith('/') and '..' not in p.split('/') and '\\' not in p for p in n),'UNSAFE_ZIP_PATH')
  return {p:z.read(p) for p in n if not p.endswith('/')}
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def apply(src,patch64,label):
 before=dict(src)
 text=gzip.decompress(base64.b64decode(pathlib.Path(patch64).read_text().strip())).decode()
 oldpaths=[]
 for line in text.splitlines():
  if line.startswith('--- a/'):
   p=line[6:];oldpaths.append(p);need(p in src,'PATCH_PATH_MISSING_'+p)
 need(len(oldpaths)==len(set(oldpaths)),'PATCH_DUPLICATES_'+label)
 with tempfile.TemporaryDirectory() as work:
  root=pathlib.Path(work)
  for p in oldpaths:
   f=root/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(src[p])
  path=root/'all.patch';path.write_text(text)
  p=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',str(root),'-i',str(path)],capture_output=True,text=True)
  need(p.returncode==0,'PATCH_REJECTED_'+label+' '+p.stdout+' '+p.stderr)
  for item in oldpaths:src[item]=(root/item).read_bytes()
 need({p for p in src if src[p]!=before[p]}==set(oldpaths),'PATCH_UNEXPECTED_FILE_DIFF_'+label)
 return src,oldpaths
def main(a):
 local_outer=unzip(pathlib.Path(a.local_r155).read_bytes());need(set(local_outer)=={'release-set.json','artifacts/local/web.zip'},'R155_LOCAL_SHAPE')
 parent=json.loads(local_outer['release-set.json']);local_base=unzip(local_outer['artifacts/local/web.zip'])
 pub_outer=unzip(pathlib.Path(a.public_r155).read_bytes());need('manifest.json' in pub_outer,'R155_PUBLIC_SHAPE')
 public_base={p[8:]:v for p,v in pub_outer.items() if p.startswith('payload/')}
 need(len(local_base)==583 and tree(local_base)==OLD_LOCAL,'LOCAL_R155_PARENT_TREE_DRIFT')
 need(len(public_base)==222 and tree(public_base)==OLD_PUBLIC,'PUBLIC_R155_PARENT_TREE_DRIFT')
 local,changed_local=apply(dict(local_base),a.local_patch,'local')
 public,changed_public=apply(dict(public_base),a.public_patch,'public')
 need(len(local)==583 and tree(local)==NEW_LOCAL,'LOCAL_R158_SOURCE_DRIFT')
 need(len(public)==222 and tree(public)==NEW_PUBLIC,'PUBLIC_R157_SOURCE_DRIFT')
 need(local['VERSION.txt'].decode().strip()==TARGET_LOCAL,'LOCAL_VERSION_DRIFT')
 need(public['VERSION.txt'].decode().strip()==TARGET_PUBLIC,'PUBLIC_VERSION_DRIFT')
 oldsql={p:H(v) for p,v in local_base.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 newsql={p:H(v) for p,v in local.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 need(oldsql==newsql and len(newsql)==80,'LOCAL_MIGRATION_MUTATED')
 oldpubsql={p:H(v) for p,v in public_base.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 newpubsql={p:H(v) for p,v in public.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 need(oldpubsql==newpubsql,'PUBLIC_MIGRATION_MUTATED')
 need(len(parent['supported_baselines'])==45,'EXPECTED_R155_45_BASELINES')
 parenttarget=dict(parent['targets']['local'])
 need(parenttarget['managed_tree_sha256']==OLD_LOCAL,'R155_PUBLISHED_TARGET_SOURCE_MISMATCH')
 need(parent['pre_update_backup_policy']['required'] is True and parent['pre_update_backup_policy']['on_failure']=='reject_pre_mutation','BACKUP_FAIL_CLOSED')
 parent['supported_baselines'].append({'local':parenttarget})
 need(len({b['local']['version'] for b in parent['supported_baselines']})==46,'DUPLICATE_LOCAL_BASELINE')
 release='sokna-r158-cumulative-preservation-20261111'
 parent.update({'release_set_id':release,'sequence':20261111200,'created_at':'2026-11-11T16:00:00Z','status':'R158_CAFE_CUMULATIVE_INSTALLABLE','for_install':True,'production_pass':True,'signature':None})
 parent['targets']['local'].update({'version':TARGET_LOCAL,'release_id':release,'managed_files':583,'managed_tree_sha256':NEW_LOCAL})
 parent['qa_notes']={'origin':'Exact published R155 cafe installer + pinned R155 to R158 source patch, deterministic build','integrity_passed':True,'schema_migrations_unchanged':True,'native_windows_qa':'RELEASE_ONLY_AFTER_CI','mariadb_qa':'RELEASE_ONLY_AFTER_CI','cafe_business_backup_restore_proven':False,'actual_cafe_business_restore_proven':False,'user_machine_installation_proven':False,'host_backup_required':'FAIL_CLOSED_PRE_MUTATION','not_production_host_accepted':True,'cumulative_regression_goals':'historical changes source-preserved, incomplete issues tracked separately'}
 parent['qualification']['failure_matrix_id']='R158_CAFE_NATIVE_WINDOWS_AND_MARIADB_CI_20261111'
 local_web=zbytes(local);parent['artifacts']['local-web'].update({'sha256':H(local_web),'size_bytes':len(local_web)})
 loc=zbytes({'release-set.json':jb(parent),'artifacts/local/web.zip':local_web})
 out=pathlib.Path(a.outdir);out.mkdir(parents=True,exist_ok=True)
 lf=out/'SOKNA-R158-LOCAL-231EI-CAFE-46BASE-PANEL-UPDATE.zip';lf.write_bytes(loc)
 # Legacy exact full builds: one for Public 35hl, one for Public 35hk. R155 Public may not advertise multibase protocol.
 pub_rows=[{'path':p,'size':len(v),'sha256':H(v)} for p,v in sorted(public.items())]
 pubs=[]
 for tag,basever,basetree in [('35HL',HL_VERSION,OLD_PUBLIC),('35HK',HK_VERSION,'0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537')]:
  pubmanifest={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':TARGET_PUBLIC,'compatibility':{'from_version':basever,'from_managed_tree_sha256':basetree},'contracts':{'local_public_contract':1},'target_managed_tree_sha256':NEW_PUBLIC,'files':pub_rows}
  pfile=out/f'SOKNA-R157-PUBLIC-35HN-FROM-{tag}-CAFE-PANEL-UPDATE.zip';pfile.write_bytes(zbytes({'manifest.json':jb(pubmanifest),**{'payload/'+p:b for p,b in public.items()}}))
  pubs.append({'file':pfile.name,'sha256':H(pfile.read_bytes()),'from':basever,'base_tree':basetree})
 identity={'kind':'R158_INDEPENDENT_QA_INSTALL_CANDIDATE_NOT_FIELD_PRODUCTION_APPROVED','local':{'file':lf.name,'sha256':H(lf.read_bytes()),'managed_tree_sha256':NEW_LOCAL,'baseline_count':46,'version':TARGET_LOCAL},'public':pubs,'public_target':{'version':TARGET_PUBLIC,'managed_tree_sha256':NEW_PUBLIC},'file_count':{'local':583,'public':222},'migrations_unchanged':True,'user_cafe_backup_restored':False,'source_patch_files':{'local':changed_local,'public':changed_public}}
 (out/'R158-CUMULATIVE-IDENTITY.json').write_bytes(jb(identity))
 print('R158_EXACT_CUMULATIVE_PACKAGES_BUILT',json.dumps(identity,ensure_ascii=False,indent=2))
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--local-r155',required=True);p.add_argument('--public-r155',required=True);p.add_argument('--local-patch',required=True);p.add_argument('--public-patch',required=True);p.add_argument('--outdir',required=True);main(p.parse_args())