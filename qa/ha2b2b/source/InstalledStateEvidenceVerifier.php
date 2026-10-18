<?php
declare(strict_types=1);
/**
 * SOKNA F1-HA2C1 - purely read-only LAB verifier, not an installed-state authority.
 * Caller-supplied keys, floor and filesystem snapshots cannot be promoted to trusted production provenance.
 * The only positive outcome is MATCHED_LAB_EVIDENCE_NOT_COMMIT, always with install authorization false.
 */
final class InstalledStateEvidenceVerifier {
    private const PREFIX = "SOKNA-HA2C1-INSTALLED-EVIDENCE-LAB-V1\n";
    private const PAYLOAD_KEYS = ['installation_id','release_set_sha256','active_sha256','schema_sha256','migrations_sha256','authority_epoch','issued_at','expires_at','nonce'];
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    public static function physical(PDO $db): string {
        $sql="SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ha2c1_lab_installations','ha2c1_lab_migrations') ORDER BY TABLE_NAME,ORDINAL_POSITION";
        $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)!==10) throw new RuntimeException('LAB_PHYSICAL_SCHEMA_COLUMN_COUNT');
        $tables=array_count_values(array_column($rows,'TABLE_NAME'));
        if (($tables['ha2c1_lab_installations']??0)!==5 || ($tables['ha2c1_lab_migrations']??0)!==5) throw new RuntimeException('LAB_PHYSICAL_SCHEMA_DRIFT');
        $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ha2c1_lab_installations','ha2c1_lab_migrations') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
        if(count($engines)!==2||strtoupper($engines[0]['ENGINE'])!=='INNODB'||strtoupper($engines[1]['ENGINE'])!=='INNODB')throw new RuntimeException('LAB_ENGINE_NOT_INNODB');
        return hash('sha256',json_encode(['columns'=>$rows,'engines'=>$engines],self::FLAGS));
    }
    public static function migrations(PDO $db): string {
        $rows=$db->query('SELECT migration_id,checksum,applied_epoch,applied_at,status FROM ha2c1_lab_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])throw new RuntimeException('LAB_MIGRATIONS_EMPTY');
        foreach($rows as $r){if(!preg_match('/^[a-z0-9_]{1,48}$/D',$r['migration_id'])||!preg_match('/^[a-f0-9]{64}$/D',$r['checksum'])||$r['status']!=='applied'||(int)$r['applied_epoch']<1)throw new RuntimeException('LAB_MIGRATION_NOT_APPLIED');}
        return hash('sha256',json_encode($rows,self::FLAGS));
    }
    public static function signedBytes(array $payload): string {
        if(array_keys($payload)!==self::PAYLOAD_KEYS)throw new RuntimeException('RECEIPT_KEYS_ORDER_OR_UNKNOWN');
        if(!preg_match('/^[a-f0-9]{32}$/D',$payload['installation_id']??''))throw new RuntimeException('RECEIPT_BAD_INSTALL_ID');
        foreach(['release_set_sha256','active_sha256','schema_sha256','migrations_sha256'] as $k){if(!is_string($payload[$k])||!preg_match('/^[a-f0-9]{64}$/D',$payload[$k]))throw new RuntimeException('RECEIPT_BAD_DIGEST');}
        if(!is_int($payload['authority_epoch'])||$payload['authority_epoch']<1||!is_int($payload['issued_at'])||!is_int($payload['expires_at'])||$payload['issued_at']>=$payload['expires_at']||!is_string($payload['nonce'])||!preg_match('/^[a-f0-9]{32}$/D',$payload['nonce']))throw new RuntimeException('RECEIPT_BAD_TYPES');
        return self::PREFIX.json_encode($payload,self::FLAGS);
    }
    private static function denied(string $reason):array{return ['status'=>'BLOCKED','reason'=>$reason,'matched_lab_evidence'=>false,'installed_release_proven'=>false,'commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];}
    public static function verify(PDO $db, array $payload,string $signatureB64,string $publicPem,string $pinnedKeySha256,string $expectInstall,string $expectRelease,string $activeFile,int $floor,int $now):array {
        try {
            $bytes=self::signedBytes($payload);
            if(!preg_match('/^[a-f0-9]{64}$/D',$pinnedKeySha256)||!hash_equals($pinnedKeySha256,hash('sha256',$publicPem)))throw new RuntimeException('UNPINNED_SIGNING_KEY');
            $sig=base64_decode($signatureB64,true);
            if($sig===false||strlen($sig)>256||strlen($sig)<64||openssl_verify($bytes,$sig,$publicPem,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('INVALID_SIGNATURE');
            if($now<$payload['issued_at']||$now>$payload['expires_at']||$payload['expires_at']-$payload['issued_at']>300)throw new RuntimeException('EXPIRED_OR_FUTURE_RECEIPT');
            if($floor<1||$payload['authority_epoch']<$floor)throw new RuntimeException('STALE_EPOCH_FLOOR');
            if(!hash_equals($expectInstall,$payload['installation_id'])||!hash_equals($expectRelease,$payload['release_set_sha256']))throw new RuntimeException('WRONG_INSTALL_OR_RELEASE');
            if(!is_file($activeFile)||is_link($activeFile)||!hash_equals($payload['active_sha256'],hash_file('sha256',$activeFile)))throw new RuntimeException('ACTIVE_FILE_DRIFT');
            if(!preg_match('/^sokna_ha2_lab_[a-f0-9]{8,16}$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('LAB_DB_ONLY');
            $db->beginTransaction();
            try {
                $q=$db->prepare('SELECT installation_id,release_set_sha256,active_sha256,authority_epoch,state FROM ha2c1_lab_installations WHERE installation_id=?');$q->execute([$expectInstall]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!$row||$row['state']!=='observed_lab'||$row['authority_epoch']!=(string)$payload['authority_epoch']||!hash_equals($row['release_set_sha256'],$expectRelease)||!hash_equals($row['active_sha256'],$payload['active_sha256']))throw new RuntimeException('DB_LEDGER_MISMATCH');
                if(!hash_equals(self::physical($db),$payload['schema_sha256'])||!hash_equals(self::migrations($db),$payload['migrations_sha256']))throw new RuntimeException('PHYSICAL_SCHEMA_OR_MIGRATION_DRIFT');
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            return ['status'=>'MATCHED_LAB_EVIDENCE_NOT_COMMIT','reason'=>null,'matched_lab_evidence'=>true,'installed_release_proven'=>false,'commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];
        }catch(Throwable $e){return self::denied($e->getMessage());}
    }
}
