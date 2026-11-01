<?php
declare(strict_types=1);
$web=$argv[1];$helper=$argv[2];$tmp=$argv[3];$rows=[];
function check(bool $pass,string $name):void{global $rows;$rows[]=['test'=>$name,'pass'=>$pass];if(!$pass)throw new RuntimeException($name);}
foreach(['Config','Database','MigrationExecutionException','Migrations'] as $c)require_once $web.'/src/Core/'.$c.'.php';
$cfg=['db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'r91_rebind','user'=>'root','pass'=>'r91-test','charset'=>'utf8mb4']];
$pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));$m=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');$m->migrate();
$catalog=$m->catalog();$lines=[];foreach($catalog as $v=>$p)$lines[]=$v."\0".hash_file('sha256',$p);$fp='migration-ledger-sha256-v1:'.hash('sha256',implode("\n",$lines)."\n");$plan=['SourceFingerprint'=>$fp,'TargetFingerprint'=>$fp,'SourceHead'=>array_key_last($catalog),'TargetHead'=>array_key_last($catalog),'SourceCount'=>count($catalog),'TargetCount'=>count($catalog)];
mkdir($tmp,0700,true);file_put_contents($tmp.'/config.php','<?php return '.var_export($cfg,true).';');
$run=function()use($web,$helper,$tmp,$plan){$cmd=[PHP_BINARY,$helper,$tmp,$web,$web,'inspect',json_encode($plan),'',''];$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);return [$code,$out];};
$before=$m->appliedVersions();[$code,$out]=$run();check($code===0&&trim($out)==='SOKNA_DATABASE_OK','embedded production helper attests actual 76-migration MariaDB ledger');check($before===$m->appliedVersions(),'read-only attestation preserves applied ledger');
$last=array_key_last($catalog);$q=$pdo->prepare('DELETE FROM schema_migrations WHERE version=?');$q->execute([$last]);[$code]=$run();check($code===67,'actual incomplete ledger is rejected before identity adoption');
echo json_encode($rows,JSON_PRETTY_PRINT).PHP_EOL;
