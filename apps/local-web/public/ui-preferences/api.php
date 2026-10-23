<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireUser($core);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')WebAction::json(['success'=>true,'preferences'=>$core->uiPreferences()->forUser((int)$user['id'])]);
    $data=WebAction::requireMutation();$patch=is_array($data['preferences']??null)?$data['preferences']:[];
    WebAction::json(['success'=>true,'preferences'=>$core->uiPreferences()->savePatch((int)$user['id'],$patch)]);
}catch(Throwable $e){WebAction::unexpected($core,$e,'ui_preferences.save','ذخیره تنظیمات نمایش انجام نشد.');}
