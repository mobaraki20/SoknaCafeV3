<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
function cli_arg(array $argv,string $key): string {foreach($argv as $a)if(str_starts_with($a,$key.'='))return substr($a,strlen($key)+1);return '';}
function cli_fail(string $m): never {fwrite(STDERR,$m.PHP_EOL);exit(2);}
$command=$argv[1]??'';$root=dirname(__DIR__,3);$configFile=$root.DIRECTORY_SEPARATOR.'config.php';if(!is_file($configFile))cli_fail('SOKNA config.php is missing.');$config=require $configFile;if(!is_array($config))cli_fail('SOKNA config is invalid.');$core=sokna_local_bootstrap($config);
$passFile=cli_arg($argv,'--passphrase-file');if($passFile===''||!is_file($passFile))cli_fail('Passphrase file is required.');$pass=rtrim((string)file_get_contents($passFile),"\r\n");
if($command==='create'){
    $output=cli_arg($argv,'--output');if($output==='')cli_fail('Output file is required.');$meta=$core->businessBackup()->createEncrypted($output,$pass,0);$set=cli_arg($argv,'--recovery-set');if($set!==''){$payload=['format'=>'sokna-recovery-set-v1','created_at'=>$meta['created_at'],'business_backup'=>['path'=>basename($output),'sha256'=>$meta['sha256']],'source_installation_id'=>$meta['source_installation_id'],'excluded_machine_identity'=>$meta['excluded_machine_identity']];file_put_contents($set,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",LOCK_EX);}echo json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;exit(0);
}
if($command==='inspect'){ $source=cli_arg($argv,'--input');if($source==='')cli_fail('Input file is required.');echo json_encode($core->businessBackup()->inspectEncrypted($source,$pass),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;exit(0); }
cli_fail('Usage: business-backup.php create|inspect ...');
