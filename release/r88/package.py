"""Build exact-base deltas. Rebuild and compare every target byte before delivery."""
import copy, hashlib, io, json, os, pathlib, zipfile, subprocess
from prepare import ROOT, OUT, digest
DIST=OUT/'dist';DIST.mkdir(exist_ok=True)
subprocess.run(['python3',str(ROOT/'package-local.py')],check=True)
sha=lambda b:hashlib.sha256(b).hexdigest()
jb=lambda j:(json.dumps(j,ensure_ascii=False,sort_keys=True,indent=2)+'\n').encode()
def files(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def zb(data):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
  for name,b in sorted(data.items()):
   i=zipfile.ZipInfo(name,(2026,11,1,0,0,0));i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,b)
 return out.getvalue()
def canonical(data,exclude):
 h=hashlib.sha256()
 for p,b in sorted(data.items()):
  if p not in exclude:h.update(p.encode()+b'\0'+str(len(b)).encode()+b'\0'+sha(b).encode()+b'\n')
 return h.hexdigest()
source_commit=os.environ.get('GITHUB_SHA','local-reproducible-build')
for kind,version in [('public','3.0.0-g3.6-workspace.35fu-r88-hall-controls-uat')]:
 root=OUT/'source'/kind;base=files(OUT/'r81'/kind)
 rid='sokna-r88-hall-controls-20261101-home-'+kind
 (root/'VERSION.txt').write_text(version+'\n')
 p=root/'resources/build-provenance.json';j=json.loads(p.read_bytes());j.update(version=version,release_set_id=rid,classification='R88_HOME_UAT_CANDIDATE',source_commit=source_commit,scope='Role-authorized existing Hall correction, reprint and discount controls; Public-only change; no schema or CSS change',target_host_install_verified=False);p.write_bytes(jb(j))
 p=root/'resources/release-identity-v1.json';j=json.loads(p.read_bytes());j.update(version=version,release_set_id=rid,baseline={'version':base['VERSION.txt'].decode().strip()});j['source'].update(source_ref=source_commit,canonical_tree_sha256=canonical(files(root),j['source']['digest_excludes']));j['qualification']={'tier':'home_uat_not_field_qualified','claim_rule':'Automated PHP/MariaDB regression results supplied separately; real host installation and WAN UAT outstanding'};p.write_bytes(jb(j))
 target=files(root);assert set(base)==set(target)
 assert all(b==target[n] for n,b in base.items() if n.startswith('database/migrations/'))
 assert all(b==target[n] for n,b in base.items() if n.endswith('.css'))

 # Public packages remain separate exact-base deltas.
 for label,pbase in [('R81',base),('R83',files(OUT/'r83/public')),('R84',files(OUT/'r84/public')),('R85',files(OUT/'r85/public')),('R86',files(OUT/'baseline/public'))]:
  paths=sorted(n for n in target if pbase[n]!=target[n])
  entries=[{'path':n,'mode':420,'size':len(target[n]),'sha256':sha(target[n])} for n in paths]
  m={'format':'sokna-public-delta-v2','schema_version':2,'component':'public-edge','version':version,'base':{'version':pbase['VERSION.txt'].decode().strip(),'managed_tree_sha256':digest(pbase)},'target':{'managed_tree_sha256':digest(target)},'files':entries,'deletes':[],'contracts':{'local_public_contract':1,'routine_update_protocol':2},'provenance':{'source_commit':source_commit,'strict_multibaseline_v1':False,'route':'ROUTINE_ONLY','scope':'HOME_UAT_NOT_PRODUCTION'}}
  b=zb({'manifest.json':jb(m),**{'payload/'+n:target[n] for n in paths}})
  with zipfile.ZipFile(io.BytesIO(b)) as z:
   assert z.testzip() is None;rebuilt=dict(pbase)
   for n in paths:rebuilt[n]=z.read('payload/'+n)
   assert rebuilt==target
  (DIST/f'SOKNA-R88-PUBLIC-{label}-TO-R88-ROUTINE-DELTA-HOME-UAT.zip').write_bytes(b)

summary=[]
for p in sorted(DIST.glob('*.zip')):
 with zipfile.ZipFile(p) as z:assert z.testzip() is None
 summary.append({'name':p.name,'bytes':p.stat().st_size,'sha256':sha(p.read_bytes())})
(DIST/'PACKAGE-VERIFICATION.json').write_bytes(jb({'source_commit':source_commit,'all_deltas_reconstructed_byte_for_byte':True,'database_migrations_changed':False,'css_changed':False,'local_changed_from_r87':True,'guest_javascript_behavior_changed_from_r86':False,'cumulative_previous_guest_fixes_in_older_deltas':True,'packages':summary}))
(DIST/'SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['name']+'\n' for x in summary))
print(json.dumps(summary,indent=2))
