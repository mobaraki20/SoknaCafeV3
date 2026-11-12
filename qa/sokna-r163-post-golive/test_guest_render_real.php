<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\GuestLan {
  final class GuestLanRuntimeService {
    public function __construct(private array $state){}
    public function bundle(string $installationId):array{return $installationId==='ci-install'?$this->state:[];}
    public function actionState(array $bundle):array{return ['enabled'=>true,'last_sync_at'=>''];}
  }
}
namespace {
  $file=$argv[1]??'';
  if (!is_file($file)) throw new \RuntimeException('renderer path missing');
  require $file;
  use Sokna\Local\Domain\GuestLan\GuestLanPageRenderer;
  use Sokna\Local\Domain\GuestLan\GuestLanRuntimeService;
  $bundle=[
     'active'=>true,'display_name'=>'سکنا','snapshot'=>[
       'cafe_name'=>'سکنا',
       'tables'=>[['id'=>7,'name'=>'میز 14','token'=>'qr-test','code'=>'T14']],
       'categories'=>[['id'=>1,'name'=>'نوشیدنی','sort_order'=>1]],
       'items'=>[['id'=>10,'category_id'=>1,'category_name'=>'نوشیدنی','name'=>'آمریکانو','description'=>'','price'=>200000,'available'=>true]],
       'presentation'=>['settings'=>['public_about_enabled'=>true]],
       'marketing'=>['events'=>[],'campaigns'=>[]],
       'features'=>['table_sessions_enabled'=>false]
     ],
     'availability'=>['order_acceptance'=>['cafe'=>true,'bar'=>true,'kitchen'=>true],'waiter_enabled_table'=>false],
     'media_manifest'=>[]
  ];
  $renderer=new GuestLanPageRenderer(new GuestLanRuntimeService($bundle),'/public');
  $r=$renderer->render('ci-install',['table'=>'qr-test'],['css'=>'/guest.css','js'=>'/guest.js']);
  $html=(string)$r['body'];
  $checks=['header_table_fa'=>str_contains($html,'class="sg-table-badge">میز ۱۴</span>'),
           'search_anchor'=>substr_count($html,'id="sg-menu-search"')===1,
           'top_return_target'=>str_contains($html,'href="#sg-menu-search"'),
           'menu_item_not_lost'=>str_contains($html,'آمریکانو')];
  $a=$renderer->about('ci-install',['table'=>'qr-test'],['css'=>'/guest.css']);
  $checks['about_table_fa']=str_contains((string)$a['body'],'بازگشت به میز ۱۴');
  foreach($checks as $name=>$pass){if(!$pass){file_put_contents('php://stderr',"FAIL $name\n");exit(2);}echo "PASS $name\n";}
  echo "PASS REAL_GUEST_RENDERER_WITH_DISPOSABLE_FIXTURE\n";
}
