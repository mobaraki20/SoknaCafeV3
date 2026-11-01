import pathlib,zipfile,json,hashlib,io,os,copy,shutil
ROOT=pathlib.Path(__file__).resolve().parent;OUT=ROOT/'build';DIST=OUT/'dist';DIST.mkdir(parents=True,exist_ok=True)
sha=lambda b:hashlib.sha256(b).hexdigest()
jb=lambda x:(json.dumps(x,sort_keys=True,ensure_ascii=False,indent=2)+'\n').encode()
def zb(data):
 o=io.BytesIO()
 with zipfile.ZipFile(o,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
  for n,b in sorted(data.items()):
   i=zipfile.ZipInfo(n,(2026,11,2,0,0,0));i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,b)
 return o.getvalue()
def digest(data):return sha(''.join(n+'\0'+sha(b)+'\n' for n,b in sorted(data.items())).encode())
p=ROOT/'baseline/SOKNA-LOCAL-UNIVERSAL-UPDATE.zip';assert sha(p.read_bytes())=='e8bf1c516e606de3aaf712b85a584dc66d5dadcdae5517557d7c07dbf15daa8a'
with zipfile.ZipFile(p) as z:
 m=json.loads(z.read('release-set.json'));raw=z.read(m['artifacts']['local-web']['path']);assert sha(raw)==m['artifacts']['local-web']['sha256']
 with zipfile.ZipFile(io.BytesIO(raw)) as w:data={n:w.read(n) for n in w.namelist()}
assert digest(data)==m['targets']['local']['managed_tree_sha256'];baseline=dict(data)
patch=pathlib.Path('app-baselines/release/r90');checks=json.loads((patch/'baseline-file-shas.json').read_text())
for n,h in checks.items():assert sha(data[n])==h;data[n]=(patch/'changes/local'/n).read_bytes()
version='3.0.0-g6.2-workspace.231cn-r91-universal-ready-uat';rid='sokna-r91-universal-20261102-local';commit=os.environ['GITHUB_SHA'];data['VERSION.txt']=(version+'\n').encode()
node=json.loads(data['resources/build-provenance.json']);node.update(version=version,release_set_id=rid,source_commit=commit,classification='R91_UNIVERSAL_UAT',scope='R90 concise update feedback and safe cause diagnostics; R88 app fixes preserved');data['resources/build-provenance.json']=jb(node)
node=json.loads(data['resources/release-identity-v1.json']);node.update(version=version,release_set_id=rid);node['source'].update(source_ref=commit)
excluded=node['source']['digest_excludes'];node['source']['canonical_tree_sha256']=sha(b''.join(n.encode()+b'\0'+str(len(b)).encode()+b'\0'+sha(b).encode()+b'\n' for n,b in sorted(data.items()) if n not in excluded));data['resources/release-identity-v1.json']=jb(node)
source=OUT/'source/local'
for n,b in data.items():f=source/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
previous=copy.deepcopy(m['targets']['local']);m['supported_baselines'].append({'local':previous,'public':None});m['targets']['local'].update(version=version,release_id=rid,managed_tree_sha256=digest(data),managed_files=len(data));m.update(release_set_id=rid,sequence=2026110291,status='R91_UNIVERSAL_UAT');m['requirements'].update(maintenance_supervisor_min='0.3.19',update_engine_min='0.3.19');inner=zb(data);m['artifacts']['local-web'].update(kind='web-tree-zip-v1',path='artifacts/local/web.zip',sha256=sha(inner),size_bytes=len(inner));m['qualification'].update(classification='UNIVERSAL_KNOWN_BASELINES_UAT',host_install_test='NOT_RUN',required_database_reconciliation='exact physical source plus live ledger attestation; no migration during rebind')
(DIST/'SOKNA-LOCAL-UNIVERSAL-UPDATE.zip').write_bytes(zb({'release-set.json':jb(m),'artifacts/local/web.zip':inner}))
pub=ROOT/'baseline/SOKNA-PUBLIC-UNIVERSAL-UPDATE.zip';assert sha(pub.read_bytes())=='553fa5c9d210b50c8cda28508cdd1dc9ead7c6724f4b2e8e15a9d753b3362632';shutil.copyfile(pub,DIST/pub.name)
assert len(data)==534 and len(m['supported_baselines'])==10
assert all(data[n]==b for n,b in baseline.items() if n.startswith('database/migrations/'))
summary={p.name:{'bytes':p.stat().st_size,'sha256':sha(p.read_bytes())} for p in DIST.glob('*.zip')};(DIST/'PACKAGE-VERIFICATION.json').write_bytes(jb({'source_commit':commit,'packages':summary,'local_baselines':10,'public_unchanged_r89':True,'database_migrations_changed':False,'local_target':m['targets']['local']}));(DIST/'SHA256SUMS.txt').write_text(''.join(v['sha256']+'  '+n+'\n' for n,v in summary.items()));print(json.dumps(summary,indent=2))
