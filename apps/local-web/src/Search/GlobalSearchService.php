<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use Sokna\Local\Core\SnapshotGuard;

final class GlobalSearchService
{
    /** @param list<SearchProvider> $providers */
    public function __construct(private readonly array $providers,private readonly SnapshotGuard $guard){}

    /** @return array{query:string,results:list<array<string,mixed>>,count:int,min_length:int,partial:bool,errors:array<string,array{code:string,message:string,support_id:string}>} */
    public function search(string $raw,array $user,int $limit=14): array
    {
        $query=SearchNormalizer::normalize($raw);$limit=max(1,min(20,$limit));
        if(SearchNormalizer::length($query)<2)return ['query'=>$query,'results'=>[],'count'=>0,'min_length'=>2,'partial'=>false,'errors'=>[]];
        if(SearchNormalizer::length($query)>80)$query=function_exists('mb_substr')?mb_substr($query,0,80,'UTF-8'):substr($query,0,80);
        $all=[];$perProvider=6;$errors=[];
        foreach($this->providers as $provider){
            $key=$this->providerKey($provider);
            $section=$this->guard->capture('global_search',$key,[],'بخشی از نتایج جست‌وجو فعلاً در دسترس نیست.',fn(): array=>$provider->search($query,$user,$perProvider));
            if($section['error']!==null)$errors[$key]=$section['error'];
            foreach($section['value'] as $result)if(is_array($result))$all[]=$result;
        }
        usort($all,static fn(array $a,array $b)=>((int)($b['score']??0)<=>((int)($a['score']??0)))?:strcmp((string)($a['title']??''),(string)($b['title']??'')));
        $seen=[];$out=[];
        foreach($all as $result){$id=(string)($result['id']??'');if($id===''||isset($seen[$id]))continue;$seen[$id]=true;unset($result['score']);$out[]=$result;if(count($out)>=$limit)break;}
        return ['query'=>$query,'results'=>$out,'count'=>count($out),'min_length'=>2,'partial'=>$errors!==[],'errors'=>$errors];
    }

    private function providerKey(SearchProvider $provider): string
    {
        $class=get_class($provider);$short=strrchr($class,'\\');$short=$short===false?$class:substr($short,1);
        $short=preg_replace('/SearchProvider$/','',$short)??$short;
        return strtolower(preg_replace('/(?<!^)[A-Z]/','_$0',$short)??$short);
    }
}
