<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use PDO;
use Sokna\Local\Core\Config;

final class IntegrationWorkspaceService
{
    public function __construct(private readonly PDO $pdo,private readonly Config $config) {}

    public function snapshot(): array
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
        return ['accommodation'=>$accommodation];
    }

    private function safeEndpoint(string $value): string
    {
        $value=trim($value);if($value==='')return '';
        $parts=parse_url($value);if(!is_array($parts))return '';
        $scheme=(string)($parts['scheme']??'');$host=(string)($parts['host']??'');$port=isset($parts['port'])?':'.(int)$parts['port']:'';
        return $scheme!==''&&$host!==''?$scheme.'://'.$host.$port:'';
    }
}
