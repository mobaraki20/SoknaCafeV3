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
    $applied=$next->appliedVersions();$original=array_keys($before);$expected=array_keys($after);
    if ($mode==='inspect' || $mode==='backup') {
        if ($applied!==$original) exit(67);
        if ((int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()!==0) exit(68);
    } elseif ($mode==='apply') {
        if (array_diff($original,$applied) || array_diff($applied,$expected)) exit(69);
        // An interrupted journal may only concern the exact target catalog.
        $journal=$pdo->query('SELECT DISTINCT version FROM schema_migration_statements')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($journal,$expected)) exit(70);
        $next->migrate();
        if ($next->appliedVersions()!==$expected || (int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()!==0) exit(71);
    } else exit(72);
    if ($mode==='backup') {
        // --single-transaction is valid only for transactional application tables.
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'");
        if ((int)$q->fetchColumn()!==0) exit(73);
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
} catch (Throwable $e) { exit(78); }
