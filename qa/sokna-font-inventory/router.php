<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'){http_response_code(503);exit;}
$tmp=getenv('RUNNER_TEMP');$path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$base=getenv('SOKNA_Z_MODE')==='subfolder'?'/public/menu':'';
if(getenv('SOKNA_QA_FONT_ROUTE')==='physical'){
    foreach(['/font.php','/assets/scds/font.php'] as $rel){
        if($path===$base.$rel){require $tmp.'/public'.$rel;exit;}
    }
}
require dirname(__DIR__).'/sokna-request-z/router.php';
