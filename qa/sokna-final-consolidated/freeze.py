#!/usr/bin/env python3
"""Exact C2 factory continuation. Builds quarantined artifacts; no deployment."""
import pathlib,zipfile,io,hashlib,json,base64,zlib,os,copy,sys
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
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
R=pathlib.Path(os.environ['RUNNER_TEMP']);out=pathlib.Path(os.environ.get('SOKNA_FINAL_FROZEN','frozen'));out.mkdir(exist_ok=True,parents=True)
parts=sorted(pathlib.Path(__file__).parent.glob('delta-*.b64'));assert len(parts)==9
raw=zlib.decompress(base64.b64decode(b''.join(p.read_bytes() for p in parts),validate=True));source_sha=H(raw);assert source_sha=='eaa69b4810791e17df25cb8192b5eb1b002714a9e76be6949ab633285350a174';delta=json.loads(raw)
def locate(folder,name,sha):
 p=list((R/folder).rglob(name));assert len(p)==1,(folder,name,p);b=p[0].read_bytes();assert H(b)==sha,(name,H(b));return b
lb=locate('published','SOKNA-LOCAL-231GC-UNIVERSAL-UPDATE.zip','f864a42fb82124e4f16c9b9437464256ef3b59d79d26518232cf05cbc9393f42');pb=locate('published','SOKNA-PUBLIC-35IQ-UNIVERSAL-UPDATE.zip','1d93994d3a013f470eba34c769cb5e3ac595a6724c5558f18bc596684bba9c98')
l=read(lb);m=json.loads(l['release-set.json']);L0=read(l['artifacts/local/web.zip']);assert H(l['artifacts/local/web.zip'])=='3f5860ffbc30fa718b68709d89780ae56d3898fb9cc100157580d98d9ca2b2f9'
p=read(pb);pm=json.loads(p['manifest.json']);P0={n[8:]:b for n,b in p.items() if n.startswith('payload/')}
if os.environ.get('SOKNA_FINAL_LOCAL_PROTECTED'):
 assert not os.environ.get('GITHUB_ACTIONS') # local structural QA only
 root=pathlib.Path(os.environ['SOKNA_FINAL_LOCAL_PROTECTED']);protected={str(n.relative_to(root)):n.read_bytes() for n in root.rglob('*') if n.is_file() and str(n.relative_to(root)) in ['emergency.php','src/Emergency/EmergencyAccessService.php','src/Emergency/PairingSecretStore.php','src/Emergency/PublicTakeoverService.php','src/Emergency/PublicUpdateException.php','src/Emergency/PublicUpdateService.php']}
else:
 clean=read(locate('c2','PUBLIC-C2-WEB.zip','8afa590187f37f8d1ebad66c4764731e5e169f2e47e4c9a3e31f62caaa652aa4'));protected={n:b for n,b in clean.items() if n not in P0};assert all(clean[n]==v for n,v in P0.items())
assert len(protected)==6
P0=P0|protected

def apply(c,old):
 cfg=delta[c];exclude=set(cfg['exclude']);f=dict(old)
 assert tree({n:b for n,b in f.items() if n not in exclude})==cfg['base_tree'],c
 for n,v in cfg['files'].items():
  assert (H(f[n]) if n in f else None)==v['before'],n
  lines=f.get(n,b'').decode().splitlines(keepends=True)
  for a,b,s in reversed(v['edits']):lines[a:b]=[s]
  f[n]=''.join(lines).encode();assert H(f[n])==v['after'],n
 assert len(f)==cfg['count'] and tree({n:b for n,b in f.items() if n not in exclude})==cfg['target_tree'],c
 return f
L=apply('local',L0);Pfull=apply('public',P0);assert all(Pfull[n]==b for n,b in protected.items());P={n:b for n,b in Pfull.items() if n not in protected}
# Owner-reported regressions; verified against the exact pre-correction consolidated tree.
owner_raw=zlib.decompress(base64.b64decode(b''.join(p.read_bytes() for p in sorted(pathlib.Path(__file__).parent.glob('owner-delta-*.b64'))),validate=True))
owner_sha=H(owner_raw);assert owner_sha=='8b00c8bf1f7748e830aef08b66fcca8e0eb11a1275ee81ccc518db3a3b3ae7ba'
for path,v in json.loads(owner_raw).items():
 c,n=path.split('/',1); files=L if c=='local' else P
 assert H(files[n])==v['before'],path
 lines=files[n].decode().splitlines(keepends=True)
 for a,b,s in reversed(v['edits']):lines[a:b]=[s]
 files[n]=''.join(lines).encode();assert H(files[n])==v['after'],path
source_sha=H((source_sha+':'+owner_sha).encode())
# Validated internal settings snapshot; same product bytes as focused run 45487427833.
ops_path='src/Domain/Orders/OrderStaffActionService.php'
assert H(L[ops_path])=='b6eebc43bf151ea17be35c57092ab0aae0918f4f20665243840fc99a57584b41'
old=b"        $this->actorAny($user,['orders_floor','cashier_accounts','shift_supervision']);\n        $keys=["
new=b"        $this->actorAny($user,['orders_floor','cashier_accounts','shift_supervision']);\n        return $this->printQuickPreferencesSnapshot();\n    }\n\n    /** Internal read-only settings projection; authenticated actions retain their role checks. */\n    public function printQuickPreferencesSnapshot(): array\n    {\n        $keys=["
assert L[ops_path].count(old)==1
L[ops_path]=L[ops_path].replace(old,new)
assert H(L[ops_path])=='9c6e1523bcb422af9dfd679cb615d70f8a45f8e5142b2a0d727dd3ace85e80e0'
source_sha=H((source_sha+':'+H(L[ops_path])).encode())
# Verified transactional discount invalidation: real HTTP root/subfolder 45488410331.
settlement_path='src/Domain/Finance/SettlementService.php'
assert H(L[settlement_path])=='e6d6eb2c196e05fd316df11d0d61d4a49e677b280f83182d4d543df3c76da4b9'
for kind,indent in [('granted',b'        '),('corrected',b'            '),('rebased',b'        ')]:
 marker=b"$this->audit('settlement.remaining_discount_"+kind.encode()+b"'"
 assert L[settlement_path].count(marker)==1
 L[settlement_path]=L[settlement_path].replace(marker,b'$this->invalidateRemainingDiscountProjectionTx($sessionId);\n'+indent+marker)
marker=b'    private function audit(string $action'
assert L[settlement_path].count(marker)==1
new=b"""    /** Publish the changed account atomically with its grant/revision; audit alone only invalidates reports. */
    private function invalidateRemainingDiscountProjectionTx(int $sessionId): void
    {
        if(!$this->pdo->inTransaction())throw new \\LogicException('Discount invalidation requires an open transaction.');
        $this->pdo->prepare('INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES(?,?,?,?)')
            ->execute(['settlement.remaining_discount_changed','session',(string)$sessionId,'operations,reports']);
    }

"""
L[settlement_path]=L[settlement_path].replace(marker,new+marker)
assert H(L[settlement_path])=='c82cdc68b960552fbbfbccfd389ec44cfa04886ab9ffc4735a05165bb4b9a385'
source_sha=H((source_sha+':'+H(L[settlement_path])).encode())


# Operations history and authorized cost display; exact three-file delta.
ui_raw=zlib.decompress(base64.b64decode(pathlib.Path(__file__).with_name('operations-ui.b64').read_bytes(),validate=True))
assert H(ui_raw)=='6a12ad1822a792b3091b27209b3943ef1bd4ff97f3c437e0cd109664d026dde1'
for path,v in json.loads(ui_raw).items():
 c,n=path.split('/',1); files=L if c=='local' else P
 assert H(files[n])==v['before'],path
 lines=files[n].decode().splitlines(keepends=True)
 for a,b,s in reversed(v['edits']):lines[a:b]=[s]
 files[n]=''.join(lines).encode();assert H(files[n])==v['after'],path
source_sha=H((source_sha+':'+H(ui_raw)).encode())
# Bundle quantity and full exact range report: verified real DB and browser 45494270795.
bundle_raw=zlib.decompress(base64.b64decode((pathlib.Path(__file__).parent.parent/'sokna-bundle-report'/'delta.b64').read_bytes(),validate=True))
assert H(bundle_raw)=='02daec6598c20460b7bd6e684977611a7eacfe006ee62ba97a735937a774057b'
for path,v in json.loads(bundle_raw).items():
 c,n=path.split('/',1); files=L if c=='local' else P
 assert H(files[n])==v['before'],path
 lines=files[n].decode().splitlines(keepends=True)
 for a,b,s in reversed(v['edits']):lines[a:b]=[s]
 files[n]=''.join(lines).encode();assert H(files[n])==v['after'],path
source_sha=H((source_sha+':'+H(bundle_raw)).encode())
# Current guest/order report quantities and stable hall card width.
for filename,sha in [('sokna-bundle-read/delta.b64','9f1e6d76cb4767678753a04eee332e91027766f9d2cb6af993b4d7c89cc57d64'),('sokna-hall-acceptance/stable-width.b64','6f077c321765c3c0a6db29f45737adc2dc104d4f6acdbc5f44a285db770cbfc7')]:
 raw=zlib.decompress(base64.b64decode((pathlib.Path(__file__).parent.parent/filename).read_bytes(),validate=True))
 assert H(raw)==sha
 for path,v in json.loads(raw).items():
  c,n=path.split('/',1); files=L if c=='local' else P
  assert H(files[n])==v['before'],path
  lines=files[n].decode().splitlines(keepends=True)
  for a,b,s in reversed(v['edits']):lines[a:b]=[s]
  files[n]=''.join(lines).encode();assert H(files[n])==v['after'],path
 source_sha=H((source_sha+':'+H(raw)).encode())
def identity(d,version,rel,origins):
 d['VERSION.txt']=(version+'\n').encode();i=json.loads(d['resources/release-identity-v1.json']);i.update(version=version,release_set_id=rel);i['baseline']['source_versions']=origins
 i['source'].update(managed_file_count=len(d),canonical_file_count=len(d)-2,canonical_tree_sha256=canonical(d),source_ref='Exact consolidated source delta SHA256 '+source_sha)
 i['qualification']={'tier':'EXACT_CANDIDATE_EXTERNAL_RECEIPT_REQUIRED','claim_rule':'Distribution requires successful exact-package admission; no automatic deployment.'};d['resources/release-identity-v1.json']=J(i)
 p=json.loads(d['resources/build-provenance.json']);p.update(version=version,release_set_id=rel,classification='CONTROLLED_CANDIDATE_EXTERNAL_RECEIPT_REQUIRED',source='sha256:'+source_sha,scope='Consolidated C2-Z, ordered auth, owner-reported menu/dashboard/sidebar/discount corrections',operator_warning='External exact gate receipt required',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False);d['resources/build-provenance.json']=J(p);assert i['source']['canonical_tree_sha256']==canonical(d)
m['supported_baselines'].append({'local':copy.deepcopy(m['targets']['local'])});lv='3.0.0-g6.2-workspace.231ge-consolidated-20261124';lr='sokna-local-231ge-consolidated-20261124';mi=json.loads(L['resources/local-managed-files-v1.json']);mi['files']=sorted(L);L['resources/local-managed-files-v1.json']=J(mi);identity(L,lv,lr,[x['local']['version'] for x in m['supported_baselines']])
migs=sorted(n for n in L if n.startswith('database/migrations/') and n.endswith('.sql'));assert len(migs)==92;head=migs[-1].split('/')[-1][:-4];assert head=='0092_public_auth_snapshot_clock';assert all(L[n]==L0[n] for n in migs[:-1]);fp='migration-ledger-sha256-v1:'+H(''.join(n.split('/')[-1][:-4]+'\0'+H(L[n])+'\n' for n in migs).encode());m['targets']['local'].update(version=lv,release_id=lr,managed_files=len(L),managed_tree_sha256=tree(L),migration_head=head,schema_fingerprint=fp);m.update(release_set_id=lr,sequence=202611240006,status='QUARANTINED_RELEASE_CANDIDATE');m['database']['local']['migration_dag'].append(head);m['qa_notes'].update(consolidated='Single exact candidate; external admission required',operator_install_allowed=False,verified_baselines_only=[x['local']['version'] for x in m['supported_baselines']]);web=pack(L);m['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web));(out/'LOCAL-FINAL-FROZEN.zip').write_bytes(pack({'release-set.json':J(m),'artifacts/local/web.zip':web}));(out/'LOCAL-FINAL-WEB.zip').write_bytes(web)
mp=sorted(n for n in P if n.startswith('database/migrations/') and n.endswith('.sql'));assert len(mp)==12 and all(P[n]==P0[n] for n in mp[:-1]);pm['compatibility']['supported_baselines'].append({'version':pm['version'],'managed_tree_sha256':pm['target_managed_tree_sha256']});pv='3.0.0-g3.6-workspace.35is-consolidated-20261124';pr='sokna-public-35is-consolidated-20261124';o=json.loads(P['src/Update/emergency-owner-v1.json']);o['release_version']=pv;assert o['sha256']==H(P['src/Update/EmergencyOwnerCandidate.php']);P['src/Update/emergency-owner-v1.json']=J(o);identity(P,pv,pr,[x['version'] for x in pm['compatibility']['supported_baselines']]);pm.update(version=pv,target_managed_tree_sha256=tree(P),files=[{'path':n,'size':len(v),'sha256':H(v)} for n,v in sorted(P.items())]);pm['provenance'].update(build='35is consolidated exact source',origin_package_sha256=H(pb),source_managed_tree_sha256=tree({n:b for n,b in P0.items() if n not in protected}),modified_files=sorted(n for n in P if P[n]!=P0.get(n)),qualification_qa_run='EXTERNAL_EXACT_GATE_RECEIPT_REQUIRED',release_qualification='CONTROLLED_OPERATOR_DISTRIBUTION_PENDING_EXACT_GATE');(out/'PUBLIC-FINAL-FROZEN.zip').write_bytes(pack({'manifest.json':J(pm)}|{'payload/'+n:v for n,v in P.items()}));(out/'PUBLIC-FINAL-WEB.zip').write_bytes(pack(P|protected))
receipt={'status':'QUARANTINED','source_delta_sha256':source_sha,'local_managed_files':len(L),'public_managed_files':len(P),'local_migrations':len(migs),'public_migrations':len(mp),'protected':{n:H(b) for n,b in protected.items()},'files':{p.name:H(p.read_bytes()) for p in out.glob('*.zip')},'distribution_authorized':False,'deployment_authorized':False};(out/'freeze-receipt.json').write_bytes(J(receipt));print(json.dumps(receipt,indent=2))
