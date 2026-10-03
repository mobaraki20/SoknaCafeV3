<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

use PDO;
use Sokna\PublicEdge\Core\CanonicalJson;
use Throwable;

final class GuestPublishService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GuestMediaStore $media,
    ) {
    }

    public function publish(string $installationId, array $body): array
    {
        $installationId = trim($installationId);
        $revisionId = trim((string)($body['revision_id'] ?? ''));
        $contentHash = strtolower(trim((string)($body['content_hash'] ?? '')));
        $generatedAt = trim((string)($body['generated_at'] ?? ''));
        $snapshot = is_array($body['snapshot'] ?? null) ? $body['snapshot'] : [];
        $manifest = is_array($body['media_manifest'] ?? null) ? $body['media_manifest'] : [];

        if ($installationId === ''
            || !preg_match('/^guest-[a-f0-9]{32}$/', $revisionId)
            || !preg_match('/^[a-f0-9]{64}$/', $contentHash)
            || ($snapshot['format'] ?? '') !== 'sokna-guest-snapshot-v1') {
            return $this->error(400, 'invalid_guest_revision');
        }

        $recomputed = CanonicalJson::sha256([
            'format' => 'sokna-guest-snapshot-v1',
            'snapshot' => $snapshot,
            'media_manifest' => $manifest,
        ]);
        if (!hash_equals($contentHash, $recomputed) || !hash_equals($revisionId, 'guest-' . substr($recomputed, 0, 32))) {
            return $this->error(422, 'guest_revision_integrity_failed');
        }

        $manifestCheck = $this->media->verifyManifest($installationId, $manifest);
        if (($manifestCheck['ok'] ?? false) !== true) {
            $body = ['ok' => false, 'error' => (string)$manifestCheck['error']];
            if (isset($manifestCheck['source'])) $body['source'] = (string)$manifestCheck['source'];
            if (isset($manifestCheck['sha256'])) $body['sha256'] = (string)$manifestCheck['sha256'];
            return ['status' => (int)$manifestCheck['status'], 'body' => $body];
        }

        $generatedTs = strtotime($generatedAt);
        $generatedSql = gmdate('Y-m-d H:i:s', $generatedTs === false ? time() : $generatedTs);
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare(
                'SELECT content_hash FROM guest_publish_revisions WHERE installation_id=? AND revision_id=? FOR UPDATE'
            );
            $existing->execute([$installationId, $revisionId]);
            $old = $existing->fetchColumn();
            if ($old !== false && !hash_equals((string)$old, $contentHash)) {
                $this->pdo->rollBack();
                return $this->error(409, 'revision_id_conflict');
            }

            if ($old === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) VALUES(?,?,?,?,?,?)'
                );
                $insert->execute([$installationId, $revisionId, $contentHash, $snapshotJson, $manifestJson, $generatedSql]);
            }

            $pointer = $this->pdo->prepare(
                'INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE revision_id=VALUES(revision_id),activated_at=UTC_TIMESTAMP()'
            );
            $pointer->execute([$installationId, $revisionId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 200, 'body' => [
            'ok' => true,
            'revision_id' => $revisionId,
            'active' => true,
            'deduplicated' => $old !== false,
        ]];
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
