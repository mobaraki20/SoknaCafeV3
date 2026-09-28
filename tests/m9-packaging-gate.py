#!/usr/bin/env python3
from __future__ import annotations
import json, subprocess, sys, tempfile
from pathlib import Path

R=Path(__file__).resolve().parents[1]
L=R/'packaging/tools/lifecycle.py'
C=R/'packaging/manifests/compatibility-v1.json'

def need(v,m):
    if not v:
        print(m,file=sys.stderr); raise SystemExit(1)

def run(*a,ok=True):
    p=subprocess.run([sys.executable,str(L),*map(str,a)],cwd=R,text=True,capture_output=True)
    if ok and p.returncode:
        print(p.stdout,p.stderr,file=sys.stderr); raise SystemExit(1)
    if not ok and p.returncode==0:
        print('command unexpectedly succeeded:',*a,file=sys.stderr); raise SystemExit(1)
    return p

# Preserve immutable generic lifecycle semantics independently of user-facing Windows composition.
with tempfile.TemporaryDirectory(prefix='sokna-m9-') as td:
    t=Path(td); src=t/'src'; src.mkdir(); (src/'a.txt').write_text('one',encoding='utf-8')
    p1=t/'p1'; run('build','--component','runtime','--version','1.0.0','--source',src,'--out',p1,'--source-commit','abc','--contracts','{"runtime_contract":"1.0.0"}')
    run('verify',p1); root=t/'root'; run('stage','--root',root,'--package',p1,'--compat',C); run('activate','--root',root,'--component','runtime','--version','1.0.0','--compat',C)
    src.joinpath('a.txt').write_text('two',encoding='utf-8'); p2=t/'p2'; run('build','--component','runtime','--version','1.0.1','--source',src,'--out',p2,'--source-commit','def','--contracts','{"runtime_contract":"1.0.0"}')
    run('stage','--root',root,'--package',p2,'--compat',C)
    fail=run('activate','--root',root,'--component','runtime','--version','1.0.1','--compat',C,'--simulate-failure',ok=False)
    need(fail.returncode!=0,'simulated activation failure did not fail')
    active=json.loads((root/'active/runtime.json').read_text(encoding='utf-8')); need(active['version']=='1.0.0','failed activation did not rollback active pointer')
    run('activate','--root',root,'--component','runtime','--version','1.0.1','--compat',C); run('rollback','--root',root,'--component','runtime')
    need(json.loads((root/'active/runtime.json').read_text(encoding='utf-8'))['version']=='1.0.0','manual rollback failed')

# Final architecture: Windows setup owns Runtime + Print Agent service lifecycle only.
compat=json.loads((R/'packaging/windows/windows-services-compatibility-v1.json').read_text(encoding='utf-8'))
need(compat['format']=='sokna-windows-services-compatibility-v1','Windows Services compatibility format missing')
need(set(compat['components'])=={'runtime','print-agent'},'Windows Services package must own Runtime + Print Agent only')
need(compat['external_infrastructure']['installer_ownership'] is False,'external infrastructure ownership fence missing')
need({'local-web','public-edge','php','apache','mariadb','business-data'}.issubset(set(compat['forbidden_payload_ownership'])),'forbidden payload ownership incomplete')

setup=(R/'packaging/windows/setup-ui/Program.cs').read_text(encoding='utf-8')
host=(R/'packaging/windows/setup-host/Program.cs').read_text(encoding='utf-8')
iss=(R/'packaging/windows/installer/SOKNA.iss').read_text(encoding='utf-8')
prepare=(R/'packaging/windows/scripts/prepare-shell-payload.ps1').read_text(encoding='utf-8')
bridge=(R/'packaging/windows/scripts/setup-windows-services.ps1').read_text(encoding='utf-8')

for stale in ['admin_password','db_host','recovery_file','apps/local-web','apps\\local-web']:
    need(stale.lower() not in (setup+'\n'+host+'\n'+iss+'\n'+prepare).lower(),f'Windows Services composition still owns Local/Recovery concern: {stale}')
need('SoknaAppPayload.zip' not in iss,'Inno still bundles legacy Local Web payload')
need('SOKNA Windows Services' in iss and 'SoknaSetupUi.exe' in iss,'Windows Services installer identity/composition incomplete')
need('remove-windows-services.ps1' in iss and '[UninstallRun]' in iss,'Windows Services uninstall cleanup missing')
need('business_data_mutated=$false' in bridge and 'external_infrastructure_mutated=$false' in bridge,'service lifecycle ownership fences missing')
need('php.exe' not in bridge.lower() and 'apache' not in bridge.lower() and 'mariadb' not in bridge.lower(),'service lifecycle mutates external infrastructure')

# ADR-0004 hard removal: historical Local/Apache ownership scripts must not return.
for legacy in [
    'platform/windows/setup-sokna.ps1','platform/windows/configure-apache.ps1','platform/windows/provision-local-https.ps1',
    'platform/windows/remove-owned-services.ps1','packaging/windows/scripts/deploy-seed.ps1','packaging/windows/scripts/prepare-prerequisite-bundle.ps1'
]:
    need(not (R/legacy).exists(),f'legacy deployment ownership implementation remains: {legacy}')

print('M9 revised immutable packaging/lifecycle gate passed.')
