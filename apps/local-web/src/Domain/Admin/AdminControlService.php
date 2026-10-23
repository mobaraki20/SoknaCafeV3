<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Admin;

use PDO;
use PDOException;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\SnapshotGuard;
use Sokna\Local\Domain\PublicEdge\RemoteCredentialService;
use Throwable;

final class AdminControlService
{
    private const MODULES=[
        'inventory'=>['label'=>'انبار','default'=>true],
        'supply'=>['label'=>'تأمین و خرید','default'=>true],
        'staff_consumption'=>['label'=>'مصرف پرسنل','default'=>true],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly SnapshotGuard $snapshotGuard,
        private readonly ?RemoteCredentialService $remoteCredentials=null,
    ){}

    public function snapshot(array $user): array
    {
        $this->assertAdmin($user);
        $errors=[];

        $usersSection=$this->snapshotGuard->capture('admin','users',[],'اطلاعات حساب‌های ورود فعلاً در دسترس نیست.',function() use ($user): array {
            $users=$this->pdo->query("SELECT u.id,u.username,u.display_name,u.role,u.active,u.created_at,u.updated_at,CASE WHEN rc.user_id IS NULL THEN 0 ELSE 1 END remote_credential_configured,CASE WHEN rc.source_mode='local_password' THEN 1 ELSE 0 END remote_login_ready FROM users u LEFT JOIN remote_user_credentials rc ON rc.user_id=u.id ORDER BY u.role DESC,u.active DESC,u.display_name,u.id")->fetchAll(PDO::FETCH_ASSOC);
            $capRows=$this->pdo->query('SELECT user_id,capability,enabled FROM user_capabilities ORDER BY user_id,capability')->fetchAll(PDO::FETCH_ASSOC);
            $areaRows=$this->pdo->query('SELECT user_id,area_key FROM user_preparation_areas ORDER BY user_id,area_key')->fetchAll(PDO::FETCH_ASSOC);
            $capMap=[];$areaMap=[];
            foreach($capRows as $row)if((int)$row['enabled']===1)$capMap[(int)$row['user_id']][]=(string)$row['capability'];
            foreach($areaRows as $row)$areaMap[(int)$row['user_id']][]=(string)$row['area_key'];
            foreach($users as &$row){$id=(int)$row['id'];$row['capabilities']=$capMap[$id]??[];$row['preparation_areas']=$areaMap[$id]??[];$row['is_self']=$id===(int)$user['id'];$row['remote_login_ready']=(int)($row['remote_login_ready']??0);}unset($row);
            return $users;
        });
        if($usersSection['error']!==null)$errors['users']=$usersSection['error'];

        $personnelSection=$this->snapshotGuard->capture('admin','personnel',[],'اطلاعات پرسنل فعلاً در دسترس نیست.',fn(): array => $this->pdo->query(
            'SELECT p.id,p.display_name,p.personnel_code,p.job_title,p.active,p.notes,p.archived_at,p.linked_user_id,u.username linked_username,u.active linked_user_active FROM personnel p LEFT JOIN users u ON u.id=p.linked_user_id ORDER BY p.active DESC,p.display_name,p.id'
        )->fetchAll(PDO::FETCH_ASSOC));
        if($personnelSection['error']!==null)$errors['personnel']=$personnelSection['error'];

        $tablesSection=$this->snapshotGuard->capture('admin','tables',[],'اطلاعات میزها و QR فعلاً در دسترس نیست.',fn(): array => $this->tableRowsForAdmin());
        if($tablesSection['error']!==null)$errors['tables']=$tablesSection['error'];

        $settingsSection=$this->snapshotGuard->capture('admin','settings',[
            'cafe_name'=>'','brand_subtitle'=>'','business_day_cutoff'=>'','waiter_call_enabled'=>false,
        ],'تنظیمات مجموعه فعلاً در دسترس نیست.',fn(): array => [
            'cafe_name'=>$this->setting('cafe.name','سکنا'),
            'brand_subtitle'=>$this->setting('brand.subtitle','سامانه مدیریت کافه'),
            'business_day_cutoff'=>$this->setting('business_day_cutoff','04:00'),
            'waiter_call_enabled'=>$this->settingBool('waiter_call_enabled',true),
        ]);
        if($settingsSection['error']!==null)$errors['settings']=$settingsSection['error'];

        $modulesSection=$this->snapshotGuard->capture('admin','modules',[],'وضعیت امکانات قابل مدیریت فعلاً در دسترس نیست.',function(): array {
            $modules=[];
            $inventoryEnabled=$this->settingBool('module.inventory.enabled',true);
            $supplyEnabled=$this->settingBool('module.supply.enabled',true);
            $staffConsumptionEnabled=$this->settingBool('module.staff_consumption.enabled',true);
            $draftCount=(bool)$this->pdo->query("SELECT 1 FROM inventory_count_sessions WHERE status='draft' LIMIT 1")->fetchColumn();
            $preparingSupply=(bool)$this->pdo->query("SELECT 1 FROM inventory_supply_needs WHERE status='open' AND preparing_quantity_base>0 LIMIT 1")->fetchColumn();
            foreach(self::MODULES as $key=>$meta){
                $enabled=match($key){'inventory'=>$inventoryEnabled,'supply'=>$supplyEnabled,'staff_consumption'=>$staffConsumptionEnabled};
                $blockedReason=null;$canEnable=true;$canDisable=true;
                if($key==='supply'&&!$inventoryEnabled){$canEnable=false;$blockedReason='برای فعال‌کردن تأمین، ابتدا انبار را فعال کن.';}
                if($key==='supply'&&$enabled&&$preparingSupply){$canDisable=false;$blockedReason='تا وقتی خرید در حال انجام است، تأمین را نمی‌شود غیرفعال کرد.';}
                if($key==='inventory'&&$enabled&&$supplyEnabled){$canDisable=false;$blockedReason='تا وقتی تأمین فعال است، انبار را نمی‌شود غیرفعال کرد.';}
                if($key==='inventory'&&$enabled&&!$supplyEnabled&&$draftCount){$canDisable=false;$blockedReason='تا وقتی شمارش باز وجود دارد، انبار را نمی‌شود غیرفعال کرد.';}
                $modules[]=[
                    'key'=>$key,'label'=>$meta['label'],'enabled'=>$enabled,
                    'depends_on'=>$key==='supply'?['inventory']:[],
                    'dependency_satisfied'=>$key!=='supply'||$inventoryEnabled,
                    'can_enable'=>$canEnable,'can_disable'=>$canDisable,'blocked_reason'=>$blockedReason,
                ];
            }
            return $modules;
        });
        if($modulesSection['error']!==null)$errors['modules']=$modulesSection['error'];

        return [
            'users'=>$usersSection['value'],
            'personnel'=>$personnelSection['value'],
            'tables'=>$tablesSection['value'],
            'settings'=>$settingsSection['value'],
            'modules'=>$modulesSection['value'],
            'capability_definitions'=>Capabilities::DEFINITIONS,
            'preparation_areas'=>['kitchen','bar'],
            'errors'=>$errors,
            'notes'=>['qr_public_url'=>'Public QR URL/print composition is completed in G3; Local owns table token lifecycle and rotation.'],
        ];
    }

    public function saveTeamMember(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);
        $personnelId=(int)($data['personnel_id']??0);$userId=(int)($data['user_id']??0);
        $name=$this->text($data['display_name']??'',120);if($name==='')throw new AdminControlException('team_name','نام عضو تیم لازم است.',422);
        $title=$this->nullableText($data['job_title']??null,120);$code=$this->nullableText($data['personnel_code']??null,40);$notes=$this->nullableText($data['notes']??null,500);
        $active=$this->bool($data['active']??true);$systemAccess=$active&&$this->bool($data['system_access']??false);
        $username=$this->text($data['username']??'',80);$password=(string)($data['password']??'');
        $caps=$this->stringList($data['capabilities']??[],Capabilities::DEFINITIONS);$areas=$this->stringList($data['preparation_areas']??[],['kitchen','bar']);
        $this->pdo->beginTransaction();
        try{
            $personnel=null;
            if($personnelId>0){$q=$this->pdo->prepare('SELECT id,linked_user_id FROM personnel WHERE id=? FOR UPDATE');$q->execute([$personnelId]);$personnel=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($personnel))throw new AdminControlException('personnel_not_found','عضو تیم پیدا نشد.',404);if($userId<1)$userId=(int)($personnel['linked_user_id']??0);}
            $existingUser=null;
            if($userId>0){$q=$this->pdo->prepare('SELECT id,username,display_name,role,active FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);$existingUser=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($existingUser))throw new AdminControlException('user_not_found','حساب ورود عضو تیم پیدا نشد.',404);}
            $role=(string)($existingUser['role']??'operator');
            if(!$systemAccess&&$userId===(int)$admin['id'])throw new AdminControlException('self_deactivate','دسترسی حسابی که با آن وارد شده‌ای نباید غیرفعال شود.',409);
            if($role==='admin'){$caps=Capabilities::DEFINITIONS;$areas=['kitchen','bar'];$systemAccess=true;$active=true;}
            if($systemAccess){
                if($username==='')throw new AdminControlException('username_required','برای ورود به سامانه نام کاربری لازم است.',422);
                if($role!=='admin'&&$caps===[])throw new AdminControlException('capability_required','برای عضو دارای دسترسی، حداقل یک دسترسی انتخاب کن.',422);
                if(in_array('preparation',$caps,true)&&$areas===[])throw new AdminControlException('preparation_area_required','برای آماده‌سازی، آشپزخانه، بار یا هر دو را انتخاب کن.',422);
                if($userId===0&&strlen($password)<8)throw new AdminControlException('password_short','برای حساب تازه رمز حداقل ۸ کاراکتری لازم است.',422);
                if($userId>0&&$password!==''&&strlen($password)<8)throw new AdminControlException('password_short','رمز تازه حداقل ۸ کاراکتر باشد.',422);
                if($userId>0){
                    if($password!==''){$q=$this->pdo->prepare('UPDATE users SET username=?,display_name=?,active=1,password_hash=? WHERE id=?');$q->execute([$username,$name,password_hash($password,PASSWORD_DEFAULT),$userId]);$this->remoteCredentials?->syncFromPassword($userId,$password);}
                    else{$q=$this->pdo->prepare('UPDATE users SET username=?,display_name=?,active=1 WHERE id=?');$q->execute([$username,$name,$userId]);}
                }else{$q=$this->pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'operator',1)");$q->execute([$username,password_hash($password,PASSWORD_DEFAULT),$name]);$userId=(int)$this->pdo->lastInsertId();$this->remoteCredentials?->syncFromPassword($userId,$password);}
                $up=$this->pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)');foreach(Capabilities::DEFINITIONS as $cap)$up->execute([$userId,$cap,in_array($cap,$caps,true)?1:0]);
                $this->pdo->prepare('DELETE FROM user_preparation_areas WHERE user_id=?')->execute([$userId]);if(in_array('preparation',$caps,true)){$ins=$this->pdo->prepare('INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,?)');foreach($areas as $area)$ins->execute([$userId,$area]);}
            }elseif($userId>0){
                $this->pdo->prepare('UPDATE users SET display_name=?,active=0 WHERE id=?')->execute([$name,$userId]);
            }
            if($personnelId>0){$q=$this->pdo->prepare('UPDATE personnel SET display_name=?,personnel_code=?,job_title=?,linked_user_id=?,active=?,notes=?,archived_at=? WHERE id=?');$q->execute([$name,$code,$title,$userId>0?$userId:null,$active?1:0,$notes,$active?null:date('Y-m-d H:i:s'),$personnelId]);}
            else{$q=$this->pdo->prepare('INSERT INTO personnel(display_name,personnel_code,job_title,linked_user_id,active,notes,archived_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$name,$code,$title,$userId>0?$userId:null,$active?1:0,$notes,$active?null:date('Y-m-d H:i:s')]);$personnelId=(int)$this->pdo->lastInsertId();}
            $this->audit('team_member.updated','personnel',(string)$personnelId,(int)$admin['id'],['user_id'=>$userId>0?$userId:null,'active'=>$active,'system_access'=>$systemAccess,'capabilities'=>$systemAccess?$caps:[],'job_title'=>$title,'password_changed'=>$password!=='','same_password_local_public'=>true]);
            $this->pdo->commit();return ['personnel_id'=>$personnelId,'user_id'=>$userId>0?$userId:null,'system_access'=>$systemAccess];
        }catch(PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((int)($e->errorInfo[1]??0)===1062)throw new AdminControlException('team_duplicate','نام کاربری، کد پرسنلی یا حساب متصل قبلاً استفاده شده است.',409);throw $e;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function tableRowsForAdmin(): array
    {
        $sql="SELECT t.id,t.name,t.table_number,t.zone_label,t.code,t.access_token,t.previous_access_token,t.qr_rotated_at,t.qr_rotated_by_user_id,qr_user.display_name qr_rotated_by_name,t.active,t.sort_order,
            s.id session_id,s.status session_status,d.id draft_id,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) accounted_order_count,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count,
            COALESCE((SELECT COUNT(*) FROM waiter_calls w WHERE w.table_id=t.id AND w.status IN('new','accepted')),0) active_waiter_count
            FROM cafe_tables t
            LEFT JOIN users qr_user ON qr_user.id=t.qr_rotated_by_user_id
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') AND s2.live_table_guard IS NOT NULL ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            LEFT JOIN table_drafts d ON d.table_id=t.id AND d.state='active'
            ORDER BY COALESCE(t.zone_label,''),t.table_number,t.id";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function(array $row): array {
            $sessionId=(int)($row['session_id']??0);$draftId=(int)($row['draft_id']??0);$pending=(int)($row['pending_order_count']??0);$accounted=(int)($row['accounted_order_count']??0);$waiter=(int)($row['active_waiter_count']??0);
            $pendingOnly=$sessionId>0&&$pending>0&&$accounted===0&&$draftId===0;
            $row['has_live_session']=$sessionId>0?1:0;$row['has_draft']=$draftId>0?1:0;
            $row['occupancy_kind']=$pendingOnly?'pending_guest':($sessionId>0?'account':($draftId>0?'draft':($waiter>0?'waiter':'free')));
            return $row;
        },$rows);
    }

    public function saveUser(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$id=(int)($data['id']??0);
        $username=$this->text($data['username']??'',80);$display=$this->text($data['display_name']??'',120);$password=(string)($data['password']??'');$active=$this->bool($data['active']??true);
        if($username===''||$display==='')throw new AdminControlException('user_required','نام و اطلاعات ورود را کامل کن.',422);
        $caps=$this->stringList($data['capabilities']??[],Capabilities::DEFINITIONS);
        $areas=$this->stringList($data['preparation_areas']??[],['kitchen','bar']);
        $this->pdo->beginTransaction();
        try{
            $existing=null;
            if($id>0){$st=$this->pdo->prepare('SELECT id,username,display_name,role,active FROM users WHERE id=? FOR UPDATE');$st->execute([$id]);$existing=$st->fetch(PDO::FETCH_ASSOC);if(!is_array($existing))throw new AdminControlException('user_not_found','حساب پیدا نشد.',404);}
            $role=(string)($existing['role']??'operator');
            if($id===(int)$admin['id']&&!$active)throw new AdminControlException('self_deactivate','حسابی که با آن وارد شده‌ای نباید غیرفعال شود.',409);
            if($role==='admin'){$caps=Capabilities::DEFINITIONS;$areas=['kitchen','bar'];$active=true;}
            if($active&&$role!=='admin'&&$caps===[])throw new AdminControlException('capability_required','برای حساب فعال حداقل یک دسترسی انتخاب کن.',422);
            if(in_array('preparation',$caps,true)&&$areas===[])throw new AdminControlException('preparation_area_required','برای آماده‌سازی، آشپزخانه، بار یا هر دو را انتخاب کن.',422);
            if($id===0&&strlen($password)<8)throw new AdminControlException('password_short','برای حساب تازه رمز حداقل ۸ کاراکتری لازم است.',422);
            if($id>0&&$password!==''&&strlen($password)<8)throw new AdminControlException('password_short','رمز تازه حداقل ۸ کاراکتر باشد.',422);
            if($id>0){
                if($password!==''){$q=$this->pdo->prepare('UPDATE users SET username=?,display_name=?,active=?,password_hash=? WHERE id=?');$q->execute([$username,$display,$active?1:0,password_hash($password,PASSWORD_DEFAULT),$id]);$this->remoteCredentials?->syncFromPassword($id,$password);}
                else{$q=$this->pdo->prepare('UPDATE users SET username=?,display_name=?,active=? WHERE id=?');$q->execute([$username,$display,$active?1:0,$id]);}
            }else{$q=$this->pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'operator',?)");$q->execute([$username,password_hash($password,PASSWORD_DEFAULT),$display,$active?1:0]);$id=(int)$this->pdo->lastInsertId();$this->remoteCredentials?->syncFromPassword($id,$password);}
            $up=$this->pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)');
            foreach(Capabilities::DEFINITIONS as $cap)$up->execute([$id,$cap,in_array($cap,$caps,true)?1:0]);
            $this->pdo->prepare('DELETE FROM user_preparation_areas WHERE user_id=?')->execute([$id]);
            if(in_array('preparation',$caps,true)){$ins=$this->pdo->prepare('INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,?)');foreach($areas as $area)$ins->execute([$id,$area]);}
            $this->audit('user.access_updated','user',(string)$id,(int)$admin['id'],['created'=>$existing===null,'active'=>$active,'capabilities'=>$caps,'preparation_areas'=>$areas,'password_changed'=>$password!=='','same_password_local_public'=>true]);
            $this->pdo->commit();return ['id'=>$id];
        }catch(PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((int)($e->errorInfo[1]??0)===1062)throw new AdminControlException('username_exists','نام کاربری تکراری است.',409);throw $e;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function savePersonnel(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$id=(int)($data['id']??0);$name=$this->text($data['display_name']??'',120);if($name==='')throw new AdminControlException('personnel_name','نام پرسنل لازم است.',422);
        $code=$this->nullableText($data['personnel_code']??null,40);$title=$this->nullableText($data['job_title']??null,120);$notes=$this->nullableText($data['notes']??null,500);$userId=(int)($data['linked_user_id']??0);$userId=$userId>0?$userId:null;$active=$this->bool($data['active']??true);
        $this->pdo->beginTransaction();
        try{
            if($userId!==null){$q=$this->pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$userId]);if(!$q->fetchColumn())throw new AdminControlException('linked_user_missing','حساب کاربری انتخاب‌شده پیدا نشد.',422);}
            if($id>0){$q=$this->pdo->prepare('SELECT id FROM personnel WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new AdminControlException('personnel_not_found','پرسنل پیدا نشد.',404);$q=$this->pdo->prepare('UPDATE personnel SET display_name=?,personnel_code=?,job_title=?,linked_user_id=?,active=?,notes=?,archived_at=? WHERE id=?');$q->execute([$name,$code,$title,$userId,$active?1:0,$notes,$active?null:date('Y-m-d H:i:s'),$id]);}
            else{$q=$this->pdo->prepare('INSERT INTO personnel(display_name,personnel_code,job_title,linked_user_id,active,notes,archived_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$name,$code,$title,$userId,$active?1:0,$notes,$active?null:date('Y-m-d H:i:s')]);$id=(int)$this->pdo->lastInsertId();}
            $this->audit('personnel.updated','personnel',(string)$id,(int)$admin['id'],['active'=>$active,'linked_user_id'=>$userId,'job_title'=>$title]);$this->pdo->commit();return ['id'=>$id];
        }catch(PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((int)($e->errorInfo[1]??0)===1062)throw new AdminControlException('personnel_duplicate','کد پرسنلی یا حساب متصل قبلاً استفاده شده است.',409);throw $e;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function saveSettings(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$name=$this->text($data['cafe_name']??'',120);$subtitle=$this->text($data['brand_subtitle']??'',120);$cutoff=trim((string)($data['business_day_cutoff']??''));if($name==='')throw new AdminControlException('cafe_name','نام مجموعه لازم است.',422);if($subtitle==='')$subtitle='سامانه مدیریت کافه';if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$cutoff))throw new AdminControlException('cutoff','ساعت شروع روز کاری معتبر نیست.',422);$waiter=$this->bool($data['waiter_call_enabled']??true);
        $this->pdo->beginTransaction();try{$up=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach(['cafe.name'=>$name,'brand.subtitle'=>$subtitle,'business_day_cutoff'=>$cutoff,'waiter_call_enabled'=>$waiter?'1':'0'] as $k=>$v)$up->execute([$k,$v]);$this->audit('settings.local_updated','setting','local',(int)$admin['id'],['cafe_name'=>$name,'brand_subtitle'=>$subtitle,'business_day_cutoff'=>$cutoff,'waiter_call_enabled'=>$waiter]);$this->pdo->commit();return ['saved'=>true];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function setModule(string $key,bool $enabled,array $actor): array
    {
        $admin=$this->assertAdmin($actor);if(!isset(self::MODULES[$key]))throw new AdminControlException('module_owner','این قابلیت از owner اختصاصی خودش مدیریت می‌شود.',422);
        $this->pdo->beginTransaction();try{
            if($key==='supply'&&$enabled&&!$this->settingBoolTx('module.inventory.enabled',true))throw new AdminControlException('dependency','برای فعال‌کردن تأمین، انبار باید فعال باشد.',409);
            if($key==='supply'&&!$enabled){$q=$this->pdo->query("SELECT 1 FROM inventory_supply_needs WHERE status='open' AND preparing_quantity_base>0 LIMIT 1 FOR UPDATE");if($q->fetchColumn())throw new AdminControlException('supply_in_progress','تا وقتی خرید در حال انجام است، تأمین را نمی‌شود غیرفعال کرد.',409);}
            if($key==='inventory'&&!$enabled&&$this->settingBoolTx('module.supply.enabled',true))throw new AdminControlException('dependent','تا وقتی تأمین فعال است، انبار را نمی‌شود غیرفعال کرد.',409);
            if($key==='inventory'&&!$enabled){$q=$this->pdo->query("SELECT 1 FROM inventory_count_sessions WHERE status='draft' LIMIT 1 FOR UPDATE");if($q->fetchColumn())throw new AdminControlException('inventory_count_open','تا وقتی شمارش باز وجود دارد، انبار را نمی‌شود غیرفعال کرد.',409);}
            $current=$this->settingBoolTx('module.'.$key.'.enabled',(bool)self::MODULES[$key]['default']);if($current!==$enabled){$q=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['module.'.$key.'.enabled',$enabled?'1':'0']);$this->audit('module.state_changed','module',$key,(int)$admin['id'],['enabled'=>$enabled]);}
            $this->pdo->commit();return ['key'=>$key,'enabled'=>$enabled,'changed'=>$current!==$enabled];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function saveTable(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$id=(int)($data['id']??0);$name=$this->text($data['name']??'',100);$number=(int)($data['table_number']??0);$zone=$this->nullableText($data['zone_label']??null,80);$active=$this->bool($data['active']??true);if($name==='')throw new AdminControlException('table_name_required','نام میز لازم است.',422,['fields'=>['name'=>'نام میز را وارد کنید.']]);if($number<1||$number>9999)throw new AdminControlException('table_number_invalid','شماره میز معتبر نیست.',422,['fields'=>['table_number'=>'شماره میز باید بین ۱ تا ۹۹۹۹ باشد.']]);
        $this->pdo->beginTransaction();try{
            if($id>0){$q=$this->pdo->prepare('SELECT id,active FROM cafe_tables WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($old))throw new AdminControlException('table_missing','میز پیدا نشد.',404);if(!$active&&(int)$old['active']===1&&$this->hasLiveSessionTx($id))throw new AdminControlException('table_live','این میز حساب باز دارد و فعلاً غیرفعال نمی‌شود.',409);$q=$this->pdo->prepare('UPDATE cafe_tables SET name=?,table_number=?,zone_label=?,sort_order=?,active=? WHERE id=?');$q->execute([$name,$number,$zone,$number,$active?1:0,$id]);}
            else{$q=$this->pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,zone_label,active,sort_order) VALUES(?,?,?,?,?,?,?)');$q->execute([$name,$number,$this->tableCode($number),$this->tableToken(),$zone,$active?1:0,$number]);$id=(int)$this->pdo->lastInsertId();}
            $this->audit('table.admin_updated','cafe_table',(string)$id,(int)$admin['id'],['name'=>$name,'table_number'=>$number,'zone_label'=>$zone,'active'=>$active]);$this->pdo->commit();return ['id'=>$id];
        }catch(PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((int)($e->errorInfo[1]??0)===1062)throw new AdminControlException('table_duplicate','شماره میز تکراری است.',409,['fields'=>['table_number'=>'این شماره میز قبلاً ثبت شده است.']]);throw $e;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function bulkCreateTables(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$from=(int)($data['from_number']??0);$to=(int)($data['to_number']??0);$prefix=$this->text($data['name_prefix']??'میز',60)?:'میز';$zone=$this->nullableText($data['zone_label']??null,80);if($from<1||$to<$from||$to-$from>99)throw new AdminControlException('table_range','بازه ساخت میز معتبر نیست یا بیش از ۱۰۰ میز است.',422);
        $this->pdo->beginTransaction();try{$existing=array_map('intval',$this->pdo->query('SELECT table_number FROM cafe_tables FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));$seen=array_fill_keys($existing,true);$ins=$this->pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,zone_label,active,sort_order) VALUES(?,?,?,?,?,1,?)');$created=[];$skipped=[];for($n=$from;$n<=$to;$n++){if(isset($seen[$n])){$skipped[]=$n;continue;}$ins->execute([$prefix.' '.$n,$n,$this->tableCode($n),$this->tableToken(),$zone,$n]);$created[]=$n;}$this->audit('table.bulk_created','cafe_table','bulk',(int)$admin['id'],['from'=>$from,'to'=>$to,'created'=>$created,'skipped'=>$skipped,'zone_label'=>$zone]);$this->pdo->commit();return ['created'=>$created,'skipped'=>$skipped];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function rotateQr(int $tableId,array $actor,bool $restore=false): array
    {
        $admin=$this->assertAdmin($actor);if($tableId<1)throw new AdminControlException('table_invalid','میز معتبر نیست.',422);$this->pdo->beginTransaction();try{$q=$this->pdo->prepare('SELECT id,name,access_token,previous_access_token FROM cafe_tables WHERE id=? FOR UPDATE');$q->execute([$tableId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new AdminControlException('table_missing','میز پیدا نشد.',404);if($restore){$next=trim((string)($row['previous_access_token']??''));if($next==='')throw new AdminControlException('qr_no_previous','QR قبلی برای بازگردانی وجود ندارد.',409);$previous=(string)$row['access_token'];}else{$next=$this->tableToken();$previous=(string)$row['access_token'];}$u=$this->pdo->prepare('UPDATE cafe_tables SET access_token=?,previous_access_token=?,qr_rotated_at=NOW(),qr_rotated_by_user_id=? WHERE id=?');$u->execute([$next,$previous,(int)$admin['id'],$tableId]);$this->audit($restore?'table.qr_restored':'table.qr_rotated','cafe_table',(string)$tableId,(int)$admin['id'],['table_name'=>(string)$row['name']]);$this->pdo->commit();return ['table_id'=>$tableId,'access_token'=>$next,'previous_available'=>true];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function assertAdmin(array $user): array
    {
        $id=(int)($user['id']??0);if($id<1)throw new AdminControlException('forbidden','حساب کاربری معتبر نیست.',403);$fresh=$this->identity->findActiveById($id);if($fresh===null||(string)($fresh['role']??'')!=='admin')throw new AdminControlException('forbidden','این بخش فقط برای مدیر فعال است.',403);return $fresh;
    }
    private function hasLiveSessionTx(int $tableId): bool{$q=$this->pdo->prepare("SELECT 1 FROM table_sessions WHERE table_id=? AND status IN('active','pending') LIMIT 1 FOR UPDATE");$q->execute([$tableId]);return (bool)$q->fetchColumn();}
    private function setting(string $key,string $default): string{$q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');$q->execute([$key]);$v=$q->fetchColumn();return $v===false||$v===null?$default:(string)$v;}
    private function settingBool(string $key,bool $default): bool{return $this->toBool($this->setting($key,$default?'1':'0'));}
    private function settingBoolTx(string $key,bool $default): bool{$q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1 FOR UPDATE');$q->execute([$key]);$v=$q->fetchColumn();return $v===false||$v===null?$default:$this->toBool((string)$v);}
    private function bool(mixed $value): bool{return is_bool($value)?$value:in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);}
    private function toBool(string $value): bool{return in_array(strtolower(trim($value)),['1','true','yes','on'],true);}
    private function text(mixed $value,int $max): string{return mb_substr(trim((string)$value),0,$max,'UTF-8');}
    private function nullableText(mixed $value,int $max): ?string{$v=$this->text($value,$max);return $v===''?null:$v;}
    /** @param list<string> $allowed @return list<string> */ private function stringList(mixed $value,array $allowed): array{$items=is_array($value)?$value:[];$items=array_values(array_unique(array_map('strval',$items)));return array_values(array_intersect($allowed,$items));}
    private function tableToken(): string{return bin2hex(random_bytes(24));}
    private function tableCode(int $number): string{return 'T'.$number.'-'.substr(bin2hex(random_bytes(5)),0,10);}
    private function audit(string $action,string $entity,string $entityId,int $actorId,array $details): void{$q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)');$q->execute([$actorId,$action,$entity,$entityId,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
}
