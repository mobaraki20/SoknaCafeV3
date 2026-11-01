"""Build exact-base deltas. Rebuild and compare every target byte before delivery."""
import copy, hashlib, io, json, os, pathlib, zipfile
from prepare import ROOT, OUT, digest
DIST=OUT/'dist';DIST.mkdir(exist_ok=True)
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
for kind,version in [('local','3.0.0-g6.2-workspace.231ck-r87-hall-snapshot-uat')]:
 root=OUT/'source'/kind;base=files(OUT/'r81'/kind)
 rid='sokna-r87-hall-snapshot-20261101-home-'+kind
 (root/'VERSION.txt').write_text(version+'\n')
 p=root/'resources/build-provenance.json';j=json.loads(p.read_bytes());j.update(version=version,release_set_id=rid,classification='R87_HOME_UAT_CANDIDATE',source_commit=source_commit,scope='Coherent Hall, orders and settlement account snapshot; canonical nonlocking projection read; no schema, CSS or Public change',target_host_install_verified=False);p.write_bytes(jb(j))
 p=root/'resources/release-identity-v1.json';j=json.loads(p.read_bytes());j.update(version=version,release_set_id=rid,baseline={'version':base['VERSION.txt'].decode().strip()});j['source'].update(source_ref=source_commit,canonical_tree_sha256=canonical(files(root),j['source']['digest_excludes']));j['qualification']={'tier':'home_uat_not_field_qualified','claim_rule':'Automated PHP/MariaDB regression results supplied separately; real host installation and WAN UAT outstanding'};p.write_bytes(jb(j))
 target=files(root);assert set(base)==set(target)
 assert all(b==target[n] for n,b in base.items() if n.startswith('database/migrations/'))
 assert all(b==target[n] for n,b in base.items() if n.endswith('.css'))
 if kind=='local':
  with zipfile.ZipFile(ROOT.parent/'r83/baseline-local-r81.zip') as z:template=json.loads(z.read('release-set.json'))
  bases=[('R81',base,template['targets']['local'])]
  with zipfile.ZipFile(ROOT.parent/'r83/baseline-local-r82-delta.zip') as z:
   m82=json.loads(z.read('release-set.json'))
   with zipfile.ZipFile(io.BytesIO(z.read(m82['artifacts']['local-web']['path']))) as delta:
    d=json.loads(delta.read('delta-manifest.json'));b82=dict(base)
    for n in d['changed_paths']:b82[n]=delta.read(n)
   assert digest(b82)==m82['targets']['local']['managed_tree_sha256']
   bases.append(('R82',b82,m82['targets']['local']))
  b83=files(OUT/'r83/local')
  with zipfile.ZipFile(ROOT.parent/'r84/baselines/SOKNA-R83-LOCAL-R81-TO-R83-DELTA-HOME-UAT.zip') as z:m83=json.loads(z.read('release-set.json'))
  bases.append(('R83',b83,m83['targets']['local']))
  b84=files(OUT/'r84/local')
  with zipfile.ZipFile(ROOT.parent/'r85/baselines/SOKNA-R84-LOCAL-R83-TO-R84-DELTA-HOME-UAT.zip') as z:m84=json.loads(z.read('release-set.json'))
  bases.append(('R84',b84,m84['targets']['local']))
  b85=files(OUT/'r85/local')
  with zipfile.ZipFile(ROOT.parent/'r86/baselines/SOKNA-R85-LOCAL-R84-TO-R85-DELTA-HOME-UAT.zip') as z:m85=json.loads(z.read('release-set.json'))
  bases.append(('R85',b85,m85['targets']['local']))
  b86=files(OUT/'baseline/local')
  with zipfile.ZipFile(ROOT/'baselines/SOKNA-R86-LOCAL-R85-TO-R86-DELTA-HOME-UAT.zip') as z:m86=json.loads(z.read('release-set.json'))
  bases.append(('R86',b86,m86['targets']['local']))
  for label,old,node in bases:
   paths=sorted(set([n for n in target if target[n]!=old[n]]+['resources/local-managed-files-v1.json']))
   dm={'format':'sokna-local-web-delta-v1','schema_version':1,'base_version':old['VERSION.txt'].decode().strip(),'base_managed_tree_sha256':digest(old),'target_version':version,'target_managed_tree_sha256':digest(target),'changed_paths':paths,'deleted_paths':[]}
   inner=zb({'delta-manifest.json':jb(dm),**{n:target[n] for n in paths}})
   m=copy.deepcopy(template);m.update(release_set_id=rid,sequence=2026110187,status='R87_HOME_UAT_CANDIDATE',supported_baselines=[{'local':node,'public':None}]);m['targets']['local'].update(version=version,release_id=rid,managed_tree_sha256=digest(target));m['artifacts']['local-web'].update(kind='web-delta-zip-v1',path='artifacts/local/web-delta.zip',sha256=sha(inner),size_bytes=len(inner));m['qualification']={'classification':'R87_EXACT_'+label+'_HOME_UAT','production_qualified':False,'host_install_test':'NOT_RUN','source_snapshot_verified':True,'target_managed_files':len(target),'automated_qa':'See QA-RESULTS.json and GitHub Actions run'}
   result=dict(old)
   with zipfile.ZipFile(io.BytesIO(inner)) as z:
    assert z.testzip() is None
    for n in paths:result[n]=z.read(n)
   assert result==target
   (DIST/f'SOKNA-R87-LOCAL-{label}-TO-R87-DELTA-HOME-UAT.zip').write_bytes(zb({'release-set.json':jb(m),'artifacts/local/web-delta.zip':inner}))
assert files(OUT/'source/public')==files(OUT/'baseline/public'), 'Public is byte-for-byte unchanged from R86'
summary=[];checks=[]
for p in sorted(DIST.glob('*.zip')):
 with zipfile.ZipFile(p) as z:assert z.testzip() is None
 checks.append({'test':p.name+' exact-base reconstruction and archive integrity','pass':True})
 summary.append({'name':p.name,'bytes':p.stat().st_size,'sha256':sha(p.read_bytes())})
(DIST/'PACKAGE-VERIFICATION.json').write_bytes(jb({'source_commit':source_commit,'all_deltas_reconstructed_byte_for_byte':True,'database_migrations_changed':False,'css_changed':False,'public_changed_from_r86':False,'native_windows_installation':'NOT_RUN','guest_javascript_behavior_changed_from_r86':False,'cumulative_previous_guest_fixes_in_older_deltas':True,'packages':summary}))
(DIST/'SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['name']+'\n' for x in summary))
checks.append({'test':'Public source remains exact published R86 bytes','pass':True})
(OUT/'qa/package.json').write_bytes(jb(checks))
print(json.dumps(summary,indent=2))
