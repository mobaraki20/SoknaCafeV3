<?php
// Embedded in the Engine. No executable code is taken from the uploaded ZIP here.
// The target migration runner itself is bound by the verified managed-tree hash.
declare(strict_types=1);
try {
    if ($argc !== 8) exit(64);
    [$unused,$root,$source,$target,$mode,$json,$backup,$dump]=$argv;
    $plan=json_decode($json,true,32,JSON_THROW_ON_ERROR);
    $cfg=require $root.'/config.php';
    if (!is_array($cfg)) exit(65);
    // BrowserSetupService persists a numeric string. Reject invalid ports
    // before connecting, and normalize again for the actual backup, so a
    // changed config cannot reuse an earlier preflight result (M06 regression).
    if ($mode==='backup-preflight' || $mode==='backup') {
        $port=$cfg['db']['port']??3306;
        if ((!is_int($port) && !is_string($port)) ||
            !preg_match('/^[0-9]{1,5}$/D',(string)$port) ||
            (int)$port<1 || (int)$port>65535) exit(81);
        $cfg['db']['port']=(int)$port;
        if (!is_file($dump) || !is_readable($dump)) exit(82);
    }
    foreach (['Config','Database','MigrationExecutionException','Migrations'] as $class)
        require_once $target.'/src/Core/'.$class.'.php';
    $pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));
    $old=new \Sokna\Local\Core\Migrations($pdo,$source.'/database/migrations');
    $next=new \Sokna\Local\Core\Migrations($pdo,$target.'/database/migrations');
    $fingerprint=static function(array $catalog): string {
        $lines=[];foreach($catalog as $version=>$path)$lines[]=$version."\0".hash_file('sha256',$path);
        return 'migration-ledger-sha256-v1:'.hash('sha256',implode("\n",$lines)."\n");
    };
    $before=$old->catalog();$after=$next->catalog();
    if ($fingerprint($before)!==$plan['SourceFingerprint'] || $fingerprint($after)!==$plan['TargetFingerprint']) exit(66);
    if ($mode==='inspect' || $mode==='inspect-external-rollback') { $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction(); }
    $applied=$next->appliedVersions();$original=array_keys($before);$expected=array_keys($after);
    if ($mode==='inspect' || $mode==='inspect-external-rollback' || $mode==='backup' || $mode==='preflight' || $mode==='backup-preflight') {
        if ($applied!==$original) exit(67);
        if ((int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()!==0) exit(68);
        if ($mode==='inspect-external-rollback') {
            if ($plan['SourceHead']!=='0076_late_served_accounting') exit(84);
            if ((int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE version >= '0077'")->fetchColumn()!==0) exit(85);
            if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='inventory_opening_activations'")->fetchColumn()!==0) exit(86);
        }
        if ($mode==='preflight') {
            // Safely probe leading SELECT guards in each pending signed migration
            // while Apache is still running. Never execute DDL/DML in preflight.
            // SQL files are from the target tree whose bytes were already attested.
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            try {
                foreach ($after as $version=>$path) {
                    if (isset($before[$version])) continue;
                    $sql=@file_get_contents($path);
                    if (!is_string($sql) || trim($sql)==='') exit(79);
                    foreach (\Sokna\Local\Core\Migrations::splitStatements($sql) as $ordinal=>$statement) {
                        if (preg_match('/^SELECT\\b/i',ltrim($statement))!==1) break;
                        try {
                            $result=$pdo->query($statement);
                            if (!$result) exit(80);
                            do { while ($result->fetch(PDO::FETCH_NUM)!==false) {} }
                            while ($result->nextRowset());
                            $result->closeCursor();
                        } catch (PDOException $error) {
                            throw new \Sokna\Local\Core\MigrationExecutionException($version,$ordinal+1,$error);
                        }
                    }
                }
            } finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        }
    } elseif ($mode==='apply') {
        if (array_diff($original,$applied) || array_diff($applied,$expected)) exit(69);
        // An interrupted journal may only concern the exact target catalog.
        $journal=$pdo->query('SELECT DISTINCT version FROM schema_migration_statements')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($journal,$expected)) exit(70);
        $next->migrate();
        if ($next->appliedVersions()!==$expected || (int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()!==0) exit(71);
    } else exit(72);
    if ($mode==='backup' || $mode==='backup-preflight') {
        // --single-transaction is valid only for transactional application tables.
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'");
        if ((int)$q->fetchColumn()!==0) exit(73);
        // Success here means backup prerequisites only, never RESTORE_VERIFIED.
        // Do not allocate credentials/dump files during read-only preflight.
        if ($mode==='backup-preflight') { echo 'SOKNA_DATABASE_OK'; exit(0); }
        $options=$backup.'.credentials';
        $quote=static fn($v)=>'"'.str_replace(["\\","\"","\r","\n"],["\\\\","\\\"","\\r","\\n"],(string)$v).'"';
        $db=$cfg['db'];
        $body="[client]\nuser=".$quote($db['user'])."\npassword=".$quote($db['pass']??'')."\nhost=".$quote($db['host'])."\nport=".$quote($db['port']??3306)."\nprotocol=tcp\n";
        $f=fopen($options,'x');if($f===false)exit(74);fwrite($f,$body);fclose($f);chmod($options,0600);
        try {
            $cmd=[$dump,'--defaults-extra-file='.$options,'--single-transaction','--quick','--routines','--triggers','--events','--hex-blob','--default-character-set=utf8mb4','--databases',(string)$db['name'],'--result-file='.$backup];
            $pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['file',$backup.'.stdout','w'],2=>['file',$backup.'.stderr','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($proc))exit(75);fclose($pipes[0]);$code=proc_close($proc);
            if($code!==0 || !is_file($backup) || filesize($backup)<200)exit(76);
            $f=fopen($backup,'rb');fseek($f,max(0,filesize($backup)-4096));$tail=stream_get_contents($f);fclose($f);
            if(!str_contains($tail,'-- Dump completed on'))exit(77);
        } finally { @unlink($options);@unlink($backup.'.stdout');@unlink($backup.'.stderr'); }
    }
    echo 'SOKNA_DATABASE_OK';
} catch (Throwable $e) {
    // No SQL text, credentials, database messages or business rows on stderr.
    // The Engine may include this strict, redacted envelope in support receipts.
    if ($e instanceof \Sokna\Local\Core\MigrationExecutionException) {
        $version=preg_match('/^\\d{4}_[a-z0-9_]{1,156}$/D',$e->migrationVersion)
            ? $e->migrationVersion : 'invalid_migration_id';
        $state=preg_match('/^[A-Z0-9]{5}$/D',$e->sqlState) ? $e->sqlState : '00000';
        fwrite(STDERR,'SOKNA_DB_SAFE_FAILURE migration='.$version.' ordinal='.max(1,$e->statementOrdinal)
            .' state='.$state.' errno='.max(0,$e->mysqlErrno).PHP_EOL);
    }
    exit(78);
}


