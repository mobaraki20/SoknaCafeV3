<?php
/** Separate static CI gate: NEVER invokes the real recovery script. */
declare(strict_types=1);
$s=(string)file_get_contents(__DIR__.'/SOKNA-RECONCILE.php');
$cmd=(string)file_get_contents(__DIR__.'/START-R129-RECONCILE-MAINTENANCE.cmd');
$required=[
 'exact R112 signature'=>"CURRENT_BUILD = '3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat'",
 'exact target R125'=>"FAILED_BUILD = '3.0.0-g6.2-workspace.231dt-r125-local-inventory-repair-uat'",
 'specific operation'=>"OP_ID = 'b26dc160ce42441ebf8f6e393f41b924'",
 'backup hash'=>"BACKUP_SHA = '074e0affb4c0eea114dc7eed3c48b5687a4c42b54e8f6d9d8597e15cff0c57a6'",
 'single blocking operation check'=>'FOREIGN_OR_MISSING_BLOCKING_OPERATIONS',
 'reconciler never adds migration'=>'DATABASE_NOT_EXACT_RESTORED_0076',
 'recovered only old slot'=>'WEB_NOT_PREVIOUS_SLOT',
 'stamped original evidence archived'=>'ORIGINAL_EVIDENCE_COPY_FAILED',
 'backup SHA checked'=>"hash_file('sha256',\$backupPath)",
 'concurrent operation lock'=>'RECONCILIATION_ALREADY_ACTIVE',
 'no automatic install'=>'DO_NOT_INSTALL_R125_OR_UNQUALIFIED_R126_R128_ON_CAFE',
 'op request hash check'=>'OP_REQUEST_IDENTITY_HASH',
 'per-file sha attestation'=>'MANAGED_FILE_MISSING',
 'state after rollback'=>'RECOVERED_TO_PREVIOUS_SLOT',
 'no browser database modifications'=>'START TRANSACTION READ ONLY',
];
foreach($required as $name=>$needle){if(!str_contains($s,$needle))throw new RuntimeException('MISSING: '.$name);}
foreach(['DROP TABLE ','DELETE FROM ','INSERT INTO ','UPDATE schema_','ALTER TABLE ','CREATE TABLE ','TRUNCATE '] as $forbidden)
 if(str_contains(strtoupper($s),$forbidden))throw new RuntimeException('MUTATING SQL: '.$forbidden);
foreach(['sc.exe','net start','net stop','Stop-Service','Start-Service'] as $forbidden)
 if(stripos($s,$forbidden)!==false)throw new RuntimeException('SERVICE CONTROL: '.$forbidden);
foreach(['Run as administrator','RECONCILE-R112','SOKNA-RECONCILE.php'] as $needle)
 if(!str_contains($cmd,$needle))throw new RuntimeException('CMD GUARD MISSING: '.$needle);
echo "PASS static PHP/Windows prerequisites: ".count($required)." required assertions, no DB DML/DDL and no service control\n";