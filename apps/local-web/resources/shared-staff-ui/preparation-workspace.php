<?php
/** Canonical Preparation workspace markup shared by Local and Public Staff hosts.
 * @var array<string,mixed> $preparationConfig
 */
$preparationConfig=is_array($preparationConfig??null)?$preparationConfig:[];
$prepE=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$prepMode=(string)($preparationConfig['mode']??'local');
$prepAttrs=[
    'data-preparation-workspace'=>'',
    'data-staff-ui-mode'=>$prepMode,
    'data-auto-load'=>(($preparationConfig['auto_load']??true)?'1':'0'),
];
foreach(['csrf'=>'data-csrf','api'=>'data-api'] as $key=>$attr){$value=(string)($preparationConfig[$key]??'');if($value!=='')$prepAttrs[$attr]=$value;}
$prepAttrHtml='';foreach($prepAttrs as $name=>$value)$prepAttrHtml.=' '.$name.($value===''?'':'="'.$prepE($value).'"');
?>
<section class="sc-workspace"<?=$prepAttrHtml?>>
  <button class="sc-button sc-button--secondary" type="button" data-refresh hidden>تازه‌سازی</button>
  <section class="sc-preparation-panel">
    <div class="sc-preparation-summary" data-preparation-summary aria-live="polite"></div>
    <div class="sc-alert" role="status" aria-live="polite" data-status hidden></div>
    <div class="sc-preparation-groups" data-preparation-list></div>
  </section>
</section>
<script src="<?=\Sokna\Local\UI\ProductShell::asset('assets/staff-app/preparation-workspace.js')?>" defer></script>
