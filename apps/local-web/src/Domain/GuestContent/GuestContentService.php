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

    /** @var array<string,int> */
    private const IDENTITY_VARIANTS = [
        'logo-256'=>256,
        'icon-16'=>16,
        'icon-32'=>32,
        'icon-48'=>48,
        'icon-180'=>180,
        'icon-192'=>192,
        'icon-512'=>512,
    ];
    private const IDENTITY_VARIANT_MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string,array{label:string,default:string,max:int,multiline:bool}> */
    private const COPY = [
        'menu_subtitle' => ['label'=>'زیرعنوان منو','default'=>'منوی عمومی','max'=>80,'multiline'=>false],
        'ready_title' => ['label'=>'عنوان ارتباط زنده','default'=>'ارتباط زنده برقرار است','max'=>100,'multiline'=>false],
        'ready_body' => ['label'=>'توضیح ارتباط زنده','default'=>'وضعیت سفارش‌گیری از کافه به‌روز است.','max'=>180,'multiline'=>true],
        'syncing_title' => ['label'=>'عنوان هنگام همگام‌سازی','default'=>'اتصال سفارش برقرار است','max'=>100,'multiline'=>false],
        'syncing_body' => ['label'=>'توضیح هنگام همگام‌سازی','default'=>'وضعیت منو در حال همگام‌سازی است؛ سفارش هنگام ثبت دوباره توسط کافه بررسی می‌شود.','max'=>220,'multiline'=>true],
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
        'featured_badge' => ['label'=>'نشان پیشنهاد ویژه','default'=>'پیشنهاد کافه','max'=>60,'multiline'=>false],
        'featured_title' => ['label'=>'عنوان پیشنهادهای کافه','default'=>'پیشنهادهای کافه','max'=>100,'multiline'=>false],
        'featured_subtitle' => ['label'=>'زیرعنوان پیشنهادها','default'=>'انتخاب‌های ویژه کافه','max'=>100,'multiline'=>false],
        'review_order' => ['label'=>'دکمه مرور سفارش','default'=>'بررسی سفارش','max'=>70,'multiline'=>false],
        'cart_title' => ['label'=>'عنوان مرور سفارش','default'=>'مرور سفارش','max'=>80,'multiline'=>false],
        'cart_open' => ['label'=>'متن مشاهده سبد','default'=>'دیدن سفارش','max'=>70,'multiline'=>false],
        'order_note_placeholder' => ['label'=>'راهنمای یادداشت سفارش','default'=>'چیزی هست که دوست داری بدونیم؟','max'=>180,'multiline'=>false],
        'item_note_placeholder' => ['label'=>'راهنمای یادداشت آیتم','default'=>'مثلاً بدون شکر یا یخ کمتر','max'=>160,'multiline'=>false],
        'order_received_title' => ['label'=>'عنوان ثبت موفق سفارش','default'=>'سفارشت رسید 👌','max'=>100,'multiline'=>false],
        'post_order_social_cta' => ['label'=>'دعوت بعد از ثبت سفارش','default'=>'حال‌وهوای سکنا رو استوری کن و پیجمون رو منشن کن','max'=>180,'multiline'=>true],
        'more_sokna_title' => ['label'=>'عنوان بخش بیشتر از سکنا','default'=>'بیشتر از سکنا','max'=>90,'multiline'=>false],
        'waiter_question' => ['label'=>'عنوان تأیید فراخوان','default'=>'گارسون رو صدا کنیم؟','max'=>100,'multiline'=>false],
        'waiter_explainer' => ['label'=>'توضیح فراخوان گارسون','default'=>'فقط درخواست حضور گارسون فرستاده می‌شه.','max'=>180,'multiline'=>true],
        'waiter_button_sent' => ['label'=>'وضعیت دکمه بعد از فراخوان','default'=>'گارسون خبر شده','max'=>80,'multiline'=>false],
        'waiter_button_accepted' => ['label'=>'وضعیت دکمه بعد از پذیرش','default'=>'همکارمون در راهه','max'=>80,'multiline'=>false],
    ];

    /** @var array<string,array{label:string,group:string,type:string,default:mixed,max?:int,help?:string}> */
    private const PUBLIC_SETTINGS = [
        'public_about_enabled' => ['label'=>'نمایش درباره سکنا','group'=>'about','type'=>'bool','default'=>true],
        'about_title' => ['label'=>'عنوان درباره سکنا','group'=>'about','type'=>'text','default'=>'درباره سکنا','max'=>100],
        'about_intro' => ['label'=>'معرفی کوتاه','group'=>'about','type'=>'textarea','default'=>'','max'=>700],
        'seo_description' => ['label'=>'توضیح کوتاه برای موتورهای جست‌وجو','group'=>'about','type'=>'textarea','default'=>'','max'=>320],
        'public_address' => ['label'=>'نشانی','group'=>'about','type'=>'textarea','default'=>'','max'=>320],
        'public_phone' => ['label'=>'شماره تماس','group'=>'about','type'=>'text','default'=>'','max'=>60],
        'instagram_cafe_url' => ['label'=>'اینستاگرام کافه','group'=>'social','type'=>'url','default'=>'','max'=>500],
        'instagram_house_url' => ['label'=>'اینستاگرام اقامتگاه','group'=>'social','type'=>'url','default'=>'','max'=>500],
        'whatsapp_enabled' => ['label'=>'نمایش واتس‌اپ','group'=>'social','type'=>'bool','default'=>false],
        'whatsapp_number' => ['label'=>'شماره واتس‌اپ','group'=>'social','type'=>'text','default'=>'','max'=>40,'help'=>'شماره با کد کشور؛ مثال 98912…'],
        'whatsapp_message' => ['label'=>'متن اولیه واتس‌اپ','group'=>'social','type'=>'textarea','default'=>'سلام، از منوی سکنا پیام می‌دم.','max'=>500],
        'social_footer_enabled' => ['label'=>'نمایش لینک‌های اجتماعی پایین منو','group'=>'social','type'=>'bool','default'=>true],
        'post_order_instagram_enabled' => ['label'=>'دعوت اینستاگرام بعد از سفارش','group'=>'social','type'=>'bool','default'=>true],
        'accommodation_enabled' => ['label'=>'نمایش معرفی اقامتگاه','group'=>'accommodation','type'=>'bool','default'=>true],
        'accommodation_site_url' => ['label'=>'لینک اصلی اقامتگاه','group'=>'accommodation','type'=>'url','default'=>'','max'=>500],
        'accommodation_rooms_url' => ['label'=>'لینک اتاق‌ها','group'=>'accommodation','type'=>'url','default'=>'','max'=>500],
        'accommodation_tours_url' => ['label'=>'لینک تورها و تجربه‌ها','group'=>'accommodation','type'=>'url','default'=>'','max'=>500],
        'accommodation_card_title' => ['label'=>'عنوان کارت اقامتگاه','group'=>'accommodation','type'=>'text','default'=>'اقامت در سکنا','max'=>100],
        'accommodation_card_text' => ['label'=>'متن کارت اقامتگاه','group'=>'accommodation','type'=>'textarea','default'=>'اتاق‌ها و تجربه‌های سکنا را ببین.','max'=>300],
        'public_waiter_call_enabled' => ['label'=>'فراخوان گارسون در منوی عمومی بدون QR','group'=>'runtime','type'=>'bool','default'=>false,'help'=>'فراخوان داخل QR میز همچنان از تنظیم داخلی سالن پیروی می‌کند.'],
        'pwa_enabled' => ['label'=>'فعال بودن نصب وب‌اپ منوی مهمان','group'=>'runtime','type'=>'bool','default'=>true],
        'app_canonical_url' => ['label'=>'آدرس اصلی منوی عمومی','group'=>'runtime','type'=>'url','default'=>'','max'=>500],
        'analytics_enabled' => ['label'=>'ثبت آمار تعامل‌های مهمان','group'=>'runtime','type'=>'bool','default'=>true],
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
        $this->ensureBuiltinThemePackages();
        $state = $this->state();
        $draftSettings = $this->jsonObject((string)$state['draft_theme_settings_json']);
        $publishedSettings = $this->jsonObject((string)$state['published_theme_settings_json']);
        $draftCopy = $this->mergeCopy($this->jsonObject((string)$state['draft_copy_json']));
        $publishedCopy = $this->mergeCopy($this->jsonObject((string)$state['published_copy_json']));
        $media = $this->pdo->query(
            "SELECT a.id,a.media_key,a.sha256,a.mime,a.extension,a.byte_size,a.width_px,a.height_px,a.focal_x,a.focal_y,a.original_name,a.alt_text,a.active,a.archived_at,a.created_at,"
            . "(SELECT COUNT(*) FROM guest_media_references r WHERE r.media_id=a.id) reference_count,"
            . "d.sha256 guest_card_sha256,d.byte_size guest_card_bytes,d.width_px guest_card_width,d.height_px guest_card_height,d.processor guest_card_processor "
            . "FROM guest_media_assets a LEFT JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' ORDER BY a.active DESC,a.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = $this->pdo->query(
            "SELECT i.id,i.name,i.image_path,c.name category_name FROM items i JOIN categories c ON c.id=i.category_id "
            . "WHERE i.active=1 AND i.staff_only=0 AND i.sellable_kind='menu_item' ORDER BY c.sort_order,i.sort_order,i.id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'themes' => $this->themeCatalog(),
            'draft_theme' => $this->resolveStateTheme($state,'draft',$draftSettings),
            'published_theme' => $this->resolveStateTheme($state,'published',$publishedSettings),
            'theme_history' => $this->themeHistoryRows(),
            'copy_schema' => self::COPY,
            'draft_copy' => $draftCopy,
            'published_copy' => $publishedCopy,
            'public_settings_schema' => self::PUBLIC_SETTINGS,
            'draft_public_settings' => $this->publicSettingsForState($state,'draft'),
            'published_public_settings' => $this->publicSettingsForState($state,'published'),
            'published_revision' => (int)$state['published_revision'],
            'published_at' => $state['published_at'],
            'draft_identity' => $this->identityStateFromConfig($state,'draft'),
            'published_identity' => $this->identityStateFromConfig($state,'published'),
            'media' => $media,
            'items' => $items,
            'staff_menu_groups_visible' => $this->settingBool('staff.order_menu_groups_visible',true),
        ];
    }

    public function saveThemeDraft(array $data, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $this->ensureBuiltinThemePackages();
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        if(array_key_exists('menu_groups_visible',$settings))$menuGroupsVisible=$this->menuGroupsVisible($settings);
        else{$current=$this->state();$menuGroupsVisible=$this->menuGroupsVisible($this->jsonObject((string)$current['draft_theme_settings_json']));}
        $staffMenuGroupsVisible=array_key_exists('staff_menu_groups_visible',$settings)?$this->truthy($settings['staff_menu_groups_visible']):$this->settingBool('staff.order_menu_groups_visible',true);
        $themeSettings=$this->themeResolverSettings($settings);
        $packageId=(int)($data['theme_package_id']??0);
        try {
            $package=$packageId>0?$this->themePackageById($packageId):$this->themePackageByLegacyKey(trim((string)($data['theme_key']??'')));
            $resolved = $this->themes->resolveManifest($package['manifest'], $themeSettings);
        } catch (GuestContentException $e) { throw $e; }
        catch (Throwable $e) {
            throw new GuestContentException('theme_invalid','بسته یا تنظیمات تم معتبر نیست.',422,['reason'=>$e->getMessage()]);
        }
        $editable = (array)$resolved['editable_tokens'];
        $stored = [];
        foreach ($editable as $key) if (array_key_exists($key, $settings)) $stored[(string)$key] = (string)$resolved['tokens'][(string)$key];
        if(array_key_exists('composition',$settings))$stored['composition']=array_values((array)$resolved['composition']);
        $stored['menu_groups_visible']=$menuGroupsVisible;
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('UPDATE guest_content_config SET draft_theme_key=?,draft_theme_package_id=?,draft_theme_settings_json=? WHERE id=1');
            $q->execute([(string)$resolved['theme_key'],(int)$package['id'], $this->json($stored)]);
            $setting=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $setting->execute(['staff.order_menu_groups_visible',$staffMenuGroupsVisible?'1':'0']);
            $this->audit('guest.theme_draft_saved','guest_content','theme',(int)$admin['id'],[
                'theme_key'=>(string)$resolved['theme_key'],'theme_version'=>(string)$resolved['version'],
                'theme_fingerprint'=>(string)$resolved['fingerprint'],'package_id'=>(int)$package['id'],'settings'=>$stored,'staff_menu_groups_visible'=>$staffMenuGroupsVisible,
            ]);
            $this->pdo->commit();
            return ['saved'=>true,'theme'=>$this->decorateTheme($resolved,$package)];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{filename:string,content:string} */
    public function exportThemePackage(int $packageId,array $actor): array
    {
        $this->assertAdmin($actor);$this->ensureBuiltinThemePackages();$package=$this->themePackageById($packageId);$manifest=$this->themeManifestPayload((array)$package['manifest']);
        $content=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        return ['filename'=>(string)$manifest['theme_key'].'-v'.(string)$manifest['version'].'.soknatheme','content'=>$content];
    }

    public function importThemePackage(string $raw,array $actor): array
    {
        $admin=$this->assertAdmin($actor);
        $this->ensureBuiltinThemePackages();
        try{$manifest=$this->themes->validateRaw($raw);}
        catch(Throwable $e){throw new GuestContentException('theme_invalid','فایل .soknatheme معتبر نیست.',422,['reason'=>$e->getMessage()]);}
        $fingerprint=(string)$manifest['fingerprint'];
        $q=$this->pdo->prepare('SELECT id FROM guest_theme_packages WHERE fingerprint=? LIMIT 1');$q->execute([$fingerprint]);$same=(int)($q->fetchColumn()?:0);
        if($same>0)return ['deduplicated'=>true,'package'=>$this->themePackagePublic($this->themePackageById($same))];
        $q=$this->pdo->prepare('SELECT id,fingerprint FROM guest_theme_packages WHERE theme_key=? AND version=? LIMIT 1');
        $q->execute([(string)$manifest['theme_key'],(string)$manifest['version']]);$conflict=$q->fetch(PDO::FETCH_ASSOC);
        if(is_array($conflict))throw new GuestContentException('theme_version_conflict','برای این تم و نسخه، بسته دیگری با محتوای متفاوت ثبت شده است.',409);
        $payload=$this->themeManifestPayload($manifest);
        $insert=$this->pdo->prepare("INSERT INTO guest_theme_packages(theme_key,version,name,fingerprint,package_json,source_kind,installed_by_user_id) VALUES(?,?,?,?,?,'imported',?)");
        try{$insert->execute([(string)$manifest['theme_key'],(string)$manifest['version'],(string)$manifest['name'],$fingerprint,$this->json($payload),(int)$admin['id']]);}
        catch(Throwable $e){
            $q=$this->pdo->prepare('SELECT id,fingerprint FROM guest_theme_packages WHERE theme_key=? AND version=? LIMIT 1');$q->execute([(string)$manifest['theme_key'],(string)$manifest['version']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($row)&&hash_equals((string)$row['fingerprint'],$fingerprint))return ['deduplicated'=>true,'package'=>$this->themePackagePublic($this->themePackageById((int)$row['id']))];
            throw $e;
        }
        $id=(int)$this->pdo->lastInsertId();
        $this->audit('guest.theme_imported','guest_theme',(string)$id,(int)$admin['id'],['theme_key'=>$manifest['theme_key'],'version'=>$manifest['version'],'fingerprint'=>$fingerprint]);
        return ['deduplicated'=>false,'package'=>$this->themePackagePublic($this->themePackageById($id))];
    }

    public function rollbackThemeRevision(int $revision,array $actor): array
    {
        $admin=$this->assertAdmin($actor);
        if($revision<1)throw new GuestContentException('theme_revision_invalid','نسخه انتشار معتبر نیست.',422);
        $this->ensureBuiltinThemePackages();
        $q=$this->pdo->prepare('SELECT revision,theme_package_id,theme_key,theme_version,theme_fingerprint,theme_settings_json FROM guest_content_revisions WHERE revision=? LIMIT 1');
        $q->execute([$revision]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new GuestContentException('theme_revision_missing','نسخه انتشار پیدا نشد.',404);
        $package=$this->themePackageById((int)$row['theme_package_id']);
        if(!hash_equals((string)$row['theme_fingerprint'],(string)$package['fingerprint']))throw new GuestContentException('theme_revision_integrity','یکپارچگی بسته تم این نسخه معتبر نیست.',409);
        $settings=$this->jsonObject((string)$row['theme_settings_json']);
        try{$resolved=$this->themes->resolveManifest($package['manifest'],$settings);}
        catch(Throwable $e){throw new GuestContentException('theme_invalid','تم این نسخه دیگر معتبر نیست.',409,['reason'=>$e->getMessage()]);}
        $this->pdo->prepare('UPDATE guest_content_config SET draft_theme_key=?,draft_theme_package_id=?,draft_theme_settings_json=? WHERE id=1')->execute([(string)$resolved['theme_key'],(int)$package['id'],$this->json($settings)]);
        $this->audit('guest.theme_revision_restored_to_draft','guest_content','theme',(int)$admin['id'],['revision'=>$revision,'package_id'=>(int)$package['id']]);
        return ['restored_to_draft'=>true,'revision'=>$revision,'theme'=>$this->decorateTheme($resolved,$package)];
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

    public function savePublicSettingsDraft(array $data, array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $input = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $settings = $this->normalizePublicSettings($input);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE guest_content_config SET draft_public_settings_json=? WHERE id=1')->execute([$this->json($settings)]);
            $this->audit('guest.public_settings_draft_saved','guest_content','public_settings',(int)$admin['id'],['keys'=>array_keys($settings)]);
            $this->pdo->commit();
            return ['saved'=>true,'settings'=>$settings];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function publishDraft(array $actor): array
    {
        $admin = $this->assertAdmin($actor);
        $this->ensureBuiltinThemePackages();
        $this->pdo->beginTransaction();
        try {
            $state = $this->pdo->query('SELECT * FROM guest_content_config WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state)) throw new GuestContentException('content_state_missing','وضعیت محتوای مهمان پیدا نشد.',500);
            $settings=$this->jsonObject((string)$state['draft_theme_settings_json']);
            try {
                $theme=$this->resolveStateTheme($state,'draft',$settings);
            } catch (Throwable $e) {
                throw new GuestContentException('theme_invalid','پیش‌نویس تم دیگر معتبر نیست.',422,['reason'=>$e->getMessage()]);
            }
            $copy = $this->normalizeCopy($this->jsonObject((string)$state['draft_copy_json']));
            $publicSettings = $this->publicSettingsForState($state,'draft');
            $revision = (int)$state['published_revision'] + 1;
            $publishedAt=gmdate('Y-m-d H:i:s');
            $identityKey=trim((string)($state['draft_identity_media_key']??''));
            $identityVersion=trim((string)($state['draft_identity_version']??''));
            if($identityKey!==''&&$identityVersion==='')throw new GuestContentException('identity_invalid','پیش‌نویس هویت تصویری کامل نیست.',422);
            $q = $this->pdo->prepare(
                'UPDATE guest_content_config SET published_theme_key=?,published_theme_package_id=?,published_theme_settings_json=draft_theme_settings_json,'
                . 'published_copy_json=?,published_public_settings_json=?,published_identity_media_key=?,published_identity_version=?,published_revision=?,published_at=?,published_by_user_id=? WHERE id=1'
            );
            $q->execute([(string)$theme['theme_key'],(int)$theme['package_id'],$this->json($copy),$this->json($publicSettings),$identityKey!==''?$identityKey:null,$identityVersion!==''?$identityVersion:null,$revision,$publishedAt,(int)$admin['id']]);
            $history=$this->pdo->prepare('INSERT INTO guest_content_revisions(revision,theme_package_id,theme_key,theme_version,theme_fingerprint,theme_settings_json,copy_json,public_settings_json,identity_media_key,identity_version,published_at,published_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $history->execute([$revision,(int)$theme['package_id'],(string)$theme['theme_key'],(string)$theme['version'],(string)$theme['fingerprint'],$this->json($settings),$this->json($copy),$this->json($publicSettings),$identityKey!==''?$identityKey:null,$identityVersion!==''?$identityVersion:null,$publishedAt,(int)$admin['id']]);
            $settingUpsert=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach (['public_waiter_call_enabled','pwa_enabled','analytics_enabled'] as $key) $settingUpsert->execute([$key,!empty($publicSettings[$key])?'1':'0']);
            $settingUpsert->execute(['app_canonical_url',(string)($publicSettings['app_canonical_url'] ?? '')]);
            $this->audit('guest.content_published','guest_content','published',(int)$admin['id'],['revision'=>$revision,'theme_key'=>(string)$theme['theme_key'],'theme_version'=>(string)$theme['version'],'package_id'=>(int)$theme['package_id'],'identity_media_key'=>$identityKey,'identity_version'=>$identityVersion,'public_settings'=>$publicSettings]);
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
        $this->ensureBuiltinThemePackages();
        $state = $this->state();
        try {
            $theme = $this->resolveStateTheme($state,'published',$this->jsonObject((string)$state['published_theme_settings_json']));
        } catch (Throwable) {
            $fallback=$this->themePackageByLegacyKey('sokna-house');
            $theme=$this->decorateTheme($this->themes->resolveManifest($fallback['manifest'],[]),$fallback);
        }
        return [
            'theme' => $theme,
            'copy' => $this->mergeCopy($this->jsonObject((string)$state['published_copy_json'])),
            'settings' => $this->publicSettingsForState($state,'published'),
            'menu_groups_visible' => $this->menuGroupsVisible($this->jsonObject((string)$state['published_theme_settings_json'])),
            'revision' => (int)$state['published_revision'],
            'published_at' => (string)($state['published_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function brandIdentity(string $mode='draft'): array
    {
        try{$state=$this->state();return $this->identityStateFromConfig($state,$mode==='published'?'published':'draft');}
        catch(Throwable){return ['active'=>false,'media_key'=>'','version'=>'fallback','variants'=>[]];}
    }

    /** @return array{path:string,mime:string,sha256:string}|null */
    public function brandIdentityAsset(string $variant,string $mode='draft'): ?array
    {
        if(!array_key_exists($variant,self::IDENTITY_VARIANTS))return null;
        try{$identity=$this->brandIdentity($mode);$mediaKey=trim((string)($identity['media_key']??''));if(empty($identity['active'])||$mediaKey==='')return null;
            $q=$this->pdo->prepare("SELECT d.path_relpath,d.mime,d.sha256 FROM guest_media_assets a JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key=? WHERE a.media_key=? AND a.active=1 LIMIT 1");
            $q->execute(['identity-'.$variant,$mediaKey]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return null;$path=$this->path((string)$row['path_relpath']);
            return is_file($path)&&is_readable($path)?['path'=>$path,'mime'=>(string)$row['mime'],'sha256'=>(string)$row['sha256']]:null;
        }catch(Throwable){return null;}
    }

    /** @return array{snapshot:array<string,mixed>,manifest:array<string,array<string,mixed>>} */
    public function publishedIdentityProjection(): array
    {
        $identity=$this->brandIdentity('published');$manifest=[];$variants=[];
        if(!empty($identity['active']))foreach((array)($identity['variants']??[]) as $key=>$meta){if(!is_array($meta))continue;$source='identity:'.(string)$key;$public=['source'=>$source,'sha256'=>(string)($meta['sha256']??''),'mime'=>(string)($meta['mime']??''),'extension'=>(string)($meta['extension']??''),'size'=>(int)($meta['byte_size']??0),'width'=>(int)($meta['width_px']??0),'height'=>(int)($meta['height_px']??0)];$variants[(string)$key]=$public;$manifest[$source]=$public+['alt_text'=>''];}
        return ['snapshot'=>['active'=>!empty($identity['active']),'version'=>(string)($identity['version']??'fallback'),'variants'=>$variants],'manifest'=>$manifest];
    }

    public function saveBrandIdentityDraft(string $mediaKey,array $variants,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$mediaKey=trim($mediaKey);if($mediaKey==='')throw new GuestContentException('identity_media_required','یک تصویر از آلبوم انتخاب کن.',422);
        $q=$this->pdo->prepare('SELECT id,sha256,width_px,height_px,original_name FROM guest_media_assets WHERE media_key=? AND active=1 LIMIT 1');$q->execute([$mediaKey]);$media=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($media))throw new GuestContentException('identity_media_missing','تصویر انتخاب‌شده فعال نیست.',404);
        if(min((int)($media['width_px']??0),(int)($media['height_px']??0))<192)throw new GuestContentException('identity_media_small','برای هویت تصویری، ضلع کوتاه تصویر باید حداقل ۱۹۲ پیکسل باشد.',422);
        $expected=array_keys(self::IDENTITY_VARIANTS);$actual=array_keys($variants);sort($expected,SORT_STRING);sort($actual,SORT_STRING);if($actual!==$expected)throw new GuestContentException('identity_variants','مجموعه اندازه‌های هویت تصویری کامل نیست.',422);
        $prepared=[];$total=0;
        foreach(self::IDENTITY_VARIANTS as $key=>$size){$encoded=(string)($variants[$key]??'');if(str_starts_with($encoded,'data:image/png;base64,'))$encoded=substr($encoded,22);$bytes=base64_decode($encoded,true);if(!is_string($bytes)||$bytes==='')throw new GuestContentException('identity_variant_decode','یکی از اندازه‌های لوگو قابل خواندن نیست.',422,['variant'=>$key]);$len=strlen($bytes);$total+=$len;if($len>self::IDENTITY_VARIANT_MAX_BYTES||$total>6*1024*1024)throw new GuestContentException('identity_variant_size','حجم خروجی هویت تصویری بیش از حد مجاز است.',413,['variant'=>$key]);$info=@getimagesizefromstring($bytes);if(!is_array($info)||(string)($info['mime']??'')!=='image/png'||(int)($info[0]??0)!==$size||(int)($info[1]??0)!==$size)throw new GuestContentException('identity_variant_geometry','اندازه خروجی هویت تصویری معتبر نیست.',422,['variant'=>$key,'expected'=>$size]);$rel='derived/'.$mediaKey.'/identity-'.$key.'.png';$dst=$this->path($rel);$this->ensureDir(dirname($dst));$tmp=$dst.'.'.bin2hex(random_bytes(5)).'.tmp';if(file_put_contents($tmp,$bytes,LOCK_EX)!==$len){@unlink($tmp);throw new GuestContentException('identity_store','ذخیره خروجی هویت تصویری انجام نشد.',500);} @chmod($tmp,0640);if(!@rename($tmp,$dst)){@unlink($tmp);throw new GuestContentException('identity_store','جایگزینی امن خروجی هویت تصویری انجام نشد.',500);} $prepared[$key]=['sha256'=>hash('sha256',$bytes),'mime'=>'image/png','extension'=>'png','byte_size'=>$len,'width_px'=>$size,'height_px'=>$size,'path_relpath'=>$rel,'processor'=>'browser-canvas-crop-v1'];}
        $fingerprint=hash('sha256',$mediaKey.'|'.(string)$media['sha256'].'|'.implode('|',array_map(static fn(array $v):string=>$v['sha256'],$prepared)));
        $this->pdo->beginTransaction();try{$up=$this->pdo->prepare('INSERT INTO guest_media_derivatives(media_id,variant_key,sha256,mime,extension,byte_size,width_px,height_px,path_relpath,processor) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sha256=VALUES(sha256),mime=VALUES(mime),extension=VALUES(extension),byte_size=VALUES(byte_size),width_px=VALUES(width_px),height_px=VALUES(height_px),path_relpath=VALUES(path_relpath),processor=VALUES(processor),created_at=CURRENT_TIMESTAMP');foreach($prepared as $key=>$meta)$up->execute([(int)$media['id'],'identity-'.$key,$meta['sha256'],$meta['mime'],$meta['extension'],$meta['byte_size'],$meta['width_px'],$meta['height_px'],$meta['path_relpath'],$meta['processor']]);$this->pdo->prepare("DELETE FROM guest_media_references WHERE owner_type='brand_identity' AND owner_id='primary' AND slot_key='master'")->execute();$this->pdo->prepare("INSERT INTO guest_media_references(media_id,owner_type,owner_id,slot_key) VALUES(?,'brand_identity','primary','master')")->execute([(int)$media['id']]);$cfg=$this->pdo->prepare('UPDATE guest_content_config SET draft_identity_media_key=?,draft_identity_version=? WHERE id=1');$cfg->execute([$mediaKey,$fingerprint]);$this->audit('guest.identity_draft_saved','guest_content','identity',(int)$admin['id'],['media_key'=>$mediaKey,'version'=>$fingerprint,'source_sha256'=>(string)$media['sha256']]);$this->pdo->commit();return ['saved'=>true,'identity'=>$this->brandIdentity('draft')];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function resetBrandIdentityDraft(array $actor): array
    {
        $admin=$this->assertAdmin($actor);$this->pdo->beginTransaction();try{$this->pdo->prepare("DELETE FROM guest_media_references WHERE owner_type='brand_identity' AND owner_id='primary' AND slot_key='master'")->execute();$this->pdo->prepare('UPDATE guest_content_config SET draft_identity_media_key=NULL,draft_identity_version=NULL WHERE id=1')->execute();$this->audit('guest.identity_draft_reset','guest_content','identity',(int)$admin['id'],[]);$this->pdo->commit();return ['saved'=>true,'identity'=>$this->brandIdentity('draft')];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
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
        $derivative = $this->createGuestCard($originalPath,$mediaKey,$mime,$extension,(int)$dimensions[0],(int)$dimensions[1]);
        $square = $this->createFocalSquare($originalPath,$mediaKey,$mime,$extension,(int)$dimensions[0],(int)$dimensions[1],0.5,0.5);
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
            if(is_array($square))$d->execute([$id,'menu-square',$square['sha256'],$square['mime'],$square['extension'],$square['byte_size'],$square['width'],$square['height'],$square['relpath'],$square['processor']]);
            $this->audit('guest.media_imported','guest_media',$mediaKey,(int)$admin['id'],['sha256'=>$sha,'mime'=>$mime,'bytes'=>$size,'derivative_processor'=>$derivative['processor'],'square_processor'=>is_array($square)?$square['processor']:'unavailable']);
            $this->pdo->commit();
            return ['deduplicated'=>false,'media_key'=>$mediaKey];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function mediaLibrary(array $actor): array
    {
        $this->assertAdmin($actor);
        $rows=$this->pdo->query(
            "SELECT a.id,a.media_key,a.original_name,a.alt_text,a.width_px,a.height_px,a.focal_x,a.focal_y,a.byte_size,a.active,"
            ."(SELECT COUNT(*) FROM guest_media_references r WHERE r.media_id=a.id) reference_count "
            ."FROM guest_media_assets a WHERE a.active=1 ORDER BY a.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach($rows as &$row){$row['id']=(int)$row['id'];$row['width_px']=$row['width_px']!==null?(int)$row['width_px']:null;$row['height_px']=$row['height_px']!==null?(int)$row['height_px']:null;$row['focal_x']=$this->focal((float)($row['focal_x']??0.5));$row['focal_y']=$this->focal((float)($row['focal_y']??0.5));$row['byte_size']=(int)$row['byte_size'];$row['reference_count']=(int)$row['reference_count'];$row['active']=(int)$row['active']===1;}unset($row);
        return $rows;
    }

    public function assignMediaToCategory(int $categoryId, string $mediaKey, array $actor): array
    {
        $admin=$this->assertAdmin($actor);
        if($categoryId<1)throw new GuestContentException('category_invalid','دسته‌بندی معتبر نیست.',422);
        $mediaKey=trim($mediaKey);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT id,name FROM categories WHERE id=? FOR UPDATE');$q->execute([$categoryId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new GuestContentException('category_missing','دسته‌بندی پیدا نشد.',404);
            $this->pdo->prepare("DELETE FROM guest_media_references WHERE owner_type='category' AND owner_id=? AND slot_key='card'")->execute([(string)$categoryId]);
            $imagePath='';
            if($mediaKey!==''){
                $q=$this->pdo->prepare('SELECT id FROM guest_media_assets WHERE media_key=? AND active=1 FOR UPDATE');$q->execute([$mediaKey]);$mediaId=(int)($q->fetchColumn()?:0);
                if($mediaId<1)throw new GuestContentException('media_missing','تصویر فعال پیدا نشد.',404);
                $imagePath='media:'.$mediaKey;
                $this->pdo->prepare("INSERT INTO guest_media_references(media_id,owner_type,owner_id,slot_key) VALUES(?,'category',?,'card')")->execute([$mediaId,(string)$categoryId]);
            }
            $this->pdo->prepare('UPDATE categories SET image_path=? WHERE id=?')->execute([$imagePath,$categoryId]);
            $this->audit('guest.media_assigned','category',(string)$categoryId,(int)$admin['id'],['media_key'=>$mediaKey]);
            $this->pdo->commit();
            return ['category_id'=>$categoryId,'image_path'=>$imagePath];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
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

    public function updateFocal(string $mediaKey,float $focalX,float $focalY,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$mediaKey=trim($mediaKey);
        if(!is_finite($focalX)||!is_finite($focalY)||$focalX<0||$focalX>1||$focalY<0||$focalY>1)throw new GuestContentException('media_focal_invalid','نقطه کانون تصویر معتبر نیست.',422);
        $focalX=$this->focal($focalX);$focalY=$this->focal($focalY);
        $q=$this->pdo->prepare('SELECT id,mime,extension,width_px,height_px,original_relpath FROM guest_media_assets WHERE media_key=? AND active=1 LIMIT 1');$q->execute([$mediaKey]);$media=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($media))throw new GuestContentException('media_missing','تصویر فعال پیدا نشد.',404);$source=$this->path((string)$media['original_relpath']);if(!is_file($source)||!is_readable($source))throw new GuestContentException('media_master_missing','فایل اصلی تصویر در دسترس نیست.',500);
        $square=$this->createFocalSquare($source,$mediaKey,(string)$media['mime'],(string)$media['extension'],(int)$media['width_px'],(int)$media['height_px'],$focalX,$focalY);
        $this->pdo->beginTransaction();try{$this->pdo->prepare('UPDATE guest_media_assets SET focal_x=?,focal_y=? WHERE id=?')->execute([$focalX,$focalY,(int)$media['id']]);
            if(is_array($square)){$up=$this->pdo->prepare("INSERT INTO guest_media_derivatives(media_id,variant_key,sha256,mime,extension,byte_size,width_px,height_px,path_relpath,processor) VALUES(?,'menu-square',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sha256=VALUES(sha256),mime=VALUES(mime),extension=VALUES(extension),byte_size=VALUES(byte_size),width_px=VALUES(width_px),height_px=VALUES(height_px),path_relpath=VALUES(path_relpath),processor=VALUES(processor),created_at=CURRENT_TIMESTAMP");$up->execute([(int)$media['id'],$square['sha256'],$square['mime'],$square['extension'],$square['byte_size'],$square['width'],$square['height'],$square['relpath'],$square['processor']]);}
            else{$this->pdo->prepare("DELETE FROM guest_media_derivatives WHERE media_id=? AND variant_key='menu-square'")->execute([(int)$media['id']]);}
            $this->audit('guest.media_focal_updated','guest_media',$mediaKey,(int)$admin['id'],['focal_x'=>$focalX,'focal_y'=>$focalY,'crop_ready'=>is_array($square),'processor'=>is_array($square)?$square['processor']:'css-focal-fallback']);$this->pdo->commit();
            return ['saved'=>true,'media_key'=>$mediaKey,'focal_x'=>$focalX,'focal_y'=>$focalY,'crop_ready'=>is_array($square),'processor'=>is_array($square)?$square['processor']:'css-focal-fallback'];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
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
            "SELECT a.id,a.media_key,a.original_relpath FROM guest_media_assets a "
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
                $paths=$this->pdo->prepare('SELECT path_relpath FROM guest_media_derivatives WHERE media_id=?');$paths->execute([(int)$row['id']]);$derivativePaths=array_map('strval',$paths->fetchAll(PDO::FETCH_COLUMN)?:[]);
                $this->pdo->prepare('DELETE FROM guest_media_assets WHERE id=? AND active=0')->execute([(int)$row['id']]);
                $this->audit('guest.media_gc','guest_media',(string)$row['media_key'],(int)$admin['id'],['derivative_count'=>count($derivativePaths)]);
                $this->pdo->commit();
                $originalRel=trim((string)($row['original_relpath']??''));if($originalRel!=='')@unlink($this->path($originalRel));foreach($derivativePaths as $rel){$rel=trim($rel);if($rel!=='')@unlink($this->path($rel));}
                $removed[] = (string)$row['media_key'];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
        }
        return ['dry_run'=>false,'removed'=>$removed];
    }

    /** @return array{path:string,mime:string}|null */
    public function localPreview(string $mediaKey,array $actor,string $variant='card'): ?array
    {
        $this->assertAdmin($actor);$mediaKey=trim($mediaKey);
        if($variant==='master'){$q=$this->pdo->prepare("SELECT original_relpath path_relpath,mime FROM guest_media_assets WHERE media_key=? AND active=1 LIMIT 1");$q->execute([$mediaKey]);}
        else{$q=$this->pdo->prepare("SELECT d.path_relpath,d.mime FROM guest_media_assets a JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' WHERE a.media_key=? AND a.active=1 LIMIT 1");$q->execute([$mediaKey]);}
        $row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return null;$path=$this->path((string)$row['path_relpath']);
        return is_file($path)&&is_readable($path)?['path'=>$path,'mime'=>(string)$row['mime']]:null;
    }

    /** @return array{path:string,mime:string}|null */
    public function publishedMediaFile(string $sha,string $extension): ?array
    {
        $sha=strtolower(trim($sha));$extension=strtolower(trim($extension));
        if(preg_match('/^[a-f0-9]{64}$/D',$sha)!==1||preg_match('/^(jpg|png|webp|gif)$/D',$extension)!==1)return null;
        $q=$this->pdo->prepare("SELECT d.path_relpath,d.mime,d.sha256,d.extension FROM guest_media_derivatives d JOIN guest_media_assets a ON a.id=d.media_id WHERE a.active=1 AND d.sha256=? AND d.extension=? LIMIT 1");
        $q->execute([$sha,$extension]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return null;
        $path=$this->path((string)$row['path_relpath']);if(!is_file($path)||!is_readable($path))return null;
        $actual=hash_file('sha256',$path);if(!is_string($actual)||!hash_equals($sha,$actual))return null;
        return ['path'=>$path,'mime'=>(string)$row['mime']];
    }

    /** @return array{source:string,manifest:array<string,mixed>}|null */
    public function publicMediaForSource(string $source): ?array
    {
        $source = trim($source);
        if (!preg_match('/^media:([A-Za-z0-9_-]{3,80})$/D', $source, $m)) return null;
        $q = $this->pdo->prepare(
            "SELECT d.sha256,d.mime,d.extension,d.byte_size,a.alt_text,a.focal_x,a.focal_y,sq.sha256 square_sha256 FROM guest_media_assets a "
            . "JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' LEFT JOIN guest_media_derivatives sq ON sq.media_id=a.id AND sq.variant_key='menu-square' WHERE a.media_key=? AND a.active=1 LIMIT 1"
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
            'focal_x'=>$this->focal((float)($row['focal_x']??0.5)),
            'focal_y'=>$this->focal((float)($row['focal_y']??0.5)),
            'crop_aspect'=>'1:1',
            'crop_ready'=>trim((string)($row['square_sha256']??''))!=='',
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
                "SELECT d.sha256,d.mime,d.extension,d.byte_size,d.path_relpath FROM guest_media_derivatives d WHERE d.sha256=? LIMIT 1"
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

    private function ensureBuiltinThemePackages(): void
    {
        foreach($this->themes->packages() as $manifest){
            $payload=$this->themeManifestPayload($manifest);
            $insert=$this->pdo->prepare("INSERT IGNORE INTO guest_theme_packages(theme_key,version,name,fingerprint,package_json,source_kind,installed_by_user_id) VALUES(?,?,?,?,?,'builtin',NULL)");
            $insert->execute([(string)$manifest['theme_key'],(string)$manifest['version'],(string)$manifest['name'],(string)$manifest['fingerprint'],$this->json($payload)]);
            $q=$this->pdo->prepare('SELECT fingerprint FROM guest_theme_packages WHERE theme_key=? AND version=? LIMIT 1');
            $q->execute([(string)$manifest['theme_key'],(string)$manifest['version']]);$actual=(string)($q->fetchColumn()?:'');
            if($actual===''||!hash_equals((string)$manifest['fingerprint'],$actual))throw new GuestContentException('builtin_theme_conflict','بسته داخلی تم با نسخه ثبت‌شده سازگار نیست.',500,['theme_key'=>$manifest['theme_key'],'version'=>$manifest['version']]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function themeCatalog(): array
    {
        $rows=$this->pdo->query('SELECT id,theme_key,version,name,fingerprint,package_json,source_kind,installed_at FROM guest_theme_packages ORDER BY theme_key,installed_at,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
        return array_map(fn(array $row):array=>$this->themePackagePublic($this->themeRowToPackage($row)),$rows);
    }

    /** @return list<array<string,mixed>> */
    private function themeHistoryRows(): array
    {
        $rows=$this->pdo->query('SELECT r.revision,r.theme_package_id,r.theme_key,r.theme_version,r.theme_fingerprint,r.published_at,r.published_by_user_id,u.display_name published_by_name,p.name theme_name FROM guest_content_revisions r LEFT JOIN users u ON u.id=r.published_by_user_id LEFT JOIN guest_theme_packages p ON p.id=r.theme_package_id ORDER BY r.revision DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC)?:[];
        return array_map(static fn(array $r):array=>[
            'revision'=>(int)$r['revision'],'theme_package_id'=>(int)$r['theme_package_id'],'theme_key'=>(string)$r['theme_key'],
            'theme_name'=>trim((string)($r['theme_name']??''))!==''?(string)$r['theme_name']:(string)$r['theme_key'],
            'theme_version'=>(string)$r['theme_version'],'theme_fingerprint'=>(string)$r['theme_fingerprint'],'published_at'=>(string)$r['published_at'],
            'published_by_user_id'=>$r['published_by_user_id']!==null?(int)$r['published_by_user_id']:null,'published_by_name'=>(string)($r['published_by_name']??''),
        ],$rows);
    }

    /** @return array<string,mixed> */
    private function themePackageById(int $id): array
    {
        if($id<1)throw new GuestContentException('theme_package_invalid','بسته تم معتبر نیست.',422);
        $q=$this->pdo->prepare('SELECT id,theme_key,version,name,fingerprint,package_json,source_kind,installed_at FROM guest_theme_packages WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new GuestContentException('theme_package_missing','بسته تم پیدا نشد.',404);
        return $this->themeRowToPackage($row);
    }

    /** @return array<string,mixed> */
    private function themePackageByLegacyKey(string $themeKey): array
    {
        if(!preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$themeKey))throw new GuestContentException('theme_package_invalid','بسته تم معتبر نیست.',422);
        try{$builtin=$this->themes->get($themeKey);$q=$this->pdo->prepare('SELECT id,theme_key,version,name,fingerprint,package_json,source_kind,installed_at FROM guest_theme_packages WHERE fingerprint=? LIMIT 1');$q->execute([(string)$builtin['fingerprint']]);$row=$q->fetch(PDO::FETCH_ASSOC);if(is_array($row))return $this->themeRowToPackage($row);}catch(Throwable){}
        $q=$this->pdo->prepare('SELECT id,theme_key,version,name,fingerprint,package_json,source_kind,installed_at FROM guest_theme_packages WHERE theme_key=? ORDER BY id DESC LIMIT 1');$q->execute([$themeKey]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new GuestContentException('theme_package_missing','بسته تم پیدا نشد.',404);
        return $this->themeRowToPackage($row);
    }

    /** @return array<string,mixed> */
    private function themeRowToPackage(array $row): array
    {
        $raw=json_decode((string)$row['package_json'],true);if(!is_array($raw))throw new GuestContentException('theme_package_corrupt','اطلاعات بسته تم قابل خواندن نیست.',500);
        try{$manifest=$this->themes->validateManifest($raw);}catch(Throwable $e){throw new GuestContentException('theme_package_corrupt','اطلاعات بسته تم معتبر نیست.',500,['reason'=>$e->getMessage()]);}
        if(!hash_equals((string)$row['fingerprint'],(string)$manifest['fingerprint']))throw new GuestContentException('theme_package_integrity','اثر انگشت بسته تم معتبر نیست.',500);
        return ['id'=>(int)$row['id'],'theme_key'=>(string)$row['theme_key'],'version'=>(string)$row['version'],'name'=>(string)$row['name'],'fingerprint'=>(string)$row['fingerprint'],'source_kind'=>(string)$row['source_kind'],'installed_at'=>(string)$row['installed_at'],'manifest'=>$manifest];
    }

    private function resolveStateTheme(array $state,string $prefix,array $settings): array
    {
        $id=(int)($state[$prefix.'_theme_package_id']??0);
        $package=$id>0?$this->themePackageById($id):$this->themePackageByLegacyKey((string)($state[$prefix.'_theme_key']??'sokna-house'));
        $resolved=$this->themes->resolveManifest($package['manifest'],$this->themeResolverSettings($settings));
        return $this->decorateTheme($resolved,$package)+['menu_groups_visible'=>$this->menuGroupsVisible($settings)];
    }

    /** @param array<string,mixed> $settings */
    private function menuGroupsVisible(array $settings): bool
    {
        if(!array_key_exists('menu_groups_visible',$settings))return true;
        $value=$settings['menu_groups_visible'];
        if(is_bool($value))return $value;
        if(is_int($value))return $value!==0;
        if(is_string($value))return !in_array(strtolower(trim($value)),['0','false','off','no'],true);
        return (bool)$value;
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function themeResolverSettings(array $settings): array
    {
        unset($settings['menu_groups_visible'],$settings['staff_menu_groups_visible']);
        return $settings;
    }

    private function truthy(mixed $value): bool
    {
        if(is_bool($value))return $value;if(is_int($value))return $value!==0;if(is_string($value))return !in_array(strtolower(trim($value)),['0','false','off','no',''],true);return (bool)$value;
    }

    private function settingBool(string $key,bool $default): bool
    {
        $q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:$this->truthy($v);
    }

    private function settingValue(string $key,string $default=''): string
    {
        $q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:trim((string)$v);
    }

    /** @return array<string,mixed> */
    private function publicSettingsForState(array $state,string $prefix): array
    {
        $column=$prefix.'_public_settings_json';$stored=$this->jsonObject((string)($state[$column]??''));
        if($stored!==[])return $this->mergePublicSettings($stored);
        // Compatibility bridge for installations that already had the historical runtime
        // settings before H193 introduced one canonical Draft -> Publish settings owner.
        return $this->mergePublicSettings([
            'public_waiter_call_enabled'=>$this->settingBool('public_waiter_call_enabled',false),
            'pwa_enabled'=>$this->settingBool('pwa_enabled',true),
            'analytics_enabled'=>$this->settingBool('analytics_enabled',true),
            'app_canonical_url'=>$this->settingValue('app_canonical_url',''),
        ]);
    }

    private function decorateTheme(array $resolved,array $package): array
    {
        return $resolved+[
            'package_id'=>(int)$package['id'],'source_kind'=>(string)$package['source_kind'],'installed_at'=>(string)$package['installed_at'],
        ];
    }

    private function themePackagePublic(array $package): array
    {
        $m=$package['manifest'];
        $format=(string)($m['format']??'sokna-guest-theme-v1');
        $defaultComposition=$format==='sokna-guest-theme-v2'?['campaigns','featured','catalog','more']:['campaigns','featured','catalog'];
        return [
            'package_id'=>(int)$package['id'],'format'=>$format,'engine_contract'=>(string)($m['engine_contract']??''),'contract_revision'=>(int)($m['contract_revision']??0),'theme_key'=>(string)$m['theme_key'],'name'=>(string)$m['name'],'version'=>(string)$m['version'],
            'description'=>(string)$m['description'],'layout'=>(string)$m['layout'],'media_aspect'=>(string)($m['media_aspect']??'1:1'),'composition'=>(array)($m['composition']??$defaultComposition),'variants'=>(array)($m['variants']??[]),'fingerprint'=>(string)$m['fingerprint'],'tokens'=>(array)$m['tokens'],
            'editable_tokens'=>(array)$m['editable_tokens'],'source_kind'=>(string)$package['source_kind'],'installed_at'=>(string)$package['installed_at'],
        ];
    }

    private function themeManifestPayload(array $manifest): array
    {
        $format=(string)($manifest['format']??'sokna-guest-theme-v1');
        $payload=[
            'format'=>$format,'theme_key'=>(string)$manifest['theme_key'],'name'=>(string)$manifest['name'],'version'=>(string)$manifest['version'],
            'description'=>(string)($manifest['description']??''),'layout'=>(string)$manifest['layout'],'tokens'=>(array)$manifest['tokens'],'editable_tokens'=>(array)$manifest['editable_tokens'],
        ];
        if(array_key_exists('media_aspect',$manifest))$payload['media_aspect']=(string)$manifest['media_aspect'];
        if(array_key_exists('composition',$manifest))$payload['composition']=array_values((array)$manifest['composition']);
        if($format==='sokna-guest-theme-v2'){
            $payload['engine_contract']=(string)($manifest['engine_contract']??'');$payload['contract_revision']=(int)($manifest['contract_revision']??0);
            if(array_key_exists('variants',$manifest))$payload['variants']=(array)$manifest['variants'];
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $row = $this->pdo->query('SELECT * FROM guest_content_config WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new GuestContentException('content_state_missing','وضعیت محتوای مهمان پیدا نشد.',500);
        return $row;
    }

    /** @return array<string,mixed> */
    private function identityStateFromConfig(array $state,string $mode): array
    {
        $prefix=$mode==='published'?'published':'draft';$mediaKey=trim((string)($state[$prefix.'_identity_media_key']??''));$version=trim((string)($state[$prefix.'_identity_version']??''));if($mediaKey===''||$version==='')return ['active'=>false,'media_key'=>'','version'=>'fallback','variants'=>[]];
        $q=$this->pdo->prepare('SELECT id,media_key,original_name,width_px,height_px,alt_text FROM guest_media_assets WHERE media_key=? AND active=1 LIMIT 1');$q->execute([$mediaKey]);$media=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($media))return ['active'=>false,'media_key'=>'','version'=>'fallback','variants'=>[]];
        $d=$this->pdo->prepare("SELECT variant_key,sha256,mime,extension,byte_size,width_px,height_px FROM guest_media_derivatives WHERE media_id=? AND variant_key LIKE 'identity-%' ORDER BY variant_key");$d->execute([(int)$media['id']]);$variants=[];foreach($d->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$key=preg_replace('/^identity-/','',(string)$row['variant_key']);if(!is_string($key)||!array_key_exists($key,self::IDENTITY_VARIANTS))continue;$variants[$key]=['sha256'=>(string)$row['sha256'],'mime'=>(string)$row['mime'],'extension'=>(string)$row['extension'],'byte_size'=>(int)$row['byte_size'],'width_px'=>(int)$row['width_px'],'height_px'=>(int)$row['height_px']];}
        foreach(self::IDENTITY_VARIANTS as $key=>$_)if(!isset($variants[$key]))return ['active'=>false,'media_key'=>$mediaKey,'version'=>'incomplete','variants'=>$variants,'original_name'=>(string)$media['original_name'],'width_px'=>(int)$media['width_px'],'height_px'=>(int)$media['height_px']];
        return ['active'=>true,'media_key'=>$mediaKey,'version'=>$version,'variants'=>$variants,'original_name'=>(string)$media['original_name'],'alt_text'=>(string)$media['alt_text'],'width_px'=>(int)$media['width_px'],'height_px'=>(int)$media['height_px']];
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

    /** @return array<string,mixed> */
    private function normalizePublicSettings(array $input): array
    {
        $out=[];
        foreach (self::PUBLIC_SETTINGS as $key=>$meta) {
            $type=(string)$meta['type'];
            if ($type==='bool') {
                $out[$key]=array_key_exists($key,$input)?$this->truthy($input[$key]):(bool)$meta['default'];
                continue;
            }
            $value=array_key_exists($key,$input)?(string)$input[$key]:(string)$meta['default'];
            $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value)??'';
            $value=trim($value);
            if(str_contains($value,'<')||str_contains($value,'>'))throw new GuestContentException('guest_setting_markup','تنظیمات عمومی باید متن ساده و بدون HTML باشند.',422,['key'=>$key]);
            $max=(int)($meta['max']??700);
            if($this->length($value)>$max)throw new GuestContentException('guest_setting_too_long','یکی از تنظیمات عمومی بیش از حد طولانی است.',422,['key'=>$key,'max'=>$max]);
            if($type==='url'&&$value!==''&&preg_match('#^https?://#i',$value)!==1)throw new GuestContentException('guest_setting_url','آدرس باید با http:// یا https:// شروع شود.',422,['key'=>$key]);
            if($key==='whatsapp_number'&&$value!==''&&!preg_match('/^\+?[0-9۰-۹٠-٩ ()-]{6,30}$/u',$value))throw new GuestContentException('guest_setting_phone','شماره واتس‌اپ معتبر نیست.',422,['key'=>$key]);
            $out[$key]=$value;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function mergePublicSettings(array $stored): array
    {
        $out=[];
        foreach(self::PUBLIC_SETTINGS as $key=>$meta){
            if((string)$meta['type']==='bool'){$out[$key]=array_key_exists($key,$stored)?$this->truthy($stored[$key]):(bool)$meta['default'];continue;}
            $value=trim((string)($stored[$key]??''));$max=(int)($meta['max']??700);$out[$key]=$value!==''?$this->slice($value,$max):(string)$meta['default'];
        }
        return $out;
    }

    private function createGuestCard(string $sourcePath,string $mediaKey,string $mime,string $extension,int $width,int $height): array
    {
        // Animated GIFs are kept byte-for-byte so a resize never silently destroys animation.
        if($mime==='image/gif'){
            $rel='derived/'.$mediaKey.'/guest-card.gif';$dst=$this->path($rel);$this->ensureDir(dirname($dst));
            if(!copy($sourcePath,$dst))throw new GuestContentException('media_derivative','ساخت نسخه انتشار تصویر انجام نشد.',500);@chmod($dst,0640);$sha=hash_file('sha256',$dst);
            if(!is_string($sha))throw new GuestContentException('media_derivative_hash','خواندن نسخه انتشار تصویر انجام نشد.',500);
            return ['sha256'=>$sha,'mime'=>'image/gif','extension'=>'gif','byte_size'=>(int)filesize($dst),'width'=>$width,'height'=>$height,'relpath'=>$rel,'processor'=>'passthrough-animated-gif-v1'];
        }
        $targetWidth=min(960,$width);$targetHeight=max(1,(int)round($height*($targetWidth/max(1,$width))));
        $loader=match($mime){'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp',default=>''};
        if(function_exists('imagecreatetruecolor')&&$loader!==''&&function_exists($loader)){
            $src=@$loader($sourcePath);if($src!==false){$canvas=imagecreatetruecolor($targetWidth,$targetHeight);imagealphablending($canvas,false);imagesavealpha($canvas,true);$transparent=imagecolorallocatealpha($canvas,0,0,0,127);imagefill($canvas,0,0,$transparent);imagecopyresampled($canvas,$src,0,0,0,0,$targetWidth,$targetHeight,$width,$height);
                if(function_exists('imagewebp')){$outMime='image/webp';$outExt='webp';$processor='gd-fit-960-webp-v2';$rel='derived/'.$mediaKey.'/guest-card.webp';$dst=$this->path($rel);$this->ensureDir(dirname($dst));$ok=@imagewebp($canvas,$dst,84);}else{$outMime=$mime;$outExt=$extension;$processor='gd-fit-960-v2';$rel='derived/'.$mediaKey.'/guest-card.'.$extension;$dst=$this->path($rel);$this->ensureDir(dirname($dst));$ok=match($mime){'image/jpeg'=>@imagejpeg($canvas,$dst,86),'image/png'=>@imagepng($canvas,$dst,6),'image/webp'=>@imagewebp($canvas,$dst,84),default=>false};}
                imagedestroy($canvas);imagedestroy($src);if($ok){@chmod($dst,0640);$sha=hash_file('sha256',$dst);if(is_string($sha))return ['sha256'=>$sha,'mime'=>$outMime,'extension'=>$outExt,'byte_size'=>(int)filesize($dst),'width'=>$targetWidth,'height'=>$targetHeight,'relpath'=>$rel,'processor'=>$processor];}
            }
        }
        $rel='derived/'.$mediaKey.'/guest-card.'.$extension;$dst=$this->path($rel);$this->ensureDir(dirname($dst));if(!copy($sourcePath,$dst))throw new GuestContentException('media_derivative','ساخت نسخه انتشار تصویر انجام نشد.',500);@chmod($dst,0640);$sha=hash_file('sha256',$dst);if(!is_string($sha))throw new GuestContentException('media_derivative_hash','خواندن نسخه انتشار تصویر انجام نشد.',500);return ['sha256'=>$sha,'mime'=>$mime,'extension'=>$extension,'byte_size'=>(int)filesize($dst),'width'=>$width,'height'=>$height,'relpath'=>$rel,'processor'=>'passthrough'];
    }

    private function createFocalSquare(string $sourcePath,string $mediaKey,string $mime,string $extension,int $width,int $height,float $focalX,float $focalY): ?array
    {
        // Never flatten animated GIFs as a side effect; CSS object-position remains the safe fallback.
        if($mime==='image/gif'||!function_exists('imagecreatetruecolor'))return null;
        $loader=match($mime){'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp',default=>''};if($loader===''||!function_exists($loader))return null;$src=@$loader($sourcePath);if($src===false)return null;
        $focalX=$this->focal($focalX);$focalY=$this->focal($focalY);$cropSide=max(1,min($width,$height));$target=max(1,min(960,$cropSide));$sx=max(0,min(max(0,$width-$cropSide),(int)round($focalX*$width-$cropSide/2)));$sy=max(0,min(max(0,$height-$cropSide),(int)round($focalY*$height-$cropSide/2)));$canvas=imagecreatetruecolor($target,$target);imagealphablending($canvas,false);imagesavealpha($canvas,true);$transparent=imagecolorallocatealpha($canvas,0,0,0,127);imagefill($canvas,0,0,$transparent);imagecopyresampled($canvas,$src,0,0,$sx,$sy,$target,$target,$cropSide,$cropSide);
        if(function_exists('imagewebp')){$outMime='image/webp';$outExt='webp';$rel='derived/'.$mediaKey.'/menu-square.webp';$dst=$this->path($rel);$this->ensureDir(dirname($dst));$ok=@imagewebp($canvas,$dst,84);$processor='gd-focal-square-webp-v2';}else{$outMime=$mime;$outExt=$extension;$rel='derived/'.$mediaKey.'/menu-square.'.$extension;$dst=$this->path($rel);$this->ensureDir(dirname($dst));$ok=match($mime){'image/jpeg'=>@imagejpeg($canvas,$dst,86),'image/png'=>@imagepng($canvas,$dst,6),'image/webp'=>@imagewebp($canvas,$dst,84),default=>false};$processor='gd-focal-square-v2';}
        imagedestroy($canvas);imagedestroy($src);if(!$ok){@unlink($dst);return null;}@chmod($dst,0640);$sha=hash_file('sha256',$dst);if(!is_string($sha))return null;return ['sha256'=>$sha,'mime'=>$outMime,'extension'=>$outExt,'byte_size'=>(int)filesize($dst),'width'=>$target,'height'=>$target,'relpath'=>$rel,'processor'=>$processor];
    }

    private function focal(float $value): float
    {
        if(!is_finite($value))return 0.5;return round(max(0.0,min(1.0,$value)),5);
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
