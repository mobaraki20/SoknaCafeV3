<?php
declare(strict_types=1);
/**
 * SOKNA go-live preparation, READ-ONLY. Never mutates installation, database, files,
 * service state or transport queues. Use from the host CLI only, never from web.
 * Example: php SOKNA-GOLIVE-PREFLIGHT-READONLY.php --surface=local --root=C:\\Sokna\\local
 *          php SOKNA-GOLIVE-PREFLIGHT-READONLY.php --surface=public --root=/home/.../public
 * Script and JSON policy must be in same directory. Both Local and Public need independent reports.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
function fail(string $code): never { fwrite(STDERR, "SOKNA_GOLIVE_PREFLIGHT_BLOCKED: $code\n"); exit(2); }
function arg(string $name): string {
    global $argv;
    foreach ($argv as $a) if (str_starts_with($a,'--'.$name.'=')) return substr($a,strlen($name)+3);
    return '';
}
$surface=arg('surface');
if (!in_array($surface,['local','public'],true)) fail('SURFACE_REQUIRED');
$root=realpath(arg('root'));
if (!is_string($root) || !is_dir($root)) fail('ROOT_INVALID');
$policyPath=__DIR__.'/SOKNA-GOLIVE-TABLE-POLICY-READONLY.json';
if (!is_file($policyPath)) fail('POLICY_MISSING');
try {
    $policy=json_decode((string)file_get_contents($policyPath),true,64,JSON_THROW_ON_ERROR);
    if (($policy['schema']??'')!=='sokna-golive-readonly-classification-v1' || !is_array($policy[$surface]??null)) fail('POLICY_UNSUPPORTED');
    $configPath=dirname($root).DIRECTORY_SEPARATOR.'config.php';
    $corePath=$root.'/src/Core';
    if (!is_file($configPath) || !is_file($corePath.'/Config.php') || !is_file($corePath.'/Database.php')) fail('SITE_OR_CONFIG_MISSING');
    $config=require $configPath;
    if (!is_array($config)) fail('CONFIG_INVALID');
    require_once $corePath.'/Config.php';
    require_once $corePath.'/Database.php';
    $cls=$surface==='local'?'Sokna\\Local\\Core\\':'Sokna\\PublicEdge\\Core\\';
    $cfg=$cls.'Config';$db=$cls.'Database';
    $pdo=$db::connect($cfg::fromArray($config));
    $dbName=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName==='') fail('DATABASE_UNKNOWN');
    $result=$pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    $tables=[];
    foreach ($result as $table) {
        if (!is_string($table)||!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$table)) fail('TABLE_NAME_UNSAFE');
        $tables[$table]=true;
    }
    $expected=$policy[$surface];
    $unknown=array_values(array_diff(array_keys($tables),array_keys($expected)));
    $missing=array_values(array_diff(array_keys($expected),array_keys($tables)));
    $counts=[];
    foreach(array_keys($tables) as $name){
        $action=(string)($expected[$name]['action']??'UNKNOWN');
        $count=(int)$pdo->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn();
        $counts[$name]=['rows'=>$count,'classification'=>$action];
    }
    $fks=$pdo->query('SELECT TABLE_NAME AS child_table, COLUMN_NAME AS child_column, REFERENCED_TABLE_NAME AS parent_table FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, COLUMN_NAME')->fetchAll(PDO::FETCH_ASSOC);
    $preservedDeps=[];
    foreach($fks as $fk){
        $child=(string)$fk['child_table'];$parent=(string)$fk['parent_table'];
        $childState=$counts[$child]['classification']??'UNKNOWN';
        $parentState=$counts[$parent]['classification']??'UNKNOWN';
        if($childState==='PRESERVE' && $parentState==='PURGE_CANDIDATE'){
            $preservedDeps[]=['preserved_child'=>$child,'parent_to_clear'=>$parent,'column'=>$fk['child_column']];
        }
    }
    $snapshot=[
        'format'=>'sokna-golive-readonly-preflight-v1',
        'surface'=>$surface,
        'site_root_hash'=>hash('sha256',$root),
        'database_name_hash'=>hash('sha256',$dbName),
        'source_version'=>is_file($root.'/VERSION.txt')?trim((string)file_get_contents($root.'/VERSION.txt')):'UNKNOWN',
        'policy_sha256'=>hash_file('sha256',$policyPath),
        'schema_table_count'=>count($tables),
        'expected_table_count'=>count($expected),
        'unknown_tables'=>$unknown,
        'missing_tables'=>$missing,
        'preserved_foreign_keys_to_purge_candidates'=>$preservedDeps,
        'table_counts'=>$counts,
        'status'=>count($unknown)+count($missing)+count($preservedDeps)>0?'BLOCKED_SCHEMA_REVIEW':'PREFLIGHT_READONLY_COMPLETE',
        'execute_authorization'=>'DENIED',
        'backup_verified'=>false,
        'deletion_executed'=>false,
        'requires_before_delete'=>[
            'Verified MariaDB full backup and successful isolated restore for Local AND Public',
            'Verified media/config/keys/print templates backup with SHA256',
            'Pause all writers including Local/Public/Windows workers; verify no pending guest orders',
            'Agree separate authority epoch / replay prevention and preservation of table QR tokens',
            'Live FK dependency review and immutable before/after fingerprints',
        ]
    ];
    echo json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
} catch(Throwable $e) {
    fail('INSPECTION_FAILED_'.preg_replace('/[^A-Za-z0-9_]/','_',get_class($e)));
}