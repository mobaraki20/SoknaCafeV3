#!/usr/bin/env python3
"""Run the actual embedded PHP helper against a disposable MariaDB only."""
import hashlib
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
if os.environ.get('SOKNA_SAFETY_DISPOSABLE')!='YES':
    raise SystemExit('DISPOSABLE_CI_ONLY')
DB='sokna_safety_preflight_ci'
env={**os.environ,'MYSQL_PWD':'ci-root-only'}
def sql(query):
    return subprocess.run(['mariadb','--protocol=tcp','-h127.0.0.1','-uroot','-N','-B','-e',query],
                          env=env,check=True,capture_output=True,text=True).stdout.strip()
def assert_ok(value,label):
    if not value:raise AssertionError(label)
    print('PASS',label,flush=True)
sql(f'DROP DATABASE IF EXISTS {DB}; CREATE DATABASE {DB}; USE {DB};'
    'CREATE TABLE schema_migrations(version VARCHAR(160) PRIMARY KEY) ENGINE=InnoDB;'
    "INSERT INTO schema_migrations VALUES('0001_base');"
    'CREATE TABLE schema_migration_statements(version VARCHAR(160),ordinal_no INT,state VARCHAR(16)) ENGINE=InnoDB;'
    'CREATE TABLE business_rows(id INT PRIMARY KEY, qty INT) ENGINE=InnoDB; INSERT INTO business_rows VALUES(1,10);')
try:
    with tempfile.TemporaryDirectory() as td:
        tmp=Path(td);root=tmp/'root';source=tmp/'old';target=tmp/'target';root.mkdir()
        for p in [source,target]:(p/'database/migrations').mkdir(parents=True)
        for p in [source,target]:(p/'database/migrations/0001_base.sql').write_text('SELECT 1;\n')
        (target/'database/migrations/0002_guard.sql').write_text('SELECT 1;\nUPDATE business_rows SET qty=20;\n')
        core=target/'src/Core';core.mkdir(parents=True)
        for name in ['Config','Database','Migrations','MigrationExecutionException']:
            shutil.copyfile(ROOT/f'apps/local-web/src/Core/{name}.php',core/f'{name}.php')
        def fingerprint(p):
            return 'migration-ledger-sha256-v1:'+hashlib.sha256(''.join(
                f.stem+'\0'+hashlib.sha256(f.read_bytes()).hexdigest()+'\n'
                for f in sorted((p/'database/migrations').glob('*.sql'))).encode()).hexdigest()
        plan=json.dumps({'SourceFingerprint':fingerprint(source),'TargetFingerprint':fingerprint(target)})
        helper=(ROOT/'windows/maintenance/src/Sokna.Maintenance.Core/RoutineLocalDatabase.php').read_text().removeprefix('<?php')
        dump=shutil.which('mariadb-dump') or shutil.which('mysqldump')
        assert_ok(dump is not None,'real dump executable available')
        def configure(port):
            cfg={'db':{'host':'127.0.0.1','port':port,'name':DB,'user':'root','pass':'ci-root-only','charset':'utf8mb4'}}
            (root/'config.php').write_text("<?php return json_decode('"+json.dumps(cfg)+"',true);\n")
        def run(mode='backup-preflight',tool=None,expected=0):
            p=subprocess.run(['php','-r',helper,str(root),str(source),str(target),mode,plan,
                              str(root/'never-created.sql'),tool or dump],capture_output=True,text=True)
            assert_ok(p.returncode==expected,f'{mode} exit={expected}')
            if expected==0:assert_ok(p.stdout=='SOKNA_DATABASE_OK' and p.stderr=='','bounded success response')
            assert_ok(not (root/'never-created.sql').exists() and not (root/'never-created.sql.credentials').exists(),
                      'no dump or credential files created')
        for port in [3306,'3306']:
            configure(port);before=(root/'config.php').read_bytes();run()
            assert_ok((root/'config.php').read_bytes()==before,'configuration remains byte exact')
        for port in ['not-a-port','3306\n',0,65536,True,3306.5]:
            configure(port);run(expected=81)
        configure('3306');run(tool=str(root/'missing-dump.exe'),expected=82)
        sql(f'USE {DB}; CREATE TABLE legacy_myisam(id INT) ENGINE=MyISAM;')
        run(expected=73)
        sql(f'USE {DB}; DROP TABLE legacy_myisam;')
        configure('3306');run()
        # A previously successful preflight must not authorize changed config.
        configure('invalid-now');run(mode='backup',expected=81)
        assert_ok(sql(f'USE {DB}; SELECT qty FROM business_rows; SELECT COUNT(*) FROM schema_migration_statements;')=='10\n0',
                  'business rows and migration journal unchanged')
        # M06 used an unescaped MariaDB reserved identifier. PHP lint alone
        # cannot detect this; exercise the actual SQL parser and corrected SQL.
        invalid=subprocess.run(['mariadb','--protocol=tcp','-h127.0.0.1','-uroot',DB,'-e',
                                'CREATE TABLE bad_clock(cursor BIGINT);'],env=env,capture_output=True)
        assert_ok(invalid.returncode!=0,'M06 unescaped cursor rejected by real MariaDB')
        sql(f'USE {DB}; CREATE TABLE good_clock(`cursor` BIGINT); INSERT INTO good_clock VALUES(1);')
        assert_ok(sql(f'USE {DB}; SELECT `cursor` FROM good_clock;')=='1','escaped cursor works on real MariaDB')
finally:
    sql(f'DROP DATABASE IF EXISTS {DB};')
print('BACKUP_PREFLIGHT_MARIADB_PASS_NO_HOST_QUALIFICATION')
