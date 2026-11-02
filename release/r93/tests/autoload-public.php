<?php
// Load production classes lazily; separately provisioned Emergency owners are
// outside the managed payload and are not dependencies of these domain tests.
spl_autoload_register(static function(string $class)use($root):void{
    foreach(['Sokna\\PublicEdge\\'=>$root.'/public/src/','Sokna\\Local\\UI\\'=>$root.'/public/src/UI/'] as $prefix=>$base){
        if(str_starts_with($class,$prefix)){$path=$base.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;return;}
    }
});
