<?php
declare(strict_types=1);

namespace Sokna\Local\Setup;

use PDO;
use Throwable;
use InvalidArgumentException;
use Sokna\Local\Core\LocalEndpoint;
use Sokna\Local\UI\FontRuntime;

final class BrowserSetupService
{
    private const BINDING_FORMAT = 'sokna-local-installation-binding-v1';
    private const ARCHIVE_FORMAT = 'sokna-local-setup-archive-v1';

    public function __construct(
        private readonly string $packageRoot,
        private readonly string $localWebRoot,
    ) {
        $this->adoptLegacyRootFilesIfNeeded();
    }

    public function status(): array
    {
        $config = $this->configPath();
        $lock = $this->lockPath();
        $hasConfig = is_file($config);
        $hasLock = is_file($lock);
        $installationId = '';
        if ($hasConfig) {
            try {
                $loaded = require $config;
                if (is_array($loaded)) $installationId = trim((string)($loaded['installation']['id'] ?? ''));
            } catch (Throwable) {}
        }
        $validLock = $hasConfig && $hasLock && $this->lockMatchesInstallation($installationId);
        $binding = $this->readJsonFile($this->bindingPath());
        $bindingInstallationId = trim((string)($binding['installation_id'] ?? ''));
        $bindingValid = $validLock
            && ($binding['format'] ?? '') === self::BINDING_FORMAT
            && $bindingInstallationId !== ''
            && hash_equals($installationId, $bindingInstallationId);
        $state = match (true) {
            $validLock && $bindingValid => 'installed',
            $validLock => 'existing',
            $hasConfig && !$validLock => 'partial',
            !$hasConfig && $hasLock => 'inconsistent',
            default => 'new',
        };
        return [
            'state' => $state,
            'installed' => $state === 'installed',
            'existing_install_detected' => $state === 'existing',
            'locked' => $validLock,
            'lock_file_present' => $hasLock,
            'has_config' => $hasConfig,
            'binding_present' => is_file($this->bindingPath()),
            'binding_valid' => $bindingValid,
            'installation_id' => $installationId,
            'resume_available' => $state === 'partial',
        ];
    }

    public function preflight(string $dataDir = ''): array
    {
        $checks = [];
        $checks[] = $this->check('php_version', version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP 8.2+', PHP_VERSION);
        foreach (['pdo','pdo_mysql','json','mbstring','sodium','zlib','zip','session'] as $ext) {
            $checks[] = $this->check('ext_'.$ext, extension_loaded($ext), 'PHP extension '.$ext, extension_loaded($ext) ? 'available' : 'missing');
        }
        $migrationDir = $this->localWebRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $checks[] = $this->check('migrations_readable', is_dir($migrationDir) && is_readable($migrationDir), 'Migration catalog', (is_dir($migrationDir) && is_readable($migrationDir)) ? 'available' : 'missing');
        $packageWritable=is_dir($this->packageRoot) && is_writable($this->packageRoot);$checks[] = $this->check('package_root_writable', $packageWritable, 'Package root writable', $packageWritable ? 'writable' : 'not_writable');
        if (trim($dataDir) !== '') {
            $ok = $this->isAbsolutePath($dataDir) && $this->pathCanBeCreated($dataDir);
            $checks[] = $this->check('data_dir_writable', $ok, 'Data directory writable', $ok ? 'writable' : 'not_writable');
        }
        $ok = !in_array(false, array_column($checks, 'ok'), true);
        return ['ok'=>$ok,'checks'=>$checks,'php'=>PHP_VERSION];
    }

    public static function normalizeLocalBaseUrl(string $value): string
    {
        try { return LocalEndpoint::normalize($value); }
        catch (InvalidArgumentException $e) {
            $code=$e->getMessage()==='local_endpoint_not_loopback'?'local_endpoint_not_loopback':'local_endpoint_invalid';
            $message=$code==='local_endpoint_not_loopback'
                ?'آدرس Local Web باید فقط روی همین کامپیوتر (loopback) باشد.'
                :'آدرس Local Web یا پورت انتخاب‌شده معتبر نیست.';
            throw new SetupException($code,$message,422);
        }
    }

    public function testDatabase(array $db, bool $createDatabase = false): array
    {
        $db = $this->normalizeDb($db);
        $server = $this->connectServer($db);
        $version = (string)$server->query('SELECT VERSION()')->fetchColumn();
        $compatible = $this->isSupportedMariaDb($version);
        if (!$compatible) throw new SetupException('database_version_unsupported','نسخه دیتابیس باید MariaDB 11.4.x باشد.',409,['current'=>$version,'required'=>'MariaDB 11.4.x']);
        if ($createDatabase) {
            $name = str_replace('`','``',$db['name']);
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        $pdo = $this->connectDatabase($db);
        $pdo->query('SELECT 1')->fetchColumn();
        return ['ok'=>true,'server_version'=>$version,'compatible'=>true,'database'=>$db['name']];
    }

    public function installNew(array $input): array
    {
        $this->prepareLongRunningSetup();
        $status = $this->status();
        if ($status['installed']) throw new SetupException('setup_locked','نصب قبلاً نهایی شده است.',423);
        if ($status['state'] === 'existing') throw new SetupException('existing_install_choice_required','نصب قبلی پیدا شد. ابتدا مشخص کن نصب موجود ادامه داده شود یا نصب جدید شروع شود.',409);
        $dataDir = rtrim(trim((string)($input['data_dir'] ?? '')), "\\/");
        $timezone = trim((string)($input['timezone'] ?? 'Asia/Tehran')) ?: 'Asia/Tehran';
        $localBaseUrl = self::normalizeLocalBaseUrl((string)($input['local_base_url'] ?? ''));
        if (!$this->isAbsolutePath($dataDir)) throw new SetupException('data_dir_invalid','مسیر داده باید کامل باشد.');
        try { new \DateTimeZone($timezone); } catch (Throwable) { throw new SetupException('timezone_invalid','منطقه زمانی معتبر نیست.'); }
        $preflight = $this->preflight($dataDir);
        if (!$preflight['ok']) throw new SetupException('preflight_failed','پیش‌نیازهای نصب کامل نیستند.',409,['checks'=>$preflight['checks']]);

        $db = $this->normalizeDb((array)($input['db'] ?? []));
        $this->testDatabase($db, (bool)($input['create_database'] ?? false));
        $username = trim((string)($input['admin_user'] ?? ''));
        $password = (string)($input['admin_password'] ?? '');
        if (strlen($username) < 3 || strlen($password) < 8) throw new SetupException('admin_invalid','نام کاربری یا رمز مدیر اولیه معتبر نیست.');
        $cafeName = trim((string)($input['cafe_name'] ?? 'SOKNA')) ?: 'SOKNA';
        $tableCount = max(1,min(100,(int)($input['table_count'] ?? 10)));

        $existing = $this->loadConfig();
        $installationId = trim((string)($existing['installation']['id'] ?? ''));
        if ($installationId === '') $installationId = 'local-'.bin2hex(random_bytes(16));
        $localToken = trim((string)($existing['runtime']['local_token'] ?? ''));
        if ($localToken === '') $localToken = bin2hex(random_bytes(32));
        $appConfig = [
            'app'=>['timezone'=>$timezone,'data_dir'=>$dataDir,'session_lifetime'=>43200],
            'db'=>['host'=>$db['host'],'port'=>$db['port'],'name'=>$db['name'],'charset'=>'utf8mb4','user'=>$db['user'],'pass'=>$db['pass']],
            'installation'=>['id'=>$installationId],
            'runtime'=>['local_token'=>$localToken,'local_base_url'=>$localBaseUrl],
            'public'=>['base_url'=>'','shared_secret'=>''],
            'integrations'=>['accommodation'=>['base_url'=>'','secret'=>'']],
        ];

        $this->ensurePrivateDir($dataDir);
        $this->writePrivate($this->configPath(), $this->configPhp($appConfig));
        try {
            $core = \sokna_local_bootstrap($appConfig);
            $pdo = $core->database();
            $core->migrations()->migrate();
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $savedInstallationId = $this->setting($pdo,'installation.id');
            if ($users > 0 && $savedInstallationId !== $installationId) {
                throw new SetupException('target_not_empty','نصب جدید فقط روی دیتابیس خالی یا Setup نیمه‌تمام همین نصب مجاز است.',409);
            }
            if ($users === 0) {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)")->execute([$username,password_hash($password,PASSWORD_DEFAULT),$username]);
                $adminId=(int)$pdo->lastInsertId();
                $setting=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                foreach ([['cafe.name',$cafeName],['installation.id',$installationId],['setup.status','initialized']] as [$k,$v]) $setting->execute([$k,$v]);
                $count=(int)$pdo->query('SELECT COUNT(*) FROM cafe_tables')->fetchColumn();
                if ($count === 0) {
                    $ins=$pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES(?,?,?,?,1,?)');
                    for($i=1;$i<=$tableCount;$i++) $ins->execute(['میز '.$i,$i,'T'.$i,bin2hex(random_bytes(24)),$i]);
                }
                $pdo->prepare("INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,'setup.initialized','installation',?,?)")
                    ->execute([$adminId,$username,$installationId,json_encode(['table_count'=>$tableCount,'surface'=>'browser'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                $pdo->commit();
            }
            $adminId=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
            if($adminId<1)throw new SetupException('admin_missing','مدیر فعال برای تکمیل داده اولیه پیدا نشد.',500);
            $core->defaultContentSeeder()->seed($adminId);
            $this->provisionMachineFiles($appConfig);
            $health = $this->finalHealth($appConfig);
            $this->writePrivate($this->lockPath(), json_encode([
                'format'=>'sokna-install-lock-v3','installation_id'=>$installationId,'created_at'=>gmdate('c'),
                'setup_surface'=>'browser','health'=>$health,
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
            $this->writeBinding($installationId, 'browser-install');
            FontRuntime::ensure($this->packageRoot);
            return ['success'=>true,'state'=>'installed','installation_id'=>$installationId,'health'=>$health,'next'=>'/login.php'];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof SetupException) throw $e;
            throw new SetupException('setup_failed','نصب کامل نشد. وضعیت Setup برای تلاش دوباره حفظ شده است.',500);
        }
    }

    public function resume(): array
    {
        $this->prepareLongRunningSetup();
        $status = $this->status();
        if ($status['installed']) return ['success'=>true,'state'=>'installed','next'=>'/login.php'];
        if ($status['state'] === 'existing') throw new SetupException('existing_install_choice_required','نصب قبلی پیدا شد. ابتدا مشخص کن نصب موجود ادامه داده شود یا نصب جدید شروع شود.',409);
        $config = $this->loadConfig();
        if ($config === []) throw new SetupException('resume_unavailable','Setup نیمه‌تمامی برای ادامه پیدا نشد.',404);
        try {
            $core=\sokna_local_bootstrap($config);$pdo=$core->database();$core->migrations()->migrate();
            $installationId=trim((string)($config['installation']['id']??''));
            if ($installationId==='' || $this->setting($pdo,'installation.id')!==$installationId || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()<1) {
                throw new SetupException('resume_not_ready','Setup نیمه‌تمام هنوز به مرحله قابل نهایی‌سازی نرسیده است.',409);
            }
            $adminId=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
            if($adminId<1)throw new SetupException('admin_missing','مدیر فعال برای ادامه داده اولیه پیدا نشد.',500);
            $core->defaultContentSeeder()->seed($adminId);
            $this->provisionMachineFiles($config);
            $health=$this->finalHealth($config);
            $this->writePrivate($this->lockPath(),json_encode(['format'=>'sokna-install-lock-v3','installation_id'=>$installationId,'created_at'=>gmdate('c'),'setup_surface'=>'browser-resume','health'=>$health],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
            $this->writeBinding($installationId, 'browser-resume');
            FontRuntime::ensure($this->packageRoot);
            return ['success'=>true,'state'=>'installed','resumed'=>true,'installation_id'=>$installationId,'health'=>$health,'next'=>'/login.php'];
        } catch (Throwable $e) {
            if ($e instanceof SetupException) throw $e;
            throw new SetupException('resume_failed','ادامه Setup کامل نشد. تنظیمات نیمه‌تمام حفظ شده است.',500);
        }
    }

    public function continueExisting(): array
    {
        $status = $this->status();
        if ($status['installed']) return ['success'=>true,'state'=>'installed','next'=>'/login.php'];
        if ($status['state'] !== 'existing') throw new SetupException('existing_install_unavailable','نصب قبلی معتبری برای ادامه پیدا نشد.',409);

        $config = $this->loadConfig();
        $installationId = trim((string)($config['installation']['id'] ?? ''));
        if ($installationId === '') throw new SetupException('existing_install_invalid','شناسه نصب قبلی معتبر نیست.',409);

        try {
            $core = \sokna_local_bootstrap($config);
            $pdo = $core->database();
            $databaseOk = (int)$pdo->query('SELECT 1')->fetchColumn() === 1;
            $identityOk = $this->setting($pdo, 'installation.id') === $installationId;
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            if (!$databaseOk || !$identityOk || $adminCount < 1) {
                throw new SetupException('existing_install_health_failed','نصب قبلی پیدا شد اما اتصال یا هویت آن قابل تأیید نیست. هیچ داده‌ای تغییر نکرد.',409);
            }
            $this->writeBinding($installationId, 'existing-install-continued');
            FontRuntime::ensure($this->packageRoot);
            return ['success'=>true,'state'=>'installed','continued_existing'=>true,'installation_id'=>$installationId,'next'=>'/login.php'];
        } catch (Throwable $e) {
            if ($e instanceof SetupException) throw $e;
            throw new SetupException('existing_install_unavailable','نصب قبلی پیدا شد اما در حال حاضر قابل استفاده نیست. هیچ داده‌ای تغییر نکرد.',409);
        }
    }

    public function startFresh(array $input): array
    {
        $status = $this->status();
        if ($status['state'] !== 'existing') throw new SetupException('existing_install_unavailable','نصب قبلی معتبری برای آرشیو پیدا نشد.',409);
        if (!hash_equals('archive-and-start-new', trim((string)($input['confirmation'] ?? '')))) {
            throw new SetupException('fresh_install_confirmation_required','برای شروع نصب جدید، تأیید صریح لازم است.',409);
        }

        $installationId = trim((string)($status['installation_id'] ?? ''));
        $archiveId = gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $archiveDir = $this->packageRoot.DIRECTORY_SEPARATOR.'SetupArchive'.DIRECTORY_SEPARATOR.$archiveId;
        $this->ensurePrivateDir($archiveDir);

        $files = [
            'config.php' => $this->configPath(),
            'install.lock' => $this->lockPath(),
        ];
        if (is_file($this->bindingPath())) $files['web-installation-binding.json'] = $this->bindingPath();

        $archived = [];
        foreach ($files as $name => $source) {
            if (!is_file($source)) continue;
            $target = $archiveDir.DIRECTORY_SEPARATOR.$name;
            $bytes = @file_get_contents($source);
            if (!is_string($bytes) || @file_put_contents($target, $bytes, LOCK_EX) === false) {
                throw new SetupException('existing_state_archive_failed','آرشیو امن وضعیت نصب قبلی کامل نشد؛ نصب قبلی بدون تغییر باقی ماند.',500);
            }
            @chmod($target, 0600);
            if (!hash_equals((string)hash_file('sha256', $source), (string)hash_file('sha256', $target))) {
                throw new SetupException('existing_state_archive_failed','صحت آرشیو وضعیت نصب قبلی تأیید نشد؛ نصب قبلی بدون تغییر باقی ماند.',500);
            }
            $archived[$name] = ['size'=>(int)filesize($target),'sha256'=>(string)hash_file('sha256',$target)];
        }

        $manifest = [
            'format'=>self::ARCHIVE_FORMAT,
            'archive_id'=>$archiveId,
            'installation_id'=>$installationId,
            'created_at'=>gmdate('c'),
            'files'=>$archived,
            'business_data_deleted'=>false,
            'database_deleted'=>false,
        ];
        $this->writePrivate($archiveDir.DIRECTORY_SEPARATOR.'archive.json', json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");

        $detach = [
            $this->configPath() => $archiveDir.DIRECTORY_SEPARATOR.'config.php',
            $this->lockPath() => $archiveDir.DIRECTORY_SEPARATOR.'install.lock',
            $this->bindingPath() => $archiveDir.DIRECTORY_SEPARATOR.'web-installation-binding.json',
        ];
        $removed = [];
        foreach ($detach as $path => $backup) {
            if (!is_file($path)) continue;
            if (@unlink($path)) { $removed[$path] = $backup; continue; }
            foreach ($removed as $restorePath => $restoreBackup) {
                $bytes=@file_get_contents($restoreBackup);
                if(is_string($bytes))@file_put_contents($restorePath,$bytes,LOCK_EX);
            }
            throw new SetupException('existing_state_archive_finalize_failed','آرشیو ساخته شد اما جداکردن وضعیت قبلی کامل نشد. وضعیت قابل بازیابی حفظ شد و نصب جدید شروع نشد.',500,['archive_id'=>$archiveId]);
        }

        return ['success'=>true,'state'=>'new','fresh_start_ready'=>true,'archive_id'=>$archiveId,'next'=>'/setup/'];
    }

    private function finalHealth(array $config): array
    {
        $core=\sokna_local_bootstrap($config);$pdo=$core->database();
        $dbOk=(int)$pdo->query('SELECT 1')->fetchColumn()===1;
        $migrationFiles=glob($this->localWebRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.sql') ?: [];
        $applied=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $adminCount=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
        $installationId=trim((string)($config['installation']['id']??''));
        $identityOk=$installationId!=='' && $this->setting($pdo,'installation.id')===$installationId;
        $seedOk=$this->setting($pdo,'default_content.v2')==='complete';
        $seedCounts=['menus'=>(int)$pdo->query('SELECT COUNT(*) FROM menus')->fetchColumn(),'categories'=>(int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),'items'=>(int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn(),'media'=>(int)$pdo->query('SELECT COUNT(*) FROM guest_media_assets')->fetchColumn()];
        $seedOk=$seedOk&&$seedCounts['menus']>=3&&$seedCounts['categories']>=14&&$seedCounts['items']>=131&&$seedCounts['media']>=24;
        if (!$dbOk || $applied < count($migrationFiles) || $adminCount < 1 || !$identityOk || !$seedOk) throw new SetupException('final_health_failed','بررسی نهایی نصب کامل نشد.',500,['database'=>$dbOk,'migrations'=>$applied.'/'.count($migrationFiles),'admin'=>$adminCount,'identity'=>$identityOk,'default_content'=>$seedOk,'default_content_counts'=>$seedCounts]);
        return ['database'=>'ok','migrations_applied'=>$applied,'migration_files'=>count($migrationFiles),'admin'=>'ok','installation_identity'=>'ok','default_content'=>'ok','default_content_counts'=>$seedCounts];
    }

    private function provisionMachineFiles(array $config): void
    {
        $dataDir=rtrim((string)$config['app']['data_dir'],"\\/");$secrets=$dataDir.DIRECTORY_SEPARATOR.'secrets';$runtimeDir=$dataDir.DIRECTORY_SEPARATOR.'runtime';
        $this->ensurePrivateDir($secrets);$this->ensurePrivateDir($runtimeDir);
        $localToken=trim((string)($config['runtime']['local_token']??''));if($localToken==='')throw new SetupException('runtime_token_missing','توکن Local Runtime موجود نیست.',500);
        $healthPath=$secrets.DIRECTORY_SEPARATOR.'runtime-health.token';
        $healthToken=is_file($healthPath)?trim((string)file_get_contents($healthPath)):'';if($healthToken==='')$healthToken=bin2hex(random_bytes(32));
        $this->writePrivate($secrets.DIRECTORY_SEPARATOR.'runtime-local.token',$localToken."\n");$this->writePrivate($healthPath,$healthToken."\n");
        $localBaseUrl=trim((string)($config['runtime']['local_base_url']??''));if($localBaseUrl==='')$localBaseUrl=$this->localBaseUrl();
        $runtime=['contractVersion'=>1,'instanceId'=>'runtime-'.bin2hex(random_bytes(12)),'dataRoot'=>$runtimeDir,'healthPort'=>17621,'runtimeTokenFile'=>$healthPath,'localTokenFile'=>$secrets.DIRECTORY_SEPARATOR.'runtime-local.token','localBaseUrl'=>$localBaseUrl,'printAgentServiceName'=>'SoknaPrintWorker','supervisePrintAgent'=>true,'triggers'=>[['key'=>'inventory.order_events','intervalSeconds'=>15],['key'=>'public.relay_sync','intervalSeconds'=>5],['key'=>'public.projection_sync','intervalSeconds'=>30],['key'=>'notifications.outbox','intervalSeconds'=>15],['key'=>'maintenance.health','intervalSeconds'=>60]]];
        $runtimePath=$runtimeDir.DIRECTORY_SEPARATOR.'runtime-config.json';
        if (!is_file($runtimePath)) $this->writePrivate($runtimePath,json_encode($runtime,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    }


    private function prepareLongRunningSetup(): void
    {
        // Browser Setup legitimately performs the full migration catalog, initial
        // content seed and machine provisioning. The stock PHP 30-second web
        // timeout is too short on slower Windows disks and caused a reproducible
        // first-attempt failure while a second attempt merely resumed the work.
        @ini_set('max_execution_time','600');
        if (function_exists('set_time_limit')) @set_time_limit(600);
        @ignore_user_abort(true);
    }

    private function localBaseUrl(): string
    {
        $statePath=$this->packageRoot.DIRECTORY_SEPARATOR.'Infrastructure'.DIRECTORY_SEPARATOR.'infrastructure-state.json';
        if(is_file($statePath)){
            try{
                $raw=@file_get_contents($statePath);$state=is_string($raw)?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;
                if(is_array($state)){
                    $endpoint=(array)($state['endpoints']['local_web']??[]);
                    foreach([(string)($endpoint['origin']??''),(string)($endpoint['base_url']??'')] as $candidate){
                        try { return LocalEndpoint::normalize($candidate); } catch (InvalidArgumentException) {}
                    }
                }
            }catch(Throwable){}
        }
        try { return LocalEndpoint::fromServer($_SERVER); }
        catch (InvalidArgumentException) { return 'http://127.0.0.1:18080/'; }
    }

    private function validateLoopbackUrl(string $value): string
    {
        try { return rtrim(LocalEndpoint::normalize($value),'/'); }
        catch (InvalidArgumentException) { return ''; }
    }

    private function adoptLegacyRootFilesIfNeeded(): void
    {
        // g6.2 incorrectly walked one directory too far and could place config.php
        // and install.lock at the drive root (for example D:\\config.php). Adopt a
        // matching legacy install into the selected SOKNA root without deleting the
        // legacy files, so an already-installed machine remains recoverable.
        if(is_file($this->configPath())||is_file($this->lockPath()))return;
        $expectedLocal=$this->packageRoot.DIRECTORY_SEPARATOR.'Web';
        if(strcasecmp(rtrim(str_replace('/','\\',$this->localWebRoot),'\\'),rtrim(str_replace('/','\\',$expectedLocal),'\\'))!==0)return;
        $legacyRoot=dirname($this->packageRoot);if($legacyRoot===$this->packageRoot)return;
        $legacyConfig=$legacyRoot.DIRECTORY_SEPARATOR.'config.php';if(!is_file($legacyConfig))return;
        try{$cfg=require $legacyConfig;}catch(Throwable){return;}
        if(!is_array($cfg)||!is_array($cfg['app']??null)||!is_array($cfg['db']??null)||!is_array($cfg['installation']??null))return;
        $installationId=trim((string)($cfg['installation']['id']??''));if($installationId==='')return;
        $this->writePrivate($this->configPath(),(string)file_get_contents($legacyConfig));
        $legacyLock=$legacyRoot.DIRECTORY_SEPARATOR.'install.lock';if(!is_file($legacyLock))return;
        try{$raw=(string)file_get_contents($legacyLock);$lock=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(is_array($lock)&&($lock['format']??'')==='sokna-install-lock-v3'&&hash_equals($installationId,trim((string)($lock['installation_id']??''))))$this->writePrivate($this->lockPath(),$raw);}catch(Throwable){}
    }

    private function normalizeDb(array $db): array
    {
        $host=trim((string)($db['host']??''));$port=(string)($db['port']??'3306');$name=trim((string)($db['name']??''));$user=trim((string)($db['user']??''));$pass=(string)($db['pass']??'');
        if($host===''||$user===''||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)||!ctype_digit($port)||(int)$port<1||(int)$port>65535) throw new SetupException('database_config_invalid','تنظیمات دیتابیس معتبر نیست.');
        return compact('host','port','name','user','pass');
    }
    private function connectServer(array $db): PDO { return $this->pdo("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",$db['user'],$db['pass']); }
    private function connectDatabase(array $db): PDO { return $this->pdo("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass']); }
    private function pdo(string $dsn,string $user,string $pass): PDO { try{return new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}catch(Throwable){throw new SetupException('database_unavailable','اتصال دیتابیس برقرار نشد.',409);} }
    private function isSupportedMariaDb(string $version): bool { return stripos($version,'mariadb')!==false && preg_match('/(^|[^0-9])11\.4\./',$version)===1; }
    private function setting(PDO $pdo,string $key): string { $stmt=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');$stmt->execute([$key]);$v=$stmt->fetchColumn();return $v===false?'':(string)$v; }
    private function loadConfig(): array { if(!is_file($this->configPath()))return [];try{$v=require $this->configPath();return is_array($v)?$v:[];}catch(Throwable){return [];} }

    private function lockMatchesInstallation(string $installationId): bool
    {
        if ($installationId === '' || !is_file($this->lockPath())) return false;
        try {
            $raw=@file_get_contents($this->lockPath());
            $lock=is_string($raw)?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;
            return is_array($lock) && ($lock['format']??'')==='sokna-install-lock-v3' && hash_equals($installationId,trim((string)($lock['installation_id']??'')));
        } catch (Throwable) { return false; }
    }
    private function bindingPath(): string { return $this->localWebRoot.DIRECTORY_SEPARATOR.'.sokna-installation.json'; }
    private function writeBinding(string $installationId,string $source): void
    {
        $installationId=trim($installationId);
        if($installationId==='')throw new SetupException('installation_identity_missing','شناسه نصب برای اتصال نسخه وب موجود نیست.',500);
        $this->writePrivate($this->bindingPath(),json_encode([
            'format'=>self::BINDING_FORMAT,
            'installation_id'=>$installationId,
            'bound_at'=>gmdate('c'),
            'source'=>$source,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    }
    private function readJsonFile(string $path): array
    {
        $raw=@file_get_contents($path);if(!is_string($raw)||$raw==='')return [];
        try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}
    }
    private function configPath(): string { return $this->packageRoot.DIRECTORY_SEPARATOR.'config.php'; }
    private function lockPath(): string { return $this->packageRoot.DIRECTORY_SEPARATOR.'install.lock'; }
    private function configPhp(array $config): string { return "<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n"; }
    private function writePrivate(string $path,string $bytes): void { $this->ensurePrivateDir(dirname($path));$tmp=$path.'.tmp-'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$bytes,LOCK_EX)===false){@unlink($tmp);throw new SetupException('write_failed','فایل Setup قابل نوشتن نیست.',500);} @chmod($tmp,0600);if(!@rename($tmp,$path)){@unlink($tmp);throw new SetupException('write_failed','نوشتن اتمیک فایل Setup کامل نشد.',500);} }
    private function ensurePrivateDir(string $path): void { if(!is_dir($path)&&!@mkdir($path,0700,true)&&!is_dir($path))throw new SetupException('directory_unavailable','پوشه موردنیاز Setup قابل ایجاد نیست.',500,['path'=>$path]);if(!is_writable($path))throw new SetupException('directory_not_writable','پوشه موردنیاز Setup قابل نوشتن نیست.',500,['path'=>$path]); }
    private function isAbsolutePath(string $path): bool { return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D',trim($path))===1; }
    private function pathCanBeCreated(string $path): bool { if(is_dir($path))return is_writable($path);$p=$path;while($p!==dirname($p)&&!file_exists($p))$p=dirname($p);return is_dir($p)&&is_writable($p); }
    private function check(string $id,bool $ok,string $label,string $current): array { return compact('id','ok','label','current'); }
}
