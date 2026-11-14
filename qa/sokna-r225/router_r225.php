<?php
// This QA-only router stands in for Apache's URI dispatch; original guest-lan.php handles the actual request.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R225_DISPOSABLE_ONLY')!=='YES') {http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if(is_string($path)&&str_starts_with($path,'/api/guest/')){require rtrim((string)getenv('SOKNA_R213_WEB'),'/').'/public/guest-lan.php';return true;}
return false;