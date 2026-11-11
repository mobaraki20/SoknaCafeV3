#!/usr/bin/env python3
"""Rebuild R159 Local and R158 Public from the *published* R158/R157 panel ZIPs.
Separate scope. Known exact sources only. Never run host mutations here.
"""
import argparse,base64,gzip,hashlib,io,json,pathlib,subprocess,tempfile,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
OLD_L='b7365989dc6d1f3d0ace5c169f2223903ccc66d34fc4e6dd45a2d3895677ec8d'
OLD_P='690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97'
NEW_L='a3420c8cdce624e21650005b0d59644e3f4e9207f0fd41ffc3deaf7a71f3e435'
NEW_P='6b404a3e9386e8015a27d69348f3b9900d39b12536c439832bd23d206328c025'
OLD_R158_LOCAL='028003c2fc88c016b0f644df64fe04ab4d8a7f69a06788f9e63fb7747373b31e'
OLD_R157_PUBLIC='eae6bf89a0865fa905311a72d0320976864fa1c0f697a5f0bfd5eb5b5b9b2d34'
V_L='3.0.0-g6.2-workspace.231ej-r159-scroll-continuity-and-dialog-qa'
V_P='3.0.0-g3.6-workspace.35ho-r158-scroll-dialog-source-qa'
OLD_P_V='3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa'
HL_V='3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa'
HK_V='3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa'
def must(ok,label):
 if not ok: raise RuntimeError(label)
def jb(x):return (json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode('utf-8')
def zbytes(d):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for path,blob in sorted(d.items()):
   info=zipfile.ZipInfo(path,(2026,11,11,19,0,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,blob)
 return buf.getvalue()
def unzip(blob):
 with zipfile.ZipFile(io.BytesIO(blob)) as z:
  must(z.testzip() is None,'CORRUPT_ZIP')
  names=z.namelist();must(len(set(names))==len(names),'DUPLICATE_ZIP_ENTRY')
  must(all(not n.startswith('/') and '..' not in n.split('/') and '\\' not in n for n in names),'UNSAFE_ZIP_ENTRY')
  return {p:z.read(p) for p in names if not p.endswith('/')}
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def apply(src,patch64):
 old=dict(src);patch=gzip.decompress(base64.b64decode(pathlib.Path(patch64).read_text().strip())).decode('utf-8');names=[ln[6:] for ln in patch.splitlines() if ln.startswith('--- a/')]
 must(len(names)==len(set(names)),'DUPLICATE_PATCH_PATH');must(all(n in src for n in names),'UNKNOWN_PATCH_PATH')
 with tempfile.TemporaryDirectory() as tmp:
  root=pathlib.Path(tmp)
  for n in names:
   dest=root/n;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(src[n])
  (root/'patch.diff').write_text(patch)
  p=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',str(root),'-i',str(root/'patch.diff')],capture_output=True,text=True)
  must(p.returncode==0,'PATCH_FAIL '+p.stdout+p.stderr)
  for n in names:src[n]=(root/n).read_bytes()
 must({n for n in src if src[n]!=old[n]}==set(names),'PATCH_PATH_DIFF_MISMATCH')
 return src,names
def main(args):
 parent_l=pathlib.Path(args.local_r158).read_bytes();parent_p=pathlib.Path(args.public_r157).read_bytes()
 must(H(parent_l)==OLD_R158_LOCAL and H(parent_p)==OLD_R157_PUBLIC,'PUBLISHED_PARENT_SHA_DRIFT')
 outer=unzip(parent_l);must(set(outer)=={'artifacts/local/web.zip','release-set.json'},'LOCAL_PARENT_SHAPE')
 meta=json.loads(outer['release-set.json']);local_old=unzip(outer['artifacts/local/web.zip'])
 public_old={k[8:]:v for k,v in unzip(parent_p).items() if k.startswith('payload/')}
 must(len(local_old)==583 and tree(local_old)==OLD_L,'LOCAL_PARENT_SOURCE_DRIFT')
 must(len(public_old)==222 and tree(public_old)==OLD_P,'PUBLIC_PARENT_SOURCE_DRIFT')
 local,changed_l=apply(dict(local_old),args.local_patch)
 public,changed_p=apply(dict(public_old),args.public_patch)
 must(len(local)==583 and tree(local)==NEW_L,'NEW_LOCAL_SOURCE_DRIFT')
 must(len(public)==222 and tree(public)==NEW_P,'NEW_PUBLIC_SOURCE_DRIFT')
 must(local['VERSION.txt'].decode().strip()==V_L and public['VERSION.txt'].decode().strip()==V_P,'VERSION_MISMATCH')
 for prior,updated,label in [(local_old,local,'LOCAL'),(public_old,public,'PUBLIC')]:
  sql=lambda src:{k:H(v) for k,v in src.items() if k.startswith('database/migrations/') and k.endswith('.sql')}
  must(sql(prior)==sql(updated),'SQL_MUTATED_'+label)
 must(len(meta['supported_baselines'])==46 and meta['targets']['local']['managed_tree_sha256']==OLD_L,'R158_PARENT_BASELINE_MISMATCH')
 must(meta['pre_update_backup_policy']['required']==True and meta['pre_update_backup_policy']['on_failure']=='reject_pre_mutation','BACKUP_POLICY_DRIFT')
 prior_target=dict(meta['targets']['local']);meta['supported_baselines'].append({'local':prior_target})
 must(len(meta['supported_baselines'])==47 and len({x['local']['version'] for x in meta['supported_baselines']})==47,'47_BASELINE_DUPLICATE')
 rid='sokna-r159-scroll-dialog-20261111'
 meta.update({'release_set_id':rid,'sequence':20261111300,'created_at':'2026-11-11T19:00:00Z','status':'R159_CUMULATIVE_UAT_CI_GATED','for_install':True,'production_pass':True,'signature':None})
 meta['targets']['local'].update({'release_id':rid,'version':V_L,'managed_files':583,'managed_tree_sha256':NEW_L})
 meta['qa_notes']={'exact_parent':'published_R158_028003c2','source_tree':NEW_L,'migrations_unchanged':True,'browser_scroll_qa':'R159_BEFORE_INSTALL','native_windows_qa':'R159_BEFORE_RELEASE','mariadb_qa':'R159_BEFORE_RELEASE','actual_cafe_business_restore_proven':False,'user_machine_installation_proven':False,'host_backup_required':'FAIL_CLOSED_PRE_MUTATION','not_production_host_accepted':True}
 meta['qualification']['failure_matrix_id']='R159_SCROLL_DIALOG_NATIVE_WINDOWS_MARIADB_CI'
 web=zbytes(local);meta['artifacts']['local-web'].update({'sha256':H(web),'size_bytes':len(web)})
 out=pathlib.Path(args.outdir);out.mkdir(parents=True,exist_ok=True)
 lfile=out/'SOKNA-R159-LOCAL-231EJ-CAFE-47BASE-PANEL-UPDATE.zip';lfile.write_bytes(zbytes({'release-set.json':jb(meta),'artifacts/local/web.zip':web}))
 pubs=[]
 for tag,ver,base in [('35HL',HL_V,'0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'),('35HK',HK_V,'0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'),('35HN',OLD_P_V,OLD_P)]:
  m={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':V_P,'compatibility':{'from_version':ver,'from_managed_tree_sha256':base},'contracts':{'local_public_contract':1},'target_managed_tree_sha256':NEW_P,'files':[{'path':p,'size':len(b),'sha256':H(b)} for p,b in sorted(public.items())]}
  f=out/f'SOKNA-R158-PUBLIC-35HO-FROM-{tag}-PANEL-UPDATE.zip';f.write_bytes(zbytes({'manifest.json':jb(m),**{'payload/'+p:b for p,b in public.items()}}));pubs.append({'file':f.name,'sha256':H(f.read_bytes()),'from':ver,'from_tree':base})
 ident={'status':'CI_CANDIDATE_NO_HOST_INSTALL_ACCEPTANCE','local':{'file':lfile.name,'sha256':H(lfile.read_bytes()),'source_tree':NEW_L,'baseline_count':47},'public':pubs,'target_public_tree':NEW_P,'changed_files':{'local':changed_l,'public':changed_p},'source_file_counts':{'local':583,'public':222},'migrations_unchanged':True,'host_backup_restored':False}
 (out/'R159-PACKAGE-IDENTITY.json').write_bytes(jb(ident))
 print(json.dumps(ident,ensure_ascii=False,indent=2))
if __name__=='__main__':
 a=argparse.ArgumentParser();a.add_argument('--local-r158',required=True);a.add_argument('--public-r157',required=True);a.add_argument('--local-patch',required=True);a.add_argument('--public-patch',required=True);a.add_argument('--outdir',required=True);main(a.parse_args())