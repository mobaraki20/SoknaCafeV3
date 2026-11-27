<?php
declare(strict_types=1);
header('Content-Type: application/json');
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer synthetic-cc16-accommodation'||($_GET['action']??'')!=='search'){http_response_code(401);echo '{"success":false,"code":"unauthorized"}';exit;}
echo '{"success":true,"data":[]}';
