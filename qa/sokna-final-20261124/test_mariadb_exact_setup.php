<?php
declare(strict_types=1);
/**
 * Disposable MariaDB integration gate. Uses the exact production Local Bootstrap,
 * BrowserSetup's two-stage clean-install ordering, and the original migration runner.
 * Never connects to a real customer host or production credentials.
 *
 * Usage: php test_mariadb_exact_setup.php /path/to/local-root DB_NAME setup|upgrade [expected_final]
 */
if ($argc < 4) throw new RuntimeException('USAGE local-root db-name setup|upgrade [expected_final]');
$webRoot = realpath((string)$argv[1]);
$dbName = (string)$argv[2];
$mode = (string)$argv[3];
$expected = $argv[4] ?? '0088_historical_variant_order_snapshots';
if ($webRoot === false || !is_file($webRoot . '/bootstrap.php') || !preg_match('/^sokna_ci_[a-z0-9_]+$/D', $dbName) || !in_array($mode, ['setup','upgrade'], true)) {
    throw new RuntimeException('UNTRUSTED_CI_INPUT');
}
$dataDir = dirname($webRoot) . '/ci-private-data-' . $dbName;
$mediaDir = $dataDir . '/guest-media';
if (!is_dir($mediaDir) && !mkdir($mediaDir, 0700, true) && !is_dir($mediaDir)) {
    throw new RuntimeException('CI_MEDIA_VAULT_UNAVAILABLE');
}
$cfg = [
    'app'=>['data_dir'=>$dataDir,'timezone'=>'Asia/Tehran','session_lifetime'=>43200],
    'db'=>[
        'host'=>'127.0.0.1', 'port'=>'3306','name'=>$dbName,'charset'=>'utf8mb4',
        'user'=>'root','pass'=>(string)getenv('SOKNA_CI_DB_PASS')
    ],
    'installation'=>['id'=>'disposable-ci-' . $dbName],
    'runtime'=>['local_token'=>'ci-only-do-not-use-on-host','local_base_url'=>'http://127.0.0.1'],
    'public'=>['base_url'=>'','shared_secret'=>''],
];
// The core photo preflight checks dirname(webRoot)/config.php BEFORE any SQL.
// All values are disposable CI-only, not customer secrets or site config.
$configPath = dirname($webRoot) . '/config.php';
$serialized = "<?php\nreturn " . var_export($cfg, true) . ";\n";
if (file_put_contents($configPath, $serialized) === false) throw new RuntimeException('CI_CONFIG_WRITE_FAILED');
require_once $webRoot . '/bootstrap.php';
$core = \sokna_local_bootstrap($cfg);
$pdo = $core->database();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pre = [];
$seed = null;
if ($mode === 'setup') {
    // Exactly the verified BrowserSetupService::install() sequence; migration 0059
    // requires canonical SKU records and must not run before the catalog seed.
    $pre = $core->migrations()->migrate('0059_menu_business_reconciliation_v4');
    if (count($pre) < 40) throw new RuntimeException('INSUFFICIENT_SCHEMA_PHASE_' . count($pre));
    $seed = $core->defaultContentSeeder()->seed();
    $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    if ($itemCount < 144) throw new RuntimeException('CATALOG_SEED_INCOMPLETE_' . $itemCount);
}
$after = $core->migrations()->migrate();
$versions = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($expected, $versions, true)) {
    throw new RuntimeException('MISSING_EXPECTED_MIGRATION_' . $expected);
}
if ($expected === '0088_historical_variant_order_snapshots') {
    foreach ([
        '0084_remaining_account_discount_revisions','0085_whole_order_cancellations',
        '0086_order_cancellation_batches','0087_partial_order_payment_reversals',
        '0088_historical_variant_order_snapshots',
    ] as $v) {
        if (!in_array($v, $versions, true)) throw new RuntimeException('MISSING_NEW_MIGRATION_' . $v);
    }
    // Validate new table schema, not just the ledger's self-reported row.
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_items'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['variant_family_id_snapshot','variant_family_key_snapshot','variant_family_name_snapshot','variant_label_snapshot','variant_snapshot_quality'] as $col) {
        if (!in_array($col,$cols,true)) throw new RuntimeException('0088_COLUMN_MISSING_' . $col);
    }
}
$again = $core->migrations()->migrate();
if ($again !== []) throw new RuntimeException('MIGRATION_IDEMPOTENCY_FAILURE_' . json_encode($again));
$report = [
    'schema'=>'sokna-ci-mariadb-local-setup-v1',
    'mode'=>$mode,'database'=>$dbName,'source_version'=>trim(file_get_contents($webRoot.'/VERSION.txt')),
    'migrated_before_seed'=>count($pre),'seed_status'=>$seed['status']??null,
    'migrated_after_seed_or_upgrade'=>count($after),
    'final_migration'=>end($versions),'migration_count'=>count($versions),
    'expected_migration'=>$expected,'idempotent_second_run'=>true,'result'=>'PASS',
];
echo json_encode($report, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR) . "\n";