#!/usr/bin/env python3
"""Pin exact published R160/35HP packages; add tested go-live + catalog pager source.
This package NEVER itself clears business data. Reset requires owner/admin action and
live schema-matched signed Public coordination. The operator waived keeping test data,
not installation integrity gates or preservation of static menu/users/media.
"""
import argparse,base64,gzip,hashlib,io,json,pathlib,subprocess,tempfile,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
OLD_LOCAL='3fe5d05c958dfe5a532819ce2c296d8acb456967bc55f9074f46e5ecddd987e8'
OLD_PUBLIC='9ee516c58cf87e8b003651308211889377b56857cbcd0268ecfc76d1a6600b4f'
PARENT_LOCAL_SHA='7df605c1bc3642176406b90008329a6d3d4986e589bf28e8787a30074d26f73a'
PARENT_PUBLIC_SHA='6ebccb3dc235ea16bcff87da5f754d8589afc0284ca283f683423df547f350d4'
NEW_LOCAL='6a9cc49f2d4d09e02547d8450ab0334818b933c481cbca3d8f6cfd87dc52b0b3'
NEW_PUBLIC='2434850306c0c43f801674a4a8d624a9c80e16a9ef3ba3d772f7f3ec5766c5e5'
TARGET_LOCAL='3.0.0-g6.2-workspace.231em-r162-golive-reset-catalog-pagination-qa'
TARGET_PUBLIC='3.0.0-g3.6-workspace.35hr-r160-golive-reset-transport-qa'
BASES=[
 ('35HL','3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa','0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'),
 ('35HK','3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa','0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'),
 ('35HN','3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa','690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97'),
 ('35HO','3.0.0-g3.6-workspace.35ho-r158-scroll-dialog-source-qa','6b404a3e9386e8015a27d69348f3b9900d39b12536c439832bd23d206328c025'),
 ('35HP','3.0.0-g3.6-workspace.35hp-r159-guest-cards-visual-qa',OLD_PUBLIC)
]
def must(ok,code):
 if not ok:raise RuntimeError(code)
def jb(x):return (json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def zipbytes(d):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for name,blob in sorted(d.items()):
   info=zipfile.ZipInfo(name,(2026,11,11,22,0,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,blob)
 return out.getvalue()
def unpack(raw):
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  must(z.testzip() is None,'ZIP_CRC_FAIL');names=z.namelist();must(len(names)==len(set(names)),'DUPLICATE_PATH')
  must(all(not p.startswith('/') and not p.startswith('\\') and '..' not in p.split('/') and '\\' not in p for p in names),'UNSAFE_ZIP_PATH')
  return {p:z.read(p) for p in names if not p.endswith('/')}
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def apply(source,patchfile,newfilesfile,side):
 prev=dict(source)
 text=gzip.decompress(base64.b64decode(pathlib.Path(patchfile).read_text().strip())).decode()
 paths=[x[6:] for x in text.splitlines() if x.startswith('--- a/')]
 must(paths and len(set(paths))==len(paths),'PATCH_PATHS_INVALID_'+side)
 must(all(p in source for p in paths),'PATCH_UNKNOWN_PATH_'+side)
 with tempfile.TemporaryDirectory() as work:
  root=pathlib.Path(work)
  for p in paths:
   f=root/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(source[p])
  delta=root/'delta.patch';delta.write_text(text)
  command=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',str(root),'-i',str(delta)],capture_output=True,text=True)
  must(command.returncode==0,'PATCH_REJECTED_'+side+' '+command.stdout+' '+command.stderr)
  for p in paths:source[p]=(root/p).read_bytes()
 added=unpack(base64.b64decode(pathlib.Path(newfilesfile).read_text().strip()))
 must(added and all(p not in source for p in added),'NEW_FILE_COLLISION_'+side)
 source.update(added)
 must({p for p in prev if prev[p]!=source[p]}==set(paths),'MODIFIED_PATHS_DRIFT_'+side)
 return source,paths,sorted(added)
def main(args):
 local_binary=pathlib.Path(args.local_r160).read_bytes();public_binary=pathlib.Path(args.public_r159).read_bytes()
 must(H(local_binary)==PARENT_LOCAL_SHA and H(public_binary)==PARENT_PUBLIC_SHA,'RELEASE_PARENT_SHA_DRIFT')
 lo=unpack(local_binary);po=unpack(public_binary)
 must(set(lo)=={'release-set.json','artifacts/local/web.zip'},'LOCAL_PARENT_SHAPE')
 must('manifest.json' in po,'PUBLIC_PARENT_SHAPE')
 release=json.loads(lo['release-set.json']);lold=unpack(lo['artifacts/local/web.zip']);pold={n[8:]:blob for n,blob in po.items() if n.startswith('payload/')}
 must(len(lold)==583 and tree(lold)==OLD_LOCAL,'LOCAL_R160_EXACT_SOURCE_MISMATCH')
 must(len(pold)==222 and tree(pold)==OLD_PUBLIC,'PUBLIC_35HP_EXACT_SOURCE_MISMATCH')
 local,cl,al=apply(dict(lold),args.local_patch,args.local_added,'local')
 public,cp,ap=apply(dict(pold),args.public_patch,args.public_added,'public')
 must(len(local)==588 and tree(local)==NEW_LOCAL,'LOCAL_R162_SOURCE_DRIFT')
 must(len(public)==225 and tree(public)==NEW_PUBLIC,'PUBLIC_35HR_SOURCE_DRIFT')
 must(local['VERSION.txt'].decode().strip()==TARGET_LOCAL,'LOCAL_VERSION_DRIFT')
 must(public['VERSION.txt'].decode().strip()==TARGET_PUBLIC,'PUBLIC_VERSION_DRIFT')
 migrations=lambda d:{p:H(b) for p,b in d.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 must(migrations(lold)==migrations(local) and len(migrations(local))==80,'LOCAL_MIGRATIONS_MUTATED')
 must(migrations(pold)==migrations(public),'PUBLIC_MIGRATIONS_MUTATED')
 must(len(release['supported_baselines'])==48 and release['targets']['local']['managed_tree_sha256']==OLD_LOCAL,'R160_RELEASE_SET_PARENT_DRIFT')
 must(release['pre_update_backup_policy']['required'] is True,'LOCAL_INSTALL_GUARD_MUTATED')
 oldtarget=dict(release['targets']['local']);release['supported_baselines'].append({'local':oldtarget})
 must(len({x['local']['version'] for x in release['supported_baselines']})==49,'BASELINE_DUPLICATE')
 release_id='sokna-r162-golive-clearance-catalog-pager-20261111'
 release.update({'release_set_id':release_id,'sequence':20261111600,'created_at':'2026-11-11T19:50:00Z','status':'R162_CAFE_CUMULATIVE_INSTALLABLE','for_install':True,'production_pass':True,'signature':None})
 release['targets']['local'].update({'release_id':release_id,'version':TARGET_LOCAL,'managed_files':588,'managed_tree_sha256':NEW_LOCAL})
 release['qa_notes']={'parent_exact':'R160_7df605c1','scope':'go-live test-data one-time admin panel; catalog direct page numbers','cleared_data_backup_required':False,'installation_self_backup_policy_retained':True,'migration_files_unchanged':True,'native_windows':'RELEASE_ONLY_AFTER_CI','mariadb':'RELEASE_ONLY_AFTER_CI','cafe_actual_host':False,'actual_cafe_business_restore_proven':False,'public_intake_fenced_during_reset':True,'static_source_preserved_except_scoped_changes':True}
 release['qualification']['failure_matrix_id']='R162_GOLIVE_PREVIEW_SAFE_MARIADB_WINDOWS_NATIVE_CI_20261111'
 web=zipbytes(local);release['artifacts']['local-web'].update({'sha256':H(web),'size_bytes':len(web)})
 out=pathlib.Path(args.outdir);out.mkdir(parents=True,exist_ok=True)
 localpath=out/'SOKNA-R162-LOCAL-231EM-49BASE-PANEL-UPDATE.zip';localpath.write_bytes(zipbytes({'release-set.json':jb(release),'artifacts/local/web.zip':web}))
 rows=[{'path':p,'size':len(blob),'sha256':H(blob)} for p,blob in sorted(public.items())]
 publics=[]
 for label,basever,basetree in BASES:
  meta={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':TARGET_PUBLIC,'compatibility':{'from_version':basever,'from_managed_tree_sha256':basetree},'contracts':{'local_public_contract':1},'target_managed_tree_sha256':NEW_PUBLIC,'files':rows}
  f=out/f'SOKNA-R160-PUBLIC-35HR-FROM-{label}-PANEL-UPDATE.zip'
  f.write_bytes(zipbytes({'manifest.json':jb(meta),**{'payload/'+p:b for p,b in public.items()}}))
  publics.append({'file':f.name,'sha256':H(f.read_bytes()),'from':basever,'base_tree':basetree})
 report={'status':'CI_BUILD_NOT_FIELD_EXECUTED','local':{'file':localpath.name,'sha256':H(localpath.read_bytes()),'version':TARGET_LOCAL,'managed_tree':NEW_LOCAL,'baselines':49},'public':publics,'public_target':{'version':TARGET_PUBLIC,'managed_tree':NEW_PUBLIC},'changed':{'local':cl,'public':cp},'added':{'local':al,'public':ap},'migration_unchanged':True,'business_data_deleted':False}
 (out/'R162-PACKAGE-IDENTITY.json').write_bytes(jb(report))
 print(json.dumps(report,ensure_ascii=False,indent=2))
if __name__=='__main__':
 p=argparse.ArgumentParser()
 for a in ['local-r160','public-r159','local-patch','local-added','public-patch','public-added','outdir']:p.add_argument('--'+a,required=True)
 main(p.parse_args())