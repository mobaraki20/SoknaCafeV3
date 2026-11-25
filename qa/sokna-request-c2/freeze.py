#!/usr/bin/env python3
import base64,zlib,hashlib,json,zipfile,io,pathlib,os,copy
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
tree=lambda d:H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def canonical(d):return H(''.join(k+'\0'+str(len(v))+'\0'+H(v)+'\n' for k,v in sorted(d.items()) if k not in ('resources/build-provenance.json','resources/release-identity-v1.json')).encode())
def read(b):
    with zipfile.ZipFile(io.BytesIO(b)) as z:
        assert len(z.namelist())==len(set(z.namelist())) and z.testzip() is None
        return {n:z.read(n) for n in z.namelist()}
def pack(d):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w') as z:
        for n,v in sorted(d.items()):
            assert not n.startswith('/') and '..' not in n.split('/')
            i=zipfile.ZipInfo(n,(2026,11,24,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(i,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return b.getvalue()
r=pathlib.Path(os.environ['RUNNER_TEMP'])
out=pathlib.Path('frozen');out.mkdir(exist_ok=True)
raw=zlib.decompress(base64.b64decode(pathlib.Path('qa/sokna-request-c2/source-delta.b64').read_bytes()))
assert H(raw)=='7d9da3688b22bb04ecd6ef760abdabfab4520486ef0682cae6b6350f128edc61'
delta=json.loads(raw)
def locate(d,n,sha):
    p=list((r/d).rglob(n));assert len(p)==1,(d,n,p)
    b=p[0].read_bytes();assert H(b)==sha,(n,H(b));return b
def apply(comp,old):
    cfg=delta[comp];f=dict(old)
    for n,v in cfg['files'].items():
        assert (H(f[n]) if n in f else None)==v['before'],n
        lines=f.get(n,b'').decode().splitlines(keepends=True)
        for a,b,s in reversed(v['edits']):lines[a:b]=[s]
        f[n]=''.join(lines).encode();assert H(f[n])==v['after'],n
    assert tree(f)==cfg['tree'],comp
    return f
def identity(d,version,rel,origins):
    d['VERSION.txt']=(version+'\n').encode()
    i=json.loads(d['resources/release-identity-v1.json'])
    i.update(version=version,release_set_id=rel);i['baseline']['source_versions']=origins
    i['source'].update(managed_file_count=len(d),canonical_file_count=len(d)-2,canonical_tree_sha256=canonical(d),source_ref='C2 exact source SHA256 96e7e8bead29f26fab06c69d95c4ff7172e5f619a51edd3e9de266f5bc24e54f')
    i['qualification']={'tier':'EXACT_CANDIDATE_EXTERNAL_RECEIPT_REQUIRED','claim_rule':'Distribution requires matching successful exact-package admission; no automatic deployment.'}
    d['resources/release-identity-v1.json']=J(i)
    p=json.loads(d['resources/build-provenance.json']);p.update(version=version,release_set_id=rel,classification='CONTROLLED_CANDIDATE_EXTERNAL_RECEIPT_REQUIRED',source='sha256:96e7e8bead29f26fab06c69d95c4ff7172e5f619a51edd3e9de266f5bc24e54f',scope='C2 verified request fixes; remaining acceptance separate',operator_warning='External exact gate receipt required',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False)
    d['resources/build-provenance.json']=J(p)
    assert i['source']['canonical_tree_sha256']==canonical(d)
lb=locate('local','SOKNA-LOCAL-231GB-UNIVERSAL-UPDATE.zip','d94bd1a539cede71a0d443b3f417fa9bffba3898c38b446caea6e09ec826d627')
l=read(lb);m=json.loads(l['release-set.json']);old=read(l['artifacts/local/web.zip']);assert H(l['artifacts/local/web.zip'])==delta['local']['before']
L=apply('local',old);assert len(L)==622
m['supported_baselines'].append({'local':copy.deepcopy(m['targets']['local'])})
lv='3.0.0-g6.2-workspace.231gc-request-c2-20261124';lr='sokna-local-231gc-request-c2-20261124'
mi=json.loads(L['resources/local-managed-files-v1.json']);mi['files']=sorted(L);L['resources/local-managed-files-v1.json']=J(mi)
identity(L,lv,lr,[x['local']['version'] for x in m['supported_baselines']])
migs=sorted(n for n in L if n.startswith('database/migrations/') and n.endswith('.sql'));assert len(migs)==91
head=migs[-1].split('/')[-1][:-4];assert head=='0091_verified_purchase_unit_reconciliation'
fp='migration-ledger-sha256-v1:'+H(''.join(n.split('/')[-1][:-4]+'\0'+H(L[n])+'\n' for n in migs).encode())
m['targets']['local'].update(version=lv,release_id=lr,managed_files=len(L),managed_tree_sha256=tree(L),migration_head=head,schema_fingerprint=fp)
m.update(release_set_id=lr,sequence=202611240004,status='QUARANTINED_RELEASE_CANDIDATE')
m['database']['local']['migration_dag'].append(head)
m['qa_notes'].update(consolidated='C2 exact candidate; external admission only after native and operational tests',operator_install_allowed=False,verified_baselines_only=[x['local']['version'] for x in m['supported_baselines']])
web=pack(L);m['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
(out/'LOCAL-C2-FROZEN.zip').write_bytes(pack({'release-set.json':J(m),'artifacts/local/web.zip':web}))
(out/'LOCAL-C2-WEB.zip').write_bytes(web)
pb=locate('public','SOKNA-PUBLIC-35IP-UNIVERSAL-UPDATE.zip','5428105ee9b86b9583637552f1a66a3b29501fd8e7b5556a4dbef7fc53ba8765')
pc=locate('public','SOKNA-PUBLIC-35IP-CLEAN-WEB.zip','1206dd043d87f8e832b9d3660924c1f288bc8565a6854bd09144c9c80956c040')
assert H(pc)==delta['public']['before']
old=read(pc);P=apply('public',old);assert len(P)==257
pz=read(pb);pm=json.loads(pz['manifest.json']);managed={n[8:]:v for n,v in pz.items() if n.startswith('payload/')}
protected=set(old)-set(managed);assert len(protected)==6 and all(P[n]==old[n] for n in protected)
P={n:v for n,v in P.items() if n not in protected}
pm['compatibility']['supported_baselines'].append({'version':pm['version'],'managed_tree_sha256':pm['target_managed_tree_sha256']})
pv='3.0.0-g3.6-workspace.35iq-request-c2-20261124';pr='sokna-public-35iq-request-c2-20261124'
o=json.loads(P['src/Update/emergency-owner-v1.json']);o['release_version']=pv;assert o['sha256']==H(P['src/Update/EmergencyOwnerCandidate.php']);P['src/Update/emergency-owner-v1.json']=J(o)
identity(P,pv,pr,[x['version'] for x in pm['compatibility']['supported_baselines']])
pm.update(version=pv,target_managed_tree_sha256=tree(P),files=[{'path':n,'size':len(v),'sha256':H(v)} for n,v in sorted(P.items())])
pm['provenance'].update(build='35iq C2 exact source',origin_package_sha256=H(pb),source_managed_tree_sha256=tree(managed),modified_files=sorted(n for n in P if P[n]!=managed[n]),qualification_qa_run='EXTERNAL_EXACT_GATE_RECEIPT_REQUIRED',release_qualification='CONTROLLED_OPERATOR_DISTRIBUTION_PENDING_EXACT_GATE')
(out/'PUBLIC-C2-FROZEN.zip').write_bytes(pack({'manifest.json':J(pm)}|{'payload/'+n:v for n,v in P.items()}))
(out/'PUBLIC-C2-WEB.zip').write_bytes(pack(P|{n:old[n] for n in protected}))
print(json.dumps({p.name:H(p.read_bytes()) for p in out.glob('*.zip')},indent=2))
