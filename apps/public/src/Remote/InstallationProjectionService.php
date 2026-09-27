<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Remote;

use PDO;
use RuntimeException;

final class InstallationProjectionService
{
    public function __construct(private readonly PDO $pdo) {}

    public function sync(string $installationId, array $body): array
    {
        $installationId=trim($installationId);
        if($installationId==='' || preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$installationId)!==1){
            throw new RuntimeException('invalid installation id');
        }
        $revoked=$this->pdo->prepare('SELECT revoked_at FROM installations WHERE installation_id=? LIMIT 1');$revoked->execute([$installationId]);$rv=$revoked->fetchColumn();if($rv!==false&&$rv!==null&&trim((string)$rv)!=='')throw new RuntimeException('installation revoked');
        $display=trim((string)($body['display_name']??''));
        $remote=!array_key_exists('remote_enabled',$body) || !empty($body['remote_enabled']);
        $orders=!array_key_exists('order_intake_enabled',$body) || !empty($body['order_intake_enabled']);
        $q=$this->pdo->prepare(
            'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,1,?,?) '.
            'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=VALUES(remote_enabled),order_intake_enabled=VALUES(order_intake_enabled)'
        );
        $q->execute([$installationId,$display!==''?$display:null,$remote?1:0,$orders?1:0]);
        return ['status'=>200,'body'=>['ok'=>true,'installation_id'=>$installationId,'remote_enabled'=>$remote,'order_intake_enabled'=>$orders]];
    }
}
