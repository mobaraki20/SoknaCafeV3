"""Exact CP100 bridge reconstruction and separate quarantined final candidate.
All stable owner bytes come from pinned historical clean archives, NOT the actual Cafe.
"""
import pathlib as P,hashlib,zipfile,io,json,zlib,base64,sys,os
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,sort_keys=True,separators=(',',':'),ensure_ascii=False)+'\n').encode()
def need(c,msg):
 if not c:raise RuntimeError(msg)
def read(raw):
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  ns=z.namelist();need(z.testzip() is None and len(set(ns))==len(ns) and all(n and not n.startswith('/') and '..' not in n.split('/') and chr(92) not in n for n in ns),'ZIP')
  return {n:z.read(n) for n in ns if not n.endswith('/')}
def tree(d):return H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
def write(path,d):
 with zipfile.ZipFile(path,'w') as z:
  for n,v in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,v,compresslevel=9)
 need(read(path.read_bytes())==d,'READBACK')
r=P.Path(os.environ['RUNNER_TEMP'])
a=read((r/'origin.zip').read_bytes());n=[n for n in a if n.endswith('SOKNA-PUBLIC-35IQ-UNIVERSAL-UPDATE.zip')];need(len(n)==1,'ORIGIN');old=a[n[0]]
need(H(old)=='1d93994d3a013f470eba34c769cb5e3ac595a6724c5558f18bc596684bba9c98','ORIGIN_PIN')
o=read(old);om=json.loads(o['manifest.json']);origin={n[8:]:v for n,v in o.items() if n.startswith('payload/')};need(tree(origin)=='c08f3e42e5c9c2d7dc0dbc1a0d8f525ea2958d9deba73b0eb2e749d3515b02d4','ORIGIN_TREE')
a=read((r/'stable.zip').read_bytes());n=[n for n in a if n.endswith('SOKNA-PUBLIC-35IP-CLEAN-WEB.zip')];need(len(n)==1,'STABLE_ARCHIVE');stable_raw=a[n[0]];need(H(stable_raw)=='1206dd043d87f8e832b9d3660924c1f288bc8565a6854bd09144c9c80956c040','STABLE_PIN')
clean=read((r/'cp76/out/PUBLIC-35IU-CLEAN-WEB-QA-DO-NOT-INSTALL.zip').read_bytes())
need(H((r/'cp76/out/PUBLIC-35IU-CLEAN-WEB-QA-DO-NOT-INSTALL.zip').read_bytes())=='b6c622515cc1f532531b049fc9d252bbebeff39d09dc4faed2a6368a3a7ca6e3','CLEAN_PIN')
delta=base64.b64decode((P.Path(__file__).parent/'pending-owner-delta.b64').read_text().strip(),validate=True)
need(H(delta)=='867ee5423eacd577683ad20499ab376263c5400ba0c1532cf11e56ec61a70dc2','DELTA_PIN')
x=json.loads(zlib.decompress(delta));m=x['manifest'];q=x['plan']
for n in ('recovery.php','src/Recovery/StableRecoveryService.php','src/Emergency/PublicUpdateService.php'):
 need(H(clean[n])==q['patch'][n]['after'],'OWNER_PIN');q['patch'][n]['bytes_b64']=base64.b64encode(clean[n]).decode()
p=J(q);need(H(p)==m['provenance']['protected_owner_transition']['plan_sha256']=='cd9c57199ffb10029523c72bb0a2f947172a8daf399c175411349263dd1c51cc','PLAN_PIN')
m['provenance']['protected_owner_transition']['plan_b64']=base64.b64encode(p).decode()
d=dict(o);d['manifest.json']=J(m)
for n,op in x['delta'].items():
 if 'full' in op:d[n]=op['full'].encode()
 else:
  lines=d[n].decode().splitlines(keepends=True)
  for i,j,new in reversed(op['ops']):lines[i:j]=new
  d[n]=''.join(lines).encode()
need({n:H(v) for n,v in d.items()}==x['target_inventory'],'EXACT_INVENTORY')
out=r/'cp100';out.mkdir();bridge=out/'bridge.zip';write(bridge,d);need(H(bridge.read_bytes())==x['target_sha']=='f563b4328616862d352c1fcaa4be8b44c3604f2586c866ff9c2f46ac273cd904','BRIDGE_PIN')
stable={n:read(stable_raw)[n] for n in q['stable_before']};need({n:H(v) for n,v in stable.items()}==q['stable_before'],'STABLE_BYTES')
web=P.Path('/var/www/sokna-cp100/webroot/public');web.mkdir(parents=True,exist_ok=False)
for n,v in (origin|stable).items():
 f=web/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(v)
# Build the final ordinary package from EXACT CP76, not a hand-edited host or a lower version.
raw=(r/'cp76/out/PUBLIC-35IU-UPDATE-FOUNDATION-FIXTURE-QA-DO-NOT-INSTALL.zip').read_bytes();need(H(raw)=='692c4379a3ef8c2b8bfdbbd9cec5a319422dd80c587476f20a8a4ffe2ff03797','FACTORY_PIN')
z=read(raw);fm=json.loads(z.pop('manifest.json'));f={n[8:]:v for n,v in z.items()};prev=dict(f)
ver='3.0.0-g3.6-workspace.35ix-cafe-owner-qualified-qa';release='sokna-public-35ix-cafe-owner-qualified-qa'
f['VERSION.txt']=(ver+'\n').encode();owner=json.loads(f['src/Update/emergency-owner-v1.json']);owner['release_version']=ver;f['src/Update/emergency-owner-v1.json']=J(owner)
meta={'resources/release-identity-v1.json','resources/build-provenance.json'}
canon=lambda d:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in meta).encode())
i=json.loads(f['resources/release-identity-v1.json']);i.update(version=ver,release_set_id=release,baseline={'mode':'exact_compatible_allowlist','source_versions':[q['version']]},qualification={'tier':'CAFE_OWNER_TRANSITION_QA_ONLY','claim_rule':'Only exact post-owner baseline; no live qualification.'});i['source'].update(canonical_tree_sha256=canon(f));f['resources/release-identity-v1.json']=J(i)
prov=json.loads(f['resources/build-provenance.json']);prov.update(version=ver,release_set_id=release,classification='CAFE_OWNER_TRANSITION_QA_ONLY',operator_warning='DO NOT INSTALL; actual host drain and delivery qualification missing.');f['resources/build-provenance.json']=J(prov)
need({n for n in f if f[n]!=prev[n]}==meta|{'VERSION.txt','src/Update/emergency-owner-v1.json'},'FINAL_DRIFT')
for n in ('recovery.php','src/Recovery/StableRecoveryService.php'):need(H(f[n])==q['managed_after'][n],'FINAL_OWNER_DRIFT')
sql={n:H(v) for n,v in origin.items() if n.startswith('database/migrations/') and n.endswith('.sql')};need(len(sql)==11 and all(H(f[n])==v for n,v in sql.items()) and len([n for n in f if n.startswith('database/migrations/') and n.endswith('.sql')])==12,'SQL_SCOPE')
fm.update(version=ver,target_managed_tree_sha256=tree(f),compatibility={'supported_baselines':[{'version':q['version'],'managed_tree_sha256':q['target_tree']}]},files=[{'path':n,'size':len(v),'sha256':H(v)} for n,v in sorted(f.items())]);fm['provenance'].update(installation_authorized=False,production_host_qualified=False,release_qualification='CAFE_OWNER_TRANSITION_QA_ONLY',host_operator_note='DO NOT INSTALL; actual host qualification required.')
final=out/'final.zip';write(final,{'manifest.json':J(fm)}|{'payload/'+n:v for n,v in f.items()})
(out/'plan.json').write_bytes(p);(out/'contract.json').write_bytes(J({'origin':om['version'],'origin_tree':tree(origin),'bridge':m['version'],'bridge_tree':m['target_managed_tree_sha256'],'bridge_sha':H(bridge.read_bytes()),'post_tree':q['target_tree'],'plan_sha':H(p),'final':ver,'final_tree':tree(f),'final_sha':H(final.read_bytes()),'stable_before':q['stable_before'],'stable_after':q['stable_after'],'scope':'DISPOSABLE_QA_ONLY_NOT_INSTALLABLE'}))
print('EXACT_BRIDGE',H(bridge.read_bytes()),'EXACT_FINAL_QA',H(final.read_bytes()),'POST_OWNER_TREE',q['target_tree'])
