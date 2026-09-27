<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

final class GuestThemeCssService
{
    private const MAP=[
        'canvas'=>'--sg-color-canvas','surface'=>'--sg-color-surface','surface_soft'=>'--sg-color-surface-soft',
        'text'=>'--sg-color-text','muted'=>'--sg-color-muted','border'=>'--sg-color-border','primary'=>'--sg-color-primary',
        'primary_contrast'=>'--sg-color-primary-contrast','focus'=>'--sg-color-focus','ready_bg'=>'--sg-color-ready-bg',
        'ready_text'=>'--sg-color-ready-text','degraded_bg'=>'--sg-color-degraded-bg','degraded_text'=>'--sg-color-degraded-text',
        'radius_md'=>'--sg-radius-md','radius_lg'=>'--sg-radius-lg',
    ];

    public function __construct(private readonly GuestRuntimeService $runtime){}

    public function css(string $installationId): string
    {
        $bundle=$this->runtime->bundle($installationId);
        $snapshot=is_array($bundle['snapshot']??null)?$bundle['snapshot']:[];
        $presentation=is_array($snapshot['presentation']??null)?$snapshot['presentation']:[];
        $theme=is_array($presentation['theme']??null)?$presentation['theme']:[];
        $tokens=is_array($theme['tokens']??null)?$theme['tokens']:[];
        $decl=[];
        foreach(self::MAP as $key=>$cssVar){
            $value=trim((string)($tokens[$key]??''));
            if($value==='')continue;
            if(str_starts_with($key,'radius_')){
                if(!preg_match('/^(?:[8-9]|[1-3]\d|40)px$/D',$value))continue;
            }elseif(!preg_match('/^#[0-9A-Fa-f]{6}$/D',$value))continue;
            $decl[]=$cssVar.':'.$value;
        }
        return $decl===[]?'':":root{".implode(';',$decl)."}\n";
    }
}
