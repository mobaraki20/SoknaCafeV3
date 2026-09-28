<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/src/Domain/Sellables/CategoryIconLibrary.php';
use Sokna\Local\Domain\Sellables\CategoryIconLibrary;
function dc(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$lib=new CategoryIconLibrary($root.'/apps/local-web/resources/default-content/v1/category-icons.json');
$groups=$lib->groups();$keys=$lib->keys();
dc(count($groups)===6,'Expected six icon groups');
dc(count($keys)===56,'Expected 56 category icons');
dc(count(array_unique($keys))===56,'Category icon keys are not unique');
foreach(['bean','tea','sharbat','cold-drink','shake','cake','water','appetizer','burger','pasta','food','iranian-food','sauce','service','list'] as $key)dc($lib->isAllowed($key),'Expected icon missing: '.$key);
dc($lib->resolve('', 'قهوه')==='bean','Persian coffee auto-resolution failed');
dc($lib->resolve('', 'انواع برگر')==='burger','Persian burger auto-resolution failed');
dc($lib->resolve('pizza','هر نامی')==='pizza','Explicit icon must win');
dc($lib->resolve('not-valid','دسته ناشناخته')==='list','Unknown category must fall back to list');
echo "Default category icon library pure selftest: OK\n";
