<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

/**
 * Compatibility boundary for shared Local/Public back-office Staff workspaces.
 *
 * The browser executes the exact Local workspace templates/controllers. This
 * adapter only projects Public read-models into the Local HTTP response shape.
 * Unsupported mutations fail closed instead of creating a second authority.
 */
final class PublicStaffWorkspaceHttpAdapter
{
    public function __construct(private readonly Bootstrap $core) {}

    public function handle(string $sessionToken,string $workspace,string $method,array $query,string $rawBody): array
    {
        $session=$this->core->publicSessions()->resolve($sessionToken);
        if(!is_array($session))return $this->response(401,['success'=>false,'code'=>'unauthorized','message'=>'نشست راه‌دور معتبر نیست.']);
        $workspace=trim(strtolower($workspace));$method=strtoupper(trim($method));
        return match($workspace){
            'operations'=>$this->operations($session,$method,$query,$rawBody),
            'subscribers'=>$this->subscribers($session,$method,$query,$rawBody),
            'reports'=>$this->reports($session,$method,$query),
            'notifications'=>$this->notifications($session,$method,$rawBody),
            default=>$this->response(404,['success'=>false,'code'=>'unknown_workspace','message'=>'فضای کاری پیدا نشد.']),
        };
    }

    private function operations(array $session,string $method,array $query,string $rawBody): array
    {
        if($method!=='GET')return $this->readOnly();
        $model=$this->model($session,'operations_workspace');if(($model['ok']??false)!==true)return $model['response'];
        $snapshot=(array)$model['payload'];$action=(string)($query['action']??'snapshot');
        if($action==='inventory_movements')return $this->response(200,['success'=>true,'movements'=>(array)($snapshot['inventory']['recent_movements']??[]),'staff_meta'=>$model['meta']]);
        if($action!=='snapshot')return $this->response(422,['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.']);
        return $this->response(200,['success'=>true,'snapshot'=>$snapshot,'staff_meta'=>$model['meta']]);
    }

    private function subscribers(array $session,string $method,array $query,string $rawBody): array
    {
        if($method!=='GET')return $this->readOnly();
        $model=$this->model($session,'subscribers');if(($model['ok']??false)!==true)return $model['response'];
        $payload=(array)$model['payload'];$rows=array_values(array_filter((array)($payload['rows']??[]),'is_array'));$action=(string)($query['action']??'search');
        if($action==='search'||$action==='settlement_search'){
            $q=$this->fold((string)($query['q']??''));$status=(string)($query['status']??'all');$debt=((string)($query['debt']??'0'))==='1';
            $out=[];foreach($rows as $row){$active=(int)($row['active']??0)===1;$balance=(int)($row['balance']??0);if($status==='active'&&!$active)continue;if($status==='inactive'&&$active)continue;if($debt&&$balance<=0)continue;if($q!==''&&!str_contains($this->fold((string)($row['name']??'').' '.(string)($row['mobile']??'')),$q))continue;$out[]=$this->subscriberRow($row);if($action==='settlement_search'&&count($out)>=20)break;}
            return $this->response(200,['success'=>true,'rows'=>$out,'staff_meta'=>$model['meta']]);
        }
        if($action==='detail'){
            $id=(int)($query['id']??0);$found=null;foreach($rows as $row)if((int)($row['id']??0)===$id){$found=$row;break;}if(!is_array($found))return $this->response(404,['success'=>false,'code'=>'subscriber_not_found','message'=>'مشتری پیدا نشد.']);
            $ledgerMap=is_array($payload['ledger']??null)?$payload['ledger']:[];$ledger=array_values(array_filter((array)($ledgerMap[(string)$id]??[]),'is_array'));
            // The Local read projection already carries finalized invoices with line items.
            // Never fetch another customer's invoices or fabricate a complete historical list.
            $invoiceMap=is_array($payload['invoices']??null)?$payload['invoices']:[];
            $invoices=array_values(array_filter((array)($invoiceMap[(string)$id]??[]),'is_array'));
            $coverageMap=is_array($payload['coverage']??null)?$payload['coverage']:[];
            $coverage=is_array($coverageMap[(string)$id]??null)?$coverageMap[(string)$id]:['available'=>false,'invoices_complete'=>false,'ledger_complete'=>false];
            return $this->response(200,['success'=>true,'detail'=>[
                'subscriber'=>$this->subscriberRow($found),'ledger'=>$ledger,'invoices'=>$invoices,
                'coverage'=>$coverage,'as_of'=>(string)($payload['as_of']??''),'projection_stale'=>(bool)($model['meta']['stale']??true)
            ],'staff_meta'=>$model['meta']]);
        }
        return $this->response(422,['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.']);
    }

    private function reports(array $session,string $method,array $query): array
    {
        if($method!=='GET')return $this->readOnly();$model=$this->model($session,'reports');if(($model['ok']??false)!==true)return $model['response'];$action=(string)($query['action']??'report');
        if($action==='activity'){
            // Do not fabricate an empty audit history when Local has not projected one.
            // The regular financial report remains available independently of activity.
            $activity=$model['payload']['activity']??null;
            if($activity===null)return $this->response(404,['success'=>false,'code'=>'activity_model_unavailable','message'=>'گزارش فعالیت هنوز از Local به Public منتقل نشده است.','staff_meta'=>$model['meta']]);
            if(!is_array($activity)||!is_array($activity['summary']??null)||!is_array($activity['rows']??null)||!is_array($activity['pagination']??null))
                return $this->response(502,['success'=>false,'code'=>'activity_model_invalid','message'=>'اطلاعات گزارش فعالیت معتبر نیست.','staff_meta'=>$model['meta']]);
            return $this->response(200,['success'=>true,'activity'=>$activity,'staff_meta'=>$model['meta']]);
        }
        if($action!=='report')return $this->response(422,['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.']);
        $payload=$model['payload'];
        $requestedFrom=trim((string)($query['from']??''));$requestedTo=trim((string)($query['to']??''));
        $baseRange=is_array($payload['range']??null)?$payload['range']:[];
        if($requestedFrom===''&&$requestedTo==='')return $this->response(200,['success'=>true,'report'=>$payload,'staff_meta'=>$model['meta']]);
        $from=$requestedFrom!==''?$requestedFrom:(string)($baseRange['from']??'');
        $to=$requestedTo!==''?$requestedTo:(string)($baseRange['to']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to)||$from>$to)
            return $this->response(422,['success'=>false,'code'=>'invalid_report_range','message'=>'بازهٔ تاریخ را بررسی کنید؛ تاریخ آغاز نباید پس از پایان باشد.']);
        $derived=PublicReportArchiveRange::aggregate($payload,$from,$to);
        if($derived!==null)return $this->response(200,['success'=>true,'report'=>$derived,'staff_meta'=>$model['meta']]);
        if($from===($baseRange['from']??null)&&$to===($baseRange['to']??null))
            return $this->response(200,['success'=>true,'report'=>$payload,'staff_meta'=>$model['meta']]);
        return $this->response(409,['success'=>false,'code'=>'report_range_not_synced','message'=>'این بازه هنوز در آرشیو گزارش‌های همگام‌شده موجود نیست. بازهٔ دیگری انتخاب کنید یا همگام‌سازی را بررسی کنید.','staff_meta'=>$model['meta']]);
    }

    private function notifications(array $session,string $method,string $rawBody): array
    {
        if($method!=='GET')return $this->readOnly();$model=$this->model($session,'notifications');if(($model['ok']??false)!==true)return $model['response'];$items=array_values(array_filter((array)($model['payload']['items']??[]),'is_array'));$unread=0;foreach($items as &$row){$row['target_url']=$this->publicNotificationTarget((string)($row['target_url']??''));if(trim((string)($row['read_at']??''))==='')$unread++;}unset($row);
        return $this->response(200,['success'=>true,'snapshot'=>['items'=>$items,'unread'=>$unread],'staff_meta'=>$model['meta']]);
    }

    /** @return array{ok:bool,payload?:array,meta?:array,response?:array} */
    private function model(array $session,string $key): array
    {
        $result=$this->core->remoteReadModels()->read($session,$key);
        return $this->modelResult($key,$result);
    }

    /** Interprets one remote model response without depending on network state. */
    private function modelResult(string $key,array $result): array
    {
        $status=(int)($result['status']??500);$body=is_array($result['body']??null)?$result['body']:[];
        // Preserve a server failure as a server failure; a missing projection is not a 500.
        if($status!==200||($body['ok']??false)!==true){
            $failureStatus=$status===200?502:$status;
            $code=(string)($body['error']??($status===200?'invalid_model_response':'model_unavailable'));
            return ['ok'=>false,'response'=>$this->modelFailureResponse($failureStatus,$code,(string)($body['correlation_id']??''))];
        }
        // A 200 without the projected report must never be accepted as a healthy empty report.
        if(!is_array($body['payload']??null))return ['ok'=>false,'response'=>$this->modelFailureResponse(502,'invalid_model_response')];
        return ['ok'=>true,'payload'=>$body['payload'],'meta'=>['model_key'=>$key,'stale'=>(bool)($body['stale']??true),'freshness'=>is_array($body['freshness']??null)?$body['freshness']:null,'last_sync_at'=>(string)($body['last_sync_at']??''),'generated_at'=>(string)($body['generated_at']??''),'connectivity'=>is_array($body['connectivity']??null)?$body['connectivity']:null]];
    }

    /** Separate unavailable/stale read models from genuine server errors. No internal exception details leak to Staff UI. */
    private function modelFailureResponse(int $status,string $code,string $correlationId=''): array
    {
        if($status>=500){
            // The upstream SafeErrors correlation ID is shareable with support; never reflect untrusted text.
            $supportId=preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D',$correlationId)===1?$correlationId:'';
            $message='دریافت اطلاعات از سرویس Public با خطا روبه‌رو شد. دوباره تلاش کنید؛ اگر ادامه داشت، به پشتیبانی اطلاع دهید.';
            if($supportId!=='')$message.=' کد پیگیری: '.$supportId;
            $body=['success'=>false,'code'=>'remote_model_service_error','message'=>$message];
            if($supportId!=='')$body['support_id']=$supportId;
            return $this->response($status,$body);
        }
        if($status===401)return $this->response(401,['success'=>false,'code'=>'unauthorized','message'=>'نشست راه‌دور معتبر نیست. دوباره وارد شوید.']);
        if($status===403)return $this->response(403,['success'=>false,'code'=>'forbidden','message'=>'دسترسی این بخش برای حساب شما فعال نیست.']);
        if($status===404&&$code==='model_unavailable')return $this->response(404,['success'=>false,'code'=>'model_unavailable','message'=>'اطلاعات این بخش هنوز از Local همگام نشده است.']);
        return $this->response($status,['success'=>false,'code'=>$code,'message'=>'دریافت اطلاعات این بخش انجام نشد.']);
    }


    private function publicNotificationTarget(string $path): string
    {
        $path=trim($path);if($path==='')return '';
        if($path==='/preparation'||$path==='/waiter'||$path==='/staff/preparation')return '/staff/preparation/';
        foreach(['/preparation/','/waiter/'] as $legacy)if(str_starts_with($path,$legacy))return '/staff/preparation/'.substr($path,strlen($legacy));
        foreach(['/operator/'=>'/staff/operator/','/reports/'=>'/staff/reports/','/operations/'=>'/staff/operations/','/notifications/'=>'/staff/notifications/'] as $from=>$to)if(str_starts_with($path,$from))return $to.substr($path,strlen($from));
        return $path;
    }

    private function subscriberRow(array $row): array
    {
        return ['id'=>(int)($row['id']??0),'name'=>(string)($row['name']??''),'mobile'=>(string)($row['mobile']??''),'active'=>(int)($row['active']??0),'balance'=>(int)($row['balance']??0),'last_activity'=>(string)($row['last_activity']??'')];
    }

    private function fold(string $value): string
    {
        $value=strtr(trim($value),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','ي'=>'ی','ك'=>'ک']);
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    private function readOnly(): array{return $this->response(409,['success'=>false,'code'=>'remote_read_only','message'=>'این عملیات در نمای راه‌دور فقط از مسیر امن همگام‌سازی Local قابل انجام است.']);}
    private function response(int $status,array $body): array{return ['status'=>$status,'body'=>$body];}
}
