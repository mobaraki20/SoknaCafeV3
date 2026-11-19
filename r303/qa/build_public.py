from pathlib import Path
from zipfile import ZipFile,ZipInfo,ZIP_DEFLATED
import hashlib,json,io,copy,os
SHA=lambda b:hashlib.sha256(b).hexdigest()
WORK=Path(os.environ['R303_CI_WORK']);ROOT=WORK/'public';OUT=WORK/'out';OUT.mkdir(parents=True,exist_ok=True);BASE=Path(os.environ['R303_CI_BASE_PUBLIC'])
with ZipFile(BASE) as z:
 old_manifest=json.loads(z.read('manifest.json'));old={p.removeprefix('payload/'):z.read(p) for p in z.namelist() if p.startswith('payload/') and not p.endswith('/')}
new={p.relative_to(ROOT).as_posix():p.read_bytes() for p in ROOT.rglob('*') if p.is_file()}
assert len(old)==len(new)==244,(len(old),len(new))
assert set(old)==set(new)
assert all(SHA(old[x['path']])==x['sha256'] and len(old[x['path']])==x['size'] for x in old_manifest['files'])
TREE=lambda d:SHA(''.join(f'{p}\0{SHA(v)}\n' for p,v in sorted(d.items())).encode())
assert TREE(old)==old_manifest['target_managed_tree_sha256']=='91d27d4ddb0fdd0640227b8efed461cde94c7efc3c578d52623056dcb859d57c'
changed=sorted(k for k in old if old[k]!=new[k]);print('Public changed files:',changed)
assert changed==['VERSION.txt','assets/product-ui.css','assets/staff-app/order-draft-workspace.js','assets/staff-app/preparation-workspace.js','src/Update/emergency-owner-v1.json']
assert not any('migration' in x or x.startswith('src/Emergency/') or x in ('emergency.php','.htaccess','bootstrap.php','index.php') for x in changed)
manifest=copy.deepcopy(old_manifest);manifest['version']=new['VERSION.txt'].decode().strip();manifest['compatibility']={'supported_baselines':[{'version':old_manifest['version'],'managed_tree_sha256':TREE(old)}]};manifest['target_managed_tree_sha256']=TREE(new)
manifest['files']=[{'path':p,'size':len(b),'sha256':SHA(b)} for p,b in sorted(new.items())]
manifest['provenance']={
 'build':'R303 Public preparation UX-only Emergency candidate from exact R275 (35ij); no DB or Emergency owner code touched',
 'emergency_schema_migration_contract':'code_only_source_exact_v1',
 'offline_qualified':False,
 'strict_multibaseline_v1':True,
 'host_operator_note':'QA ONLY DO NOT INSTALL; requires PHP MariaDB Emergency Stage/Activate/Commit and real cafe preflight',
 'source_package_sha256':SHA(BASE.read_bytes()),
 'source_managed_tree_sha256':TREE(old),
 'modified_files':changed
}
JB=lambda v:(json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
by={'manifest.json':JB(manifest),**{'payload/'+p:b for p,b in new.items()}}
path=OUT/'SOKNA-R303-PUBLIC-35IL-EMERGENCY-UI-COMPRESSED-QA-NOT-INSTALL.zip'
with ZipFile(path,'w') as z:
 for name,b in sorted(by.items()):
  i=ZipInfo(name,(2026,11,20,0,0,0));i.compress_type=ZIP_DEFLATED;i.create_system=3;i.external_attr=0o100644<<16
  z.writestr(i,b,compress_type=ZIP_DEFLATED,compresslevel=9)
with ZipFile(path) as z:
 assert z.testzip() is None
 assert path.stat().st_size<2*1024*1024,'PUBLIC_PHP_UPLOAD_MAX_2M_EXCEEDED'
 d=json.loads(z.read('manifest.json'))
 assert d['target_managed_tree_sha256']==TREE({p.removeprefix('payload/'):z.read(p) for p in z.namelist() if p.startswith('payload/')})
 assert all(x['size']==len(z.read('payload/'+x['path'])) and x['sha256']==SHA(z.read('payload/'+x['path'])) for x in d['files'])
 assert d['compatibility']['supported_baselines'][0]['managed_tree_sha256']==TREE(old)
meta={'name':path.name,'sha256':SHA(path.read_bytes()),'source_sha256':SHA(BASE.read_bytes()),'baseline':old_manifest['version'],'target':manifest['version'],'source_tree':TREE(old),'target_tree':TREE(new),'files':len(new),'changed_files':changed,'emergency_protected_code_changed':False,'database_migrations_changed':False,'state':'QA_ONLY_NOT_INSTALL','browser_qa':'PASS','mariadb_emergency_integration':'NOT_RUN','live_host_preflight':'NOT_RUN'}
(OUT/'SOKNA-R303-PUBLIC-EMERGENCY-VERIFY.json').write_text(json.dumps(meta,ensure_ascii=False,indent=2)+'\n')
print('Created',path,'SHA256',meta['sha256'], 'target tree',meta['target_tree'])
