<?php
declare(strict_types=1);

/** Disposable GitHub-only MariaDB data and migration checker. */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('SOKNA_RELEASE_DB_QA_DISPOSABLE') !== 'YES'
    || (string)getenv('RUNNER_TEMP') === '') {
    throw new RuntimeException('REFUSE_NON_DISPOSABLE_DB_ENVIRONMENT');
}
$operation = (string)($argv[1] ?? '');
$component = (string)($argv[2] ?? '');
$dbName = (string)($argv[3] ?? '');
$expectedCount = $component === 'local' ? 81 : ($component === 'public' ? 8 : -1);
$allowed = $component === 'local'
    ? ['sokna_r213_isolated', 'sokna_r213_isolated_restore_qa']
    : ['sokna_r228_public_disposable', 'sokna_r228_public_disposable_restore_qa'];
if (!in_array($operation, ['seed','snapshot','reapply'], true)
    || !in_array($dbName, $allowed, true)
    || $expectedCount < 0) {
    throw new RuntimeException('REFUSE_UNKNOWN_DATABASE_OR_ACTION');
}
$pass = (string)getenv('SOKNA_RELEASE_DB_QA_PASSWORD');
if ($pass === '') throw new RuntimeException('CI_CREDENTIAL_MISSING');
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname='.$dbName.';charset=utf8mb4',
    'root', $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("SET time_zone = '+00:00'");
$got = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if ($got !== $expectedCount) {
    throw new RuntimeException('APPLIED_MIGRATION_COUNT_MISMATCH_'.$component);
}

if ($operation === 'seed') {
    if (str_ends_with($dbName, '_restore_qa')) throw new RuntimeException('RESTORE_DB_SEED_FORBIDDEN');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sokna_release_qa_preservation_marker ('.
               'id INT NOT NULL PRIMARY KEY, marker VARCHAR(255) NOT NULL) ENGINE=InnoDB');
    $stmt=$pdo->prepare('INSERT INTO sokna_release_qa_preservation_marker(id,marker) '.
                         'VALUES(1,?) ON DUPLICATE KEY UPDATE marker=VALUES(marker)');
    $stmt->execute(['SOURCE_R233_QA_ONLY_PRESERVE_FINANCIAL_INVENTORY_ORDER_ROWS']);
    if ($pdo->query('SELECT marker FROM sokna_release_qa_preservation_marker WHERE id=1')->fetchColumn()
        !== 'SOURCE_R233_QA_ONLY_PRESERVE_FINANCIAL_INVENTORY_ORDER_ROWS') {
        throw new RuntimeException('DISPOSABLE_MARKER_SEED_FAILED');
    }
    echo "PASS_DISPOSABLE_".strtoupper($component)."_EXISTING_ROW_MARKER_SEEDED\n";
    exit(0);
}

if ($operation === 'reapply') {
    if (str_ends_with($dbName, '_restore_qa')) throw new RuntimeException('REAPPLY_ONLY_ORIGINAL_QA_DB');
    if ($component === 'local') {
        $web=realpath((string)getenv('SOKNA_R213_WEB'));
        $temp=realpath((string)getenv('RUNNER_TEMP'));
        if (!$web || !$temp || !str_starts_with($web,$temp.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('INVALID_LOCAL_TEMP_SOURCE');
        }
        require_once $web.'/bootstrap.php';
        $cfg=require dirname($web).'/config.php';
        $core=sokna_local_bootstrap($cfg);
        if (!method_exists($core,'migrations')) throw new RuntimeException('REAL_LOCAL_MIGRATOR_NOT_AVAILABLE');
        $first=$core->migrations()->migrate();
        $second=$core->migrations()->migrate();
        if (!is_array($first) || !is_array($second) || $first!==[] || $second!==[]) {
            throw new RuntimeException('LOCAL_MIGRATION_RERUN_NOT_IDEMPOTENT');
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() !== $expectedCount) {
            throw new RuntimeException('LOCAL_SCHEMA_HISTORY_DRIFT_ON_REAPPLY');
        }
        echo "PASS_LOCAL_81_REAL_MIGRATIONS_REAPPLY_TWICE_NOOP\n";
    } else {
        $public=realpath((string)getenv('SOKNA_R228_PUBLIC'));
        $temp=realpath((string)getenv('RUNNER_TEMP'));
        if (!$public || !$temp || !str_starts_with($public,$temp.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('INVALID_PUBLIC_TEMP_SOURCE');
        }
        require_once $public.'/bootstrap.php';
        $cfg=require $public.'/config.php';
        $core=sokna_public_bootstrap($cfg);
        $migrations=new \Sokna\PublicEdge\Core\Migrations($core->database(),$public.'/database/migrations');
        $initial=$migrations->appliedVersions();
        if (count($initial)!==8 || $migrations->pendingVersions()!==[]) {
            throw new RuntimeException('PUBLIC_OWNER_MIGRATIONS_NOT_READY');
        }
        $first=$migrations->migrate();
        $second=$migrations->migrate();
        if ($first!==[] || $second!==[] || $migrations->pendingVersions()!==[]
            || $migrations->appliedVersions()!==$initial) {
            throw new RuntimeException('PUBLIC_OWNER_MIGRATION_REAPPLY_NOT_IDEMPOTENT');
        }
        echo "PASS_PUBLIC_8_REAL_OWNER_MIGRATIONS_REAPPLY_TWICE_NOOP\n";
    }
    exit(0);
}

// Deterministic logical digest of every row: only hash, counts and flags are emitted.
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables,SORT_STRING);
if (!in_array('schema_migrations',$tables,true)
    || !in_array('sokna_release_qa_preservation_marker',$tables,true)
    || count($tables)<8) {
    throw new RuntimeException('DISPOSABLE_DB_TABLE_SET_INCOMPLETE');
}
$parts=[];$total=0;
foreach ($tables as $table) {
    if (!preg_match('/^[A-Za-z0-9_]+$/D',$table)) throw new RuntimeException('UNSAFE_TABLE_NAME');
    $quoted=chr(96).$table.chr(96);
    $cols=$pdo->query('SHOW COLUMNS FROM '.$quoted)->fetchAll(PDO::FETCH_ASSOC);
    $names=array_column($cols,'Field');
    $rows=$pdo->query('SELECT * FROM '.$quoted)->fetchAll(PDO::FETCH_ASSOC);
    $rowHashes=[];
    foreach ($rows as $row) {
        $cells=[];
        foreach ($names as $name) {
            $v=$row[$name];
            $cells[$name]=$v===null?null:bin2hex((string)$v);
        }
        $rowHashes[]=hash('sha256',json_encode($cells,JSON_THROW_ON_ERROR));
    }
    sort($rowHashes,SORT_STRING);
    $total+=count($rowHashes);
    $parts[]=$table.':'.hash('sha256',implode("\n",$rowHashes)).':'.count($rowHashes);
}
if ($pdo->query('SELECT marker FROM sokna_release_qa_preservation_marker WHERE id=1')->fetchColumn()
    !== 'SOURCE_R233_QA_ONLY_PRESERVE_FINANCIAL_INVENTORY_ORDER_ROWS') {
    throw new RuntimeException('DISPOSABLE_BUSINESS_MARKER_NOT_PRESERVED');
}
echo json_encode([
    'format'=>'sokna-disposable-db-content-proof-v1',
    'component'=>$component,
    'migration_count'=>$got,
    'table_count'=>count($tables),
    'row_count'=>$total,
    'content_sha256'=>hash('sha256',implode("\n",$parts)),
    'preservation_marker_present'=>true,
    'disposable_only'=>true,
    'production_db_modified'=>false,
],JSON_THROW_ON_ERROR)."\n";
