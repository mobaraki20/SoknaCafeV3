<?php
declare(strict_types=1);
// Read-only preflight. Never connect to a real installation. Require an explicitly disposable DB.
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'){fwrite(STDERR,"BLOCKED: explicit disposable QA authorization required\n");exit(2);}
if(!in_array('mysql',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"BLOCKED: PDO MySQL not installed\n");exit(2);}
foreach(['gd','mbstring','openssl','pdo_mysql','curl'] as $ext)if(!extension_loaded($ext)){fwrite(STDERR,"BLOCKED: missing PHP extension $ext\n");exit(2);}
try{
 $p=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','synthetic-cp116-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);
 $v=(string)$p->query('SELECT VERSION()')->fetchColumn();if(stripos($v,'MariaDB')===false)throw new RuntimeException('Expected disposable MariaDB');
 $schemas=$p->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);$extra=array_diff($schemas,['information_schema','mysql','performance_schema','sys']);if($extra)throw new RuntimeException('Existing non-system databases: refusing all mutation');
 echo 'PASS isolated empty MariaDB '.$v.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"BLOCKED: disposable MariaDB preflight failed: ".$e->getMessage().PHP_EOL);exit(2);}
