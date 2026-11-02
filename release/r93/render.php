<?php
$root=$argv[1];$output=$argv[2];$kind=$argv[3];
$src=file_get_contents($root.'/resources/shared-staff-ui/dashboard-workspace.php');$a=strpos($src,'<section class="sc-card sc-dashboard-trend">');$b=strpos($src,'</section>',$a)+strlen('</section>');$fragment=substr($src,$a,$b-$a);
$reportsError=null;$todayProjectionUnavailable=false;$financeConflictDays=[];$weekFrom='2026-10-26';$todayKey='2026-11-01';$maxTrend=770000;
$weekDaily=array_map(fn($v)=>['value'=>$v,'label'=>'روز','known'=>true],(($argv[4]??'')==='signed'?[-400000,0,0,0,640000,0,770000]:[400000,0,0,0,640000,0,770000]));
$dashUrl=fn($x)=>htmlspecialchars($x);$dashE=fn($x)=>htmlspecialchars($x);
ob_start();eval('?>'.$fragment);$body=ob_get_clean();
$html='<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
$styles=$kind==='local'?['/assets/scds/tokens.css','/assets/scds/components.css','/public/assets/product-ui.css']:['/assets/scds/tokens.css','/assets/scds/components.css','/assets/scds/remote-staff.css','/assets/product-ui.css'];
foreach($styles as $p)$html.='<link rel="stylesheet" href="'.$p.'">';
$html.='<body data-page="home" data-staff-ui-mode="'.$kind.'"><main class="sc-dashboard"><div class="sc-dashboard-main">'.$body.'</div></main></body></html>';file_put_contents($output,$html);
