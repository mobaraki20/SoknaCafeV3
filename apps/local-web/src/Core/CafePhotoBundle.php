<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Exact Café Sokna photo migration bound to a Maintenance-attested Local Web tree.
 * It never invokes the destructive default menu seeder or edits item prices,
 * visibility, recipes, roles, orders or categories. Absent/mismatching item codes
 * remain unchanged; all source photos are still registered in media library.
 */
final class CafePhotoBundle
{
    private const MARKER = 'cafe_photo_bundle.15aban.r137';
    private const FORMAT = 'sokna-cafe-photo-bundle-v1';
    private const COUNT = 36;
    private const MAX_FILE_BYTES = 2500000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $manifestFile,
    ) {}

    /** @return array<string,mixed> */
    public function validate(): array
    {
        $raw = @file_get_contents($this->manifestFile);
        if (!is_string($raw) || strlen($raw) > 60000) {
            throw new RuntimeException('PHOTO_BUNDLE_MANIFEST_MISSING_OR_TOO_LARGE');
        }
        $doc = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || ($doc['format'] ?? '') !== self::FORMAT ||
            ($doc['version'] ?? '') !== '15aban-r137' ||
            !is_array($doc['images'] ?? null) || count($doc['images']) !== self::COUNT ||
            !is_array($doc['matches'] ?? null)) {
            throw new RuntimeException('PHOTO_BUNDLE_MANIFEST_INVALID');
        }
        $folder = dirname($this->manifestFile);
        $sources = [];
        foreach ($doc['images'] as $asset) {
            if (!is_array($asset)) throw new RuntimeException('PHOTO_BUNDLE_ENTRY_INVALID');
            $name = (string)($asset['file'] ?? '');
            $sha = (string)($asset['sha256'] ?? '');
            if (!preg_match('/^[0-9]{2}\.webp$/D', $name) ||
                !preg_match('/^[0-9a-f]{64}$/D', $sha) ||
                isset($sources[$name]) ||
                ($asset['width_px'] ?? null) !== 1600 ||
                ($asset['height_px'] ?? null) !== 1600) {
                throw new RuntimeException('PHOTO_BUNDLE_ENTRY_UNTRUSTED');
            }
            $path = $folder . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || is_link($path) ||
                ($size = @filesize($path)) === false || $size < 3000 ||
                $size > self::MAX_FILE_BYTES || $size !== ($asset['byte_size'] ?? null)) {
                throw new RuntimeException('PHOTO_BUNDLE_IMAGE_FILE_MISSING');
            }
            $observed = hash_file('sha256', $path);
            if (!is_string($observed) || !hash_equals($sha, $observed)) {
                throw new RuntimeException('PHOTO_BUNDLE_HASH_MISMATCH');
            }
            $dim = @getimagesize($path);
            if (!is_array($dim) || ($dim['mime'] ?? '') !== 'image/webp' ||
                (int)$dim[0] !== 1600 || (int)$dim[1] !== 1600) {
                throw new RuntimeException('PHOTO_BUNDLE_WRONG_IMAGE_DIMENSION');
            }
            $sources[$name] = ['path'=>$path,'sha'=>$sha,'size'=>(int)$size];
        }
        $seenCodes = [];
        foreach ($doc['matches'] as $match) {
            if (!is_array($match) ||
                !preg_match('/^[0-9]{3,4}$/D',(string)($match['item_code'] ?? '')) ||
                !isset($sources[(string)($match['file'] ?? '')]) ||
                !is_string($match['expected_name'] ?? null) ||
                trim($match['expected_name']) === '' ||
                isset($seenCodes[(string)$match['item_code']])) {
                throw new RuntimeException('PHOTO_BUNDLE_MATCH_AMBIGUOUS');
            }
            $seenCodes[(string)$match['item_code']] = true;
        }
        $doc['_validated'] = $sources;
        return $doc;
    }

    /** @return array{status:string,assigned:int,unmatched:int,registered:int} */
    public function apply(): array
    {
        $doc = $this->validate();
        $manifestHash = hash_file('sha256', $this->manifestFile);
        if (!is_string($manifestHash)) throw new RuntimeException('PHOTO_BUNDLE_MANIFEST_HASH_FAILURE');
        $old = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $old->execute([self::MARKER]);
        $found = $old->fetchColumn();
        $old->closeCursor();
        if (is_string($found)) {
            if (!hash_equals($manifestHash, $found)) {
                throw new RuntimeException('PHOTO_BUNDLE_ALREADY_INSTALLED_WITH_OTHER_CONTENT');
            }
            return ['status'=>'already_applied','assigned'=>0,'unmatched'=>0,'registered'=>0];
        }

        $mediaRoot = $this->validateHostReadiness();

        // Files are content-addressed. Copy and verify BEFORE the transaction;
        // any interrupted copy is harmless and idempotent on forward retry.
        $byFile = [];
        foreach ($doc['images'] as $entry) {
            $name = (string)$entry['file'];
            $source = $doc['_validated'][$name];
            $sha = $source['sha'];
            $key = 'm_' . substr($sha, 0, 24);
            $original = 'original/'.$sha.'.webp';
            $derived = 'derived/'.$key.'/guest-card.webp';
            self::copyContentAddressed($source['path'],$mediaRoot,$original,$sha);
            self::copyContentAddressed($source['path'],$mediaRoot,$derived,$sha);
            $byFile[$name] = [
                'sha'=>$sha,'key'=>$key,'size'=>$source['size'],
                'original'=>$original,'derived'=>$derived
            ];
        }

        $this->pdo->beginTransaction();
        try {
            // Ensure a concurrent catalog editor cannot interleave before the
            // marker is saved. Maintenance itself owns the machine-wide lock.
            $assetByFile = [];
            $assetQuery = $this->pdo->prepare('SELECT id,media_key FROM guest_media_assets WHERE sha256=? FOR UPDATE');
            $assetInsert = $this->pdo->prepare('INSERT INTO guest_media_assets(media_key,sha256,mime,extension,byte_size,width_px,height_px,original_name,alt_text,original_relpath,active,created_by_user_id) VALUES(?,?,\'image/webp\',\'webp\',?,1600,1600,?,?,?,1,NULL)');
            $derivativeInsert = $this->pdo->prepare("INSERT INTO guest_media_derivatives(media_id,variant_key,sha256,mime,extension,byte_size,width_px,height_px,path_relpath,processor) VALUES(?,'guest-card',?,'image/webp','webp',?,1600,1600,?,'bundle-passthrough') ON DUPLICATE KEY UPDATE sha256=VALUES(sha256),byte_size=VALUES(byte_size),width_px=VALUES(width_px),height_px=VALUES(height_px),path_relpath=VALUES(path_relpath),processor=VALUES(processor)");
            foreach ($byFile as $file=>$asset) {
                $assetQuery->execute([$asset['sha']]);
                $existing = $assetQuery->fetch(PDO::FETCH_ASSOC);
                $assetQuery->closeCursor();
                if (is_array($existing)) {
                    $id = (int)$existing['id'];
                    $asset['key'] = (string)$existing['media_key'];
                    // Same SHA = already-owned original asset. Preserve its
                    // independent card derivative and editing history; do NOT
                    // overwrite a user-edited crop on an existing media ID.
                } else {
                    $assetInsert->execute([$asset['key'],$asset['sha'],$asset['size'],$file,'',$asset['original']]);
                    $id = (int)$this->pdo->lastInsertId();
                    $derivativeInsert->execute([$id,$asset['sha'],$asset['size'],$asset['derived']]);
                }
                $assetByFile[$file] = ['id'=>$id,'key'=>$asset['key']];
            }
            $queryItem = $this->pdo->prepare('SELECT id,name FROM items WHERE item_code=? FOR UPDATE');
            $assignPath = $this->pdo->prepare('UPDATE items SET image_path=? WHERE id=?');
            $removeRef = $this->pdo->prepare("DELETE FROM guest_media_references WHERE owner_type='item' AND owner_id=? AND slot_key='card'");
            $insertRef = $this->pdo->prepare("INSERT INTO guest_media_references(media_id,owner_type,owner_id,slot_key) VALUES(?,'item',?,'card')");
            $assigned=0;$unmatched=0;
            foreach ($doc['matches'] as $match) {
                $queryItem->execute([(string)$match['item_code']]);
                $row = $queryItem->fetch(PDO::FETCH_ASSOC);
                $queryItem->closeCursor();
                if (!is_array($row) || trim((string)$row['name']) !== trim((string)$match['expected_name'])) {
                    $unmatched++;
                    continue;
                }
                $itemId=(int)$row['id'];
                $source=$assetByFile[(string)$match['file']];
                $assignPath->execute(['media:'.$source['key'],$itemId]);
                $removeRef->execute([(string)$itemId]);
                $insertRef->execute([(int)$source['id'],(string)$itemId]);
                $assigned++;
            }
            $this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
                ->execute([self::MARKER,$manifestHash]);
            $this->pdo->commit();
            return ['status'=>'installed','assigned'=>$assigned,'unmatched'=>$unmatched,'registered'=>count($byFile)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Read-only host/ACL preflight: called before Apache is stopped, before DDL.
     * An absent ProgramData vault cannot be diagnosed after DB-forward starts.
     */
    public function validateHostReadiness(): string
    {
        $webRoot = dirname(dirname($this->manifestFile, 2));
        $configFile = dirname($webRoot) . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($configFile) || is_link($configFile)) {
            throw new RuntimeException('PHOTO_BUNDLE_HOST_CONFIGURATION_MISSING');
        }
        $config = require $configFile;
        $dataDir = is_array($config) && is_array($config['app'] ?? null)
            ? trim((string)($config['app']['data_dir'] ?? '')) : '';
        if ($dataDir === '' || str_contains($dataDir, "\0")) {
            throw new RuntimeException('PHOTO_BUNDLE_HOST_DATA_DIRECTORY_UNAVAILABLE');
        }
        $mediaRoot = rtrim($dataDir, "\\/") . DIRECTORY_SEPARATOR . 'guest-media';
        if (!is_dir($mediaRoot) || is_link($mediaRoot) || !is_writable($mediaRoot)) {
            throw new RuntimeException('PHOTO_BUNDLE_MEDIA_ROOT_NOT_WRITABLE_OR_OWNED');
        }
        return $mediaRoot;
    }

    private static function copyContentAddressed(string $source,string $root,string $relative,string $sha): void
    {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('PHOTO_BUNDLE_MEDIA_DIR_CREATION_FAILED');
        }
        if (is_link($dir) || is_link($path)) throw new RuntimeException('PHOTO_BUNDLE_MEDIA_REPARSE_FORBIDDEN');
        if (is_file($path)) {
            if (!hash_equals($sha, (string)hash_file('sha256',$path))) {
                throw new RuntimeException('PHOTO_BUNDLE_EXISTING_MEDIA_DIGEST_CONFLICT');
            }
            return;
        }
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(8));
        if (!copy($source,$tmp)) throw new RuntimeException('PHOTO_BUNDLE_MEDIA_COPY_FAILED');
        @chmod($tmp,0640);
        try {
            if (!hash_equals($sha, (string)hash_file('sha256',$tmp)) || !rename($tmp,$path)) {
                throw new RuntimeException('PHOTO_BUNDLE_MEDIA_WRITE_NOT_VERIFIED');
            }
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }
}