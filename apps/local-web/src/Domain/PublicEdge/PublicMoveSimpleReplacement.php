<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use RuntimeException;
use Sokna\Local\Core\Bootstrap;

/**
 * R260 small, Local-authoritative Public replacement. ZERO reads from the OLD
 * Public. Reuses standard Public setup pairing, ordinary Local publisher and
 * signed Local API. Only the destination is new; orders/catalog stay on Local.
 *
 * One protected file stores temporary *new* connection credentials across
 * two normal Local HTTP requests. The old config is NOT modified before
 * first successful sync. An interrupted operation can just retry finish().
 */
final class PublicMoveSimpleReplacement
{
    public function __construct(
        private readonly Bootstrap $local,
        private readonly PublicMoveApprovalLedger $ledger,
        private readonly string $installationId,
        private readonly string $dataDir,
    ) {}

    public function start(string $operationId,string $pairingCode,int $actorId): array
    {
        if (!preg_match('/^[A-F0-9]{8}(?:-[A-F0-9]{8}){2}$/D',$pairingCode)) {
            throw new RuntimeException('invalid_owner_issued_pairing_code');
        }
        if ($actorId<=0)throw new RuntimeException('administrator_required');
        $challenge=$this->ledger->pendingChallenge($this->installationId,$operationId,time());
        $status=$this->ledger->status($this->installationId,$operationId);
        if (($status['status']??null)!=='owner_approved') {
            throw new RuntimeException('signed_destination_owner_approval_required');
        }
        $target=$challenge['target_base_url'];
        $path=$this->statePath();
        if(is_file($path)){
            $state=$this->readState($path);
            if(($state['operation_id']??'')!==$operationId ||
                ($state['target_base_url']??'')!==$target) {
                throw new RuntimeException('another_replacement_in_progress');
            }
            return self::result('sync_pending',$target,true);
        }
        $config=$this->local->config()->all();
        $old=$config['public']??[];
        if(!is_array($old) || ($old['base_url']??'')!==$challenge['source_base_url']) {
            throw new RuntimeException('source_public_binding_changed');
        }
        $secret=bin2hex(random_bytes(32));
        $state=[
            'format'=>'sokna-simple-public-replacement-v1',
            'operation_id'=>$operationId,
            'source_base_url'=>$challenge['source_base_url'],
            'target_base_url'=>$target,
            'new_secret'=>$secret,
            'source_public_hash'=>hash('sha256',self::json($old)),
            'stage'=>'pairing',
        ];
        $this->writeState($path,$state,true);
        try {
            // Original setup pairing endpoint. The R260 replacement flag forces
            // Public guest intake OFF. This is not Emergency or re-enrollment.
            $pair=$this->local->publicEdgeSyncClient()->initialPair(
                $target,$pairingCode,$secret,'',true
            );
            $body=(array)($pair['body']??[]);
            if(($body['replacement_mode']??null)!==true ||
                ($body['guest_intake_enabled']??null)!==false ||
                ($body['installation_id']??null)!==$this->installationId) {
                throw new RuntimeException('public_replacement_pairing_contract_missing');
            }
            $state['stage']='paired';
            $this->writeState($path,$state);
            return self::result('sync_pending',$target,false);
        }catch(\Throwable $e){
            // A remote acknowledgement could be lost after remote commit;
            // do NOT delete the recovery state or try pairing twice blindly.
            $state['stage']='pairing_uncertain';
            $this->writeState($path,$state);
            throw $e;
        }
    }

    public function finish(string $operationId): array
    {
        $state=$this->readState($this->statePath());
        if(!hash_equals((string)($state['operation_id']??''),$operationId) ||
           !in_array((string)($state['stage']??''),['paired','sync_ready','config_committed'],true)){
            throw new RuntimeException('replacement_not_ready_for_sync');
        }
        $target=(string)$state['target_base_url'];
        $values=$this->local->config()->all();
        $previous=(array)($values['public']??[]);
        $alreadyNew=(string)($previous['base_url']??'')===$target &&
                    (string)($previous['shared_secret']??'')===$state['new_secret'];
        if(!$alreadyNew &&
            (!hash_equals($state['source_public_hash'],hash('sha256',self::json($previous)))||
             (string)($previous['base_url']??'')!==$state['source_base_url'])) {
            throw new RuntimeException('local_public_settings_changed_during_replacement');
        }
        $values['public']['base_url']=$target;
        $values['public']['shared_secret']=$state['new_secret'];
        $preview=Bootstrap::fromArray($values);
        if(!$alreadyNew) {
            // On transient network failure, Local's existing binding remains.
            // Retry simply calls finish() again; the new Public stays paused.
            $result=$preview->publicEdgePublisher()->syncAll(10);
            if(($result['success']??false)!==true ||
                ($result['degraded']??true)!==false ||
                (($result['guest_publication']['media_ready']??false)!==true)) {
                return self::result('sync_retry',$target,false);
            }
            $state['stage']='sync_ready';$this->writeState($this->statePath(),$state);
            $this->ledger->claimOnce($this->installationId,$operationId,
                $this->ledger->pendingChallenge($this->installationId,$operationId,time())['nonce'],time());
            $this->local->publicReenrollment()->commitMoveConfig($target,$state['new_secret']);
            $state['stage']='config_committed';$this->writeState($this->statePath(),$state);
        }
        // Do NOT open new guest intake before the Local binding is committed.
        // Activation is signed by the *new* Local/Public pairing secret.
        $activation=$preview->publicEdgeSyncClient()->post('/api/v1/local/move/activate',[],10);
        if(($activation['body']['guest_intake_enabled']??null)!==true) {
            return self::result('activation_retry',$target,false);
        }
        @unlink($this->statePath());
        return self::result('completed',$target,false);
    }

    public function status(): array
    {
        $path=$this->statePath();
        if(!is_file($path))return ['status'=>'none','active'=>false];
        $state=$this->readState($path);
        return [
            'status'=>in_array($state['stage'],['paired','sync_ready','config_committed'],true)
                ?'sync_pending':'support_required',
            'active'=>true,
            'operation_id'=>$state['operation_id'],
            'target_base_url'=>$state['target_base_url'],
            'safe_to_cutover'=>false,
        ];
    }

    private function statePath(): string
    {
        $base=realpath($this->dataDir);
        $web=realpath(dirname(__DIR__,3).'/public');
        if($base===false || $web===false ||
           str_starts_with(strtolower(str_replace('\\','/',$base)).'/',
               strtolower(rtrim(str_replace('\\','/',$web),'/')).'/')) {
            throw new RuntimeException('private_replacement_storage_unavailable');
        }
        $dir=$base.DIRECTORY_SEPARATOR.'private-move';
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))
            throw new RuntimeException('private_replacement_directory_unavailable');
        @chmod($dir,0700);
        return $dir.DIRECTORY_SEPARATOR.'replacement-current.json';
    }

    private function readState(string $path): array
    {
        if(is_link($path)||!is_file($path)||!is_readable($path))
            throw new RuntimeException('replacement_state_missing');
        $data=json_decode((string)file_get_contents($path),true);
        if(!is_array($data)||($data['format']??null)!=='sokna-simple-public-replacement-v1'||
           !preg_match('/^[a-f0-9]{64}$/D',(string)($data['new_secret']??''))) {
            throw new RuntimeException('replacement_state_invalid');
        }
        return $data;
    }

    private function writeState(string $path,array $state,bool $exclusive=false): void
    {
        if(is_link($path))throw new RuntimeException('replacement_state_symlink');
        if($exclusive) {
            $fp=@fopen($path,'x');
            if(!is_resource($fp))throw new RuntimeException('replacement_already_in_progress');
            try{
                @chmod($path,0600);
                if(fwrite($fp,self::json($state))===false || !fflush($fp))
                    throw new RuntimeException('replacement_state_write_failed');
            }finally{fclose($fp);}
            return;
        }
        $temp=$path.'.tmp-'.bin2hex(random_bytes(5));
        if(file_put_contents($temp,self::json($state),LOCK_EX)===false ||
           !@rename($temp,$path)){
            @unlink($temp);throw new RuntimeException('replacement_state_write_failed');
        }
        @chmod($path,0600);
    }

    private static function json(array $state): string
    {
        return json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private static function result(string $status,string $target,bool $resumed): array
    {
        return [
            'status'=>$status,'target_base_url'=>$target,'resumed'=>$resumed,
            'transfer_of_old_public_data'=>false,'local_business_data_unchanged'=>true,
            'completed'=>$status==='completed',
        ];
    }
}
