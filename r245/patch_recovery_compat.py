#!/usr/bin/env python3
from __future__ import annotations

def patch(source: bytes)->bytes:
    s=source.decode('utf-8')
    needle="function ar_e(string $v): string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}"
    assert s.count(needle)==1
    helper='''function ar_actor($access): string {
    if(method_exists($access,'clientActorHint'))return $access->clientActorHint(ar_client_key());
    return 'web-console:'.substr(hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown')),0,16);
}
/** Legacy stable Emergency owner compatibility: fail closed when durable throttling is unavailable. */
function ar_legacy_login($access,string $password,array $config): array {
    $root=(string)($config['app']['storage_dir']??(__DIR__.'/storage'));
    $dir=rtrim($root,'/\\\\').'/authority-recovery';
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return ['ok'=>false,'locked'=>true,'unavailable'=>true];
    $path=$dir.'/login-throttle-v1.json';
    $fp=@fopen($path,'c+');
    if(!is_resource($fp))return ['ok'=>false,'locked'=>true,'unavailable'=>true];
    @chmod($path,0600);
    try {
        if(!flock($fp,LOCK_EX))return ['ok'=>false,'locked'=>true,'unavailable'=>true];
        rewind($fp);$raw=stream_get_contents($fp);
        $state=is_string($raw)&&$raw!==''?json_decode($raw,true):[];
        if(!is_array($state))return ['ok'=>false,'locked'=>true,'unavailable'=>true];
        $now=time();$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $key=hash('sha256','sokna-recovery-login|'.$ip);
        $row=is_array($state[$key]??null)?$state[$key]:[];
        $until=(int)($row['until']??0);
        if($until>$now)return ['ok'=>false,'locked'=>true];
        $failures=array_values(array_filter(array_map('intval',(array)($row['failures']??[])),static fn(int $t):bool=>$t>$now-600));
        // Verify under the lock to prevent simultaneous requests bypassing the attempt limit.
        $ok=$password!==''&&$access->verify($password);
        if($ok)unset($state[$key]);
        else {
            $failures[]=$now;$blocked=count($failures)>=5;
            $state[$key]=['failures'=>$blocked?[]:$failures,'until'=>$blocked?$now+900:0,'updated'=>$now];
        }
        foreach($state as $k=>$v)if(!is_array($v)||max((int)($v['until']??0),(int)($v['updated']??0))<$now-86400)unset($state[$k]);
        if(count($state)>500)$state=array_slice($state,-500,null,true);
        $new=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        rewind($fp);
        if(!ftruncate($fp,0)||fwrite($fp,$new)!==strlen($new)||!fflush($fp))return ['ok'=>false,'locked'=>true,'unavailable'=>true];
        return ['ok'=>$ok,'locked'=>!$ok&&count($failures)>=5];
    } catch(Throwable) {return ['ok'=>false,'locked'=>true,'unavailable'=>true];}
    finally {flock($fp,LOCK_UN);fclose($fp);}
}
'''
    s=s.replace(needle,needle+'\n'+helper)
    old="""    elseif($action==='login'){
        $status=$access->loginStatus(ar_client_key());
        if(!$status['allowed']){$error='ورود موقتاً قفل است.';}
        elseif($access->verify((string)($_POST['password']??''))){$access->recordLoginSuccess(ar_client_key());$_SESSION['authority_recovery_authenticated']=true;session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));$notice='ورود امن انجام شد.';}
        else{$next=$access->recordLoginFailure(ar_client_key());$access->audit('authority_recovery_login_failed',null,$access->clientActorHint(ar_client_key()),[]);$error=$next['allowed']?'رمز اضطراری صحیح نیست.':'ورود موقتاً قفل شد.';}
"""
    new="""    elseif($action==='login'){
        $password=(string)($_POST['password']??'');
        $ok=false;$locked=false;$unavailable=false;
        if(method_exists($access,'loginStatus')&&method_exists($access,'recordLoginSuccess')&&method_exists($access,'recordLoginFailure')){
            $status=$access->loginStatus(ar_client_key());
            $locked=!$status['allowed'];
            if(!$locked){
                $ok=$access->verify($password);
                if($ok)$access->recordLoginSuccess(ar_client_key());
                else{$next=$access->recordLoginFailure(ar_client_key());$locked=!$next['allowed'];}
            }
        }else{
            $result=ar_legacy_login($access,$password,$config);
            $ok=(bool)$result['ok'];$locked=(bool)$result['locked'];$unavailable=(bool)($result['unavailable']??false);
        }
        if($ok){$_SESSION['authority_recovery_authenticated']=true;session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));$notice='ورود امن انجام شد.';}
        else{$access->audit('authority_recovery_login_failed',null,ar_actor($access),[]);$error=$unavailable?'محافظت ورود در دسترس نیست؛ بازیابی متوقف شد.':($locked?'ورود موقتاً قفل است.':'رمز اضطراری صحیح نیست.');}
"""
    assert s.count(old)==1
    s=s.replace(old,new)
    old2='$access->clientActorHint(ar_client_key()),(int)($_POST[\'minimum_next_epoch\']??0)'
    assert s.count(old2)==1
    s=s.replace(old2,'ar_actor($access),(int)($_POST[\'minimum_next_epoch\']??0)')
    return s.encode('utf-8')