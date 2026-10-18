<?php
declare(strict_types=1);
/** F1-HA2C2A. A SEPARATE-DATABASE LAB PRIMITIVE, NOT A DEPLOYABLE TRUST ANCHOR.
 * The test process has both databases and a temporary signing key: no independent
 * production issuer, off-host private-key custody or rollback resistance is proven.
 */
final class SeparateLabEpochAnchor {
    private PDO $db;
    public function __construct(PDO $db) {
        if (PHP_SAPI!=='cli' || getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION') throw new RuntimeException('LAB_ONLY');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MYSQL_REQUIRED');
        $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        if (!preg_match('/^sokna_ha2_anchor_[a-f0-9]{8,16}$/D',$name)) throw new RuntimeException('SEPARATE_LAB_ANCHOR_DB_ONLY');
        if (stripos((string)$db->query('SELECT VERSION()')->fetchColumn(),'mariadb')===false) throw new RuntimeException('MARIADB_REQUIRED');
        $this->db=$db;
    }
    private static function id(string $id):void {if(!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException('INVALID_ID');}
    private static function digest(string $digest):void {if(!preg_match('/^[a-f0-9]{64}$/D',$digest)) throw new RuntimeException('INVALID_DIGEST');}
    /** CAS is transactional on a physically separate MariaDB instance. No commit/install API. */
    public function advance(string $id,int $expected,int $next,string $release):array {
        self::id($id);self::digest($release);
        if ($expected<0 || $next<1 || $next<=$expected || $next>PHP_INT_MAX-1)throw new RuntimeException('NON_MONOTONIC_REQUEST');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT epoch,revision FROM ha2c2_lab_floor WHERE installation_id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                if($expected!==0 || $next!==1)throw new RuntimeException('NO_ANCHOR_BASELINE');
                $this->db->prepare('INSERT INTO ha2c2_lab_floor (installation_id,epoch,revision,release_set_sha256) VALUES (?,?,1,?)')->execute([$id,1,$release]);
            } else {
                if((int)$old['epoch']!==$expected)throw new RuntimeException('CAS_CONFLICT');
                $update=$this->db->prepare('UPDATE ha2c2_lab_floor SET epoch=?, revision=revision+1,release_set_sha256=? WHERE installation_id=? AND epoch=? AND revision=?');
                $update->execute([$next,$release,$id,$expected,(int)$old['revision']]);
                if($update->rowCount()!==1)throw new RuntimeException('FENCE_LOST');
            }
            $this->db->commit();
            return $this->read($id);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function read(string $id):array {
        self::id($id);
        $q=$this->db->prepare('SELECT installation_id,epoch,revision,release_set_sha256 FROM ha2c2_lab_floor WHERE installation_id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row || !ctype_digit((string)$row['epoch']) || !ctype_digit((string)$row['revision']) || (int)$row['epoch']<1 || (int)$row['revision']<1)throw new RuntimeException('ANCHOR_MISSING_OR_BAD');
        self::digest((string)$row['release_set_sha256']);
        return ['installation_id'=>$row['installation_id'],'epoch'=>(int)$row['epoch'],'revision'=>(int)$row['revision'],'release_set_sha256'=>$row['release_set_sha256']];
    }
}

/** Signed, short-lived readback: signer is EPHEMERAL LAB test fixture only. */
final class LabFloorSignedReadback {
    private const PREFIX="SOKNA-HA2C2-SEPARATE-LAB-ANCHOR-V1\n";
    private const KEYS=['installation_id','epoch','revision','release_set_sha256','issued_at','expires_at','nonce'];
    public static function bytes(array $p):string {
        if(array_keys($p)!==self::KEYS || !is_string($p['installation_id']) || !preg_match('/^[a-f0-9]{32}$/D',$p['installation_id']) || !is_int($p['epoch']) || $p['epoch']<1 || !is_int($p['revision'])||$p['revision']<1||!is_string($p['release_set_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$p['release_set_sha256'])||!is_int($p['issued_at'])||!is_int($p['expires_at'])||$p['expires_at']<=$p['issued_at']||$p['expires_at']-$p['issued_at']>120||!is_string($p['nonce'])||!preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])) throw new RuntimeException('INVALID_ANCHOR_ENVELOPE');
        return self::PREFIX.json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    public static function check(SeparateLabEpochAnchor $anchor,array $p,string $signatureB64,string $publicPem,string $pin,string $install,string $release,int $now):array {
        $deny=static fn(string $reason):array=>['status'=>'BLOCKED','reason'=>$reason,'floor_matched'=>false,'commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];
        try {
            $bytes=self::bytes($p);
            if(!preg_match('/^[a-f0-9]{64}$/D',$pin)||!hash_equals(hash('sha256',$publicPem),$pin))throw new RuntimeException('UNPINNED_KEY');
            $key=openssl_pkey_get_public($publicPem);$detail=$key?openssl_pkey_get_details($key):false;
            if(!is_array($detail)||($detail['type']??-1)!==OPENSSL_KEYTYPE_EC||($detail['ec']['curve_name']??'')!=='prime256v1')throw new RuntimeException('INVALID_KEY_TYPE');
            $sig=base64_decode($signatureB64,true);
            if($sig===false||strlen($sig)<64||strlen($sig)>256||openssl_verify($bytes,$sig,$key,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('BAD_SIGNATURE');
            if($now<$p['issued_at']||$now>$p['expires_at'])throw new RuntimeException('EXPIRED_OR_FUTURE_ANCHOR');
            if(!hash_equals($p['installation_id'],$install)||!hash_equals($p['release_set_sha256'],$release))throw new RuntimeException('WRONG_ANCHOR_IDENTITY');
            $current=$anchor->read($install); // independent current read, NOT caller-supplied floor
            if($current['epoch']!==$p['epoch']||$current['revision']!==$p['revision']||!hash_equals($current['release_set_sha256'],$p['release_set_sha256']))throw new RuntimeException('REMOTE_ANCHOR_DRIFT');
            return ['status'=>'MATCHED_SEPARATE_LAB_ANCHOR_NOT_COMMIT','floor_matched'=>true,'epoch'=>$current['epoch'],'commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];
        }catch(Throwable $e){return $deny($e->getMessage());}
    }
}
