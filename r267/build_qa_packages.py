#!/usr/bin/env python3
"""Versioned OFFLINE-QA recovery artifacts; does not change live installations."""
from pathlib import Path
import zipfile,hashlib,json,io,datetime,os
R=Path(os.environ.get('SOKNA_INPUT_ROOT','/mnt/data/public-go-live-audit'))
W=Path(os.environ.get('SOKNA_FIX_ROOT','/mnt/data/sokna_public_emergency_fix'))
O=W/'output';O.mkdir(exist_ok=True)
ORIG249=R/'SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip'
ORIG262=R/'SOKNA-R262-PUBLIC-35ID-CUMULATIVE-QA-NOT-FOR-INSTALL.zip'
ORIG_249_SHA='5c887968b6fcec1126e2b1b3123552a9ba0ba2c7672d29774c05ec58fa5cd0e4'
ORIG_262_SHA='5aef4cd781fbd450d3e454f324e294da6f4180bfe1e5eb1871cb4818dc20c93a'
V249='3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate'
V265='3.0.0-g3.6-workspace.35if-r265-emergency-schema-bridge'
V266='3.0.0-g3.6-workspace.35ig-r266-public-move-schema-recovery'
H=lambda b:hashlib.sha256(b).hexdigest()
def load(p,expected):
 assert H(p.read_bytes())==expected, (p,H(p.read_bytes()))
 with zipfile.ZipFile(p) as z:
  m=json.loads(z.read('manifest.json'));d={n[len('payload/'):]:z.read(n) for n in z.namelist() if n.startswith('payload/') and not n.endswith('/')}
 assert len(d)==len(m['files']);assert all(H(d[e['path']])==e['sha256'] for e in m['files'])
 return d,m
P,mp=load(ORIG249,ORIG_249_SHA)
T,mt=load(ORIG262,ORIG_262_SHA)
assert len(P)==228 and len(T)==244
assert P['VERSION.txt'].decode().strip()==V249
# Show canonical tree identity precisely as RoutinePublicUpdateService.
def tree(d):
 raw=b''.join(path.encode()+b'\0'+H(contents).encode()+b'\n' for path,contents in sorted(d.items()))
 return H(raw)
assert tree(P)=='1a5d041fd63ec5425ed9f01f0eff63c86c90c5c76f26530b773784c74884c928',tree(P)
assert tree(T)=='3457021783e481a319a145413f804209d2a57e5babf074ac4e9fa41052b84ef1',tree(T)
# Require that existing migrations and all non-scoped code really are pinned.
assert all(P[k]==T[k] for k in P if k.startswith('database/migrations/'))
def J(x):return (json.dumps(x,sort_keys=True,separators=(',',':'),ensure_ascii=False)+'\n').encode()
def setmeta(d,version,release_name,base,mutations):
 d=dict(d)
 d['VERSION.txt']=(version+'\n').encode()
 # The historical Emergency owner is protected; keep its pinned candidate code,
 # rebind the metadata to the version so it stays available after activation.
 rel='src/Update/emergency-owner-v1.json';em=json.loads(d[rel]);em['release_version']=version
 assert H(d['src/Update/EmergencyOwnerCandidate.php'])==em['sha256'];d[rel]=J(em)
 provenance=json.loads(d['resources/build-provenance.json'])
 provenance.update({'version':version,'release_set_id':release_name,'source':'R249 exact immutable baseline / R262 exactly traced cumulative source',
    'classification':'SOKNA_SCHEMA_EMERGENCY_RECOVERY_QA_NOT_FOR_PRODUCTION',
    'production_host_install_verified':False,'target_host_install_verified':False,
    'installed_host_accepted':False,'current_cafe_backup_restored_in_lab':False,
    'full_business_acceptance':False,'qa_only_schema_compatibility':True,
    'schema_migrations':mutations,'operator_warning':'DO NOT ACTIVATE UNTIL QUALIFIED ON LIVE HOST AND VERIFIED BACKUP'})
 d['resources/build-provenance.json']=J(provenance)
 ident=json.loads(d['resources/release-identity-v1.json']);ident['release_set_id']=release_name
 ident['baseline']['source_versions']=[base]
 ident['qualification']['tier']='R265_R266_EMERGENCY_SCHEMA_QA_NOT_FOR_HOST'
 ident['source']['source_ref']='R249 exact operator ZIP plus additive R262 source; owner remains stable' if 'source_ref' in ident['source'] else None
 d['resources/release-identity-v1.json']=J(ident)
 return d
patch=setmeta(P,V265,'sokna-r265-emergency-schema-bridge-qa',V249,[])
patch['src/Update/IndependentEmergencyRoutineBridge.php']=(W/'source/IndependentEmergencyRoutineBridge.php').read_bytes()
final=setmeta(T,V266,'sokna-r266-public-move-schema-recovery-qa',V265,['0009_public_move_owner_setup','0010_public_move_owner_receipts','0011_public_move_intake_pause'])
final['src/Update/IndependentEmergencyRoutineBridge.php']=patch['src/Update/IndependentEmergencyRoutineBridge.php']
final['src/Update/RoutinePublicUpdateService.php']=(W/'source/RoutinePublicUpdateService.php').read_bytes()
final['src/Core/ResumablePublicSchema.php']=(W/'source/ResumablePublicSchema.php').read_bytes()
# A secondary module version metadata might include old app version but is not authoritative.
def finalize_identity(d):
 ident=json.loads(d['resources/release-identity-v1.json'])
 exclude=set(ident['source'].get('digest_excludes',[]))
 # Avoid any self-referential digest: manifest always hashes the identity file itself.
 exclude.update(['resources/build-provenance.json','resources/release-identity-v1.json'])
 ident['source']['canonical_file_count']=len([k for k in d if k not in exclude]);ident['source']['canonical_tree_sha256']=tree({k:v for k,v in d.items() if k not in exclude})
 d['resources/release-identity-v1.json']=J(ident)
finalize_identity(patch);finalize_identity(final)
# Unique source baseline allowlist and digest: no R249 direct -> R266 bypass of old Emergency.
def pack(d,path,version,allow,desc):
 m={'component':'public-edge','format':'sokna-component-package-v1','schema_version':1,'version':version,
    'contracts':{'local_public_contract':1},'compatibility':{'supported_baselines':[{'version':v,'managed_tree_sha256':h} for v,h in allow]},
    'files':[{'path':k,'size':len(v),'sha256':H(v)} for k,v in sorted(d.items())],
    'target_managed_tree_sha256':tree(d),
    'provenance':{'build':desc,'strict_multibaseline_v1':True,'offline_qualified':False,
     'host_operator_note':'QA ONLY: schema-aware Emergency end-to-end MariaDB/host acceptance and restore test required',
     'emergency_schema_migration_contract':'append_only_sha256_pending_exact_v1'}}
 with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
  for name,contents in [('manifest.json',J(m)),*[(f'payload/{k}',v) for k,v in sorted(d.items())]]:
   info=zipfile.ZipInfo(name,date_time=(2026,11,16,20,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
   z.writestr(info,contents,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 with zipfile.ZipFile(path) as z:
  assert z.testzip() is None
  m2=json.loads(z.read('manifest.json'))
  assert H(z.read('payload/VERSION.txt'))==H(d['VERSION.txt'])
  assert m2['target_managed_tree_sha256']==tree(d)
 return {'name':path.name,'sha256':H(path.read_bytes()),'bytes':path.stat().st_size,'version':version,'target_tree_sha256':tree(d),'managed_files':len(d),'migration_files':sum(x.startswith('database/migrations/') for x in d),'baselines':allow}
rows=[pack(patch,O/'SOKNA-R265-PUBLIC-35IF-EMERGENCY-BRIDGE-CODE-ONLY-QA.zip',V265,[(V249,tree(P))],'r265-safe-bridge-compatibility-from-exact-r249'),
      pack(final,O/'SOKNA-R266-PUBLIC-35IG-SCHEMA-MOVE-CUMULATIVE-QA.zip',V266,[(V265,tree(patch))],'r266-forward-migration-from-r265-bridge-only')]
assert all(P[k]==patch[k] for k in P if k.startswith('database/migrations/'))
assert all(T[k]==final[k] for k in T if k.startswith('database/migrations/'))
assert all(T[k]==final[k] for k in T if k not in {
    'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json','src/Update/emergency-owner-v1.json',
    'src/Update/IndependentEmergencyRoutineBridge.php','src/Update/RoutinePublicUpdateService.php','src/Core/ResumablePublicSchema.php'})
(O/'R265-R266-OFFLINE-MANIFEST.json').write_bytes(J({'status':'QA_ONLY_NO_HOST_INSTALL',
    'source_249_sha256':ORIG_249_SHA,'source_262_sha256':ORIG_262_SHA,
    'stable_emergency_owner_unchanged':True,'no_old_sql_rewrites':True,'release_sequence':rows}))
print(json.dumps(rows,indent=2))