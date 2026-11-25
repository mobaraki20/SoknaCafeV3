<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('CI only');
$t=getenv('RUNNER_TEMP');require $t.'/local/bootstrap.php';$c=sokna_local_bootstrap(require $t.'/config.php');$p=$c->database();$f=json_decode(file_get_contents($t.'/print-fixture.json'),true,512,JSON_THROW_ON_ERROR);$r=json_decode(file_get_contents($t.'/print-evidence/result.json'),true,512,JSON_THROW_ON_ERROR);
$a=function($v,$m){if(!$v)throw new RuntimeException($m);};
$a(count($r)===2,'both kinds complete');
foreach($r as $k=>$v){$d=$c->printTemplates()->details($v['base'],$f['admin']);$a($d['active']&&$d['package']===$f['originals'][$k],'original active intact '.$k);$i=$c->printTemplates()->details($v['imported'],$f['admin']);$a(!$i['active']&&$i['template']===$v['template'],'reimport canonical '.$k);}
$a((int)$p->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===$f['initial_jobs'],'preview never queues print');
echo "PASS independent database readback and no physical print jobs\n";
