<?php
declare(strict_types=1);

use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,[]);
try{
    $catalog=$core->catalogAdmin();
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot'){
            // The catalog master-data is the primary surface. Guest media and Inventory recipe
            // enrichment are optional and must never blank the whole Catalog workspace when one
            // secondary module is unavailable or mid-upgrade.
            $snapshot=$catalog->snapshot($user);
            $guard=$core->snapshotGuard();
            $media=$guard->capture('catalog','media',[],'تصاویر کاتالوگ فعلاً در دسترس نیستند؛ آیتم‌ها و دسته‌بندی‌ها همچنان قابل مدیریت‌اند.',fn():array=>$core->guestContent()->mediaLibrary($user));
            $recipe=$guard->capture('catalog','inventory_recipe',['inventory_items'=>[],'recipes'=>[]],'اطلاعات دستور مصرف انبار فعلاً در دسترس نیست؛ مدیریت کاتالوگ متوقف نشده است.',fn():array=>$core->inventoryOrders()->recipeEditorSnapshot($user));
            $snapshot['media']=$media['value'];
            $snapshot['inventory_recipe']=$recipe['value'];
            $snapshot['errors']=[];
            if($media['error']!==null)$snapshot['errors']['media']=$media['error'];
            if($recipe['error']!==null)$snapshot['errors']['inventory_recipe']=$recipe['error'];
            WebAction::json(['success'=>true,'snapshot'=>$snapshot]);
        }
        if($action==='export')WebAction::json(['success'=>true,'bundle'=>$catalog->exportBundle($user)]);
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='menu_save')WebAction::json(['success'=>true,'result'=>$catalog->saveMenu($data,$user)]);
    if($action==='category_save')WebAction::json(['success'=>true,'result'=>$catalog->saveCategory($data,$user)]);
    if($action==='item_save')WebAction::json(['success'=>true,'result'=>$catalog->saveItem($data,$user)]);
    if($action==='recipe_save')WebAction::json(['success'=>true,'result'=>$core->inventoryOrders()->saveRecipeEditor((int)($data['menu_item_id']??0),is_array($data['components']??null)?$data['components']:[],$user,(bool)($data['reviewed']??true),(bool)($data['complete']??true))]);
    if($action==='tag_save')WebAction::json(['success'=>true,'result'=>$catalog->saveTag($data,$user)]);
    if($action==='item_state')WebAction::json(['success'=>true,'result'=>$catalog->setItemState((int)($data['id']??0),(string)($data['field']??''),(bool)($data['enabled']??false),$user)]);
    if($action==='item_lifecycle')WebAction::json(['success'=>true,'result'=>$catalog->setItemLifecycleState((int)($data['id']??0),(bool)($data['enabled']??false),$user)]);
    if($action==='item_retire')WebAction::json(['success'=>true,'result'=>$catalog->retireItem((int)($data['id']??0),isset($data['note'])?(string)$data['note']:null,$user)]);
    if($action==='item_restore')WebAction::json(['success'=>true,'result'=>$catalog->restoreItem((int)($data['id']??0),$user)]);
    if($action==='category_retire')WebAction::json(['success'=>true,'result'=>$catalog->retireCategory((int)($data['id']??0),isset($data['note'])?(string)$data['note']:null,$user)]);
    if($action==='category_restore')WebAction::json(['success'=>true,'result'=>$catalog->restoreCategory((int)($data['id']??0),$user)]);
    if($action==='variant_family_save')WebAction::json(['success'=>true,'result'=>$catalog->saveVariantFamily($data,$user)]);
    if($action==='variant_family_remove')WebAction::json(['success'=>true,'result'=>$catalog->removeVariantFamily((int)($data['id']??0),$user)]);
    if($action==='suggestions_save')WebAction::json(['success'=>true,'result'=>$catalog->saveSuggestions((int)($data['source_item_id']??0),is_array($data['suggested_item_ids']??null)?$data['suggested_item_ids']:[],$user)]);
    if($action==='bundle_save')WebAction::json(['success'=>true,'result'=>$catalog->saveBundle($data,$user)]);
    if($action==='bundle_remove')WebAction::json(['success'=>true,'result'=>$catalog->removeBundle((int)($data['bundle_item_id']??0),$user)]);
    if($action==='item_copy')WebAction::json(['success'=>true,'result'=>$catalog->copyItem((int)($data['id']??0),$user)]);
    if($action==='item_bulk')WebAction::json(['success'=>true,'result'=>$catalog->bulkUpdateItems($data,$user)]);
    if($action==='category_reorder')WebAction::json(['success'=>true,'result'=>$catalog->reorderCategoriesForMenu((int)($data['menu_id']??0),(array)($data['ordered_ids']??[]),$user)]);
    if($action==='item_reorder')WebAction::json(['success'=>true,'result'=>$catalog->reorderItemsForCategory((int)($data['category_id']??0),(array)($data['ordered_ids']??[]),$user)]);
    if($action==='media_assign_item')WebAction::json(['success'=>true,'result'=>$core->guestContent()->assignMediaToItem((int)($data['item_id']??0),(string)($data['media_key']??''),$user)]);
    if($action==='media_assign_category')WebAction::json(['success'=>true,'result'=>$core->guestContent()->assignMediaToCategory((int)($data['category_id']??0),(string)($data['media_key']??''),$user)]);
    if($action==='import_preview')WebAction::json(['success'=>true,'preview'=>$catalog->previewImport(is_array($data['bundle']??null)?$data['bundle']:[],$user)]);
    if($action==='import_apply')WebAction::json(['success'=>true,'result'=>$catalog->applyImport(is_array($data['bundle']??null)?$data['bundle']:[],$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    WebAction::unexpected($core,$e,'catalog_admin_failed','عملیات کاتالوگ انجام نشد.');
}
