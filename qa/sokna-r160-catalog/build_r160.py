#!/usr/bin/env python3
"""Deterministic R160 Local / R159 Public from the exact published R159/35HO parents.
All source trees pinned. Does not access devices or database. Native installer preflight
is separate in the CI Windows stage; operator must still take verified host backup.
"""
import argparse,base64,gzip,hashlib,io,json,pathlib,subprocess,tempfile,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
OLD_L='a3420c8cdce624e21650005b0d59644e3f4e9207f0fd41ffc3deaf7a71f3e435'
OLD_P='6b404a3e9386e8015a27d69348f3b9900d39b12536c439832bd23d206328c025'
NEW_L='3fe5d05c958dfe5a532819ce2c296d8acb456967bc55f9074f46e5ecddd987e8'
NEW_P='9ee516c58cf87e8b003651308211889377b56857cbcd0268ecfc76d1a6600b4f'
PARENT_L_SHA='2b5335bbc0bd654d9dbab34bb385d0bed34aef61e64dacd875eab208a785aa1f'
PARENT_P_SHA='53864de2306dc25104b29fe18cb416d67ac680e298076b3ebed125131d10ecac'
V_L='3.0.0-g6.2-workspace.231ek-r160-catalog-viewport-preservation-qa'
V_P='3.0.0-g3.6-workspace.35hp-r159-guest-cards-visual-qa'
HO_V='3.0.0-g3.6-workspace.35ho-r158-scroll-dialog-source-qa'
HN_V='3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa'
HL_V='3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa'
HK_V='3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa'

def must(ok,label):
 if not ok:raise RuntimeError(label)
def jb(o):return (json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def zbytes(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for p,blob in sorted(d.items()):
   x=zipfile.ZipInfo(p,(2026,11,11,21,0,0));x.create_system=3;x.external_attr=0o100644<<16;x.compress_type=zipfile.ZIP_DEFLATED;z.writestr(x,blob)
 return b.getvalue()
def unzip(raw):
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  must(z.testzip() is None,'CORRUPT_ZIP');a=z.namelist();must(len(a)==len(set(a)),'DUPLICATE_PATH')
  must(all(not n.startswith('/') and '..' not in n.split('/') and '\\' not in n for n in a),'UNSAFE_PATH')
  return {p:z.read(p) for p in a if not p.endswith('/')}
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def apply(src,patch_file):
 initial=dict(src)
 text=gzip.decompress(base64.b64decode(pathlib.Path(patch_file).read_text().strip())).decode()
 changes=[s[6:] for s in text.splitlines() if s.startswith('--- a/')]
 must(changes and len(set(changes))==len(changes),'PATCH_PATHS_INVALID')
 must(all(p in src for p in changes),'UNKNOWN_PATCH_PATH')
 with tempfile.TemporaryDirectory() as temp:
  root=pathlib.Path(temp)
  for p in changes:
   f=root/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(src[p])
  patch=root/'overlay.patch';patch.write_text(text)
  p=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',str(root),'-i',str(patch)],capture_output=True,text=True)
  must(p.returncode==0,'PATCH_FAILED '+p.stdout+' '+p.stderr)
  for n in changes:src[n]=(root/n).read_bytes()
 must({n for n in src if src[n]!=initial[n]}==set(changes),'UNEXPECTED_PATCH_OUTPUT')
 return src,changes

def main(args):
 raw_l=pathlib.Path(args.local_r159).read_bytes();raw_p=pathlib.Path(args.public_r158).read_bytes()
 must(H(raw_l)==PARENT_L_SHA and H(raw_p)==PARENT_P_SHA,'PUBLISHED_PARENT_HASH_MISMATCH')
 parent_l=unzip(raw_l);parent_p=unzip(raw_p)
 must(set(parent_l)=={'release-set.json','artifacts/local/web.zip'},'LOCAL_PARENT_SHAPE')
 must('manifest.json' in parent_p,'PUBLIC_PARENT_SHAPE')
 manifest=json.loads(parent_l['release-set.json']);prior_l=unzip(parent_l['artifacts/local/web.zip']);prior_p={k[8:]:v for k,v in parent_p.items() if k.startswith('payload/')}
 must(len(prior_l)==583 and tree(prior_l)==OLD_L,'LOCAL_PINNED_PARENT_SOURCE_MISMATCH')
 must(len(prior_p)==222 and tree(prior_p)==OLD_P,'PUBLIC_PINNED_PARENT_SOURCE_MISMATCH')
 new_l,changed_l=apply(dict(prior_l),args.local_patch);new_p,changed_p=apply(dict(prior_p),args.public_patch)
 must(len(new_l)==583 and tree(new_l)==NEW_L,'LOCAL_TARGET_DRIFT')
 must(len(new_p)==222 and tree(new_p)==NEW_P,'PUBLIC_TARGET_DRIFT')
 must(new_l['VERSION.txt'].decode().strip()==V_L and new_p['VERSION.txt'].decode().strip()==V_P,'VERSION_MISMATCH')
 sql=lambda d:{p:H(b) for p,b in d.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 must(sql(new_l)==sql(prior_l) and len(sql(new_l))==80,'LOCAL_SQL_CHANGED')
 must(sql(new_p)==sql(prior_p),'PUBLIC_SQL_CHANGED')
 must(len(manifest['supported_baselines'])==47 and manifest['targets']['local']['managed_tree_sha256']==OLD_L,'PARENT_RELEASE_SET_MISMATCH')
 must(manifest['pre_update_backup_policy']['required'] is True and manifest['pre_update_backup_policy']['on_failure']=='reject_pre_mutation','BACKUP_FAIL_CLOSED_DRIFT')
 prev_target=dict(manifest['targets']['local']);manifest['supported_baselines'].append({'local':prev_target})
 must(len(manifest['supported_baselines'])==48 and len({b['local']['version'] for b in manifest['supported_baselines']})==48,'EXACT_BASELINES_DUPLICATE')
 release='sokna-r160-catalog-viewport-guest-cards-20261111'
 manifest.update({'release_set_id':release,'sequence':20261111400,'created_at':'2026-11-11T21:00:00Z','status':'R160_CAFE_CUMULATIVE_INSTALLABLE','for_install':True,'production_pass':True,'signature':None})
 manifest['targets']['local'].update({'release_id':release,'version':V_L,'managed_files':583,'managed_tree_sha256':NEW_L})
 manifest['qa_notes']={'exact_parent':'published_R159_2b5335bb','source_tree':NEW_L,'migrations_unchanged':True,'catalog_scroll': 'R159_2_OF_10_TO_R160_10_OF_10_CHROMIUM_REAL_DOM', 'guest_featured_media':'R160_CSS_BOTH_SOURCES', 'native_windows_qa':'RELEASE_ONLY_AFTER_CI','actual_cafe_business_restore_proven':False,'user_machine_installation_proven':False,'host_backup_required':'FAIL_CLOSED_PRE_MUTATION','not_production_host_accepted':True}
 manifest['qualification']['failure_matrix_id']='R160_SCROLL_PRESERVATION_NATIVE_WINDOWS_GATED'
 web=zbytes(new_l);manifest['artifacts']['local-web'].update({'sha256':H(web),'size_bytes':len(web)})
 out=pathlib.Path(args.outdir);out.mkdir(parents=True,exist_ok=True)
 local_file=out/'SOKNA-R160-LOCAL-231EK-CAFE-48BASE-PANEL-UPDATE.zip';local_file.write_bytes(zbytes({'release-set.json':jb(manifest),'artifacts/local/web.zip':web}))
 bases=[('35HL',HL_V,'0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'),('35HK',HK_V,'0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'),('35HN',HN_V,'690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97'),('35HO',HO_V,OLD_P)]
 outputs=[]
 for tag,base_version,base_tree in bases:
  metadata={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':V_P,'compatibility':{'from_version':base_version,'from_managed_tree_sha256':base_tree},'contracts':{'local_public_contract':1},'target_managed_tree_sha256':NEW_P,'files':[{'path':p,'size':len(b),'sha256':H(b)} for p,b in sorted(new_p.items())]}
  file=out/f'SOKNA-R159-PUBLIC-35HP-FROM-{tag}-PANEL-UPDATE.zip';file.write_bytes(zbytes({'manifest.json':jb(metadata),**{'payload/'+p:b for p,b in new_p.items()}}));outputs.append({'file':file.name,'sha256':H(file.read_bytes()),'from':base_version,'base_tree':base_tree})
 identity={'status':'CI_CANDIDATE_NO_HOST_INSTALL_ACCEPTANCE','local':{'file':local_file.name,'sha256':H(local_file.read_bytes()),'source_tree':NEW_L,'baseline_count':48},'public':outputs,'public_target':{'version':V_P,'source_tree':NEW_P},'source_file_counts':{'local':583,'public':222},'changed_files':{'local':changed_l,'public':changed_p},'migrations_unchanged':True,'host_backup_restored':False}
 (out/'R160-PACKAGE-IDENTITY.json').write_bytes(jb(identity))
 print(json.dumps(identity,ensure_ascii=False,indent=2))
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--local-r159',required=True);p.add_argument('--public-r158',required=True);p.add_argument('--local-patch',required=True);p.add_argument('--public-patch',required=True);p.add_argument('--outdir',required=True);main(p.parse_args())