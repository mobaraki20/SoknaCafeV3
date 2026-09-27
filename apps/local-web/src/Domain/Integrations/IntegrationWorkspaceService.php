<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use PDO;
use Sokna\Local\Core\Config;

final class IntegrationWorkspaceService
{
    public function __construct(private readonly PDO $pdo,private readonly Config $config) {}

    public function snapshot(bool $admin): array
    {
        $accommodation=[
            'enabled'=>(bool)$this->config->get('integrations.accommodation.enabled',false),
            'endpoint'=>$this->safeEndpoint($this->config->string('integrations.accommodation.base_url','')),
            'recent_transfers'=>$this->pdo->query(
                "SELECT id,session_id,reservation_code,guest_name_snapshot,room_name_snapshot,amount,invoice_number,status,attempt_count,
                        last_attempt_at,posted_at,voided_at,last_error_code,last_error,suspicious_response,local_finalize_pending,local_reversal_pending,created_at,updated_at
                 FROM accommodation_transfers ORDER BY id DESC LIMIT 50"
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
        $center=null;
        if($admin){
            $center=[
                'enabled'=>(bool)$this->config->get('integrations.center.enabled',false),
                'endpoint'=>$this->safeEndpoint($this->config->string('integrations.center.base_url','')),
                'projection_receipts'=>$this->pdo->query(
                    "SELECT source_version,user_count,state,attempt_count,last_error_code,last_error,acknowledged_at,created_at,updated_at
                     FROM center_projection_receipts ORDER BY id DESC LIMIT 30"
                )->fetchAll(PDO::FETCH_ASSOC),
                'entitlements'=>$this->pdo->query(
                    "SELECT c.local_user_id,u.display_name,c.allowed,c.remote_subject,c.checked_at,c.expires_at,c.last_error_code
                     FROM center_entitlement_cache c JOIN users u ON u.id=c.local_user_id ORDER BY c.checked_at DESC LIMIT 50"
                )->fetchAll(PDO::FETCH_ASSOC),
            ];
        }
        return ['accommodation'=>$accommodation,'center'=>$center];
    }

    private function safeEndpoint(string $value): string
    {
        $value=trim($value);if($value==='')return '';
        $parts=parse_url($value);if(!is_array($parts))return '';
        $scheme=(string)($parts['scheme']??'');$host=(string)($parts['host']??'');$port=isset($parts['port'])?':'.(int)$parts['port']:'';
        return $scheme!==''&&$host!==''?$scheme.'://'.$host.$port:'';
    }
}
