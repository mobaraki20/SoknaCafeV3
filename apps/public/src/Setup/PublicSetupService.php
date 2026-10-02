<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Setup;

use PDO;
use Sokna\PublicEdge\Core\Config;
use Sokna\PublicEdge\Core\Database;
use Throwable;

final class PublicSetupService
{
    public function __construct(
        private readonly string $componentRoot,
        private readonly string $configPath,
    ) {}

    public function status(): array
    {
        $configExists=is_file($this->configPath)&&is_readable($this->configPath);
        $lockExists=is_file($this->lockPath())&&is_readable($this->lockPath());
        if(!$configExists&&$lockExists)return ['installed'=>false,'state'=>'inconsistent','paired'=>false,'database'=>'unknown'];
        if(!$configExists)return ['installed'=>false,'state'=>'fresh','paired'=>false,'database'=>'not_configured'];

        try{
            $config=$this->loadConfig();
            $pdo=Database::connect(Config::fromArray($config));
            $dbOk=(int)$pdo->query('SELECT 1')->fetchColumn()===1;
            $applied=$this->appliedCount($pdo);
            $files=count($this->migrationFiles());
            $paired=$this->pairedInstallation($pdo)!=='';
            $installed=$lockExists&&$dbOk&&$applied===$files;
            return [
                'installed'=>$installed,
                'state'=>$installed?'installed':'partial',
                'paired'=>$paired,
                'database'=>$dbOk?'ok':'failed',
                'migrations_applied'=>$applied,
                'migration_files'=>$files,
                'storage_dir'=>(string)($config['app']['storage_dir']??$this->defaultStorageDir()),
            ];
        }catch(Throwable){
            return ['installed'=>false,'state'=>'partial','paired'=>false,'database'=>'unavailable'];
        }
    }

    public function preflight(string $storageDir='',bool $secureTransport=true,string $host=''): array
    {
        $storageDir=trim($storageDir)!==''?trim($storageDir):$this->defaultStorageDir();
        $checks=[];
        $checks[]=$this->check('php_version',version_compare(PHP_VERSION,'8.2.0','>='),'PHP 8.2+',PHP_VERSION);
        foreach(['pdo','pdo_mysql','json','sodium','openssl','session'] as $ext){
            $ok=extension_loaded($ext);
            $checks[]=$this->check('ext_'.$ext,$ok,'PHP extension '.$ext,$ok?'available':'missing');
        }
        $migrationDir=$this->componentRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $checks[]=$this->check('migrations_readable',is_dir($migrationDir)&&is_readable($migrationDir),'Migration catalog',(is_dir($migrationDir)&&is_readable($migrationDir))?'available':'missing');
        $checks[]=$this->check('config_writable',$this->pathCanBeCreated(dirname($this->configPath)),'Config directory writable',$this->pathCanBeCreated(dirname($this->configPath))?'writable':'not_writable');
        $checks[]=$this->check('storage_writable',$this->pathCanBeCreated($storageDir),'Storage directory writable',$this->pathCanBeCreated($storageDir)?'writable':'not_writable');
        $checks[]=$this->check('config_private',$this->configOutsideDocumentRoot(),'Config outside document root',$this->configOutsideDocumentRoot()?'safe':'unsafe');
        $loopback=in_array(strtolower($this->stripPort($host)),['127.0.0.1','localhost','::1'],true);
        $checks[]=$this->check('https',$secureTransport||$loopback,'HTTPS transport',($secureTransport||$loopback)?'secure':'required');
        $ok=!in_array(false,array_column($checks,'ok'),true);
        return ['ok'=>$ok,'checks'=>$checks,'storage_dir'=>$storageDir];
    }

    public function testDatabase(array $input,bool $createDatabase=false): array
    {
        $db=$this->normalizeDb($input);
        try{
            $server=$this->connectServer($db);
            $version=(string)$server->query('SELECT VERSION()')->fetchColumn();
            $exists=$this->databaseExists($server,$db['name']);
            if(!$exists&&$createDatabase){
                $server->exec('CREATE DATABASE '.$this->quoteIdentifier($db['name']).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $exists=true;
            }
            if(!$exists)throw new SetupException('database_missing','دیتابیس پیدا نشد. ابتدا آن را در پنل هاست بساز یا گزینه ساخت دیتابیس را فعال کن.',409);
            $pdo=$this->connectDatabase($db);
            if((int)$pdo->query('SELECT 1')->fetchColumn()!==1)throw new SetupException('database_unavailable','اتصال دیتابیس تأیید نشد.',409);
            return ['ok'=>true,'server_version'=>$version,'database'=>$db['name'],'database_exists'=>true];
        }catch(SetupException $e){throw $e;}
        catch(Throwable){throw new SetupException('database_unavailable','اتصال به دیتابیس برقرار نشد. Host، نام دیتابیس، نام کاربری و رمز را بررسی کن.',409);}
    }

    public function installNew(array $data,bool $secureTransport=true,string $host=''): array
    {
        $current=$this->status();
        if(($current['installed']??false)===true)throw new SetupException('already_installed','Public Edge قبلاً نصب شده است.',409);
        if(($current['state']??'')==='inconsistent')throw new SetupException('setup_inconsistent','install.lock وجود دارد اما config.php معتبر پیدا نشد.',409);

        $storage=trim((string)($data['storage_dir']??''));if($storage==='')$storage=$this->defaultStorageDir();
        $preflight=$this->preflight($storage,$secureTransport,$host);
        if(!$preflight['ok'])throw new SetupException('preflight_failed','پیش‌نیازهای نصب Public Edge کامل نیستند.',409,['checks'=>$preflight['checks']]);

        $db=$this->normalizeDb((array)($data['db']??[]));
        $this->testDatabase($db,!empty($data['create_database']));
        $this->ensureDir($storage);

        $pairingCode=$this->pairingCode();
        $config=[
            'db'=>[
                'host'=>$db['host'],'port'=>$db['port'],'name'=>$db['name'],'charset'=>'utf8mb4','user'=>$db['user'],'pass'=>$db['pass'],
            ],
            'app'=>[
                'default_installation_id'=>'',
                'cookie_secure'=>$secureTransport,
                'storage_dir'=>$storage,
            ],
            'relay'=>[
                'installation_secrets'=>[],
                'initial_pairing_code'=>$pairingCode,
                'clock_skew_seconds'=>300,
                'secret_encryption_key_base64'=>base64_encode(random_bytes(32)),
            ],
            'auth'=>[
                'session_ttl_seconds'=>28800,
                'failure_limit'=>5,
                'failure_window_seconds'=>900,
                'block_seconds'=>900,
            ],
        ];

        $this->writePrivate($this->configPath,$this->configPhp($config));
        try{
            $core=\sokna_public_bootstrap($config);
            $applied=$core->migrations()->migrate();
            $health=$this->finalHealth($config);
            $this->writePrivate($this->lockPath(),json_encode([
                'format'=>'sokna-public-install-lock-v1',
                'schema_version'=>1,
                'version'=>$this->componentVersion(),
                'created_at'=>gmdate('c'),
                'setup_surface'=>'browser',
                'health'=>$health,
            ],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
            return [
                'success'=>true,
                'state'=>'installed',
                'pairing_code'=>$pairingCode,
                'applied_migrations'=>$applied,
                'health'=>$health,
                'next'=>'pair_local',
            ];
        }catch(Throwable $e){
            if($e instanceof SetupException)throw $e;
            throw new SetupException('install_failed','نصب Public Edge کامل نشد. config.php برای ادامه Setup حفظ شده است.',500);
        }
    }

    public function resume(bool $secureTransport=true,string $host=''): array
    {
        if(!is_file($this->configPath))throw new SetupException('config_missing','config.php برای ادامه Setup پیدا نشد.',409);
        $config=$this->loadConfig();
        $storage=trim((string)($config['app']['storage_dir']??''));if($storage==='')$storage=$this->defaultStorageDir();
        $preflight=$this->preflight($storage,$secureTransport,$host);
        if(!$preflight['ok'])throw new SetupException('preflight_failed','پیش‌نیازهای ادامه نصب کامل نیستند.',409,['checks'=>$preflight['checks']]);
        try{
            $core=\sokna_public_bootstrap($config);
            $applied=$core->migrations()->migrate();
            $health=$this->finalHealth($config);
            $this->writePrivate($this->lockPath(),json_encode([
                'format'=>'sokna-public-install-lock-v1','schema_version'=>1,'version'=>$this->componentVersion(),
                'created_at'=>gmdate('c'),'setup_surface'=>'browser-resume','health'=>$health,
            ],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
            $pairing=trim((string)($config['relay']['initial_pairing_code']??''));
            if($health['paired']??false)$pairing='';
            return ['success'=>true,'state'=>'installed','pairing_code'=>$pairing,'applied_migrations'=>$applied,'health'=>$health,'next'=>'pair_local'];
        }catch(Throwable $e){
            if($e instanceof SetupException)throw $e;
            throw new SetupException('resume_failed','ادامه Setup کامل نشد. تنظیمات نیمه‌تمام حفظ شده است.',500);
        }
    }

    private function finalHealth(array $config): array
    {
        $cfg=Config::fromArray($config);$pdo=Database::connect($cfg);
        $dbOk=(int)$pdo->query('SELECT 1')->fetchColumn()===1;
        $files=count($this->migrationFiles());$applied=$this->appliedCount($pdo);
        $storage=trim((string)($config['app']['storage_dir']??''));$storageOk=$storage!==''&&is_dir($storage)&&is_writable($storage);
        if(!$dbOk||$files<1||$applied!==$files||!$storageOk)throw new SetupException('final_health_failed','بررسی نهایی نصب Public Edge کامل نشد.',500,['database'=>$dbOk,'migrations'=>$applied.'/'.$files,'storage'=>$storageOk]);
        return ['database'=>'ok','migrations_applied'=>$applied,'migration_files'=>$files,'storage'=>'ok','paired'=>$this->pairedInstallation($pdo)!==''];
    }

    private function pairedInstallation(PDO $pdo): string
    {
        try{$v=$pdo->query("SELECT installation_id FROM installations WHERE active=1 AND revoked_at IS NULL ORDER BY updated_at DESC LIMIT 1")->fetchColumn();}
        catch(Throwable){return '';}
        return is_string($v)?trim($v):'';
    }

    private function migrationFiles(): array
    {
        return glob($this->componentRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.sql')?:[];
    }

    private function appliedCount(PDO $pdo): int
    {
        try{return (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();}catch(Throwable){return 0;}
    }

    private function normalizeDb(array $db): array
    {
        $host=trim((string)($db['host']??''));$port=trim((string)($db['port']??'3306'));$name=trim((string)($db['name']??''));$user=trim((string)($db['user']??''));$pass=(string)($db['pass']??'');
        if($host===''||preg_match('/[\x00-\x20]/',$host)||!ctype_digit($port)||(int)$port<1||(int)$port>65535||preg_match('/^[A-Za-z0-9_$-]{1,64}$/D',$name)!==1||$user===''||strlen($user)>128){
            throw new SetupException('database_config_invalid','تنظیمات دیتابیس معتبر نیست.',422);
        }
        return compact('host','port','name','user','pass');
    }

    private function connectServer(array $db): PDO
    {
        try{return new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
        catch(Throwable){throw new SetupException('database_unavailable','اتصال به سرور دیتابیس برقرار نشد.',409);}
    }

    private function connectDatabase(array $db): PDO
    {
        try{return new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
        catch(Throwable){throw new SetupException('database_unavailable','اتصال به دیتابیس انتخاب‌شده برقرار نشد.',409);}
    }

    private function databaseExists(PDO $pdo,string $name): bool
    {
        $q=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=? LIMIT 1');$q->execute([$name]);return $q->fetchColumn()!==false;
    }

    private function quoteIdentifier(string $name): string{$q=chr(96);return $q.str_replace($q,$q.$q,$name).$q;}
    private function defaultStorageDir(): string{return $this->componentRoot.DIRECTORY_SEPARATOR.'storage';}
    private function lockPath(): string{return dirname($this->configPath).DIRECTORY_SEPARATOR.'install.lock';}
    private function componentVersion(): string{$p=$this->componentRoot.DIRECTORY_SEPARATOR.'VERSION.txt';return is_file($p)?trim((string)file_get_contents($p)):'unknown';}
    private function pairingCode(): string{return 'PUB-'.implode('-',str_split(strtoupper(bin2hex(random_bytes(24))),8));}
    private function configPhp(array $config): string{return "<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";}
    private function loadConfig(): array
    {
        try{$v=require $this->configPath;if(!is_array($v))throw new SetupException('config_invalid','config.php معتبر نیست.',500);return $v;}
        catch(SetupException $e){throw $e;}catch(Throwable){throw new SetupException('config_invalid','config.php قابل خواندن نیست.',500);}
    }

    private function writePrivate(string $path,string $bytes): void
    {
        $this->ensureDir(dirname($path));$tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
        if(@file_put_contents($tmp,$bytes,LOCK_EX)===false){@unlink($tmp);throw new SetupException('write_failed','نوشتن فایل Setup انجام نشد.',500);}
        @chmod($tmp,0600);if(!@rename($tmp,$path)){@unlink($tmp);throw new SetupException('write_failed','نوشتن اتمیک فایل Setup کامل نشد.',500);}
    }

    private function ensureDir(string $path): void
    {
        if(!is_dir($path)&&!@mkdir($path,0750,true)&&!is_dir($path))throw new SetupException('directory_unavailable','پوشه موردنیاز قابل ایجاد نیست.',500,['path'=>$path]);
        if(!is_writable($path))throw new SetupException('directory_not_writable','پوشه موردنیاز قابل نوشتن نیست.',500,['path'=>$path]);
    }

    private function pathCanBeCreated(string $path): bool
    {
        if(is_dir($path))return is_writable($path);$p=$path;while($p!==dirname($p)&&!file_exists($p))$p=dirname($p);return is_dir($p)&&is_writable($p);
    }

    private function configOutsideDocumentRoot(): bool
    {
        $public=$this->normalizePath($this->componentRoot.DIRECTORY_SEPARATOR.'public');
        $config=$this->normalizePath($this->configPath);
        return !str_starts_with($config,$public.DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string{return rtrim(str_replace(['\\','/'],DIRECTORY_SEPARATOR,$path),DIRECTORY_SEPARATOR);}
    private function stripPort(string $host): string{$host=trim($host);if(preg_match('/^\[([^\]]+)\](?::\d+)?$/D',$host,$m)===1)return $m[1];return preg_replace('/:\d+$/','',$host)??$host;}
    private function check(string $id,bool $ok,string $label,string $current): array{return compact('id','ok','label','current');}
}
