"""Build universal full managed snapshots with exact source allowlists."""
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
commit=os.environ.get('GITHUB_SHA','local-reproducible-build');identities={};qualification=[]
baselines={'local':['r38','r81','r82','r83','r84','r85','r86','r87','r88'],'public':['r53','r81','r83','r84','r85','r86','r88']}
with zipfile.ZipFile(ROOT.parent/'r83/baseline-local-r81.zip') as z:template=json.loads(z.read('release-set.json'))
for kind,version in [('local','3.0.0-g6.2-workspace.231cm-r89-universal-uat'),('public','3.0.0-g3.6-workspace.35fv-r89-universal-uat')]:
 root=OUT/'source'/kind;rid='sokna-r89-universal-20261101-'+kind
 (root/'VERSION.txt').write_text(version+'\n')
 p=root/'resources/build-provenance.json';j=json.loads(p.read_bytes());j.update(version=version,release_set_id=rid,classification='R89_UNIVERSAL_UAT',source_commit=commit,scope='Same qualified package per component; R88 application fixes preserved',target_host_install_verified=False);p.write_bytes(jb(j))
 p=root/'resources/release-identity-v1.json';j=json.loads(p.read_bytes());j.update(version=version,release_set_id=rid,baseline={'mode':'exact_compatible_allowlist','source_labels':baselines[kind]});j['source'].update(source_ref=commit,canonical_tree_sha256=canonical(files(root),j['source']['digest_excludes']));j['qualification']={'tier':'universal_known_baselines_uat','claim_rule':'Native receiver lab gates; actual host readback still required'};p.write_bytes(jb(j))
 target=files(root);bases=[]
 for label in baselines[kind]:
  old=files(OUT/label/kind);assert len(old)==len(target)
  # No old SQL history or stable recovery ownership may be changed by this release.
  assert all(old[n]==target[n] for n in old if n.startswith(('database/migrations/','src/Recovery/')) or n=='recovery.php')
  identity=json.loads(old['resources/release-identity-v1.json'])
  bases.append({'label':label,'version':old['VERSION.txt'].decode().strip(),'managed_tree_sha256':digest(old),'release_id':identity['release_set_id'],'managed_files':len(old)})
 identities[kind]={'version':version,'managed_tree_sha256':digest(target),'managed_files':len(target),'bases':bases}
 if kind=='local':
  m=copy.deepcopy(template);node=copy.deepcopy(template['targets']['local']);node.update(version=version,release_id=rid,managed_tree_sha256=digest(target),managed_files=len(target))
  allowed=[]
  for b in bases:
   source=copy.deepcopy(node);source.update({k:b[k] for k in ['version','release_id','managed_tree_sha256','managed_files']});allowed.append({'local':source,'public':None})
  m.update(release_set_id=rid,sequence=2026110189,status='R89_UNIVERSAL_UAT',supported_baselines=allowed);m['targets']['local']=node
  inner=zb(target);m['artifacts']['local-web'].update(kind='web-tree-zip-v1',path='artifacts/local/web.zip',sha256=sha(inner),size_bytes=len(inner))
  m['qualification']={'classification':'UNIVERSAL_KNOWN_BASELINES_UAT','production_qualified':False,'target_managed_files':len(target),'host_install_test':'NOT_RUN','packaging_choice':'Full managed payload because current native Delta is single-baseline; preserve existing updater'}
  (DIST/'SOKNA-LOCAL-UNIVERSAL-UPDATE.zip').write_bytes(zb({'release-set.json':jb(m),'artifacts/local/web.zip':inner}))
 else:
  m={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':version,'contracts':{'local_public_contract':1},'files':[{'path':n,'size':len(b),'sha256':sha(b)} for n,b in sorted(target.items())],'target_managed_tree_sha256':digest(target),'compatibility':{'supported_baselines':[{k:b[k] for k in ['version','managed_tree_sha256']} for b in bases]},'provenance':{'source_commit':commit,'strict_multibaseline_v1':True,'route':'ROUTINE_AND_EMERGENCY_SAME_BYTES','scope':'UAT_NOT_FIELD_QUALIFIED'}}
  (DIST/'SOKNA-PUBLIC-UNIVERSAL-UPDATE.zip').write_bytes(zb({'manifest.json':jb(m),**{'payload/'+n:b for n,b in target.items()}}))
(DIST/'IDENTITIES.json').write_bytes(jb(identities))
checks=[]
for kind,node in identities.items():
 for b in node['bases']:checks.append({'test':kind+' '+b['label']+' immutable migrations and exact baseline declaration','pass':True})
(OUT/'qa/package-bytes.json').write_bytes(jb(checks))
summary=[{'name':p.name,'bytes':p.stat().st_size,'sha256':sha(p.read_bytes())} for p in sorted(DIST.glob('*.zip'))]
(DIST/'SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['name']+'\n' for x in summary))
(DIST/'PACKAGE-VERIFICATION.json').write_bytes(jb({'source_commit':commit,'one_archive_per_component':True,'public_same_zip_for_both_routes':True,'payload_mode':'full_managed_snapshot_no_custom_updater','local_baselines':len(identities['local']['bases']),'public_baselines':len(identities['public']['bases']),'packages':summary,'database_migrations_changed':False}))
print(json.dumps(summary,indent=2))
