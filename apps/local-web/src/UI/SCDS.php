<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

final class SCDS
{
    public static function e(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function button(string $label,string $variant='primary',array $attrs=[]): string
    {
        $class='sc-button'.($variant==='primary'?'':' sc-button--'.preg_replace('/[^a-z-]/','',$variant));return '<button'.self::attrs(['type'=>'button','class'=>$class]+$attrs).'>'.self::e($label).'</button>';
    }
    public static function field(string $name,string $label,string $value='',array $attrs=[]): string
    {
        $id='sc-'.preg_replace('/[^A-Za-z0-9_-]/','-',$name);return '<label class="sc-field" for="'.self::e($id).'"><span class="sc-field__label">'.self::e($label).'</span><input'.self::attrs(['class'=>'sc-control','id'=>$id,'name'=>$name,'value'=>$value]+$attrs).'></label>';
    }
    public static function badge(string $label,string $tone='neutral'): string{return '<span class="sc-badge'.($tone==='neutral'?'':' sc-badge--'.self::e($tone)).'">'.self::e($label).'</span>';}
    public static function alert(string $message,string $tone='neutral'): string{return '<div role="status" class="sc-alert'.($tone==='neutral'?'':' sc-alert--'.self::e($tone)).'">'.self::e($message).'</div>';}
    public static function systemState(string $title,string $message,string $actionHtml=''): string{return '<main class="sc-system-state"><section class="sc-system-state__panel"><h1>'.self::e($title).'</h1><p>'.self::e($message).'</p>'.$actionHtml.'</section></main>';}
    private static function attrs(array $attrs): string{$out='';foreach($attrs as $key=>$value){if($value===false||$value===null)continue;$safeKey=preg_replace('/[^A-Za-z0-9_:-]/','',(string)$key);if($safeKey==='')continue;if($value===true){$out.=' '.self::e($safeKey);continue;}$out.=' '.self::e($safeKey).'="'.self::e((string)$value).'"';}return $out;}
}
