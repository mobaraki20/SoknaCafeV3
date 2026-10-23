<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\GuestContent;

use RuntimeException;

final class ThemePackageManager
{
    private const V1_FORMAT='sokna-guest-theme-v1';
    private const V2_FORMAT='sokna-guest-theme-v2';
    private const V2_ENGINE_CONTRACT='sokna-guest-theme-engine-v2';
    private const V2_CONTRACT_REVISION=1;
    private const BASE_COLOR_TOKENS = [
        'canvas','surface','surface_soft','text','muted','border','primary','primary_contrast',
        'focus','ready_bg','ready_text','degraded_bg','degraded_text',
    ];
    private const V2_COLOR_TOKENS = ['accent','accent_contrast','danger','danger_contrast'];
    private const RADIUS_TOKENS = ['radius_md','radius_lg'];
    private const V1_COMPOSITION_SLOTS = ['campaigns','featured','catalog'];
    private const V2_COMPOSITION_SLOTS = ['campaigns','featured','catalog','more'];
    private const DEFAULT_V1_COMPOSITION = ['campaigns','featured','catalog'];
    private const DEFAULT_V2_COMPOSITION = ['campaigns','featured','catalog','more'];
    private const V2_VARIANTS = [
        'header'=>['compact','brand-forward'],
        'category_nav'=>['pills','tabs'],
        'catalog'=>['adaptive','list','cards'],
        'item_media'=>['leading','top','hidden'],
        'featured'=>['rail','grid'],
        'campaigns'=>['cards','banner'],
        'surfaces'=>['outlined','soft','elevated'],
        'density'=>['compact','comfortable','airy'],
        'detail'=>['sheet','editorial'],
        'action_dock'=>['floating','attached'],
        'content_width'=>['narrow','standard','wide'],
        'typography'=>['compact','standard','large'],
        'motion'=>['subtle','none'],
    ];
    private const V2_DEFAULT_VARIANTS = [
        'header'=>'compact','category_nav'=>'pills','catalog'=>'adaptive','item_media'=>'leading',
        'featured'=>'rail','campaigns'=>'cards','surfaces'=>'outlined','density'=>'comfortable',
        'detail'=>'sheet','action_dock'=>'floating','content_width'=>'standard','typography'=>'standard','motion'=>'subtle',
    ];

    public function __construct(private readonly string $root){}

    /** @return list<array<string,mixed>> */
    public function packages(): array
    {
        if (!is_dir($this->root)) return [];
        $entries = scandir($this->root) ?: [];$out=[];
        foreach($entries as $entry){
            if($entry==='.'||$entry==='..')continue;$dir=$this->root.DIRECTORY_SEPARATOR.$entry;if(!is_dir($dir))continue;
            try{$out[]=$this->loadDir($dir);}catch(RuntimeException){}
        }
        usort($out,static fn(array $a,array $b):int=>strcmp((string)$a['theme_key'],(string)$b['theme_key']));
        return $out;
    }

    /** @return array<string,mixed> */
    public function get(string $themeKey): array
    {
        $themeKey=trim($themeKey);if(!preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$themeKey))throw new RuntimeException('invalid_theme_key');
        $package=$this->loadDir($this->root.DIRECTORY_SEPARATOR.$themeKey);
        if(!hash_equals((string)$package['theme_key'],$themeKey))throw new RuntimeException('theme_key_directory_mismatch');
        return $package;
    }

    /** @return array<string,mixed> */
    public function resolve(string $themeKey,array $settings=[]): array{return $this->resolveManifest($this->get($themeKey),$settings);}

    /** @return array<string,mixed> */
    public function resolveManifest(array $manifest,array $settings=[]): array
    {
        unset($manifest['fingerprint']);$package=$this->validateManifest($manifest);$format=(string)$package['format'];
        $tokens=(array)$package['tokens'];$editable=array_values(array_map('strval',(array)$package['editable_tokens']));
        $defaultComposition=$format===self::V2_FORMAT?self::DEFAULT_V2_COMPOSITION:self::DEFAULT_V1_COMPOSITION;
        $composition=$this->validateComposition(array_key_exists('composition',$settings)?$settings['composition']:($package['composition']??$defaultComposition),$format);
        unset($settings['composition']);
        foreach($settings as $key=>$value){
            $key=(string)$key;if(!in_array($key,$editable,true))throw new RuntimeException('theme_setting_not_editable:'.$key);
            $tokens[$key]=$this->validateToken($key,(string)$value,$format);
        }
        foreach($tokens as $key=>$value)$tokens[(string)$key]=$this->validateToken((string)$key,(string)$value,$format);
        $this->validateReadability($tokens,$format);ksort($tokens);
        $resolved=[
            'format'=>$format,'theme_key'=>(string)$package['theme_key'],'name'=>(string)$package['name'],'version'=>(string)$package['version'],
            'description'=>(string)$package['description'],'layout'=>(string)$package['layout'],'media_aspect'=>(string)($package['media_aspect']??'1:1'),
            'fingerprint'=>(string)$package['fingerprint'],'tokens'=>$tokens,'editable_tokens'=>$editable,'composition'=>$composition,
        ];
        if($format===self::V2_FORMAT){$resolved['engine_contract']=(string)$package['engine_contract'];$resolved['contract_revision']=(int)$package['contract_revision'];$resolved['variants']=$this->validateVariants($package['variants']??self::V2_DEFAULT_VARIANTS);}
        return $resolved;
    }

    /** @return array<string,mixed> */
    public function validateRaw(string $raw): array
    {
        if($raw===''||strlen($raw)>65536)throw new RuntimeException('theme_manifest_unreadable');
        $manifest=json_decode($raw,true);if(!is_array($manifest))throw new RuntimeException('theme_manifest_invalid_json');return $this->validateManifest($manifest);
    }

    /** @return array<string,mixed> */
    public function validateManifest(array $manifest): array
    {
        $format=(string)($manifest['format']??'');
        if(!in_array($format,[self::V1_FORMAT,self::V2_FORMAT],true))throw new RuntimeException('theme_format');
        $isV2=$format===self::V2_FORMAT;
        $allowed=['format','theme_key','name','version','description','layout','media_aspect','composition','tokens','editable_tokens'];
        if($isV2){$allowed[]='engine_contract';$allowed[]='contract_revision';$allowed[]='variants';}
        foreach(array_keys($manifest) as $field)if(!in_array((string)$field,$allowed,true))throw new RuntimeException('theme_field_forbidden:'.$field);
        $key=trim((string)($manifest['theme_key']??''));$name=trim((string)($manifest['name']??''));$version=trim((string)($manifest['version']??''));$layout=trim((string)($manifest['layout']??''));
        if(!preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$key))throw new RuntimeException('theme_key');
        if($name===''||$this->length($name)>80)throw new RuntimeException('theme_name');
        if(!preg_match('/^\d+\.\d+\.\d+$/D',$version))throw new RuntimeException('theme_version');
        if(!in_array($layout,['cards'],true))throw new RuntimeException('theme_layout');
        $hasAspect=array_key_exists('media_aspect',$manifest);$mediaAspect=trim((string)($manifest['media_aspect']??'1:1'));if(!in_array($mediaAspect,['1:1','4:3','3:2'],true))throw new RuntimeException('theme_media_aspect');
        $defaultComposition=$isV2?self::DEFAULT_V2_COMPOSITION:self::DEFAULT_V1_COMPOSITION;
        $hasComposition=array_key_exists('composition',$manifest);$composition=$this->validateComposition($manifest['composition']??$defaultComposition,$format);
        $tokens=is_array($manifest['tokens']??null)?$manifest['tokens']:[];$allowedTokens=$this->allowedTokens($format);
        foreach($allowedTokens as $required)if(!array_key_exists($required,$tokens))throw new RuntimeException('theme_token_missing:'.$required);
        foreach($tokens as $token=>$value){if(!in_array((string)$token,$allowedTokens,true))throw new RuntimeException('theme_token_unknown:'.$token);$tokens[(string)$token]=$this->validateToken((string)$token,(string)$value,$format);}
        $this->validateReadability($tokens,$format);
        $editable=array_values(array_unique(array_map('strval',is_array($manifest['editable_tokens']??null)?$manifest['editable_tokens']:[])));
        foreach($editable as $token)if(!array_key_exists($token,$tokens))throw new RuntimeException('theme_editable_unknown:'.$token);
        $description=trim((string)($manifest['description']??''));if($this->length($description)>240)throw new RuntimeException('theme_description');

        // Preserve the exact historical v1 canonicalization so existing fingerprints never drift.
        if(!$isV2){
            $canonical=['format'=>self::V1_FORMAT,'theme_key'=>$key,'name'=>$name,'version'=>$version,'description'=>$description,'layout'=>$layout,'tokens'=>$tokens,'editable_tokens'=>$editable];
            if($hasAspect)$canonical['media_aspect']=$mediaAspect;if($hasComposition)$canonical['composition']=$composition;
        }else{
            $engineContract=trim((string)($manifest['engine_contract']??''));$contractRevision=(int)($manifest['contract_revision']??0);
            if($engineContract!==self::V2_ENGINE_CONTRACT)throw new RuntimeException('theme_engine_contract');
            if($contractRevision<1||$contractRevision>self::V2_CONTRACT_REVISION)throw new RuntimeException('theme_contract_revision');
            $variants=$this->validateVariants($manifest['variants']??self::V2_DEFAULT_VARIANTS);
            $canonical=['format'=>self::V2_FORMAT,'engine_contract'=>$engineContract,'contract_revision'=>$contractRevision,'theme_key'=>$key,'name'=>$name,'version'=>$version,'description'=>$description,'layout'=>$layout,'media_aspect'=>$mediaAspect,'composition'=>$composition,'variants'=>$variants,'tokens'=>$tokens,'editable_tokens'=>$editable];
        }
        $encoded=json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$canonical['fingerprint']=hash('sha256',$encoded);return $canonical;
    }

    /** @return array<string,mixed> */
    private function loadDir(string $dir): array
    {
        if(!is_dir($dir))throw new RuntimeException('theme_package_missing');$files=[];$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){if(!$file->isFile())continue;$rel=str_replace('\\','/',substr($file->getPathname(),strlen($dir)+1));$files[]=$rel;if($rel!=='theme.json')throw new RuntimeException('theme_package_forbidden_file:'.$rel);}
        sort($files);if($files!==['theme.json'])throw new RuntimeException('theme_package_contract');$raw=file_get_contents($dir.DIRECTORY_SEPARATOR.'theme.json');if(!is_string($raw))throw new RuntimeException('theme_manifest_unreadable');return $this->validateRaw($raw);
    }

    /** @return list<string> */
    private function validateComposition(mixed $value,string $format): array
    {
        if(!is_array($value)||!array_is_list($value))throw new RuntimeException('theme_composition');$allowed=$format===self::V2_FORMAT?self::V2_COMPOSITION_SLOTS:self::V1_COMPOSITION_SLOTS;$out=[];
        foreach($value as $slot){if(!is_string($slot)||!in_array($slot,$allowed,true)||in_array($slot,$out,true))throw new RuntimeException('theme_composition_slot');$out[]=$slot;}
        if($format===self::V2_FORMAT){foreach(self::V2_COMPOSITION_SLOTS as $required)if(!in_array($required,$out,true))throw new RuntimeException('theme_composition_protected_slot:'.$required);return $out;}
        if(!in_array('catalog',$out,true))throw new RuntimeException('theme_composition_catalog_required');return $out;
    }

    /** @return array<string,string> */
    private function validateVariants(mixed $value): array
    {
        if(!is_array($value)||array_is_list($value))throw new RuntimeException('theme_variants');
        foreach(array_keys($value) as $key)if(!array_key_exists((string)$key,self::V2_VARIANTS))throw new RuntimeException('theme_variant_unknown:'.$key);
        $out=[];foreach(self::V2_VARIANTS as $key=>$allowed){$selected=trim((string)($value[$key]??self::V2_DEFAULT_VARIANTS[$key]));if(!in_array($selected,$allowed,true))throw new RuntimeException('theme_variant_invalid:'.$key);$out[$key]=$selected;}return $out;
    }

    /** @return list<string> */
    private function allowedTokens(string $format): array
    {
        return array_merge(self::BASE_COLOR_TOKENS,$format===self::V2_FORMAT?self::V2_COLOR_TOKENS:[],self::RADIUS_TOKENS);
    }

    /** @param array<string,string> $tokens */
    private function validateReadability(array $tokens,string $format): void
    {
        $mutedMinimum=$format===self::V2_FORMAT?4.5:3.0;
        $checks=[
            ['text','canvas',4.5,'theme_contrast_text_canvas'],['text','surface',4.5,'theme_contrast_text_surface'],['text','surface_soft',4.5,'theme_contrast_text_surface_soft'],
            ['muted','canvas',$mutedMinimum,'theme_contrast_muted_canvas'],['muted','surface',$mutedMinimum,'theme_contrast_muted_surface'],['primary_contrast','primary',4.5,'theme_contrast_primary'],
            ['focus','canvas',3.0,'theme_contrast_focus_canvas'],['focus','surface',3.0,'theme_contrast_focus_surface'],['ready_text','ready_bg',4.5,'theme_contrast_ready'],['degraded_text','degraded_bg',4.5,'theme_contrast_degraded'],
        ];
        if($format===self::V2_FORMAT){$checks[]=['accent_contrast','accent',4.5,'theme_contrast_accent'];$checks[]=['danger_contrast','danger',4.5,'theme_contrast_danger'];}
        foreach($checks as [$fg,$bg,$minimum,$code]){if(!isset($tokens[$fg],$tokens[$bg]))continue;if($this->contrastRatio((string)$tokens[$fg],(string)$tokens[$bg])+1e-9<(float)$minimum)throw new RuntimeException((string)$code);}
    }

    private function contrastRatio(string $a,string $b): float{$la=$this->relativeLuminance($a);$lb=$this->relativeLuminance($b);return (max($la,$lb)+0.05)/(min($la,$lb)+0.05);}
    private function relativeLuminance(string $hex): float{$parts=[substr($hex,1,2),substr($hex,3,2),substr($hex,5,2)];$rgb=[];foreach($parts as $part){$c=hexdec($part)/255;$rgb[]=$c<=0.04045?$c/12.92:(($c+0.055)/1.055)**2.4;}return 0.2126*$rgb[0]+0.7152*$rgb[1]+0.0722*$rgb[2];}
    private function length(string $value): int{if(function_exists('mb_strlen'))return mb_strlen($value,'UTF-8');$ok=preg_match_all('/./us',$value,$m);return $ok===false?strlen($value):$ok;}

    private function validateToken(string $key,string $value,string $format): string
    {
        $value=trim($value);$colors=array_merge(self::BASE_COLOR_TOKENS,$format===self::V2_FORMAT?self::V2_COLOR_TOKENS:[]);
        if(in_array($key,$colors,true)){if(!preg_match('/^#[0-9A-Fa-f]{6}$/D',$value))throw new RuntimeException('theme_color_invalid:'.$key);return strtoupper($value);}
        if(in_array($key,self::RADIUS_TOKENS,true)){if(!preg_match('/^(?:[8-9]|[1-3]\d|40)px$/D',$value))throw new RuntimeException('theme_radius_invalid:'.$key);return strtolower($value);}
        throw new RuntimeException('theme_token_unknown:'.$key);
    }
}
