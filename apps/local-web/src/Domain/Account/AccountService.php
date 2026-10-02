<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Account;

use PDO;
use Throwable;

final class AccountService
{
    public function __construct(private readonly PDO $pdo){}

    /** @return array{id:int,username:string,display_name:string,role:string,updated_at:string} */
    public function snapshot(array $actor): array
    {
        $id=(int)($actor['id']??0);
        if($id<1)throw new AccountException('account_missing','حساب کاربری پیدا نشد.',404);
        $q=$this->pdo->prepare('SELECT id,username,display_name,role,updated_at FROM users WHERE id=? AND active=1 LIMIT 1');
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new AccountException('account_missing','حساب کاربری پیدا نشد.',404);
        return ['id'=>(int)$row['id'],'username'=>(string)$row['username'],'display_name'=>(string)$row['display_name'],'role'=>(string)$row['role'],'updated_at'=>(string)$row['updated_at']];
    }

    public function saveProfile(array $data,array $actor): array
    {
        $id=(int)($actor['id']??0);$display=$this->text($data['display_name']??'',120);
        if($display==='')throw new AccountException('display_name_required','نام نمایشی را وارد کن.',422);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT id,display_name FROM users WHERE id=? AND active=1 FOR UPDATE');$q->execute([$id]);$before=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($before))throw new AccountException('account_missing','حساب کاربری پیدا نشد.',404);
            $this->pdo->prepare('UPDATE users SET display_name=? WHERE id=?')->execute([$display,$id]);
            $this->audit('account.profile_updated',$id,$display,['previous_display_name'=>(string)$before['display_name']]);
            $this->pdo->commit();
            return ['saved'=>true,'display_name'=>$display];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function changePassword(array $data,array $actor): array
    {
        $id=(int)($actor['id']??0);$current=(string)($data['current_password']??'');$next=(string)($data['new_password']??'');$confirm=(string)($data['confirm_password']??'');
        if($current==='')throw new AccountException('current_password_required','رمز فعلی را وارد کن.',422);
        if(strlen($next)<8)throw new AccountException('password_short','رمز تازه حداقل ۸ کاراکتر باشد.',422);
        if(!hash_equals($next,$confirm))throw new AccountException('password_confirmation','تکرار رمز تازه با رمز واردشده یکسان نیست.',422);
        if(hash_equals($current,$next))throw new AccountException('password_unchanged','رمز تازه باید با رمز فعلی متفاوت باشد.',422);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT password_hash,display_name FROM users WHERE id=? AND active=1 FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new AccountException('account_missing','حساب کاربری پیدا نشد.',404);
            if(!password_verify($current,(string)$row['password_hash']))throw new AccountException('current_password_invalid','رمز فعلی درست نیست.',422);
            $this->pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($next,PASSWORD_DEFAULT),$id]);
            $this->audit('account.password_changed',$id,(string)$row['display_name'],[]);
            $this->pdo->commit();
            return ['saved'=>true];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function text(mixed $value,int $max): string
    {
        $value=trim((string)$value);if(function_exists('mb_substr'))return mb_substr($value,0,$max,'UTF-8');return substr($value,0,$max);
    }

    private function audit(string $action,int $actorId,string $display,array $details): void
    {
        $q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)');
        $q->execute([$actorId,$display,$action,'user',(string)$actorId,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }
}
