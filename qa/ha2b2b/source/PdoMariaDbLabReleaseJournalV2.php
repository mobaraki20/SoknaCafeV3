<?php
declare(strict_types=1);
require_once __DIR__.'/ReleaseJournalPort.php';
/**
 * F1-HA2B source-only, DISPOSABLE MARiADB laboratory driver.
 * No public install/activate/recover/rollback or signed commit can be performed.
 * DB row locks are transactional, but are not an off-host monotonic trust root.
 */
final class PdoMariaDbLabReleaseJournalV2 implements ReleaseJournalPort {
    private const PHASES = ['LAB_IDLE_UNQUALIFIED','PREPARED','STAGED_OBSERVED','UNKNOWN'];
    private const DB_PREFIX = 'sokna_ha2_lab_';
    public function __construct(private PDO $db, private string $installationId, string $databaseName, bool $disposableLab=false) {
        if (!$disposableLab || !self::hex($installationId,32) || !preg_match('/\Asokna_ha2_lab_[a-f0-9]{8,16}\z/D',$databaseName)) {
            throw new RuntimeException('DISPOSABLE_DB_AND_ID_REQUIRED');
        }
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('PDO_MYSQL_REQUIRED');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        $actual=$db->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($actual) || !hash_equals($databaseName,$actual)) throw new RuntimeException('WRONG_LAB_DATABASE');
        $version=$db->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($version) || stripos($version,'mariadb')===false) throw new RuntimeException('MARIADB_REQUIRED');
    }
    private static function hex(string $s,int $n):bool{return (bool)preg_match('/\A[a-f0-9]{'.$n.'}\z/D',$s);}
    private static function out(string $code,bool $ok=false,array $extra=[]):array {
        return ['ok'=>$ok,'code'=>$code,'execution_authorized'=>false,'panel_update_ready'=>false,'for_install'=>false,
            'installed_release_proven'=>false,'commit_proven'=>false,'trusted_offhost_floor'=>false]+$extra;
    }
    private function guard(bool $forWrite):array {
        $st=$this->db->prepare('SELECT installation_id,sequence_no,fence_no,phase,tx_id,plan_sha256,stage_sha256 FROM sokna_ha2_lab_guard WHERE installation_id=?'.($forWrite?' FOR UPDATE':''));
        $st->execute([$this->installationId]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row)throw new RuntimeException('NO_DISPOSABLE_GUARD');
        if (!in_array($row['phase'],self::PHASES,true))throw new RuntimeException('BAD_GUARD_PHASE');
        $sequence=(int)$row['sequence_no'];$fence=(int)$row['fence_no'];
        if ($sequence<0||$fence<0||$sequence>=PHP_INT_MAX-1||$fence>=PHP_INT_MAX-1)throw new RuntimeException('BAD_COUNTER');
        if (($sequence===0) !== ($fence===0))throw new RuntimeException('INVALID_GENESIS');
        if ($sequence===0 && ($row['phase']!=='LAB_IDLE_UNQUALIFIED'||$row['tx_id']!==null||$row['plan_sha256']!==null||$row['stage_sha256']!==null))throw new RuntimeException('INVALID_IDLE');
        if ($sequence>0 && (!self::hex((string)$row['tx_id'],32)||!self::hex((string)$row['plan_sha256'],64)))throw new RuntimeException('INVALID_ACTIVE_ROW');
        if ($row['phase']==='STAGED_OBSERVED' && !self::hex((string)$row['stage_sha256'],64))throw new RuntimeException('INVALID_STAGE_EVIDENCE');
        // Detect missing middle rows and inconsistent last event without trusting an editable flag.
        $s=$this->db->prepare('SELECT COUNT(*) AS n, COALESCE(MAX(sequence_no),0) AS last, COALESCE(MIN(sequence_no),0) AS first FROM sokna_ha2_lab_events WHERE installation_id=?');
        $s->execute([$this->installationId]);$tot=$s->fetch(PDO::FETCH_ASSOC);
        if ((int)$tot['n']!==$sequence||(int)$tot['last']!==$sequence||($sequence>0&&(int)$tot['first']!==1))throw new RuntimeException('LAB_EVENT_GAP');
        if ($sequence>0){
            $s=$this->db->prepare('SELECT sequence_no,fence_no,tx_id,plan_sha256,event_name,stage_sha256 FROM sokna_ha2_lab_events WHERE installation_id=? AND sequence_no=?');
            $s->execute([$this->installationId,$sequence]);$event=$s->fetch(PDO::FETCH_ASSOC);
            if (!$event || (int)$event['fence_no']!==$fence || $event['tx_id']!==$row['tx_id'] || $event['plan_sha256']!==$row['plan_sha256'])throw new RuntimeException('LAST_EVENT_MISMATCH');
            $expected=['PREPARED'=>'BEGIN','STAGED_OBSERVED'=>'STAGED_OBSERVED','UNKNOWN'=>'UNKNOWN'];
            if (($expected[$row['phase']]??null)!==$event['event_name'])throw new RuntimeException('PHASE_EVENT_MISMATCH');
            if ($row['phase']==='STAGED_OBSERVED' && $event['stage_sha256']!==$row['stage_sha256'])throw new RuntimeException('STAGED_DIGEST_MISMATCH');
        }
        return $row;
    }
    private function transaction(callable $f):array {
        try {
            if ($this->db->inTransaction())return self::out('REENTRANT_TRANSACTION_DENIED');
            $this->db->beginTransaction();
            $response=$f($this->guard(true));
            $this->db->commit();return $response;
        }catch(Throwable $e){
            try {if($this->db->inTransaction())$this->db->rollBack();}catch(Throwable $ignored){}
            return self::out('DB_ERROR_OR_UNKNOWN_FAIL_CLOSED');
        }
    }
    private function append(array $row,string $phase,string $event,string $tx,string $plan,?string $stage=null,?string $reason=null,bool $new=false):array {
        $seq=(int)$row['sequence_no']+1;
        $fence=(int)$row['fence_no']+($new?1:0);
        if ($seq>=PHP_INT_MAX||$fence<1||$fence>=PHP_INT_MAX)throw new RuntimeException('COUNTER_EXHAUSTED');
        $u=$this->db->prepare('UPDATE sokna_ha2_lab_guard SET sequence_no=?,fence_no=?,phase=?,tx_id=?,plan_sha256=?,stage_sha256=? WHERE installation_id=? AND sequence_no=? AND fence_no=? AND phase=?');
        $u->execute([$seq,$fence,$phase,$tx,$plan,$stage??$row['stage_sha256'],$this->installationId,$row['sequence_no'],$row['fence_no'],$row['phase']]);
        if ($u->rowCount()!==1)throw new RuntimeException('FENCE_REJECTED');
        $i=$this->db->prepare('INSERT INTO sokna_ha2_lab_events(installation_id,sequence_no,fence_no,tx_id,plan_sha256,event_name,stage_sha256,reason_code) VALUES(?,?,?,?,?,?,?,?)');
        $i->execute([$this->installationId,$seq,$fence,$tx,$plan,$event,$stage,$reason]);
        return self::out('LAB_'.$phase.'_UNQUALIFIED',true,['db_sequence_lab_only'=>$seq,'db_fence_lab_only'=>$fence]);
    }
    public function inspect():array {
        try {$r=$this->guard(false);return self::out('LAB_'.$r['phase'],true,['phase'=>$r['phase'],'transaction_id'=>$r['tx_id'],'sequence'=>(int)$r['sequence_no'],'fence'=>(int)$r['fence_no'],'pending_recovery'=>$r['phase']!=='LAB_IDLE_UNQUALIFIED']);}
        catch(Throwable $e){return self::out('DB_UNAVAILABLE_OR_INCONSISTENT');}
    }
    public function begin(string $transactionId,string $planDigest):array {
        if(!self::hex($transactionId,32)||!self::hex($planDigest,64))return self::out('INVALID_BEGIN_INPUT');
        return $this->transaction(function(array $g)use($transactionId,$planDigest):array {
            if($g['phase']!=='LAB_IDLE_UNQUALIFIED')return self::out($g['tx_id']===$transactionId && $g['plan_sha256']===$planDigest?'LAB_IDEMPOTENT_UNRESOLVED':'LAB_TRANSACTION_CONFLICT');
            return $this->append($g,'PREPARED','BEGIN',$transactionId,$planDigest,null,null,true);
        });
    }
    public function staged(string $transactionId,string $planDigest,string $stageDigest):array {
        if(!self::hex($transactionId,32)||!self::hex($planDigest,64)||!self::hex($stageDigest,64))return self::out('INVALID_STAGED_INPUT');
        return $this->transaction(function(array $g)use($transactionId,$planDigest,$stageDigest):array {
            if($g['tx_id']!==$transactionId||$g['plan_sha256']!==$planDigest)return self::out('LAB_TX_OR_PLAN_MISMATCH');
            if($g['phase']==='STAGED_OBSERVED')return self::out($g['stage_sha256']===$stageDigest?'LAB_IDEMPOTENT_STAGED':'LAB_STAGE_DIGEST_CONFLICT');
            if($g['phase']!=='PREPARED')return self::out('LAB_INVALID_STAGE_PHASE');
            return $this->append($g,'STAGED_OBSERVED','STAGED_OBSERVED',$transactionId,$planDigest,$stageDigest);
        });
    }
    public function uncertain(string $transactionId,string $reasonCode):array {
        if(!self::hex($transactionId,32)||!preg_match('/\A[A-Z0-9_]{3,40}\z/D',$reasonCode))return self::out('INVALID_UNKNOWN_INPUT');
        return $this->transaction(function(array $g)use($transactionId,$reasonCode):array {
            if($g['tx_id']!==$transactionId)return self::out('LAB_TX_MISMATCH');
            if($g['phase']==='UNKNOWN')return self::out('LAB_IDEMPOTENT_UNKNOWN');
            if(!in_array($g['phase'],['PREPARED','STAGED_OBSERVED'],true))return self::out('LAB_INVALID_UNKNOWN_PHASE');
            return $this->append($g,'UNKNOWN','UNKNOWN',$transactionId,(string)$g['plan_sha256'],null,$reasonCode);
        });
    }
}
