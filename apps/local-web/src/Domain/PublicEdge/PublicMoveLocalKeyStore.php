<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use RuntimeException;

/**
 * Independent per-Local secrets for R259 move consent (not Public pairing secrets).
 * Stored outside the effective webroot with a locked 0600 file, never in HTTP.
 * This is intentionally small and has no remote network or cutover capability.
 */
final class PublicMoveLocalKeyStore
{
    public function __construct(
        private readonly string $dataDir,
        private readonly string $publicDocumentRoot,
    ) {}

    /** @return array{mac:string,csrf:string} raw binary keys */
    public function keys(): array
    {
        $root=realpath($this->dataDir);
        $web=realpath($this->publicDocumentRoot);
        if ($root===false || $web===false || !is_dir($root) || !is_dir($web)) {
            throw new RuntimeException('move_private_storage_unavailable');
        }
        $norm=static fn(string $p):string=>strtolower(rtrim(str_replace('\\','/',$p),'/')).'/';
        if (str_starts_with($norm($root),$norm($web))) {
            throw new RuntimeException('move_keys_inside_webroot');
        }
        $directory=$root.DIRECTORY_SEPARATOR.'private-move';
        if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) {
            throw new RuntimeException('move_key_directory_unavailable');
        }
        @chmod($directory,0700);
        $path=$directory.DIRECTORY_SEPARATOR.'local-approval.json';
        if (is_link($path) || is_link($directory)) throw new RuntimeException('move_key_symlink_denied');
        $handle=@fopen($path,'c+b');
        if (!is_resource($handle)) throw new RuntimeException('move_key_file_unavailable');
        try {
            if (!flock($handle,LOCK_EX)) throw new RuntimeException('move_key_lock_unavailable');
            @chmod($path,0600);
            rewind($handle);
            $raw=stream_get_contents($handle,4097);
            if (!is_string($raw)) throw new RuntimeException('move_key_read_failed');
            if ($raw==='') {
                $encoded=[
                    'format'=>'sokna-local-move-keys-v1',
                    'mac_key_hex'=>bin2hex(random_bytes(32)),
                    'csrf_key_hex'=>bin2hex(random_bytes(32))
                ];
                $raw=json_encode($encoded,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                if (!ftruncate($handle,0)||fseek($handle,0)!==0 ||
                    fwrite($handle,$raw)!==strlen($raw) || !fflush($handle)) {
                    throw new RuntimeException('move_key_write_failed');
                }
            }
            $obj=json_decode($raw,true);
            if (!is_array($obj) || ($obj['format']??null)!=='sokna-local-move-keys-v1') {
                throw new RuntimeException('move_key_format_invalid');
            }
            $mac=$obj['mac_key_hex']??null;$csrf=$obj['csrf_key_hex']??null;
            if (!is_string($mac)||!is_string($csrf)||
                !preg_match('/^[a-f0-9]{64}$/D',$mac)||
                !preg_match('/^[a-f0-9]{64}$/D',$csrf)) {
                throw new RuntimeException('move_key_material_invalid');
            }
            return ['mac'=>hex2bin($mac),'csrf'=>hex2bin($csrf)];
        } finally {
            @flock($handle,LOCK_UN);
            fclose($handle);
        }
    }
}
