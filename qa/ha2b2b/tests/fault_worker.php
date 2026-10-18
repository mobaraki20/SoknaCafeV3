<?php
declare(strict_types=1);
require_once __DIR__.'/../source/LabMariaDbSettings.php';
require_once __DIR__.'/../source/PdoMariaDbLabReleaseJournalV2.php';
// Real MariaDB disposable-lab worker. Mode is not an authentication claim.
try {
    if (PHP_SAPI !== 'cli' || getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM') !== 'YES_DISPOSABLE_NO_PRODUCTION') throw new RuntimeException('LAB_CONSENT_REQUIRED');
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['hold_uncommitted', 'write_then_lose_ack'], true)) throw new RuntimeException('UNKNOWN_WORKER_MODE');
    $db = LabMariaDbSettings::connect();
    $id = getenv('SOKNA_HA2B_INSTALLATION_ID') ?: '';
    $tx = getenv('SOKNA_HA2B_TX_ID') ?: '';
    $plan = getenv('SOKNA_HA2B_PLAN_SHA') ?: '';
    $lab = LabMariaDbSettings::fromEnvironment()['database'];
    $j = new PdoMariaDbLabReleaseJournalV2($db, $id, $lab, true);
    if ($mode === 'hold_uncommitted') {
        $db->beginTransaction();
        $st = $db->prepare('SELECT sequence_no FROM sokna_ha2_lab_guard WHERE installation_id=? FOR UPDATE');
        $st->execute([$id]);
        if ($st->fetchColumn() === false) throw new RuntimeException('MISSING_GUARD');
        // Deliberately corrupt only an UNCOMMITTED row, then kill this process to prove DB rollback.
        $db->prepare('UPDATE sokna_ha2_lab_guard SET sequence_no=sequence_no+37 WHERE installation_id=?')->execute([$id]);
        echo "UNCOMMITTED_AND_LOCKED\n"; flush();
        sleep(30); // Must be killed by parent; do NOT COMMIT.
        exit(4);
    }
    $result = $j->begin($tx, $plan); // DB commit is finished before reporting anything.
    if (($result['ok'] ?? false) !== true || ($result['commit_proven'] ?? true) !== false || ($result['execution_authorized'] ?? true) !== false) throw new RuntimeException('UNEXPECTED_BEGIN_RESULT');
    echo "PREPARED_RECORD_DURABLE_NOT_INSTALL_COMMIT\n"; flush();
    sleep(30); // Parent kills before any application-level ACK.
    exit(5);
} catch (Throwable $e) {
    fwrite(STDERR,"WORKER_FAIL_CLOSED\n"); exit(1);
}
