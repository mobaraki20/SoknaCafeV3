<?php
$root=getenv('RUNNER_TEMP').'/local';require $root.'/bootstrap.php';
$c=sokna_local_bootstrap(require dirname($root).'/config.php');
foreach(['currentViewWorkbook','analyticalWorkbook'] as $m){
$x=$c->reporting()->$m('2026-11-24','2026-11-24');
if(!str_starts_with($x,'PK'))throw new RuntimeException('Invalid XLSX '.$m);
}
echo "PASS actual C2 report exports\n";
