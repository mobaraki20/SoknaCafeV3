<?php
declare(strict_types=1);
$file=(string)($_GET['file']??'');$allowed=['tokens.css','components.css','scds.js'];if(!in_array($file,$allowed,true)){http_response_code(404);exit;}$path=dirname(__DIR__).'/assets/scds/'.$file;if(!is_file($path)){http_response_code(404);exit;}header('Content-Type: '.(str_ends_with($file,'.js')?'application/javascript':'text/css').'; charset=utf-8');header('Cache-Control: public,max-age=3600');readfile($path);
