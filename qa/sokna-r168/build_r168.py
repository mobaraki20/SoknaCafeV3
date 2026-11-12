#!/usr/bin/env python3
"""R168 isolated candidate: R162 exact source + byte-pinned R167/R168 deltas.
Does not contact or alter installed systems. DO NOT bypass any identity/migration checks.
"""
import argparse,base64,hashlib,io,json,pathlib,zipfile,datetime
H=lambda b:hashlib.sha256(b).hexdigest()
R162_LOCAL='16adab9910c017d97ebc256e721bfbc22e26103979adc59763c6bf978b6f5471'
R162_PUBLIC='96e976c43644f62e1246a29752af3fab5fff62978421f93db8065d8460a67503'
BASE_LOCAL='6a9cc49f2d4d09e02547d8450ab0334818b933c481cbca3d8f6cfd87dc52b0b3'
BASE_PUBLIC='2434850306c0c43f801674a4a8d624a9c80e16a9ef3ba3d772f7f3ec5766c5e5'
LV='3.0.0-g6.2-workspace.231en-r168-post-golive-guest-ux-print-qa'
PV='3.0.0-g3.6-workspace.35hs-r168-post-golive-guest-ux-qa'
ID='sokna-r168-post-golive-cumulative-guest-ux-20261112'
TS='2026-11-12T11:30:00Z'
COMPAT=[
 ('35hk','3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa','0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'),
 ('35hl','3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa','0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'),
 ('35hn','3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa','690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97'),
 ('35ho','3.0.0-g3.6-workspace.35ho-r158-scroll-dialog-source-qa','6b404a3e9386e8015a27d69348f3b9900d39b12536c439832bd23d206328c025'),
 ('35hp','3.0.0-g3.6-workspace.35hp-r159-guest-cards-visual-qa','9ee516c58cf87e8b003651308211889377b56857cbcd0268ecfc76d1a6600b4f'),
 ('35hr','3.0.0-g3.6-workspace.35hr-r160-golive-reset-transport-qa',BASE_PUBLIC),
]
J=lambda o:(json.dumps(o,sort_keys=True,separators=(',',':'),ensure_ascii=False)+'\n').encode()
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def canonical(d):return H(''.join(f'{p}\0{len(b)}\0{H(b)}\n' for p,b in sorted(d.items()) if p not in ('resources/release-identity-v1.json','resources/build-provenance.json')).encode())
def readzip(z,prefix=''):
 return {n[len(prefix):]:z.read(n) for n in z.namelist() if not n.endswith('/') and n.startswith(prefix)}
def writezip(paths):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for n,v in sorted(paths.items()):
   t=zipfile.ZipInfo(n,date_time=(2026,11,12,11,30,0));t.compress_type=zipfile.ZIP_DEFLATED;t.external_attr=(0o100644)<<16
   z.writestr(t,v)
 return b.getvalue()
def overlay(base,patch,expected):
 changes=readzip(zipfile.ZipFile(patch))
 assert changes and all(n in base for n in changes),'overlay cannot add unseen files'
 assert all(base[n]!=v for n,v in changes.items()),'overlay includes unchanged file'
 assert {x:H(v) for x,v in changes.items()}==expected,'source delta hash manifest mismatch'
 return {**base,**changes},list(sorted(changes))
def update_identity(d,version,scope):
 d=dict(d)
 d['VERSION.txt']=(version+'\n').encode()
 prov=json.loads(d['resources/build-provenance.json'])
 prov.update({'release_set_id':ID,'created_at':TS,'classification':'R168_CUMULATIVE_UAT_PENDING_FIELD_INSTALL','source':'Byte-exact published R162 + verified R163-R167 + R168 guest UI; no SQL migrations','installed_host_accepted':False,'production_host_install_verified':False,'full_business_acceptance':False})
 d['resources/build-provenance.json']=J(prov)
 identity=json.loads(d['resources/release-identity-v1.json'])
 identity['version']=version;identity['release_set_id']=ID
 identity['source'].update({'managed_file_count':len(d),'canonical_file_count':len(d)-2,'source_ref':'R162 published source plus cumulative R163-R168 scoped fixes; all migrations preserved'})
 if scope=='local':identity['baseline']['source_versions']=list(dict.fromkeys(identity['baseline']['source_versions']+[json.loads(d['resources/release-identity-v1.json'])['version']]))
 else:identity['baseline']['source_versions']=list(dict.fromkeys(identity['baseline']['source_versions']+[json.loads(d['resources/release-identity-v1.json'])['version']]))
 d['resources/release-identity-v1.json']=J(identity)
 identity['source']['canonical_tree_sha256']=canonical(d)
 d['resources/release-identity-v1.json']=J(identity)
 assert canonical(d)==identity['source']['canonical_tree_sha256']
 return d

def main():
 ap=argparse.ArgumentParser()
 for x in ['local_parent','public_parent','local_overlay','public_overlay','manifest','outdir']:ap.add_argument('--'+x,required=True)
 a=ap.parse_args();out=pathlib.Path(a.outdir);out.mkdir(parents=True,exist_ok=True)
 source_manifest=json.loads(pathlib.Path(a.manifest).read_text())
 lp=pathlib.Path(a.local_parent).read_bytes();pp=pathlib.Path(a.public_parent).read_bytes()
 assert H(lp)==R162_LOCAL and H(pp)==R162_PUBLIC,'published immutable parent SHA mismatch'
 z=zipfile.ZipFile(io.BytesIO(lp));rel=json.loads(z.read('release-set.json'))
 local=readzip(zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))))
 assert tree(local)==BASE_LOCAL and len(local)==588
 zpub=zipfile.ZipFile(io.BytesIO(pp));public=readzip(zpub,'payload/')
 assert tree(public)==BASE_PUBLIC and len(public)==225
 local,lc=overlay(local,a.local_overlay,source_manifest['local'])
 public,pc=overlay(public,a.public_overlay,source_manifest['public'])
 local=update_identity(local,LV,'local');public=update_identity(public,PV,'public')
 lm=[n for n in local if n.startswith('database/migrations/')];pm=[n for n in public if n.startswith('database/migrations/')]
 assert len(lm)==80 and len(pm)==8
 ltree=tree(local);ptree=tree(public)
 oldlocal=rel['targets']['local'].copy()
 home=[x for x in rel['supported_baselines'] if x.get('local') and x['local']['version'].startswith('3.0.0-g6.2-workspace.231ed-')]
 assert len(home)==1,'R153 exact known baseline missing'
 homealias={'local':{**home[0]['local'],'release_id':'sokna-r153-local-44base-home-canary-20261111'},'public':None}
 # Additional *exact identity*, never broad/ignore release_id; all other binding fields identical.
 assert homealias['local']['managed_tree_sha256']==home[0]['local']['managed_tree_sha256']
 assert homealias['local']['schema_fingerprint']==home[0]['local']['schema_fingerprint']
 assert homealias['local']['migration_head']==home[0]['local']['migration_head']
 r162baseline={'local':oldlocal,'public':None}
 rel['supported_baselines']+=[homealias,r162baseline]
 assert len({(b.get('local') or {}).get('release_id','') for b in rel['supported_baselines']})==len(rel['supported_baselines'])
 rel['release_set_id']=ID;rel['sequence']=20261112600;rel['created_at']=TS;rel['status']='R168_POST_GOLIVE_ISOLATED_UAT_INSTALLABLE_PENDING_HOST';rel['production_pass']=True
 rel['qualification'].update({'real_public_host':False,'clean_machine_remote_restore':False,'canary':True})
 rel['qa_notes'].update({'scope':'R168 cumulative guest mobile UX, price reconciliation, idempotency, account list, optional print template, home 231ed identity exact-base fix','previous_published_R162_unchanged':True,'actual_cafe_business_restore_proven':False,'home_canary_release_id_exact_included':True,'field_install_pending':True})
 rel['targets']['local'].update({'managed_files':len(local),'managed_tree_sha256':ltree,'version':LV,'release_id':ID})
 web=writezip(local)
 rel['artifacts']['local-web'].update({'sha256':H(web),'size_bytes':len(web)})
 localname='SOKNA-R168-LOCAL-231EN-51BASE-PANEL-UPDATE.zip'
 (out/localname).write_bytes(writezip({'artifacts/local/web.zip':web,'release-set.json':J(rel)}))
 public_outputs=[]
 for tag,version,source_tree in COMPAT:
  m=json.loads(zpub.read('manifest.json'))
  m['version']=PV;m['target_managed_tree_sha256']=ptree
  m['compatibility']={'from_version':version,'from_managed_tree_sha256':source_tree}
  m['files']=[{'path':n,'sha256':H(v),'size':len(v)} for n,v in sorted(public.items())]
  fn=f'SOKNA-R168-PUBLIC-35HS-FROM-{tag.upper()}-PANEL-UPDATE.zip'
  (out/fn).write_bytes(writezip({'manifest.json':J(m),**{'payload/'+k:v for k,v in public.items()}}))
  public_outputs.append({'file':fn,'source_version':version,'source_tree_sha256':source_tree,'sha256':H((out/fn).read_bytes())})
 report={'local_name':localname,'local_sha256':H((out/localname).read_bytes()),'local_target_tree_sha256':ltree,'public_target_tree_sha256':ptree,'local_file_count':len(local),'public_file_count':len(public),'local_delta_paths':lc,'public_delta_paths':pc,'local_supported_baselines':len(rel['supported_baselines']),'local_home_canary_supported':True,'local_previous_R162_supported':True,'public_exact_bridges':public_outputs,'migrations_untouched':True,'field_install_verified':False,'classification':'UAT_ONLY_NOT_FIELD_APPROVED'}
 (out/'R168-PACKAGE-IDENTITY.json').write_bytes(J(report))
 print(json.dumps(report,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
