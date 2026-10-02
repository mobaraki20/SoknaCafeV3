<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\GuestContent;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class GuestContentService
{
    private const MAX_MEDIA_BYTES = 8 * 1024 * 1024;
    private const MEDIA_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** @var array<string,array{label:string,default:string,max:int,multiline:bool}> */
    private const COPY = [
        'menu_subtitle' => ['label'=>'زیرعنوان منو','default'=>'منوی عمومی','max'=>80,'multiline'=>false],
        'ready_title' => ['label'=>'عنوان ارتباط زنده','default'=>'ارتباط زنده برقرار است','max'=>100,'multiline'=>false],
        'ready_body' => ['label'=>'توضیح ارتباط زنده','default'=>'وضعیت سفارش‌گیری از کافه به‌روز است.','max'=>180,'multiline'=>true],
        'degraded_title' => ['label'=>'عنوان فقط‌خواندنی','default'=>'منو در حالت فقط‌خواندنی است','max'=>100,'multiline'=>false],
        'degraded_body' => ['label'=>'توضیح فقط‌خواندنی','default'=>'ارتباط زنده با کافه موقتاً در دسترس نیست؛ مشاهده منو ادامه دارد.','max'=>220,'multiline'=>true],
        'search_label' => ['label'=>'برچسب جست‌وجو','default'=>'جست‌وجوی منو','max'=>80,'multiline'=>false],
        'search_placeholder' => ['label'=>'راهنمای جست‌وجو','default'=>'نام نوشیدنی یا غذا را بنویسید','max'=>120,'multiline'=>false],
        'empty_title' => ['label'=>'عنوان منوی خالی','default'=>'منو هنوز آیتمی ندارد','max'=>100,'multiline'=>false],
        'empty_body' => ['label'=>'توضیح منوی خالی','default'=>'لطفاً کمی بعد دوباره بررسی کنید.','max'=>180,'multiline'=>true],
        'unavailable' => ['label'=>'آیتم ناموجود','default'=>'ناموجود','max'=>60,'multiline'=>false],
        'scan_table' => ['label'=>'راهنمای سفارش بدون میز','default'=>'برای سفارش QR میز را اسکن کنید','max'=>120,'multiline'=>false],
        'ordering_disabled' => ['label'=>'سفارش‌گیری غیرفعال','default'=>'سفارش‌گیری موقتاً غیرفعال است','max'=>120,'multiline'=>false],
        'no_results' => ['label'=>'بدون نتیجه جست‌وجو','default'=>'نتیجه‌ای پیدا نشد.','max'=>100,'multiline'=>false],
        'basket_total' => ['label'=>'عنوان جمع سفارش','default'=>'جمع سفارش','max'=>70,'multiline'=>false],
        'submit_order' => ['label'=>'دکمه ثبت سفارش','default'=>'ثبت سفارش','max'=>70,'multiline'=>false],
        'call_waiter' => ['label'=>'دکمه فراخوان همکار سالن','default'=>'فراخوان گارسون','max'=>80,'multiline'=>false],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly ThemePackageManager $themes,
        private readonly string $mediaRoot,
    ) {
    }

    public function snapshot(array $actor): array
    {
        $this->assertAdmin($actor);
        $state = $this->state();
        $draftSettings = $this->jsonObject((string)$state['draft_theme_settings_json']);
        $publishedSettings = $this->jsonObject((string)$state['published_theme_settings_json']);
        $draftCopy = $this->mergeCopy($this->jsonObject((string)$state['draft_copy_json']));
        $publishedCopy = $this->mergeCopy($this->jsonObject((string)$state['published_copy_json']));
        $media = $this->pdo->query(
            "SELECT a.id,a.media_key,a.sha256,a.mime,a.extension,a.byte_size,a.width_px,a.height_px,a.original_name,a.alt_text,a.active,a.archived_at,a.created_at,"
            . "(SELECT COUNT(*) FROM guest_media_references r WHERE r.media_id=a.id) reference_count,"
            . "d.sha256 guest_card_sha256,d.byte_size guest_card_bytes,d.width_px guest_card_width,d.height_px guest_card_height,d.processor guest_card_processor "
            . "FROM guest_media_assets a LEFT JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' ORDER BY a.active DESC,a.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = $this->pdo->query(
            "SELECT i.id,i.name,i.image_path,c.name category_name FROM items i JOIN categories c ON c.id=i.category_id "
            . "WHERE i.active=1 AND i.staff_only=0 AND i.sellable_kind='menu_item' ORDER BY c.sort_order,i.sort_order,i.id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'themes' => $this->themes->packages(),
            'draft_theme' => $this->themes->resolve((string)$state['draft_theme_key'], $draftSettings),
            'published_theme' => $this->themes->resolve((string)$state['published_theme_key'], $publishedSettings),
            'copy_schema' => self::COPY,
            'draft_copy' => $draftCopy,
            'published_copy' => $publishedCopy,
            'published_revision' => (int)$state['published_revision'],
            'published_at' => $state['published_at'],
            'media' => $media,
            'items' => $items,
        ];
    }

    public function saveThemeDraft(array $data, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $themeKey = trim((string)($data['theme_key'] ?? ''));
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        try {
            $resolved = $this->themes->resolve($themeKey, $settings);
        } catch (Throwable $e) {
            throw new GuestContentException('theme_invalid','بسته یا تنظیمات تم معتبر نیست.',422,['reason'=>$e->getMessage()]);
        }
        $editable = (array)$resolved['editable_tokens'];
        $stored = [];
        foreach ($editable as $key) if (array_key_exists($key, $settings)) $stored[(string)$key] = (string)$resolved['tokens'][(string)$key];
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('UPDATE guest_content_config SET draft_theme_key=?,draft_theme_settings_json=? WHERE id=1');
            $q->execute([$themeKey, $this->json($stored)]);
            $this->audit('guest.theme_draft_saved','guest_content','theme',(int)$admin['id'],['theme_key'=>$themeKey,'settings'=>$stored]);
            $this->pdo->commit();
            return ['saved'=>true,'theme'=>$resolved];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveCopyDraft(array $data, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $input = is_array($data['copy'] ?? null) ? $data['copy'] : [];
        $copy = $this->normalizeCopy($input);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE guest_content_config SET draft_copy_json=? WHERE id=1')->execute([$this->json($copy)]);
            $this->audit('guest.copy_draft_saved','guest_content','copy',(int)$admin['id'],['keys'=>array_keys($copy)]);
            $this->pdo->commit();
            return ['saved'=>true,'copy'=>$copy];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function publishDraft(array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $this->pdo->beginTransaction();
        try {
            $state = $this->pdo->query('SELECT * FROM guest_content_config WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state)) throw new GuestContentException('content_state_missing','وضعیت محتوای مهمان پیدا نشد.',500);
            try {
                $this->themes->resolve((string)$state['draft_theme_key'], $this->jsonObject((string)$state['draft_theme_settings_json']));
            } catch (Throwable $e) {
                throw new GuestContentException('theme_invalid','پیش‌نویس تم دیگر معتبر نیست.',422,['reason'=>$e->getMessage()]);
            }
            $copy = $this->normalizeCopy($this->jsonObject((string)$state['draft_copy_json']));
            $revision = (int)$state['published_revision'] + 1;
            $q = $this->pdo->prepare(
                'UPDATE guest_content_config SET published_theme_key=draft_theme_key,published_theme_settings_json=draft_theme_settings_json,'
                . 'published_copy_json=?,published_revision=?,published_at=UTC_TIMESTAMP(),published_by_user_id=? WHERE id=1'
            );
            $q->execute([$this->json($copy), $revision, (int)$admin['id']]);
            $this->audit('guest.content_published','guest_content','published',(int)$admin['id'],['revision'=>$revision,'theme_key'=>(string)$state['draft_theme_key']]);
            $this->pdo->commit();
            return ['published'=>true,'revision'=>$revision];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{theme:array<string,mixed>,copy:array<string,string>,revision:int,published_at:string} */
    public function publishedPresentation(): array
    {
        $state = $this->state();
        try {
            $theme = $this->themes->resolve((string)$state['published_theme_key'], $this->jsonObject((string)$state['published_theme_settings_json']));
        } catch (Throwable) {
            $theme = $this->themes->resolve('sokna-house', []);
        }
        return [
            'theme' => $theme,
            'copy' => $this->mergeCopy($this->jsonObject((string)$state['published_copy_json'])),
            'revision' => (int)$state['published_revision'],
            'published_at' => (string)($state['published_at'] ?? ''),
        ];
    }

    public function importUpload(string $tmpPath, string $originalName, string $altText, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        if (!is_file($tmpPath) || !is_readable($tmpPath)) throw new GuestContentException('media_upload_missing','فایل بارگذاری‌شده در دسترس نیست.',422);
        $size = (int)filesize($tmpPath);
        if ($size < 1 || $size > self::MAX_MEDIA_BYTES) throw new GuestContentException('media_size','حجم تصویر باید حداکثر ۸ مگابایت باشد.',413);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
        $extension = self::MEDIA_MIME[$mime] ?? '';
        if ($extension === '') throw new GuestContentException('media_type','فقط JPG، PNG، WebP و GIF قابل استفاده است.',422);
        $dimensions = @getimagesize($tmpPath);
        if (!is_array($dimensions) || (int)($dimensions[0] ?? 0) < 1 || (int)($dimensions[1] ?? 0) < 1) {
            throw new GuestContentException('media_invalid_image','فایل انتخاب‌شده تصویر معتبر نیست.',422);
        }
        $sha = hash_file('sha256', $tmpPath);
        if (!is_string($sha)) throw new GuestContentException('media_hash','خواندن تصویر کامل نشد.',500);
        $existing = $this->pdo->prepare('SELECT media_key FROM guest_media_assets WHERE sha256=? LIMIT 1');
        $existing->execute([$sha]);
        $key = $existing->fetchColumn();
        if (is_string($key) && $key !== '') return ['deduplicated'=>true,'media_key'=>$key];
        $mediaKey = 'm_' . substr($sha, 0, 24);
        $originalName = $this->plain($originalName, 255, 'تصویر');
        $altText = $this->plain($altText, 180, '');
        $originalRel = 'original/' . $sha . '.' . $extension;
        $originalPath = $this->path($originalRel);
        $this->ensureDir(dirname($originalPath));
        if (!is_file($originalPath) && !copy($tmpPath, $originalPath)) throw new GuestContentException('media_store','ذخیره تصویر انجام نشد.',500);
        @chmod($originalPath, 0640);
        $derivative = $this->createGuestCard($originalPath, $mediaKey, $mime, $extension, (int)$dimensions[0], (int)$dimensions[1]);
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare(
                'INSERT INTO guest_media_assets(media_key,sha256,mime,extension,byte_size,width_px,height_px,original_name,alt_text,original_relpath,active,created_by_user_id) '
                . 'VALUES(?,?,?,?,?,?,?,?,?,?,1,?)'
            );
            $q->execute([$mediaKey,$sha,$mime,$extension,$size,(int)$dimensions[0],(int)$dimensions[1],$originalName,$altText,$originalRel,(int)$admin['id']]);
            $id = (int)$this->pdo->lastInsertId();
            $d = $this->pdo->prepare(
                'INSERT INTO guest_media_derivatives(media_id,variant_key,sha256,mime,extension,byte_size,width_px,height_px,path_relpath,processor) VALUES(?,?,?,?,?,?,?,?,?,?)'
            );
            $d->execute([$id,'guest-card',$derivative['sha256'],$derivative['mime'],$derivative['extension'],$derivative['byte_size'],$derivative['width'],$derivative['height'],$derivative['relpath'],$derivative['processor']]);
            $this->audit('guest.media_imported','guest_media',$mediaKey,(int)$admin['id'],['sha256'=>$sha,'mime'=>$mime,'bytes'=>$size,'derivative_processor'=>$derivative['processor']]);
            $this->pdo->commit();
            return ['deduplicated'=>false,'media_key'=>$mediaKey];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function assignMediaToItem(int $itemId, string $mediaKey, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        if ($itemId < 1) throw new GuestContentException('item_invalid','آیتم معتبر نیست.',422);
        $mediaKey = trim($mediaKey);
        $this->pdo->beginTransaction();
        try {
            $item = $this->pdo->prepare('SELECT id,name FROM items WHERE id=? FOR UPDATE');
            $item->execute([$itemId]);
            $row = $item->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new GuestContentException('item_missing','آیتم پیدا نشد.',404);
            $this->pdo->prepare("DELETE FROM guest_media_references WHERE owner_type='item' AND owner_id=? AND slot_key='card'")->execute([(string)$itemId]);
            $imagePath = '';
            if ($mediaKey !== '') {
                $q = $this->pdo->prepare('SELECT id FROM guest_media_assets WHERE media_key=? AND active=1 FOR UPDATE');
                $q->execute([$mediaKey]);
                $mediaId = (int)($q->fetchColumn() ?: 0);
                if ($mediaId < 1) throw new GuestContentException('media_missing','تصویر فعال پیدا نشد.',404);
                $imagePath = 'media:' . $mediaKey;
                $r = $this->pdo->prepare("INSERT INTO guest_media_references(media_id,owner_type,owner_id,slot_key) VALUES(?,'item',?,'card')");
                $r->execute([$mediaId,(string)$itemId]);
            }
            $this->pdo->prepare('UPDATE items SET image_path=? WHERE id=?')->execute([$imagePath,$itemId]);
            $this->audit('guest.media_assigned','menu_item',(string)$itemId,(int)$admin['id'],['media_key'=>$mediaKey]);
            $this->pdo->commit();
            return ['item_id'=>$itemId,'image_path'=>$imagePath];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateAlt(string $mediaKey, string $altText, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $altText = $this->plain($altText, 180, '');
        $q = $this->pdo->prepare('UPDATE guest_media_assets SET alt_text=? WHERE media_key=? AND active=1');
        $q->execute([$altText, trim($mediaKey)]);
        if ($q->rowCount() < 1) throw new GuestContentException('media_missing','تصویر فعال پیدا نشد.',404);
        $this->audit('guest.media_alt_updated','guest_media',trim($mediaKey),(int)$admin['id'],['alt_text'=>$altText]);
        return ['saved'=>true];
    }

    public function archiveMedia(string $mediaKey, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $mediaKey = trim($mediaKey);
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT id,active FROM guest_media_assets WHERE media_key=? FOR UPDATE');
            $q->execute([$mediaKey]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new GuestContentException('media_missing','تصویر پیدا نشد.',404);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM guest_media_references WHERE media_id=?');
            $count->execute([(int)$row['id']]);
            if ((int)$count->fetchColumn() > 0) throw new GuestContentException('media_in_use','این تصویر هنوز در کاتالوگ استفاده می‌شود.',409);
            $this->pdo->prepare('UPDATE guest_media_assets SET active=0,archived_at=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$row['id']]);
            $this->audit('guest.media_archived','guest_media',$mediaKey,(int)$admin['id'],[]);
            $this->pdo->commit();
            return ['archived'=>true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function garbageCollect(array $actor, bool $dryRun = true): array
    {
        $admin = $this->assertAdmin($actor);
        $rows = $this->pdo->query(
            "SELECT a.id,a.media_key,a.original_relpath,d.path_relpath FROM guest_media_assets a "
            . "LEFT JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' "
            . "WHERE a.active=0 AND NOT EXISTS(SELECT 1 FROM guest_media_references r WHERE r.media_id=a.id) ORDER BY a.id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($dryRun) return ['dry_run'=>true,'candidates'=>array_map(static fn(array $r): string => (string)$r['media_key'],$rows)];
        $removed = [];
        foreach ($rows as $row) {
            $this->pdo->beginTransaction();
            try {
                $q = $this->pdo->prepare('SELECT COUNT(*) FROM guest_media_references WHERE media_id=? FOR UPDATE');
                $q->execute([(int)$row['id']]);
                if ((int)$q->fetchColumn() !== 0) { $this->pdo->rollBack(); continue; }
                $this->pdo->prepare('DELETE FROM guest_media_assets WHERE id=? AND active=0')->execute([(int)$row['id']]);
                $this->audit('guest.media_gc','guest_media',(string)$row['media_key'],(int)$admin['id'],[]);
                $this->pdo->commit();
                foreach (['original_relpath','path_relpath'] as $field) {
                    $rel = trim((string)($row[$field] ?? ''));
                    if ($rel !== '') @unlink($this->path($rel));
                }
                $removed[] = (string)$row['media_key'];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
        }
        return ['dry_run'=>false,'removed'=>$removed];
    }

    /** @return array{path:string,mime:string}|null */
    public function localPreview(string $mediaKey,array $actor): ?array
    {
        $this->assertAdmin($actor);
        $q=$this->pdo->prepare("SELECT d.path_relpath,d.mime FROM guest_media_assets a JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' WHERE a.media_key=? AND a.active=1 LIMIT 1");
        $q->execute([trim($mediaKey)]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $path=$this->path((string)$row['path_relpath']);
        return is_file($path)&&is_readable($path)?['path'=>$path,'mime'=>(string)$row['mime']]:null;
    }

    /** @return array{source:string,manifest:array<string,mixed>}|null */
    public function publicMediaForSource(string $source): ?array
    {
        $source = trim($source);
        if (!preg_match('/^media:([A-Za-z0-9_-]{3,80})$/D', $source, $m)) return null;
        $q = $this->pdo->prepare(
            "SELECT d.sha256,d.mime,d.extension,d.byte_size,a.alt_text FROM guest_media_assets a "
            . "JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' WHERE a.media_key=? AND a.active=1 LIMIT 1"
        );
        $q->execute([$m[1]]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return ['source'=>$source,'manifest'=>[
            'sha256'=>(string)$row['sha256'],
            'mime'=>(string)$row['mime'],
            'extension'=>(string)$row['extension'],
            'size'=>(int)$row['byte_size'],
            'alt_text'=>(string)$row['alt_text'],
        ]];
    }

    /** @return list<array<string,mixed>> */
    public function mediaPayloads(array $manifest): array
    {
        $seen = [];
        $payloads = [];
        foreach ($manifest as $meta) {
            if (!is_array($meta)) continue;
            $sha = strtolower(trim((string)($meta['sha256'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/D', $sha) || isset($seen[$sha])) continue;
            $seen[$sha] = true;
            $q = $this->pdo->prepare(
                "SELECT d.sha256,d.mime,d.extension,d.byte_size,d.path_relpath FROM guest_media_derivatives d WHERE d.variant_key='guest-card' AND d.sha256=? LIMIT 1"
            );
            $q->execute([$sha]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new GuestContentException('media_replica_missing','نسخه انتشار تصویر پیدا نشد.',500,['sha256'=>$sha]);
            $path = $this->path((string)$row['path_relpath']);
            $bytes = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($bytes) || !hash_equals($sha, hash('sha256',$bytes))) throw new GuestContentException('media_replica_integrity','یکپارچگی نسخه انتشار تصویر معتبر نیست.',500,['sha256'=>$sha]);
            $payloads[] = [
                'sha256'=>$sha,
                'mime'=>(string)$row['mime'],
                'extension'=>(string)$row['extension'],
                'size'=>(int)$row['byte_size'],
                'content_base64'=>base64_encode($bytes),
            ];
        }
        return $payloads;
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $row = $this->pdo->query('SELECT * FROM guest_content_config WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new GuestContentException('content_state_missing','وضعیت محتوای مهمان پیدا نشد.',500);
        return $row;
    }

    /** @return array<string,string> */
    private function normalizeCopy(array $input): array
    {
        $out = [];
        foreach (self::COPY as $key => $meta) {
            $value = array_key_exists($key,$input) ? (string)$input[$key] : (string)$meta['default'];
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value) ?? '';
            $value = trim($value);
            if ($value === '') $value = (string)$meta['default'];
            if (str_contains($value,'<') || str_contains($value,'>')) throw new GuestContentException('copy_markup','متن مهمان باید متن ساده و بدون HTML باشد.',422,['key'=>$key]);
            if ($this->length($value) > (int)$meta['max']) throw new GuestContentException('copy_too_long','یکی از متن‌های مهمان بیش از حد طولانی است.',422,['key'=>$key,'max'=>$meta['max']]);
            $out[$key] = $value;
        }
        return $out;
    }

    /** @return array<string,string> */
    private function mergeCopy(array $stored): array
    {
        $out = [];
        foreach (self::COPY as $key => $meta) {
            $value = trim((string)($stored[$key] ?? ''));
            $out[$key] = $value !== '' ? $this->slice($value,(int)$meta['max']) : (string)$meta['default'];
        }
        return $out;
    }

    private function createGuestCard(string $sourcePath, string $mediaKey, string $mime, string $extension, int $width, int $height): array
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            throw new GuestContentException('media_processor_unavailable','پردازش استاندارد تصویر فعال نیست؛ افزونه GD با پشتیبانی WebP لازم است.',503);
        }

        $src = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($sourcePath) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($sourcePath) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($sourcePath) : false,
            default => false,
        };
        if ($src === false) {
            throw new GuestContentException('media_decode_failed','خواندن تصویر برای ساخت نسخه استاندارد منو انجام نشد.',422);
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            $orientation = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
            $rotated = match ($orientation) {
                3 => @imagerotate($src, 180, 0),
                6 => @imagerotate($src, -90, 0),
                8 => @imagerotate($src, 90, 0),
                default => false,
            };
            if ($rotated !== false) {
                imagedestroy($src);
                $src = $rotated;
            }
        }

        $sourceWidth = imagesx($src);
        $sourceHeight = imagesy($src);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($src);
            throw new GuestContentException('media_invalid_image','ابعاد تصویر معتبر نیست.',422);
        }

        $target = 640;
        $crop = min($sourceWidth, $sourceHeight);
        $sourceX = (int)floor(($sourceWidth - $crop) / 2);
        $sourceY = (int)floor(($sourceHeight - $crop) / 2);
        $canvas = imagecreatetruecolor($target, $target);
        if ($canvas === false) {
            imagedestroy($src);
            throw new GuestContentException('media_processor_unavailable','حافظه لازم برای پردازش تصویر در دسترس نیست.',503);
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $target, $target, $white);
        imagealphablending($canvas, true);
        if (!imagecopyresampled($canvas,$src,0,0,$sourceX,$sourceY,$target,$target,$crop,$crop)) {
            imagedestroy($canvas); imagedestroy($src);
            throw new GuestContentException('media_derivative','ساخت نسخه استاندارد تصویر انجام نشد.',500);
        }

        $rel = 'derived/' . $mediaKey . '/guest-card.webp';
        $dst = $this->path($rel);
        $this->ensureDir(dirname($dst));
        $ok = imagewebp($canvas,$dst,84);
        imagedestroy($canvas); imagedestroy($src);
        if (!$ok || !is_file($dst)) throw new GuestContentException('media_derivative','ساخت نسخه WebP تصویر انجام نشد.',500);
        @chmod($dst,0640);
        $sha = hash_file('sha256',$dst);
        if (!is_string($sha)) throw new GuestContentException('media_derivative_hash','خواندن نسخه انتشار تصویر انجام نشد.',500);
        return [
            'sha256'=>$sha,
            'mime'=>'image/webp',
            'extension'=>'webp',
            'byte_size'=>(int)filesize($dst),
            'width'=>$target,
            'height'=>$target,
            'relpath'=>$rel,
            'processor'=>'gd-center-crop-640-webp84',
        ];
    }

    private function assertAdmin(array $user): array
    {
        $id = (int)($user['id'] ?? 0);
        $fresh = $id > 0 ? $this->identity->findActiveById($id) : null;
        if ($fresh === null || (string)($fresh['role'] ?? '') !== 'admin') throw new GuestContentException('forbidden','این بخش فقط برای مدیر فعال است.',403);
        return $fresh;
    }

    private function plain(string $value, int $max, string $fallback): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u','',$value) ?? '');
        if ($value === '') $value = $fallback;
        return $this->slice($value,$max);
    }

    private function length(string $value): int
    {
        if(function_exists('mb_strlen'))return mb_strlen($value,'UTF-8');
        $ok=preg_match_all('/./us',$value,$m);
        return $ok===false?strlen($value):$ok;
    }

    private function slice(string $value,int $max): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$max,'UTF-8');
        $ok=preg_match_all('/./us',$value,$m);
        if($ok===false)return substr($value,0,$max);
        return implode('',array_slice($m[0],0,$max));
    }

    private function jsonObject(string $json): array
    {
        $value = json_decode($json,true);
        return is_array($value) ? $value : [];
    }

    private function json(array $value): string
    {
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private function path(string $relative): string
    {
        $relative = str_replace('\\','/',trim($relative));
        if ($relative === '' || str_contains($relative,'..') || str_starts_with($relative,'/')) throw new GuestContentException('media_path','مسیر ذخیره رسانه معتبر نیست.',500);
        return rtrim($this->mediaRoot,"\\/") . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relative);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new GuestContentException('media_storage','فضای ذخیره رسانه قابل ایجاد نیست.',500);
    }

    private function audit(string $action,string $entity,string $entityId,int $actorId,array $details): void
    {
        $q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)');
        $q->execute([$actorId,$action,$entity,$entityId,$this->json($details)]);
    }
}
