<?php
declare(strict_types=1);
namespace Sokna\Local\Http;
use Sokna\Local\Domain\Printing\PrintException;
use Sokna\Local\Domain\Printing\PrintService;
use Throwable;
final class PrintAgentV4HttpAdapter
{
    public function __construct(private readonly PrintService $printing){}
    public function handle(string $authorization,array $body): array
    {
        try{
            $agent=$this->printing->authenticate($authorization);$action=(string)($body['action']??'');
            $r=match($action){
                'probe'=>$this->printing->probe($agent,(string)($body['agent_version']??'')),
                'heartbeat'=>$this->printing->heartbeat($agent,$body),
                'claim'=>$this->printing->claim($agent,$body),
                'attempt_status'=>$this->printing->attemptStatus($agent,$body),
                'renew'=>$this->printing->renew($agent,$body),
                'accept'=>$this->printing->accept($agent,$body),
                'start'=>$this->printing->start($agent,$body),
                'report'=>$this->printing->report($agent,$body),
                default=>throw new PrintException('unknown_action','عملیات Print API v4 شناخته نشد.',404),
            };
            return ['status'=>200,'body'=>$r];
        }catch(PrintException $e){return ['status'=>$e->httpStatus,'body'=>['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details]];}
        catch(Throwable){return ['status'=>500,'body'=>['success'=>false,'code'=>'internal_error','message'=>'خطای داخلی Print API.']];}
    }
}
