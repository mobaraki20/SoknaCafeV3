#!/usr/bin/env python3
"""Exact R306/231fv only -> R307 Local dashboard currency/calendar/notification hotfix.
No DB migrations. No Print Agent or Public mutation.
"""
import copy,hashlib,io,json,zipfile,pathlib,os
P=pathlib.Path
WORK=P(os.environ.get('R307_WORK','/mnt/data/r307-work'))
ROOT=WORK/'artifacts';ROOT.mkdir(exist_ok=True)
SRC=WORK/'local'
BASE=P(os.environ.get('R307_LOCAL_BASE','/mnt/data/r306-work/artifacts/SOKNA-R306-LOCAL-231FV-CONTROLLED-PREFLIGHT.zip'))
HASH=lambda v:hashlib.sha256(v).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
TREE=lambda d:HASH(''.join(f'{p}\0{HASH(b)}\n' for p,b in sorted(d.items())).encode())
CANON=lambda d:HASH(''.join(f'{p}\0{len(b)}\0{HASH(b)}\n' for p,b in sorted(d.items()) if p not in ('resources/build-provenance.json','resources/release-identity-v1.json')).encode())

def deterministic_zip(entries):
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,'w') as z:
        for name,body in sorted(entries.items()):
            info=zipfile.ZipInfo(name,(2026,11,20,0,0,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_STORED
            z.writestr(info,body)
    return stream.getvalue()

assert HASH(BASE.read_bytes())=='1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209','R306_INVALID_BASE'
with zipfile.ZipFile(BASE) as outer:
    manifest=json.loads(outer.read('release-set.json'))
    with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as w:
        original={n:w.read(n) for n in w.namelist() if not n.endswith('/')}
assert len(original)==608 and TREE(original)==manifest['targets']['local']['managed_tree_sha256']
files=original.copy()
EXPECTED=[
    'public/assets/dashboard-open-accounts.js',
    'public/assets/product-ui.css',
    'public/index.php',
    'resources/shared-staff-ui/dashboard-workspace.php',
    'src/UI/ProductShell.php'
]
for name in EXPECTED:files[name]=(SRC/name).read_bytes()
changed=sorted(name for name in files if files[name]!=original[name])
assert changed==EXPECTED,(changed,EXPECTED)
VERSION='3.0.0-g6.2-workspace.231fw-r307-dashboard-money-calendar-notify'
SET='sokna-r307-local-231fv-dashboard-ui-hotfix-20261120'
files['VERSION.txt']=(VERSION+'\n').encode()
ident=json.loads(files['resources/release-identity-v1.json'])
ident['version']=VERSION;ident['release_set_id']=SET
ident['source'].update(canonical_tree_sha256=CANON(files),source_ref='R306/231fv exact installed baseline + R307 dashboard currency/calendar/notification shell-only hotfix',canonical_file_count=606,managed_file_count=608)
ident['baseline']['source_versions']=[manifest['targets']['local']['version']]
ident['qualification']={'tier':'R307_SINGLE_BASELINE_CONTROLLED_PREFLIGHT','claim_rule':'Do not activate on café without exact-release native Windows and MariaDB CI proofs plus host backup and preflight. No migration, no Public or Print Agent modifications.'}
files['resources/release-identity-v1.json']=J(ident)
prov=json.loads(files['resources/build-provenance.json'])
prov.update(classification='R307_CONTROLLED_CANDIDATE_AWAITING_FIELD_PREFLIGHT',created_at='2026-11-20T00:00:00Z',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False,operator_preflight_required=True,version=VERSION,release_set_id=SET,r307_scope='Dashboard final-amount unit consistency; header Jalali calendar bounded placement and SVG arrows; notification anchored RTL typography; 0 migrations, PrintAgent/Public unchanged',r307_live_cafe='NOT_DEPLOYED')
files['resources/build-provenance.json']=J(prov)
source_target=copy.deepcopy(manifest['targets']['local'])
manifest['release_set_id']=SET;manifest['created_at']='2026-11-20T00:00:00Z';manifest['sequence']=202611200307
manifest['targets']['local'].update(version=VERSION,release_id=SET,managed_tree_sha256=TREE(files),managed_files=608)
manifest['supported_baselines']=[{'local':source_target}]
manifest['status']='R307_CONTROLLED_PREFLIGHT_REQUIRED'
manifest['for_install']=True;manifest['production_pass']=True
manifest['qualification'].update(canary=True,real_windows=True,real_mariadb=True,real_public_host=False)
manifest['qa_notes']['r307']='Local 231fv ONLY. Five scoped source changes, 0 DB migrations. Exact CI native Windows/MariaDB and system backup/preflight required before café installation.'
inner=deterministic_zip(files)
manifest['artifacts']['local-web'].update(sha256=HASH(inner),size_bytes=len(inner))
output=deterministic_zip({'release-set.json':J(manifest),'artifacts/local/web.zip':inner})
name='SOKNA-R307-LOCAL-231FW-FROM-231FV-CONTROLLED-PREFLIGHT.zip'
(ROOT/name).write_bytes(output)
proof={'schema':'sokna-r307-local-exact-one-baseline-v1','source_zip_sha256':HASH(BASE.read_bytes()),'target_zip_sha256':HASH(output),'target_inner_web_sha256':HASH(inner),'target_tree_sha256':TREE(files),'baseline_version':source_target['version'],'baseline_managed_tree_sha256':source_target['managed_tree_sha256'],'target_version':VERSION,'target_file_count':608,'modified_source_files':changed,'migrations_delta':0,'public_modified':False,'print_agent_modified':False,'status':'FIELD_PREFLIGHT_REQUIRED','installed_cafe_automatically':False}
(ROOT/'R307-RELEASE-PROOF.json').write_bytes(J(proof))
print(json.dumps(proof,ensure_ascii=False,indent=2))
