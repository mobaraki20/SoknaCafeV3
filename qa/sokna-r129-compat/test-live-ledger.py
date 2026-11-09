#!/usr/bin/env python3
"""Actual embedded PHP + native PDO/Migrations against disposable MariaDB.
Synthetic catalog isolates SQL readback boundaries; not a café restore proof."""
import hashlib,json,os,pathlib,shutil,subprocess,tempfile
if os.getenv('SOKNA_SAFETY_DISPOSABLE')!='YES':raise SystemExit('DISPOSABLE_ONLY')
ROOT=pathlib.Path(__file__).resolve().parents[2];DB='sokna_r129_compat_ci'
env={**os.environ,'MYSQL_PWD':'ci-root-only'}
def sql(s):return subprocess.run(['mariadb','--protocol=tcp','-h127.0.0.1','-uroot','-NB','-e',s],env=env,capture_output=True,text=True,check=True).stdout.strip()
sql(f'CREATE DATABASE {DB}; USE {DB}; CREATE TABLE schema_migrations(version VARCHAR(160) PRIMARY KEY) ENGINE=InnoDB;'+
 "INSERT INTO schema_migrations VALUES('0076_late_served_accounting');"+
 'CREATE TABLE schema_migration_statements(version VARCHAR(160),ordinal_no INT,state VARCHAR(16)) ENGINE=InnoDB;'+
 'CREATE TABLE business_rows(id INT PRIMARY KEY,qty INT) ENGINE=InnoDB;INSERT INTO business_rows VALUES(1,129);')
try:
 with tempfile.TemporaryDirectory() as td:
  p=pathlib.Path(td);web=p/'web';(web/'database/migrations').mkdir(parents=True);(web/'src/Core').mkdir(parents=True)
  for n in ['Config','Database','Migrations','MigrationExecutionException']:shutil.copyfile(ROOT/f'apps/local-web/src/Core/{n}.php',web/f'src/Core/{n}.php')
  body=b'SELECT 1;\n';(web/'database/migrations/0076_late_served_accounting.sql').write_bytes(body)
  fp='migration-ledger-sha256-v1:'+hashlib.sha256(('0076_late_served_accounting\0'+hashlib.sha256(body).hexdigest()+'\n').encode()).hexdigest()
  plan=json.dumps(dict(SourceFingerprint=fp,TargetFingerprint=fp,SourceHead='0076_late_served_accounting',TargetHead='0076_late_served_accounting',SourceCount=1,TargetCount=1))
  (p/'config.php').write_text("<?php return "+"['db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'"+DB+"','user'=>'root','pass'=>'ci-root-only','charset'=>'utf8mb4']];")
  helper=(ROOT/'windows/maintenance/src/Sokna.Maintenance.Core/RoutineLocalDatabase.php').read_text().removeprefix('<?php')
  def run(expected):
   x=subprocess.run(['php','-r',helper,str(p),str(web),str(web),'inspect-external-rollback',plan,'',''],capture_output=True,text=True)
   assert x.returncode==expected,(x.returncode,x.stderr)
   if expected==0:assert x.stdout=='SOKNA_DATABASE_OK'
   assert sql(f'USE {DB};SELECT qty FROM business_rows')=='129'
   print('PASS actual read-only external baseline status',expected,flush=True)
  run(0)
  sql(f"USE {DB};INSERT INTO schema_migration_statements VALUES('0078_tea_herbal_three_sizes',1,'running')");run(68)
  sql(f"USE {DB};UPDATE schema_migration_statements SET state='applied'");run(85)
  sql(f'USE {DB};DELETE FROM schema_migration_statements;CREATE TABLE inventory_opening_activations(id INT) ENGINE=InnoDB');run(86)
  sql(f'USE {DB};DROP TABLE inventory_opening_activations;INSERT INTO schema_migrations VALUES(\'0077_incremental_opening_inventory\')');run(67)
  # Prove inspect executes inside a READ ONLY transaction, not merely a test
  # that happened not to issue a write: a trigger SELECT doing DML must fail.
  sql(f'USE {DB};DELETE FROM schema_migrations WHERE version>=\'0077\'')
  run(0)
  native=web/'src/Core/Migrations.php'
  text=native.read_text()
  import re
  changed,n=re.subn(r'(public function appliedVersions\(\): array\s*\{)',r"\1\n        $this->pdo->exec('UPDATE business_rows SET qty=999');",text,count=1)
  assert n==1
  native.write_text(changed)
  run(78)
  assert sql(f'USE {DB};SELECT qty FROM business_rows')=='129'
  native.write_text(text)
  assert not (p/'never-created.sql').exists()
finally:sql(f'DROP DATABASE {DB}')
print('PASS native PHP/MariaDB R129 ledger boundaries; business sentinel preserved; no host approval')
