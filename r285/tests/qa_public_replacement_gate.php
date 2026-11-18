<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Security;
use PDO;use RuntimeException;
final class PublicMoveReplacementGate {
 public function __construct(private readonly PDO $pdo){}
 public function requireNoUnresolvedGuestWork(string $id):void {
  foreach([
   "SELECT COUNT(*) FROM realtime_requests WHERE installation_id=? AND state IN ('queued','claimed','unknown_review')",
   "SELECT COUNT(*) FROM deferred_work WHERE installation_id=? AND state IN ('pending_sync','needs_review')"
  ] as $sql) {
   $s=$this->pdo->prepare($sql);$s->execute([$id]);
   if((int)$s->fetchColumn()!==0)throw new RuntimeException('destination_pending_guest_work');
  }
 }
}
