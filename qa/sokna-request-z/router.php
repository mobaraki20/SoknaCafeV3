<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'){http_response_code(503);exit;}
$tmp=getenv('RUNNER_TEMP');$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if(str_ends_with($path,'/api/v1/local/realtime/exchange')&&is_file($tmp.'/z-drop-acks')){
 $body=json_decode(file_get_contents('php://input'),true);
 if(!empty($body['acks'])){http_response_code(503);header('Content-Type: application/json');echo '{"ok":false,"error":"qa_ack_loss"}';exit;}
}
require $tmp.'/public/index.php';
