#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,subprocess,sys,tempfile
from pathlib import Path

R=Path(__file__).resolve().parents[1]
L=R/'packaging/tools/lifecycle.py'
C=R/'packaging/manifests/compatibility-v1.json'

def fail(m): print(m,file=sys.stderr); raise SystemExit(1)
def need(v,m):
    if not v: fail(m)
def run(*args,ok=True):
    p=subprocess.run([sys.executable,str(L),*map(str,args)],cwd=R,text=True,capture_output=True)
    if ok and p.returncode: fail(f"command failed: {' '.join(map(str,args))}\n{p.stdout}\n{p.stderr}")
    if not ok and p.returncode==0: fail(f"command unexpectedly succeeded: {' '.join(map(str,args))}")
    return p

def ptr(root,comp): return json.loads((root/'active'/f'{comp}.json').read_text())['version']
def mk_source(root,comp,version):
    p=root/f'src-{comp}-{version}';p.mkdir();(p/'payload.txt').write_text(f'{comp}:{version}\n',encoding='utf-8');return p

def pkg(root,comp,version,contracts):
    source=mk_source(root,comp,version);out=root/f'pkg-{comp}-{version}'
    run('build','--component',comp,'--version',version,'--source',source,'--out',out,'--source-commit','m10-fixture','--contracts',json.dumps(contracts,separators=(',',':')))
    return out

def contracts(comp):
    return {
      'local': {'runtime_contract':'1.0.0','print_server_protocol':4},
      'public': {'local_public_contract':1},
      'runtime': {'runtime_contract':'1.0.0'},
      'print-agent': {'print_server_protocol':4,'loopback_protocol':1},
      'platform': {'php_version_id':80200,'pdo_mysql':True},
    }[comp]

with tempfile.TemporaryDirectory(prefix='sokna-m10-') as td:
    t=Path(td);root=t/'life';packages={}
    # clean install of all independently owned components
    for comp in ['local','public','runtime','print-agent','platform']:
        packages[(comp,'1.0.0')]=pkg(t,comp,'1.0.0',contracts(comp))
        run('stage','--root',root,'--package',packages[(comp,'1.0.0')],'--compat',C)
        run('activate','--root',root,'--component',comp,'--version','1.0.0','--compat',C)
    baseline={c:ptr(root,c) for c in ['local','public','runtime','print-agent','platform']}
    need(set(baseline.values())=={'1.0.0'},'clean install did not activate all components')

    # component-only upgrades must not move unrelated active pointers.
    for comp in ['local','public','runtime','print-agent']:
        before={c:ptr(root,c) for c in baseline}
        p=pkg(t,comp,'1.1.0',contracts(comp));run('stage','--root',root,'--package',p,'--compat',C);run('activate','--root',root,'--component',comp,'--version','1.1.0','--compat',C)
        for other in before:
            if other!=comp: need(ptr(root,other)==before[other],f'{comp}-only update moved {other}')
        run('rollback','--root',root,'--component',comp);need(ptr(root,comp)=='1.0.0',f'{comp} rollback failed')

    # platform failed activation auto-rolls back.
    p2=pkg(t,'platform','1.1.0',contracts('platform'));run('stage','--root',root,'--package',p2,'--compat',C)
    run('activate','--root',root,'--component','platform','--version','1.1.0','--compat',C,'--simulate-failure',ok=False)
    need(ptr(root,'platform')=='1.0.0','failed platform activation did not auto-rollback')

    # same-version repair restores immutable payload bytes.
    active_pkg=root/'staged'/'local'/'1.0.0';(active_pkg/'payload'/'payload.txt').write_text('corrupt\n',encoding='utf-8')
    run('repair','--root',root,'--package',packages[('local','1.0.0')],'--compat',C)
    need((active_pkg/'payload'/'payload.txt').read_text()=='local:1.0.0\n','same-version repair did not restore canonical package')

    # incompatible contract rejects before stage/activation.
    bad=pkg(t,'runtime','9.9.9',{'runtime_contract':'2.0.0'})
    run('stage','--root',root,'--package',bad,'--compat',C,ok=False)
    need(not (root/'staged'/'runtime'/'9.9.9').exists(),'incompatible package reached staged state')
    need(ptr(root,'runtime')=='1.0.0','incompatible package moved active runtime')

    # Recovery-set contract requires machine-bound identity exclusion and backup integrity.
    business=t/'business.skb';business.write_bytes(b'cipher-fixture')
    rec=t/'recovery.json';rec.write_text(json.dumps({
      'format':'sokna-recovery-set-v1','created_at':'2026-09-26T00:00:00Z',
      'business_backup':{'path':str(business),'sha256':hashlib.sha256(business.read_bytes()).hexdigest()},
      'excluded_machine_identity':['runtime_machine_secret','print_agent_identity','tls_private_key']
    }),encoding='utf-8')
    run('verify-recovery',rec)
    badrec=t/'bad-recovery.json';badrec.write_text(json.dumps({'format':'sokna-recovery-set-v1','business_backup':{'path':str(business),'sha256':'0'*64},'excluded_machine_identity':[]}),encoding='utf-8')
    run('verify-recovery',badrec,ok=False)

# release-lock must bind the exact provider candidate bytes.
provider=R/'platform/windows/provider-candidate.json'; lock=json.loads((R/'platform/windows/release-lock.json').read_text())
need(lock['app_version']==(R/'VERSION.txt').read_text().strip(),'release lock and VERSION.txt disagree')
need(lock['source_candidate_sha256']==hashlib.sha256(provider.read_bytes()).hexdigest(),'release lock does not bind current provider candidate')
need(lock.get('release_frozen') is True,'prerequisite release lock is not frozen')

# no stale V2/legacy Windows layout may remain in active packaging paths.
scan=[]
for base in [R/'packaging/windows',R/'platform/windows']:
    for p in base.rglob('*'):
        if p.is_file() and p.suffix.lower() in {'.ps1','.iss','.cs','.md','.json','.template'}:
            scan.append((p,p.read_text(encoding='utf-8',errors='ignore')))
for p,text in scan:
    for stale in ['runtime\\windows','installer\\windows','runtime/sokna-runtime.php','database/schema.sql']:
        need(stale not in text,f'legacy packaging path {stale} remains in {p.relative_to(R)}')
need((R/'platform/windows/remove-owned-services.ps1').is_file(),'owned-service uninstall cleanup is missing')
iss=(R/'packaging/windows/installer/SOKNA.iss').read_text(encoding='utf-8')
need('[UninstallRun]' in iss and 'remove-owned-services.ps1' in iss,'installer does not invoke owned-service cleanup')
prepare=(R/'packaging/windows/scripts/prepare-shell-payload.ps1').read_text(encoding='utf-8')
need("apps/local-web/" in prepare and "VERSION.txt" in prepare,'installer seed is not scoped to Local Web')
need('apps/public/' not in prepare,'Public component leaked into Local installer seed')
setup=(R/'platform/windows/setup-sokna.ps1').read_text(encoding='utf-8')
need("'--config'" in setup and 'runtime-config.json' in setup,'Runtime service setup still uses legacy arguments')
need('apps\\local-web\\public' in setup,'Apache document root is not canonical Local public root')

# Automated gate must keep real-device checks explicit rather than claiming them passed.
uat=json.loads((R/'release/manual-uat-status.json').read_text(encoding='utf-8'))
need(uat.get('format')=='sokna-manual-uat-v1','manual UAT status contract missing')
required={'windows_clean_install','physical_thermal_printer','responsive_touch_persian_ime'}
seen={x.get('id') for x in uat.get('checks',[])}
need(required.issubset(seen),'required manual UAT checks are not declared')
for x in uat['checks']:
    need(x.get('status') in {'pending','passed','failed'},'manual UAT has invalid status')

print('M10 automated release qualification: OK')
