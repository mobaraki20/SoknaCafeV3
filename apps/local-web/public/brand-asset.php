<?php
declare(strict_types=1);
$core=require __DIR__.'/_app.php';
$variant=trim((string)($_GET['variant']??''));
$fallback=[
    'logo-256'=>'/assets/brand/Sokna-AppIcon-256.png',
    'icon-16'=>'/assets/favicon-16.png','icon-32'=>'/assets/favicon-32.png','icon-48'=>'/assets/favicon-48.png',
    'icon-180'=>'/assets/favicon-180.png','icon-192'=>'/assets/favicon-192.png','icon-512'=>'/assets/favicon-512.png',
];
$expected=['logo-256'=>256,'icon-16'=>16,'icon-32'=>32,'icon-48'=>48,'icon-180'=>180,'icon-192'=>192,'icon-512'=>512];
if(!isset($fallback[$variant])){http_response_code(404);exit;}
$validPng=static function(string $path,int $size): bool{
    if(!is_file($path)||!is_readable($path))return false;
    $info=@getimagesize($path);
    return is_array($info)&&($info[0]??0)===$size&&($info[1]??0)===$size&&($info['mime']??'')==='image/png';
};
$fallbackPath=__DIR__.str_replace('/',DIRECTORY_SEPARATOR,$fallback[$variant]);
$file=$core->guestContent()->brandIdentityAsset($variant,'draft');
if(is_array($file)){
    $candidate=(string)($file['path']??'');
    $candidateMime=strtolower(trim((string)($file['mime']??'')));
    if($candidateMime==='image/png'&&$validPng($candidate,$expected[$variant])){
        $path=$candidate;$mime='image/png';$etag='"'.(string)($file['sha256']??hash_file('sha256',$candidate)).'"';
    }
}
if(!isset($path)){
    $path=$fallbackPath;$mime='image/png';
    if(!$validPng($path,$expected[$variant])){http_response_code(404);exit;}
    $sha=@hash_file('sha256',$path);$etag='"'.(is_string($sha)?$sha:'fallback').'"';
}
$size=@filesize($path);
header('Content-Type: '.$mime);
if(is_int($size)&&$size>=0)header('Content-Length: '.$size);
header('Cache-Control: private,max-age=300,must-revalidate');
header('ETag: '.$etag);
header('X-Content-Type-Options: nosniff');
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
readfile($path);
