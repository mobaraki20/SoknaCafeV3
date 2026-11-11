#!/usr/bin/env python3
"""Exact cumulative R163 post-go-live UI-only source builder.

It will neither clear data nor relax any R162 install/reset safety gate.
Target installation is after the separately authorized R162 go-live reset.
"""
import argparse,base64,gzip,hashlib,io,json,pathlib,subprocess,tempfile,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
LOCAL_PARENT_SHA='16adab9910c017d97ebc256e721bfbc22e26103979adc59763c6bf978b6f5471'
PUBLIC_PARENT_SHA='96e976c43644f62e1246a29752af3fab5fff62978421f93db8065d8460a67503'
OLD_LOCAL='6a9cc49f2d4d09e02547d8450ab0334818b933c481cbca3d8f6cfd87dc52b0b3'
OLD_PUBLIC='2434850306c0c43f801674a4a8d624a9c80e16a9ef3ba3d772f7f3ec5766c5e5'
NEW_LOCAL='af208a53cbc34b293d1adf11697c9c78772e04116afdd0266a35b194a20296ae'
NEW_PUBLIC='eff26613898d8d9ff04d463a2bac8545e8a8f93bd077cd5a2ac914e0ab35ae13'
TARGET_LOCAL='3.0.0-g6.2-workspace.231en-r163-cumulative-ui-post-golive-qa'
TARGET_PUBLIC='3.0.0-g3.6-workspace.35hs-r161-cumulative-ui-post-golive-qa'
TARGET_ID='sokna-r163-cumulative-ui-post-golive-20261112'
ALLOW_LOCAL={'VERSION.txt','public/assets/catalog-workspace.js','public/assets/reports-workspace.js','public/assets/scds/guest.css','resources/build-provenance.json','resources/release-identity-v1.json'}
ALLOW_PUBLIC={'VERSION.txt','assets/reports-workspace.js','assets/scds/guest.css','resources/build-provenance.json','resources/release-identity-v1.json'}
def need(ok,code):
 if not ok:raise RuntimeError(code)
def jb(obj):return (json.dumps(obj,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def zipbytes(d):
 o=io.BytesIO()
 with zipfile.ZipFile(o,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for name,blob in sorted(d.items()):
   info=zipfile.ZipInfo(name,(2026,11,12,0,0,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,blob)
 return o.getvalue()
def unpack(raw):
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  need(z.testzip() is None,'CRC_FAIL');names=z.namelist();need(len(set(names))==len(names),'DUPLICATE_ZIP_PATHS')
  need(all(not p.startswith('/') and not p.startswith('\\') and '..' not in p.split('/') and '\\' not in p for p in names),'UNSAFE_ZIP_PATH')
  return {p:z.read(p) for p in names if not p.endswith('/')}
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def apply(source,patchfile,allowed,label):
 d=dict(source);patchbytes=pathlib.Path(patchfile).read_bytes();diff=gzip.decompress(base64.b64decode(patchbytes)).decode();names=[x[6:] for x in diff.splitlines() if x.startswith('--- a/')];need(set(names)==allowed and len(names)==len(allowed),'PATCH_PATHS_DRIFT_'+label)
 with tempfile.TemporaryDirectory() as temp:
  root=pathlib.Path(temp)
  for name in names:
   need(name in d,'UNKNOWN_PATCH_TARGET_'+name);f=root/name;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(d[name])
  p=root/'change.patch';p.write_text(diff);cmd=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',str(root),'-i',str(p)],capture_output=True,text=True);need(cmd.returncode==0,'PATCH_REJECTED_'+label+' '+cmd.stdout+cmd.stderr)
  for name in names:d[name]=(root/name).read_bytes()
 return d

def main(args):
 locraw=pathlib.Path(args.local_r162).read_bytes();pubraw=pathlib.Path(args.public_r160).read_bytes()
 need(H(locraw)==LOCAL_PARENT_SHA and H(pubraw)==PUBLIC_PARENT_SHA,'NOT_PUBLISHED_R162_PARENT_BYTES')
 lpack=unpack(locraw);ppack=unpack(pubraw)
 need(set(lpack)=={'release-set.json','artifacts/local/web.zip'} and 'manifest.json' in ppack,'PARENT_SHAPE_UNKNOWN')
 m=json.loads(lpack['release-set.json']);lo=unpack(lpack['artifacts/local/web.zip']);po={n[8:]:b for n,b in ppack.items() if n.startswith('payload/')}
 need(len(lo)==588 and tree(lo)==OLD_LOCAL and len(po)==225 and tree(po)==OLD_PUBLIC,'BASE_SOURCE_SHA_DRIFT')
 old_local={p:H(b) for p,b in lo.items()};old_public={p:H(b) for p,b in po.items()}
 local=apply(lo,args.local_patch,ALLOW_LOCAL,'LOCAL');public=apply(po,args.public_patch,ALLOW_PUBLIC,'PUBLIC')
 need(tree(local)==NEW_LOCAL and tree(public)==NEW_PUBLIC,'TARGET_SOURCE_SHA_DRIFT')
 need(local['VERSION.txt'].decode().strip()==TARGET_LOCAL and public['VERSION.txt'].decode().strip()==TARGET_PUBLIC,'TARGET_VERSION_DRIFT')
 lm=lambda d:{p:H(b) for p,b in d.items() if p.startswith('database/migrations/')};need(lm(lo)==lm(local) and lm(po)==lm(public),'MIGRATION_CHANGED')
 # Go-live reset engine and its policy must remain identical byte-for-byte.
 for p in ['src/Domain/System/GoLiveResetCoordinator.php','src/Domain/System/GoLiveResetEngine.php','resources/golive-clearance-policy.json','public/system/go-live-api.php']:
  need(lo[p]==local[p],'GOLIVE_LOCAL_CHANGED_'+p)
 for p in ['src/Maintenance/GoLiveResetEngine.php','src/Http/GoLiveResetHttpAdapter.php','resources/golive-clearance-policy.json']:
  need(po[p]==public[p],'GOLIVE_PUBLIC_CHANGED_'+p)
 for p in lo:
  if p not in ALLOW_LOCAL:need(old_local[p]==H(local[p]),'UNSCOPED_LOCAL_CHANGE_'+p)
 for p in po:
  if p not in ALLOW_PUBLIC:need(old_public[p]==H(public[p]),'UNSCOPED_PUBLIC_CHANGE_'+p)
 need(m['targets']['local']['managed_tree_sha256']==OLD_LOCAL and len(m['supported_baselines'])==49,'PARENT_RELEASE_SET_DRIFT')
 # Retain the exact gate. Waiving test-data backup never waives installer integrity.
 need(m['pre_update_backup_policy']['required'] is True,'BACKUP_POLICY_UNEXPECTED');need(m['targets']['local']['version']==lo['VERSION.txt'].decode().strip(),'PARENT_VERSION_DRIFT')
 prior=dict(m['targets']['local']);m['supported_baselines'].append({'local':prior})
 need(len({x['local']['version'] for x in m['supported_baselines']})==50,'DUPLICATE_BASELINE')
 m.update({'release_set_id':TARGET_ID,'sequence':20261112600,'created_at':'2026-11-12T00:00:00Z','status':'R163_POST_GOLIVE_CUMULATIVE_INSTALLABLE','for_install':True,'production_pass':True,'signature':None})
 m['targets']['local'].update({'release_id':TARGET_ID,'version':TARGET_LOCAL,'managed_files':588,'managed_tree_sha256':NEW_LOCAL})
 m['qa_notes']={'parent_exact':'R162_16adab99','scope':'UI-only: catalog Enter, reports refocus, compact waiter modal','install_after_golive_reset':True,'go_live_engine_byte_preserved':True,'migrations_unchanged':True,'real_cafe_host_accepted':False,'native_windows':'RELEASE_ONLY_AFTER_CI','mariadb':'CI_GATE','actual_cafe_business_restore_proven':False}
 m['qualification']['failure_matrix_id']='R163_AFTER_GOLIVE_SCOPED_UX_SOURCE_NATIVE_WINDOWS_CI_20261112'
 newweb=zipbytes(local);m['artifacts']['local-web'].update({'sha256':H(newweb),'size_bytes':len(newweb)})
 out=pathlib.Path(args.outdir);out.mkdir(parents=True,exist_ok=True)
 lname='SOKNA-R163-LOCAL-231EN-50BASE-POST-GOLIVE-PANEL-UPDATE.zip';pname='SOKNA-R161-PUBLIC-35HS-FROM-35HR-POST-GOLIVE-PANEL-UPDATE.zip'
 (out/lname).write_bytes(zipbytes({'release-set.json':jb(m),'artifacts/local/web.zip':newweb}))
 rows=[{'path':p,'sha256':H(b),'size':len(b)} for p,b in sorted(public.items())]
 priorv=po['VERSION.txt'].decode().strip()
 meta={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':TARGET_PUBLIC,'compatibility':{'from_version':priorv,'from_managed_tree_sha256':OLD_PUBLIC},'contracts':{'local_public_contract':1},'target_managed_tree_sha256':NEW_PUBLIC,'files':rows}
 (out/pname).write_bytes(zipbytes({'manifest.json':jb(meta),**{'payload/'+p:b for p,b in public.items()}}))
 report={'classification':'R163_CUMULATIVE_UI_UAT_NO_HOST','local':{'file':lname,'sha256':H((out/lname).read_bytes()),'version':TARGET_LOCAL,'tree_sha256':NEW_LOCAL,'verified_baselines':50},'public':{'file':pname,'sha256':H((out/pname).read_bytes()),'version':TARGET_PUBLIC,'tree_sha256':NEW_PUBLIC,'from':priorv},'preserved':{'migration_files':True,'go_live_reset_code_and_policy':True,'all_unmodified_source_files':True,'user_data_untouched':True},'changed_paths':{'local':sorted(ALLOW_LOCAL),'public':sorted(ALLOW_PUBLIC)},'field_acceptance':False}
 (out/'R163-PACKAGE-IDENTITY.json').write_bytes(jb(report));print(json.dumps(report,ensure_ascii=False,indent=2))
if __name__=='__main__':
 p=argparse.ArgumentParser()
 for name in ['local-r162','public-r160','local-patch','public-patch','outdir']:p.add_argument('--'+name,required=True)
 main(p.parse_args())