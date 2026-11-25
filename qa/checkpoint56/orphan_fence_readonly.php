<?php
declare(strict_types=1);
/**
 * QA-only read-only orphan-forward-fence schema proof. Never modifies routine state,
 * writes ledger rows, replays DDL, grants install authorization, or retires a fence.
 * Compare an offline restored database with an independently constructed reference
 * using the same MariaDB build and original migration catalog.
 */
function proofNeed(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function schemaProof(PDO $pdo): array {
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $server=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $queries=[
        'tables'=>"SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME",
        'columns'=>"SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION",
        'indexes'=>"SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
        'constraints'=>"SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME",
        'keys'=>"SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,POSITION_IN_UNIQUE_CONSTRAINT,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",
        'fk_rules'=>"SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME",
        'check_rules'=>"SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME",
        'triggers'=>"SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME",
    ];
    $out=['server'=>$server,'ledger'=>$pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)];
    foreach($queries as $key=>$sql) $out[$key]=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
function compareProof(array $actual,array $reference,array $expectedLedger): array {
    proofNeed(count($expectedLedger)===8 && count(array_unique($expectedLedger))===8,'EXPECTED_LEDGER_INVALID');
    proofNeed($actual['server']===$reference['server'],'DIFFERENT_MARIADB_BUILD');
    proofNeed($reference['ledger']===$expectedLedger,'REFERENCE_LEDGER_MISMATCH');
    proofNeed($actual['ledger']===$expectedLedger,'EXACT_LEDGER_MISMATCH');
    foreach($reference as $key=>$value) proofNeed(array_key_exists($key,$actual)&&$actual[$key]===$value,'SCHEMA_METADATA_MISMATCH_'.$key);
    return ['schema_matches_reference'=>true,'ledger_count'=>8,'schema_sha256'=>hash('sha256',json_encode($actual,JSON_THROW_ON_ERROR)),
        'fence_retirement_authorized'=>false,'production_install_authorized'=>false,'read_only'=>true];
}
function historicalFenceProof(array $state,array $receipt): void {
    $f=$state['db_forward_fence']??null;
    proofNeed(is_array($f)&&($f['policy']??null)==='forward_recovery_only','FENCE_MISSING_OR_WRONG_POLICY');
    foreach(['staged','pending_verification','activation_journal'] as $key)proofNeed(array_key_exists($key,$state)&&$state[$key]===null,'ACTIVE_TRANSACTION_'.$key);
    proofNeed(($state['interrupted_activation']??true)===false,'INTERRUPTED_ACTIVATION_PRESENT');
    proofNeed(($state['active_version']??'')!=='' && $state['active_version']===($state['current_version']??null),'ACTIVE_VERSION_MISMATCH');
    proofNeed(($state['managed_tree_matches_expected']??false)===true && preg_match('/^[a-f0-9]{64}$/D',$state['managed_tree_sha256']??'')===1 &&
        $state['managed_tree_sha256']===($state['expected_managed_tree_sha256']??null),'ACTIVE_TREE_MISMATCH');
    proofNeed(($receipt['status']??null)==='COMMITTED_FENCE_RETAINED' && ($receipt['transaction_id']??'')!=='' &&
        ($receipt['transaction_id']??null)===($f['transaction_id']??null) &&
        ($receipt['schema_owner_version']??'')!=='' && ($receipt['schema_owner_version']??null)===($f['schema_owner_version']??null),'HISTORICAL_COMMIT_IDENTITY_UNPROVEN');
    proofNeed(($receipt['pending_at_boundary']??null)===($f['pending_at_boundary']??null) &&
        ($f['pending_at_boundary']??null)===['0007_authority_epoch_fencing','0008_public_user_ui_preferences'],'HISTORICAL_SCHEMA_PLAN_MISMATCH');
    // Receipt provenance is a separate human/owner-controlled requirement; passing
    // this comparison does not authenticate a receipt or authorize fence retirement.
}
