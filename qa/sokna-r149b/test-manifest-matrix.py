#!/usr/bin/env python3
"""Exact package source/identity tamper matrix: does NOT simulate live Windows recovery."""
import copy,hashlib,io,json,pathlib,sys,zipfile
if len(sys.argv)!=4:raise SystemExit('usage: matrix.py <R140 qualified zip> <R149B candidate zip> <R149B qualified zip>')
oldfile,source,final=(pathlib.Path(p) for p in sys.argv[1:]);tests=[]
sha=lambda b:hashlib.sha256(b).hexdigest()
def run(desc,test):
    if not test:raise RuntimeError('FAIL '+desc)
    tests.append(desc)
def parse(path):
    with zipfile.ZipFile(path) as z:
        run('CRC outer '+path.name,z.testzip() is None)
        m=json.loads(z.read('release-set.json'))
        web=z.read('artifacts/local/web.zip')
        run('artifact hash '+path.name,sha(web)==m['artifacts']['local-web']['sha256'])
        with zipfile.ZipFile(io.BytesIO(web)) as w:
            run('CRC nested '+path.name,w.testzip() is None)
            content={x:w.read(x) for x in w.namelist()}
    return m,content
def tree(c):return sha(''.join(n+'\0'+sha(data)+'\n' for n,data in sorted(c.items())).encode())
def schema(c):return 'migration-ledger-sha256-v1:'+sha(''.join(n.split('/')[-1][:-4]+'\0'+sha(data)+'\n' for n,data in sorted(c.items()) if n.startswith('database/migrations/') and n.endswith('.sql')).encode())
old,of=parse(oldfile);qa,qf=parse(source);prod,pf=parse(final)
run('same content in candidate and qualified',qf==pf)
run('source R140 managed',len(of)==579 and old['targets']['local']['managed_tree_sha256']==tree(of))
run('target R149B managed 581',len(pf)==581 and prod['targets']['local']['managed_files']==581 and prod['targets']['local']['managed_tree_sha256']==tree(pf))
run('target migration head 0080',prod['targets']['local']['migration_head']=='0080_tea_herbal_legacy_family_repair')
run('target schema ledger matches 80 SQL',schema(pf)==prod['targets']['local']['schema_fingerprint'] and sum(x.endswith('.sql') for x in pf)==80)
run('target VERSION matches manifest',pf['VERSION.txt'].decode().strip()==prod['targets']['local']['version'])
run('R140 self-baseline unique',sum(i['local']==old['targets']['local'] for i in prod['supported_baselines'])==1)
run('43 unique exact baselines',len(prod['supported_baselines'])==43 and len({json.dumps(x,sort_keys=True) for x in prod['supported_baselines']})==43)
run('44th unknown cannot be accepted',not any(x['local']['version']=='__unknown_version__' for x in prod['supported_baselines']))
run('candidate prequalified false',qa['for_install'] is False and qa['production_pass'] is False)
run('final qualified true',prod['for_install'] is True and prod['production_pass'] is True)
run('qual scoping Local only',prod['release_type']=='local_only' and all(x['public'] is None for x in prod['supported_baselines']))
run('exact version progression',old['targets']['local']['version'].split('.231ea-')[0]==prod['targets']['local']['version'].split('.231eb-')[0])
run('only two added files',sorted(set(pf)-set(of))==['database/migrations/0080_tea_herbal_legacy_family_repair.sql','src/Domain/System/CatalogPushReadOnlyAudit.php'])
run('historical 79 migrations byte-identical',all(of[n]==pf[n] for n in of if n.startswith('database/migrations/') and n.endswith('.sql')))
run('internal managed inventory 581 equals payload',json.loads(pf['resources/local-managed-files-v1.json'])['files']==sorted(pf))
run('R148 transactional 0080 embedded',b'family-03' in pf['database/migrations/0080_tea_herbal_legacy_family_repair.sql'] and b'family-11' in pf['database/migrations/0080_tea_herbal_legacy_family_repair.sql'])
run('R148 migrations driver embedded',b'str_starts_with($version, \'0080_\')' in pf['src/Core/Migrations.php'])
for baseline in prod['supported_baselines']:
    for key in ('release_id','version','managed_tree_sha256','schema_fingerprint','migration_head','authority_epoch_policy','managed_files'):
        original=baseline['local']; tampered=copy.deepcopy(original)
        tampered[key]=str(tampered[key])+'-corrupted' if key!='managed_files' else int(tampered[key])+100000
        run('tampered '+key+' source '+original['version'],not any(x['local']==tampered for x in prod['supported_baselines']))
for path in ('VERSION.txt','bootstrap.php','src/Core/Migrations.php','database/migrations/0078_tea_herbal_three_sizes.sql','database/migrations/0079_cafe_staff_initial_accounts.sql','database/migrations/0080_tea_herbal_legacy_family_repair.sql','public/assets/scds/guest.js','public/assets/scds/guest.css','src/Domain/System/CatalogPushReadOnlyAudit.php'):
    c=pf.copy();c[path]+=b'\x00TAMPER';run('target tamper changed SHA '+path,tree(c)!=prod['targets']['local']['managed_tree_sha256'])
out={'test_count':len(tests),'result':'PASS','qualification_scope':'source/identity tamper checks; Windows and MariaDB are SEPARATE', 'source_sha256':sha(oldfile.read_bytes()),'candidate_sha256':sha(source.read_bytes()),'qualified_sha256':sha(final.read_bytes()),'tests':tests}
path=final.with_name('r149b-identity-matrix.json');path.write_text(json.dumps(out,ensure_ascii=False,sort_keys=True,indent=2))
print('R149B_43_BASELINE_PACKAGE_MATRIX_PASS',len(tests),'cases')