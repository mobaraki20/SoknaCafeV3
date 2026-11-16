<?php
declare(strict_types=1);
$root=(string)getenv('R261_PUBLIC_ROOT');
$codePath=(string)getenv('R261_CODE_PATH');
if(getenv('GITHUB_ACTIONS')!=='true'||!is_file($root.'/install.lock')||$codePath==='')throw new RuntimeException('R261_ONLY_DISPOSABLE_PUBLIC');
$_SERVER['HTTP_HOST']='127.0.0.1:18881';
$_SERVER['DOCUMENT_ROOT']=$root;$_SERVER['HTTPS']='off';$_SERVER['REQUEST_URI']='/new-public/setup/';
require_once $root.'/bootstrap.php';
$config=require $root.'/config.php';
if(!is_array($config))throw new RuntimeException('missing_config');
$config['app']['base_url']='http://127.0.0.1:18881/new-public';
file_put_contents($root.'/config.php',"<?php\nreturn ".var_export($config,true).";\n");
$setup=new \Sokna\PublicEdge\Setup\PublicSetupService($root);
if($setup->status()['paired'])throw new RuntimeException('public_already_paired');
$code=$setup->issueOwnerPairingCode();
file_put_contents($codePath,$code);
chmod($codePath,0600);
echo "R261_PUBLIC_TEST_PAIRING_CODE_PREPARED\n";
