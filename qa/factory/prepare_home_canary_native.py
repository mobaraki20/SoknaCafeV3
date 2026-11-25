#!/usr/bin/env python3
"""Freeze and exercise a single exact candidate across every declared Local origin.
CI only. Native manifest predicates are exercised with a quarantined candidate;
no distribution authorization is issued by this builder or by an individual job.
"""
import os,io,json,zipfile,pathlib,hashlib,re
P=pathlib.Path; H=lambda x:hashlib.sha256(x).hexdigest()
tmp=P(os.environ["RUNNER_TEMP"])
base=P(os.environ["SOKNA_BASELINE_ZIP"]); target=P(os.environ["SOKNA_CURRENT_QA_ZIP"])
origins={
'R301':('399356a587230c770cbf50f6c3bdd93f0ee4a339e35ec3c1c381d61473d8cb73','159b20c33d9b8d92a0a53f5ab1a91bcf8ca60e6a446b836636d66dbd4213193a'),
'R304':('6dccf704f130610821e2727858bbcdd2531780ede5781c896153fb480201f1e1','9de2777162d05b0fa16e41d6c06380c8b039725f88a193bba23223574f9c2d51'),
'R306':('1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209','63df287e865ac0503fd21dea275a4714c1138f0c11ee53834522b7cb3d060d7c'),
'R307':('069a267f920b2d0614ca61443851b4f385994dca379d2bbbb3e1c42aba8047fa','5455c9d2ea4762a050d826f59c1896f7e22827b1cdad9f6106bca356ba94b186')}
origins['R3']=('d94bd1a539cede71a0d443b3f417fa9bffba3898c38b446caea6e09ec826d627','dd9789e783273944e17fa31b8dad283463678c526daa59f914849dea9f1e9889')
origins['C2']=('f864a42fb82124e4f16c9b9437464256ef3b59d79d26518232cf05cbc9393f42','984f685043172eb3ccfe9dbe6e9c2178bc2ad1e69eaf1c9da7007cba03769d35')
origins['R204']=('279d64dcf3800a764c3fcf751a0fc800857de501943af32428c87cc8db2539a3','f6edff0b649bf06209ac262808c402ffc13079100686359b8b06398a610054f6')
origins['GE']=('8c14c93560e42706bf01e302047b29468c2f16e4637c8acb93711c9f08cb0b3b','47a022037744e5389042ef1616b34dcee1ce9fa0bc8cb56af07d51e419225bdc')
origin=os.environ["SOKNA_ORIGIN"]; fault=os.environ["SOKNA_FAULT"]
assert fault in ('none','APACHE_STOPPED','DATABASE_BACKUP_VERIFIED','DATABASE_FORWARD_INTENT','POINTER_SWITCHED','COMMITTED')
bsha,btree=origins[origin]; assert H(base.read_bytes())==bsha
assert target.name=='SOKNA-LOCAL-231GF-CONTROLLED-HOME-CANARY.zip'
assert fault=='none'
with zipfile.ZipFile(base) as z:
    parent=json.loads(z.read('release-set.json'))['targets']['local']
assert parent['managed_tree_sha256']==btree
with zipfile.ZipFile(target) as z:
    m=json.loads(z.read('release-set.json')); web=z.read('artifacts/local/web.zip')
assert H(web)==m['artifacts']['local-web']['sha256']
assert {t for _,t in origins.values()}=={x['local']['managed_tree_sha256'] for x in m['supported_baselines']}
keys=('release_id','version','managed_tree_sha256','schema_fingerprint','migration_head','authority_epoch_policy')
match=[x['local'] for x in m['supported_baselines'] if x['local']['version']==parent['version']]
assert len(match)==1 and all(match[0].get(k)==parent.get(k) for k in keys),'EXACT_MAINTENANCE_IDENTITY_MISMATCH'
assert H(web)=='45b671f0d9f9689223f8d899a2f7c9af7de9493e7d44ad5a9ed21553227a03b4'
assert m['for_install'] and m['production_pass'] and m['status']=='CONTROLLED_HOME_CANARY_FIELD_ACCEPTANCE_PENDING'
out=target
sha=H(out.read_bytes()); current=m['targets']['local']
s=(P(__file__).parent.parent/'sokna-request-c2'/'native_matrix_reference.ps1').read_text(encoding='utf8')
# Replace only known baseline and target pins in the historically passed runner.
subs={
'SOKNA_R301_CANDIDATE_ZIP':'SOKNA_MATRIX_TARGET_ZIP',
'SOKNA_R299_ORIGINAL_ZIP':'SOKNA_BASELINE_ZIP',
'399356a587230c770cbf50f6c3bdd93f0ee4a339e35ec3c1c381d61473d8cb73':sha,
'8a897f3ac9215e06d72f67be292b11cf17263a0ab687d9b59d8bc341ac818507':bsha,
'3.0.0-g6.2-workspace.231fq-r299-cumulative-controlled-install':parent['version'],
'3.0.0-g6.2-workspace.231fs-r301-public-move-controlled-install':current['version'],
'04e4bf1cc5c9c860835f4081a9f7f847f5fd9d9979f3d8558c5fcd3cee79517f':btree,
'159b20c33d9b8d92a0a53f5ab1a91bcf8ca60e6a446b836636d66dbd4213193a':current['managed_tree_sha256'],
"$targetInfo.migration_head -ceq '0083_remaining_account_discount'":f"$targetInfo.migration_head -ceq '{current['migration_head']}'",
"\"R301_DB_MIGRATION_0083_NOT_APPLIED\"":"\"FINAL_DB_MIGRATION_0093_NOT_APPLIED\"",
}
s=re.sub('|'.join(re.escape(k) for k in sorted(subs,key=len,reverse=True)),lambda match:subs[match.group(0)],s)
assert bsha in s and btree in s and sha in s and current['managed_tree_sha256'] in s
a=s.index(" $args=@($root,$baseline,$target,$engine,'none',$pack)")
b=s.index("\n} finally {",a)
s=s[:a]+r"""
 $fault=$env:SOKNA_FAULT
 $args=@($root,$baseline,$target,$engine,$fault,$pack)
 $configHash=(Get-FileHash (Join-Path $root 'config.php')).Hash
 $lockHash=(Get-FileHash (Join-Path $root 'install.lock')).Hash
 # A consistent original DB snapshot, restored to a separate schema, checks
 # actual baseline business tables and preserves the forward-migrated DB.
 $dump=Join-Path $mdb 'bin/mariadb-dump.exe'
 $backup=Join-Path $lab 'independent-before.sql'
 & $dump --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --single-transaction --quick "--result-file=$backup" sokna_r186_exact
 Need ($LASTEXITCODE-eq0 -and (Get-Item $backup).Length-gt100) 'BACKUP_FAILED'
 $backupSha=(Get-FileHash $backup -Algorithm SHA256).Hash.ToLowerInvariant()
 & $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --execute='CREATE DATABASE sokna_r3_independent_restore CHARACTER SET utf8mb4;'
 Need ($LASTEXITCODE-eq0) 'RESTORE_SCHEMA_FAILED'
 $restoreProc=Start-Process -FilePath $mysql -ArgumentList @('--no-defaults','--protocol=tcp','--host=127.0.0.1',"--port=$dbPort",'--user=root','sokna_r3_independent_restore') -RedirectStandardInput $backup -Wait -PassThru -NoNewWindow
 Need ($restoreProc.ExitCode-eq0 -and (Get-FileHash $backup -Algorithm SHA256).Hash.ToLowerInvariant()-ceq$backupSha) 'BACKUP_RESTORE_FAILED'
 $restoredLedger=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r3_independent_restore.schema_migrations;'
 Need ($LASTEXITCODE-eq0 -and [string]$restoredLedger -eq $env:SOKNA_BASE_LEDGER) 'RESTORED_LEDGER_WRONG'
 Run $harness (@('prepare')+$args)
 Need ((Get-Content (Join-Path $root 'Web/VERSION.txt') -Raw).Trim() -ceq $oldVersion) 'STAGE_MUTATED_ACTIVE_VERSION'
 if($fault-eq'none'){
    Run $harness (@('panel-activate')+$args)
 } else {
    & $harness @(@('activate')+$args)
    Need ($LASTEXITCODE-eq97) "FAULT_NOT_REACHED_$fault"
    Run $harness (@('recover')+$args)
 }
 $forward=$fault-in@('none','DATABASE_FORWARD_INTENT','POINTER_SWITCHED','COMMITTED')
 $wantVersion=if($forward){[string]$targetInfo.version}else{$oldVersion}
 $wantLedger=if($forward){'93'}else{$env:SOKNA_BASE_LEDGER}
 $routine=Get-Content (Join-Path $root 'Maintenance/local-slots/routine-state.json') -Raw|ConvertFrom-Json
 Need ($routine.phase-eq$(if($forward){'COMMITTED'}else{'RECOVERED_TO_PREVIOUS_SLOT'})) 'WRONG_TERMINAL_PHASE'
 Need ($routine.database_mutation_performed-eq($forward -and $env:SOKNA_ORIGIN -ne 'GE') -and -not $routine.recovery_required) 'UNRESOLVED_RECOVERY'
 $after=Invoke-WebRequest 'http://127.0.0.1:39841/login.php' -UseBasicParsing -TimeoutSec 15
 Need ($after.StatusCode-eq200 -and $after.Headers['X-Sokna-Build']-ceq$wantVersion) 'HTTP_READBACK_WRONG'
 $ledger=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r186_exact.schema_migrations;'
 Need ($LASTEXITCODE-eq0 -and [string]$ledger-eq$wantLedger) 'MIGRATION_LEDGER_WRONG'
 $restoredLedger=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r3_independent_restore.schema_migrations;'
 Need ($LASTEXITCODE-eq0 -and [string]$restoredLedger -eq $env:SOKNA_BASE_LEDGER) 'RESTORE_SCHEMA_MUTATED'
 Need ((Get-FileHash (Join-Path $root 'config.php')).Hash-ceq$configHash -and (Get-FileHash (Join-Path $root 'install.lock')).Hash-ceq$lockHash) 'PERSISTENT_IDENTITY_CHANGED'
 @{status='PASS';origin=$env:SOKNA_ORIGIN;fault=$fault;candidate_sha256=(Get-FileHash $pack -Algorithm SHA256).Hash.ToLowerInvariant();origin_tree_sha256=$baselineInfo.managed_tree_sha256;target_tree_sha256=$targetInfo.managed_tree_sha256;phase=$routine.phase;backup_sha256=$backupSha;independent_restore=$true;migrations=[int]$ledger;disposable_only=$true;distribution_authorized=$false;package_admission_tested=$true;panel_activation_tested=$true;actual_field_host=$false;scope='CONTROLLED_HOME_CANARY'}|ConvertTo-Json -Depth 6|Set-Content (Join-Path $env:RUNNER_TEMP 'matrix-receipt.json') -Encoding utf8NoBOM
""" +s[b:]
s=s.replace("[string]$priorLedger -eq '83'","[string]$priorLedger -eq $env:SOKNA_BASE_LEDGER")

# Use the identical pinned runtime setup already verified in checkpoint57.
rt=(P(__file__).parent.parent/'checkpoint57'/'windows-web-pinned.ps1').read_text(encoding='utf8')
runtime=rt[rt.index('# Exact candidate lock'):rt.index(" $web=Join-Path")]
# This section opens the same disposable DB and enters try; it also provides the pinned Apache source.
a=s.index('# Reuse the externally SHA-pinned'); b=s.index(" $infra=Join-Path",a)
s=s[:a]+runtime+s[b:]
s=s.replace(" $bootstrap=Join-Path $env:GITHUB_WORKSPACE 'r300/tests/windows_bootstrap_r299.php'"," $bootstrap=Join-Path $env:GITHUB_WORKSPACE 'qa/checkpoint59/bootstrap.php'")
s=s.replace("LoadModule authz_core_module modules/mod_authz_core.so","LoadModule authz_core_module modules/mod_authz_core.so\nLoadModule authz_host_module modules/mod_authz_host.so")
s=s.replace("AllowOverride None","AllowOverride None")
s=s.replace(" $php=Join-Path $infra 'PHP/php.exe'"," $php=Join-Path $infra 'PHP/php.exe'\n $ini=Join-Path $infra 'PHP/php.ini'\n (Get-Content $ini -Raw).Replace((P (Join-Path $phpRoot 'ext')),(P (Join-Path $infra 'PHP/ext')))|Set-Content $ini -Encoding utf8NoBOM")
s=s.replace("$args=@($root,$baseline,$target,$engine,$fault,$pack)","$args=@($root,$baseline,$target,$engine,$fault,$pack)")
(tmp/'native-matrix.ps1').write_text(s,encoding='utf8')
print(json.dumps({'origin':origin,'fault':fault,'baseline_sha256':bsha,'candidate_sha256':sha,'inner_sha256':H(web),'controlled_candidate_ci_only':True}))
