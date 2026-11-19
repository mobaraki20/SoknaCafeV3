<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R295_QA_ONLY')!=='disposable')throw new RuntimeException('QA_ONLY');
$root=rtrim((string)getenv('R295_SOURCE_ROOT'),'/');
require $root.'/src/Domain/Printing/PrintException.php';
require $root.'/src/Domain/Printing/PrintTemplatePackageService.php';
$pdo=new PDO('mysql:host=127.0.0.1;dbname=sokna_r295_print;charset=utf8mb4','root',(string)getenv('R295_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE print_template_packages (id INT PRIMARY KEY,template_key VARCHAR(80),display_name VARCHAR(200),version VARCHAR(30),document_kind VARCHAR(50),template_json JSON,package_json JSON)');
$pdo->exec('CREATE TABLE print_agents (id INT PRIMARY KEY,name VARCHAR(80),active TINYINT,retired_at DATETIME NULL,bridge_protocol_version INT,bridge_port INT,bridge_pairing_id VARCHAR(120),bridge_origin VARCHAR(200),last_heartbeat_at DATETIME NULL)');
$template=['base_font_size'=>23,'footer'=>'متن قبلی','show_prices'=>true];
$old=['format'=>'sokna-print-template-package-v1','template_key'=>'test-template','name'=>'قالب مشتری','version'=>'1.0.0','document_kind'=>'customer','description'=>'','template'=>$template];
$q=$pdo->prepare('INSERT INTO print_template_packages VALUES(1,?,?,?,?,?,?)');$q->execute(['test-template','قالب مشتری','1.0.0','customer',json_encode($template,JSON_UNESCAPED_UNICODE),json_encode($old,JSON_UNESCAPED_UNICODE)]);
$pdo->exec("INSERT INTO print_agents VALUES(1,'qa-bridge',1,NULL,1,23548,'qa-pair-123','http://localhost:8080','2026-11-19 08:00:00')");
$service=new \Sokna\Local\Domain\Printing\PrintTemplatePackageService($pdo);
$actor=['role'=>'admin','id'=>1];
$before=$pdo->query('SELECT template_json FROM print_template_packages WHERE id=1')->fetchColumn();
$normal=$service->preview(1,'',$actor,'pdf');
$draft=$service->previewDraft(1,['name'=>'قالب مشتری','version'=>'1.0.1','template'=>['base_font_size'=>26,'footer'=>'','footer_font_size'=>44,'notes_font_size'=>30]],'',$actor,'png');
$doc=json_decode($draft['preview']['payload_json'],true);
$normalDoc=json_decode($normal['preview']['payload_json'],true);
if(($doc['template']['footer']??null)!==''||($normalDoc['template']['footer']??null)!=='متن قبلی')throw new RuntimeException('footer_clear_fails');
if(($doc['template']['footer_font_size']??null)!==44||($doc['template']['notes_font_size']??null)!==30)throw new RuntimeException('font_missing');
if(($draft['preview']['type']??null)!=='print.preview'||($draft['preview']['output_format']??null)!=='png'||($draft['draft']??false)!==true)throw new RuntimeException('wrong_preview_protocol');
if(($draft['bridge']['agent_id']??null)!==1||($draft['preview']['session_id']??'')===$normal['preview']['session_id'])throw new RuntimeException('bridge_or_unique_session_wrong');
if($before!==$pdo->query('SELECT template_json FROM print_template_packages WHERE id=1')->fetchColumn())throw new RuntimeException('template_mutated');
foreach([['template'=>['base_font_size'=>80],'code'=>'print_template_range'],['template'=>['header_font_size'=>20],'code'=>'print_template_unknown_field']] as $bad){try{$service->previewDraft(1,['version'=>'1.0.1']+$bad,'',$actor);throw new RuntimeException('invalid_draft_accepted');}catch(\Sokna\Local\Domain\Printing\PrintException $e){if($e->errorCode!==$bad['code'])throw new RuntimeException('unexpected_bad_draft_error:'.$e->errorCode);}}
try{$service->previewDraft(1,['version'=>'1.0.1','template'=>['footer'=>'']],'',['id'=>2,'role'=>'operator']);throw new RuntimeException('operator_authorized');}catch(\Sokna\Local\Domain\Printing\PrintException $e){if($e->httpStatus!==403)throw $e;}
$pdo->exec('UPDATE print_agents SET active=0 WHERE id=1');
try{$service->previewDraft(1,['version'=>'1.0.1','template'=>['footer'=>'']],'',$actor);throw new RuntimeException('no_bridge_accepted');}catch(\Sokna\Local\Domain\Printing\PrintException $e){if($e->httpStatus!==409)throw $e;}
print json_encode(['status'=>'PASS','read_only_preview'=>true,'footer_empty_preserved'=>true,'independent_fonts_accepted'=>true,'live_bridge_contract'=>'print.preview','non_admin_blocked'=>true,'invalid_config_rejected'=>true,'no_agent_fails_closed'=>true,'template_unchanged'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
