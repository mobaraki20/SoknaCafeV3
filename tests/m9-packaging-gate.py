from pathlib import Path
import tempfile,json,subprocess,hashlib,sys,os
R=Path(__file__).resolve().parents[1]
L=R/'packaging/tools/lifecycle.py'; C=R/'packaging/manifests/compatibility-v1.json'
def need(x,m):
    if not x: print(m,file=sys.stderr); raise SystemExit(1)
def run(*a,ok=True):
    p=subprocess.run([sys.executable,str(L),*map(str,a)],text=True,capture_output=True)
    if ok and p.returncode: print(p.stdout,p.stderr,file=sys.stderr); raise SystemExit(1)
    return p
with tempfile.TemporaryDirectory() as td:
    t=Path(td); src=t/'src';src.mkdir();(src/'a.txt').write_text('one');(src/'nested').mkdir();(src/'nested/b.txt').write_text('two')
    p1=t/'p1'; run('build','--component','runtime','--version','1.0.0','--source',src,'--out',p1,'--source-commit','abc','--contracts','{"runtime_contract":"1.0.0"}')
    run('verify',p1); root=t/'root';run('stage','--root',root,'--package',p1,'--compat',C);run('activate','--root',root,'--component','runtime','--version','1.0.0','--compat',C)
    src.joinpath('a.txt').write_text('v2');p2=t/'p2';run('build','--component','runtime','--version','1.0.1','--source',src,'--out',p2,'--source-commit','def','--contracts','{"runtime_contract":"1.0.0"}');run('stage','--root',root,'--package',p2,'--compat',C)
    fail=run('activate','--root',root,'--component','runtime','--version','1.0.1','--compat',C,'--simulate-failure',ok=False);need(fail.returncode!=0,'simulated activation failure did not fail')
    active=json.loads((root/'active/runtime.json').read_text());need(active['version']=='1.0.0','failed activation did not rollback active pointer')
    run('activate','--root',root,'--component','runtime','--version','1.0.1','--compat',C);run('repair','--root',root,'--package',p2,'--compat',C);run('rollback','--root',root,'--component','runtime');active=json.loads((root/'active/runtime.json').read_text());need(active['version']=='1.0.0','manual rollback failed')
    backup=t/'business.skbf';backup.write_bytes(b'business-data');rec=t/'recovery.json';rec.write_text(json.dumps({'format':'sokna-recovery-set-v1','created_at':'2026-09-26T00:00:00Z','business_backup':{'path':str(backup),'sha256':hashlib.sha256(backup.read_bytes()).hexdigest()},'source_installation_id':'old-machine','excluded_machine_identity':['runtime_machine_secret','print_agent_identity','tls_private_key']}));run('verify-recovery',rec)
    bad=json.loads(rec.read_text());bad['excluded_machine_identity'].remove('tls_private_key');rec.write_text(json.dumps(bad));need(run('verify-recovery',rec,ok=False).returncode!=0,'Recovery accepted machine TLS identity')
setup=(R/'packaging/windows/setup-ui/Program.cs').read_text();host=(R/'packaging/windows/setup-host/Program.cs').read_text();iss=(R/'packaging/windows/installer/SOKNA.iss').read_text();bridge=(R/'packaging/windows/scripts/lifecycle-owner.ps1').read_text()
for token in ['new','recover','repair']: need(token in setup.lower(),'Setup UI missing '+token)
need('powershell' in host.lower(),'Setup Host no longer composes internal lifecycle helpers')
need('SOKNA-Setup-' in iss and 'SoknaSetupUi.exe' in iss and '{#SourceRoot}\\*' in iss,'single Setup shell source incomplete')
need('SoknaSetupHost.exe' in host and 'payload-manifest.json' in host,'Setup Host is not bound to immutable shell manifest')
need('wix' not in iss.lower(),'V3 Setup revived WiX')
need('business_data_mutated=$false' in bridge,'Repair/Update bridge does not fence business data ownership')
compat=json.loads(C.read_text());need(compat['activation_rule'].startswith('reject-before-activate'),'compatibility is not pre-activation')
print('M9 immutable packaging/lifecycle gate passed.')
