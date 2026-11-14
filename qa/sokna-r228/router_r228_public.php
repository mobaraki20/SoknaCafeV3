<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R228_DISPOSABLE_ONLY')!=='YES'){http_response_code(503);exit;}
$root=(string)getenv('SOKNA_R228_PUBLIC');
$uri=(string)($_SERVER['REQUEST_URI']??'/');$path=(string)(parse_url($uri,PHP_URL_PATH)?:'/');
if($path==='/api/v1/local/realtime/exchange' && is_file((string)getenv('RUNNER_TEMP').'/r228-drop-acks')){
    $raw=file_get_contents('php://input');$data=json_decode((string)$raw,true);
    if(is_array($data)&&is_array($data['acks']??null)&&$data['acks']!==[]){
        // QA-only transport fault: do not call business route. The Local outbox retains the ACK.
        http_response_code(503);header('Content-Type: application/json');echo '{"ok":false,"error":"r228_simulated_ack_transport_loss"}';exit;
    }
}
$file=realpath($root.$path);
if($file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file)&&!str_ends_with($file,'.php'))return false;
require $root.'/index.php';