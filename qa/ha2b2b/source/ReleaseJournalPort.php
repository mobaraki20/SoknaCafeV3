<?php
declare(strict_types=1);
/** Journal status is NOT deployment authorization. No commit/write-side API exists. */
interface ReleaseJournalPort {
    public function inspect(): array;
    public function begin(string $transactionId, string $planDigest): array;
    public function staged(string $transactionId, string $planDigest, string $stageDigest): array;
    public function uncertain(string $transactionId, string $reasonCode): array;
}
