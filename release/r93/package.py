import pathlib,json,zipfile,io,hashlib,os,copy
R=pathlib.Path(__file__).resolve().parent;B=R/'build';D=B/'dist';D.mkdir(exist_ok=True);sha=lambda b:hashlib.sha256(b).hexdigest();jb=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,indent=2)+'\n').encode()
def tree(p):return {f.relative_to(p).as_posix():f.read_bytes() for f in p.rglob('*') if f.is_file() and f.name not in ['chart.html','chart-signed.html']}
def digest(d):return sha(b''.join(n.encode()+b'\0'+sha(b).encode()+b'\n' for n,b in sorted(d.items())))
def archive(d):
 o=io.BytesIO()
 with zipfile.ZipFile(o,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
  for n,b in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,2,0,0,0));i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,b)
 return o.getvalue()
identities={}
for kind,version in [('local','3.0.0-g6.2-workspace.231cp-r93-workspace-sync-uat'),('public','3.0.0-g3.6-workspace.35fx-r93-workspace-sync-uat')]:
 before=tree(B/'before'/kind);data=tree(B/'after'/kind);assert len(before)==len(data)==(534 if kind=='local' else 221)
 rid='sokna-r93-workspace-20261102-'+kind;data['VERSION.txt']=(version+'\n').encode();node=json.loads(data['resources/build-provenance.json']);node.update(version=version,release_set_id=rid,source_commit=os.environ['GITHUB_SHA'],classification='R93_WORKSPACE_SYNC_UAT',scope='Canonical Local supervision, durable Public purchase and subscriber payment intents, signed sales trend');data['resources/build-provenance.json']=jb(node)
 node=json.loads(data['resources/release-identity-v1.json']);node.update(version=version,release_set_id=rid);node['source']['source_ref']=os.environ['GITHUB_SHA'];exclude=node['source']['digest_excludes'];node['source']['canonical_tree_sha256']=sha(b''.join(n.encode()+b'\0'+str(len(b)).encode()+b'\0'+sha(b).encode()+b'\n' for n,b in sorted(data.items()) if n not in exclude));data['resources/release-identity-v1.json']=jb(node)
 changed=[n for n in data if data[n]!=before[n]];allowed={key.split('/',1)[1] for key in json.loads((R/'baseline-shas.json').read_text()) if key.startswith(kind+'/')}|{'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'};assert set(changed)==allowed;assert all(data[n]==b for n,b in before.items() if n.startswith('database/migrations/'))
 with zipfile.ZipFile(R/'baseline'/('SOKNA-'+kind.upper()+'-UNIVERSAL-UPDATE.zip')) as z:m=json.loads(z.read('release-set.json' if kind=='local' else 'manifest.json'))
 if kind=='local':
  prev=copy.deepcopy(m['targets']['local']);assert prev['managed_tree_sha256']==digest(before);m['supported_baselines'].append({'local':prev,'public':None});m.update(release_set_id=rid,sequence=2026110293,status='R93_WORKSPACE_SYNC_UAT');m['targets']['local'].update(version=version,release_id=rid,managed_tree_sha256=digest(data));inner=archive(data);m['artifacts']['local-web'].update(sha256=sha(inner),size_bytes=len(inner));m['qualification'].update(scope='Canonical Local authority and durable UI commands; no SQL changes',host_install_test='NOT_RUN');payload={'release-set.json':jb(m),'artifacts/local/web.zip':inner}
 else:
  prev={'version':before['VERSION.txt'].decode().strip(),'managed_tree_sha256':digest(before)};assert prev['managed_tree_sha256']==m['target_managed_tree_sha256'];m['compatibility']['supported_baselines'].append(prev);m.update(version=version,target_managed_tree_sha256=digest(data));m['files']=[{'path':n,'size':len(b),'sha256':sha(b)} for n,b in sorted(data.items())];m['provenance'].update(source_commit=os.environ['GITHUB_SHA'],scope='Durable Staff workflow integration; unchanged strict CSP');payload={'manifest.json':jb(m),**{'payload/'+n:b for n,b in data.items()}}
 (D/('SOKNA-'+kind.upper()+'-UNIVERSAL-UPDATE.zip')).write_bytes(archive(payload));identities[kind]={'version':version,'managed_tree_sha256':digest(data),'bases':[{'label':'current','version':before['VERSION.txt'].decode().strip(),'managed_tree_sha256':digest(before)}]}
(D/'IDENTITIES.json').write_bytes(jb(identities));(D/'PACKAGE-VERIFICATION.json').write_bytes(jb({'source_commit':os.environ['GITHUB_SHA'],'packages':{p.name:{'sha256':sha(p.read_bytes()),'bytes':p.stat().st_size} for p in D.glob('*.zip')},'local_baselines':12,'public_baselines':9,'no_migrations_changed':True,'no_security_policy_changed':True,'host_install':'NOT_RUN'}))
(D/'SHA256SUMS.txt').write_text(''.join(sha(p.read_bytes())+'  '+p.name+'\n' for p in sorted(D.glob('*.zip'))))
