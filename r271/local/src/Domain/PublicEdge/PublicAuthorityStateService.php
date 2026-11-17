<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use PDO;

final class PublicAuthorityStateService
{
    public function __construct(private readonly PDO $pdo, private readonly string $installationId) {}

    public function status(): array
    {
        $id=trim($this->installationId);
        if($id==='') return ['installation_id'=>'','authority_epoch'=>1,'phase'=>'active'];
        $this->ensure();
        $q=$this->pdo->prepare('SELECT installation_id,authority_epoch,phase,reason,blocked_at,reenrolled_at,activated_at,updated_at FROM public_authority_state WHERE installation_id=? LIMIT 1');
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:['installation_id'=>$id,'authority_epoch'=>1,'phase'=>'active'];
    }

    public function epoch(): int { return max(1,(int)($this->status()['authority_epoch']??1)); }
    public function phase(): string { return (string)($this->status()['phase']??'active'); }
    public function reenrollmentRequired(): bool { return $this->phase()==='reenroll_required'; }
    public function resyncRequired(): bool { return $this->phase()==='resync_required'; }

    public function markRestoreRequired(string $reason='business_restore'): void
    {
        $this->ensure();
        $q=$this->pdo->prepare("UPDATE public_authority_state SET phase='reenroll_required',reason=?,blocked_at=UTC_TIMESTAMP(6),reenrolled_at=NULL,activated_at=NULL WHERE installation_id=?");
        $q->execute([substr($reason,0,96),$this->installationId]);
    }

    public function markReenrolled(int $epoch): void
    {
        if($epoch<1) throw new PublicEdgeSyncException('authority_epoch_invalid','نسل اعتبار Public معتبر نیست.',409);
        $this->ensure();
        $current=$this->epoch();
        if($epoch<=$current) throw new PublicEdgeSyncException('authority_epoch_not_advanced','نسل اعتبار Public باید پس از بازیابی جلو برود.',409);
        $q=$this->pdo->prepare("UPDATE public_authority_state SET authority_epoch=?,phase='resync_required',reason='authority_reenrolled',reenrolled_at=UTC_TIMESTAMP(6),activated_at=NULL WHERE installation_id=?");
        $q->execute([$epoch,$this->installationId]);
    }

    public function markActive(int $epoch): void
    {
        $this->ensure();
        if($epoch!==$this->epoch()) throw new PublicEdgeSyncException('authority_epoch_mismatch','نسل اعتبار Public با Local یکسان نیست.',409);
        $q=$this->pdo->prepare("UPDATE public_authority_state SET phase='active',reason=NULL,activated_at=UTC_TIMESTAMP(6) WHERE installation_id=?");
        $q->execute([$this->installationId]);
    }

    private function ensure(): void
    {
        $id=trim($this->installationId); if($id==='') return;
        $q=$this->pdo->prepare("INSERT INTO public_authority_state(installation_id,authority_epoch,phase) VALUES(?,1,'active') ON DUPLICATE KEY UPDATE installation_id=VALUES(installation_id)");
        $q->execute([$id]);
    }
}
